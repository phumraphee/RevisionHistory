<?php
namespace RevisionHistory;

use Doctrine\ORM\Events;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Module\AbstractModule;
use RevisionHistory\Capture\Recorder;

// Omeka only autoloads a module's classes once it is active; install() runs before that.
spl_autoload_register(function ($class) {
    if (strpos($class, __NAMESPACE__ . '\\') === 0) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen(__NAMESPACE__) + 1)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

class Module extends AbstractModule
{
    /** The roles that may read the history. Copying other modules' logs is global admin only. */
    const READERS = ['global_admin', 'site_admin', 'editor', 'reviewer'];

    /** Everybody who can save a resource, and so sees what a save would change first. */
    const SAVERS = ['global_admin', 'site_admin', 'editor', 'reviewer', 'author'];

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function install(ServiceLocatorInterface $services)
    {
        (new Store($services->get('Omeka\Connection')))->install();
        $settings = $services->get('Omeka\Settings');
        if (!$settings->get('revision_history_from')) {
            // The moment this archive started keeping its own history. Before it, only
            // what was copied from other modules can be shown.
            $settings->set('revision_history_from', gmdate('Y-m-d H:i:s'));
        }
        $stopped = $settings->get('revision_history_stopped');
        if ($stopped) {
            // Installed again after being uninstalled: nothing was recorded in between,
            // and the page has to say so rather than let the gap look like a quiet week.
            $gaps = (array) $settings->get('revision_history_gaps', []);
            $gaps[] = [$stopped, gmdate('Y-m-d H:i:s')];
            $settings->set('revision_history_gaps', $gaps);
            $settings->delete('revision_history_stopped');
        }
        // The field read as a resource's identifier (an accession or call number), shown
        // beside its title and searchable on the history page. Empty: none is shown.
        if ($settings->get('revision_history_accession_term') === null) {
            $settings->set('revision_history_accession_term', 'dcterms:identifier');
        }
        // The field read as the title people know a resource by.
        if ($settings->get('revision_history_title_term') === null) {
            $settings->set('revision_history_title_term', 'dcterms:title');
        }
        // How old the last check of the data itself may be before another one is started
        // while somebody is working in the admin pages. An hour, to begin with.
        if ($settings->get('revision_history_watch_hours') === null) {
            $settings->set('revision_history_watch_hours', 1);
        }
        // The admin pages show what a save would change, and ask, before making it.
        if ($settings->get('revision_history_confirm') === null) {
            $settings->set('revision_history_confirm', true);
        }
        // And say so when somebody else saved the resource after the form was opened (1.0.4).
        if ($settings->get('revision_history_confirm_stale') === null) {
            $settings->set('revision_history_confirm_stale', true);
        }
    }

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $services)
    {
        $this->install($services);
    }

    /**
     * Uninstall leaves the tables where they are, on purpose.
     *
     * The three log modules this one sits next to all DROP their tables on
     * uninstall, which means one click in the Modules page ends the history for
     * good. A history that can be deleted by tidying up the module list is not a
     * history. To remove it for real, drop `revision_history_*` by hand.
     */
    public function uninstall(ServiceLocatorInterface $services)
    {
        $services->get('Omeka\Settings')->set('revision_history_stopped', gmdate('Y-m-d H:i:s'));
    }

    /**
     * Whether the admin pages show what a save would change before making it, and which
     * two fields name a resource on the history page. Omeka lets only a global
     * administrator open a module's settings.
     */
    public function getConfigForm(\Laminas\View\Renderer\PhpRenderer $renderer)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $e = function ($text) use ($renderer) {
            return $renderer->escapeHtml($renderer->translate($text));
        };
        $field = function (string $name, string $label, string $info, string $input) use ($e) {
            return '<div class="field"><div class="field-meta">'
                . '<label for="' . $name . '">' . $e($label) . '</label>'
                . '<div class="field-description">' . $e($info) . '</div></div>'
                . '<div class="inputs">' . $input . '</div></div>';
        };
        $text = function (string $name, string $value) use ($renderer) {
            return '<input type="text" id="' . $name . '" name="' . $name . '" value="'
                . $renderer->escapeHtml($value) . '" placeholder="dcterms:title">';
        };
        $on = (bool) $settings->get('revision_history_confirm', true);
        $stale = (bool) $settings->get('revision_history_confirm_stale', true);
        return $field(
            'revision_history_confirm',
            'Check before saving', // @translate
            'Show what a save would change, and ask, before it is made. The history is kept either way.', // @translate
            '<input type="hidden" name="revision_history_confirm" value="0">'
                . '<input type="checkbox" id="revision_history_confirm" name="revision_history_confirm" value="1"' . ($on ? ' checked' : '') . '>'
        ) . $field(
            'revision_history_confirm_stale',
            'Warn when somebody else saved first', // @translate
            'In the check before saving, say so when the resource was saved by somebody else after the form was opened — saving the form as it stands would undo their work.', // @translate
            '<input type="hidden" name="revision_history_confirm_stale" value="0">'
                . '<input type="checkbox" id="revision_history_confirm_stale" name="revision_history_confirm_stale" value="1"' . ($stale ? ' checked' : '') . '>'
        ) . $field(
            'revision_history_title_term',
            'Title field', // @translate
            'The property read as the name of a resource on the history page, as prefix:name. Default dcterms:title.', // @translate
            $text('revision_history_title_term', (string) $settings->get('revision_history_title_term', 'dcterms:title'))
        ) . $field(
            'revision_history_accession_term',
            'Identifier field', // @translate
            'The property read as an identifier (accession number, call number) shown beside the title and searchable on the history page, as prefix:name. Leave empty for none. Default dcterms:identifier.', // @translate
            $text('revision_history_accession_term', (string) $settings->get('revision_history_accession_term', 'dcterms:identifier'))
        );
    }

    public function handleConfigForm(\Laminas\Mvc\Controller\AbstractController $controller)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $params = $controller->params();
        $settings->set('revision_history_confirm', (bool) $params->fromPost('revision_history_confirm'));
        $settings->set('revision_history_confirm_stale', (bool) $params->fromPost('revision_history_confirm_stale'));
        foreach (['revision_history_title_term', 'revision_history_accession_term'] as $name) {
            $term = trim((string) $params->fromPost($name, ''));
            if ($term !== '' && !preg_match('/^[A-Za-z][A-Za-z0-9_-]*:[A-Za-z_][A-Za-z0-9_.-]*$/', $term)) {
                $controller->messenger()->addError(sprintf(
                    $controller->translate('"%s" is not a property term like dcterms:title.'), // @translate
                    $term
                ));
                return false;
            }
            $settings->set($name, $term);
        }
        return true;
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);
        $services = $this->getServiceLocator();

        $acl = $services->get('Omeka\Acl');
        $acl->allow(self::READERS, [Controller\Admin\IndexController::class], ['browse', 'item', 'export']);
        $acl->allow(self::SAVERS, [Controller\Admin\IndexController::class], ['preview', 'refused']);
        $acl->allow('global_admin', [Controller\Admin\IndexController::class]);

        $recorder = new Recorder($services);
        $services->get('Omeka\EntityManager')->getEventManager()->addEventListener(
            [Events::onFlush, Events::postFlush],
            $recorder
        );
        // Before any other module's listener on the same event (History Log uses -50).
        $shared = $event->getApplication()->getEventManager()->getSharedManager();
        foreach (['Omeka\Entity\Item', 'Omeka\Entity\Media', 'Omeka\Entity\ItemSet'] as $entity) {
            $shared->attach($entity, 'entity.remove.pre', [$recorder, 'beforeRemove'], 10000);
        }
        foreach (['api.delete.pre', 'api.batch_delete.pre'] as $name) {
            $shared->attach('*', $name, [$recorder, 'beforeApiDelete'], 10000);
        }
        foreach (['api.delete.post', 'api.batch_delete.post'] as $name) {
            $shared->attach('*', $name, [$recorder, 'afterApiDelete'], -10000);
        }
        // What happens without a resource being saved: signing in and out, a sign-in
        // that failed, and settings. See Capture\Moments.
        $moments = new Capture\Moments($services, $recorder);
        $shared->attach('*', 'user.login', [$moments, 'signedIn']);
        $shared->attach('*', 'user.logout', [$moments, 'signedOut']);
        foreach (['setting.insert', 'setting.update', 'setting.delete'] as $name) {
            $shared->attach('*', $name, [$moments, 'setting']);
        }
        $event->getApplication()->getEventManager()->attach(
            MvcEvent::EVENT_FINISH,
            [$moments, 'finished'],
            -1000
        );

        // Every request that comes through the API is asked whether it belongs to a run
        // of another module. Nothing is done with the request itself.
        $shared->attach('*', 'api.execute.pre', [$recorder, 'noteRun'], 10000);

        // Every admin form is looked at as it arrives, with the same question Omeka is
        // about to ask of its token, so that a save refused before it reached anything
        // is written down with the state of that moment instead of vanishing. Runs
        // after the route is known and before the page is dispatched; it reads, and
        // whatever it finds, the request goes on exactly as it would have.
        $event->getApplication()->getEventManager()->attach(
            MvcEvent::EVENT_ROUTE,
            function (MvcEvent $e) use ($services) {
                Watch\Refused::look($services, $e);
            },
            -1000
        );
    }

    public function attachListeners(SharedEventManagerInterface $shared)
    {
        // Every admin page asks, once, whether a check of the data itself is due. That
        // question is one small query; the check itself, if it happens at all, is a
        // background job and the page does not wait for it.
        $shared->attach('*', 'view.layout', [$this, 'checkIfDue']);

        foreach (['Omeka\Controller\Admin\Item', 'Omeka\Controller\Admin\Media', 'Omeka\Controller\Admin\ItemSet'] as $controller) {
            $shared->attach($controller, 'view.show.sidebar', [$this, 'sidebar']);
            $shared->attach($controller, 'view.add.before', [$this, 'confirmBeforeSave']);
            $shared->attach($controller, 'view.edit.before', [$this, 'confirmBeforeSave']);
            $shared->attach($controller, 'view.batch_edit.before', [$this, 'confirmBeforeSave']);
        }
    }

    /**
     * The dialog that shows what a save would change, on the pages where a resource is
     * written. Nothing here can stop a save: the page only gets a stylesheet, a script
     * and what the script needs to know, and the script itself lets every save through
     * if anything at all goes wrong.
     */
    public function confirmBeforeSave(Event $event): void
    {
        try {
            $services = $this->getServiceLocator();
            if (!$services->get('Omeka\Settings')->get('revision_history_confirm', true)) {
                return;
            }
            $route = $services->get('Application')->getMvcEvent()->getRouteMatch();
            if (!$route) {
                return;
            }
            $kind = [
                'Omeka\Controller\Admin\Item' => 'item',
                'Omeka\Controller\Admin\Media' => 'media',
                'Omeka\Controller\Admin\ItemSet' => 'item_set',
            ][(string) $route->getParam('controller')] ?? null;
            if (!$kind) {
                return;
            }
            $batch = strpos((string) $route->getParam('action'), 'batch') === 0;
            $id = (int) $route->getParam('id');
            // WHERE THE RESOURCE STOOD WHEN THIS FORM WAS OPENED: the last line of its
            // history, and its own modified time for a resource the history has no line
            // for. Sent back with the check before saving, so that a save somebody else
            // made in between can be named (1.0.4).
            $seen = 0;
            $opened = '';
            if (!$batch && $id > 0
                && $services->get('Omeka\Settings')->get('revision_history_confirm_stale', true)) {
                try {
                    $conn = $services->get('Omeka\Connection');
                    $seen = (int) $conn->fetchOne(
                        'SELECT COALESCE(MAX(`id`), 0) FROM `revision_history_event` WHERE `resource_id` = ? OR `item_id` = ?',
                        [$id, $id]
                    );
                    $opened = (string) ($conn->fetchOne('SELECT `modified` FROM `resource` WHERE `id` = ?', [$id]) ?: '');
                } catch (\Throwable $e) {
                    $seen = 0;
                    $opened = '';
                }
            }
            $view = $event->getTarget();
            $view->headLink()->appendStylesheet($view->assetUrl('css/history.css', 'RevisionHistory'));
            $view->headLink()->appendStylesheet($view->assetUrl('css/confirm.css', 'RevisionHistory'));
            $view->headScript()->appendScript('window.RevisionHistoryConfirm = ' . json_encode([
                'url' => $view->url('admin/revision-history', ['action' => 'preview']),
                'kind' => $kind,
                'id' => $id,
                'mode' => $batch ? 'batch' : 'one',
                'seen' => $seen,
                'opened' => $opened,
                // The form that saves the resource, and nothing else: the Delete panel on
                // the same page is a form too, and asking "what would this save change"
                // before a deletion is both wrong and in the way. Omeka asks about a
                // deletion itself.
                'selector' => $batch ? '#content form[id^="batch-edit"]' : '#content form.resource-form',
                'one' => '%s ' . ['item' => 'item', 'media' => 'media', 'item_set' => 'item set'][$kind],
                'many' => '%s ' . ['item' => 'items', 'media' => 'media', 'item_set' => 'item sets'][$kind],
                'nothing' => $view->translate('Nothing has been filled in.'), // @translate
                'title' => $view->translate('Before you save'), // @translate
                'what' => $view->translate('Check what this save would change.'), // @translate
                'leaveTitle' => $view->translate('Before you leave'), // @translate
                'leaveWhat' => $view->translate('This has not been saved. Leaving loses it.'), // @translate
                'save' => $view->translate('Save'), // @translate
                'leave' => $view->translate('Leave without saving'), // @translate
                'stay' => $view->translate('Keep editing'), // @translate
                'cancel' => $view->translate('Cancel'), // @translate
                'waiting' => $view->translate('Loading…'), // @translate
                'wait' => 8000,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';');
            $view->headScript()->appendFile($view->assetUrl('js/confirm.js', 'RevisionHistory'));
        } catch (\Throwable $e) {
            // The page works, and saves, whether or not the dialog could be set up.
        }
    }

    /**
     * A check of the data itself, when the last one is older than the archive asked for.
     * Attached to the pages staff open all day, not only to the history's own, so that
     * an archive without cron still gets checked while people work.
     */
    public function checkIfDue(): void
    {
        static $asked = false;
        if ($asked) {
            return;
        }
        $asked = true;
        try {
            $services = $this->getServiceLocator();
            // Only the admin pages, and only for somebody who may run a check anyway.
            if (!$services->get('Omeka\Status')->isAdminRequest()
                || !$services->get('Omeka\Acl')->userIsAllowed(Controller\Admin\IndexController::class, 'watch')
            ) {
                return;
            }
            // A background job is started and the page carries on. Where Omeka is set to
            // run jobs inside the request instead, a page would have to wait for the
            // check to finish, so it is left to the button and to cron.
            if (strpos(get_class($services->get('Omeka\Job\DispatchStrategy')), 'Synchronous') !== false) {
                return;
            }
            $watchman = new Watch\Watchman($services->get('Omeka\Connection'), $services->get('Omeka\Settings'));
            if (!$watchman->due()) {
                return;
            }
            $watchman->sent();
            $services->get('Omeka\Job\Dispatcher')->dispatch(Job\WatchJob::class, []);
        } catch (\Throwable $e) {
            // The page opens whether or not a check could be started.
        }
    }

    /** The last few things that happened to this resource, on its own page. */
    public function sidebar(Event $event)
    {
        $view = $event->getTarget();
        $resource = $view->vars()->offsetGet('resource');
        if (!$resource) {
            return;
        }
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        if (!$acl->userIsAllowed(Controller\Admin\IndexController::class, 'item')) {
            return;
        }
        $services = $this->getServiceLocator();
        try {
            $reader = new Reader($services->get('Omeka\Connection'));
            $page = $reader->events(['resource' => $resource->id()], (string) ($services->get('Omeka\Settings')->get('time_zone') ?: 'UTC'), 5);
        } catch (\Throwable $e) {
            return;
        }
        echo $view->partial('revision-history/admin/index/sidebar', [
            'resource' => $resource,
            'page' => $page,
        ]);
    }
}
