<?php
namespace RevisionHistory\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use RevisionHistory\Copy\Copier;
use RevisionHistory\Reader;

class IndexController extends AbstractActionController
{
    private $services;

    public function __construct($services)
    {
        $this->services = $services;
    }

    private function reader(): Reader
    {
        return new Reader(
            $this->services->get('Omeka\Connection'),
            (string) $this->services->get('Omeka\Settings')->get('revision_history_title_term', 'dcterms:title')
        );
    }

    private function tz(): string
    {
        return (string) ($this->services->get('Omeka\Settings')->get('time_zone') ?: 'UTC');
    }

    private function filters(): array
    {
        $q = $this->params()->fromQuery();
        $f = [];
        foreach (['what', 'who', 'from', 'to', 'item', 'field', 'kind', 'after', 'resource'] as $k) {
            $v = isset($q[$k]) && is_string($q[$k]) ? trim($q[$k]) : '';
            if ($v !== '') {
                $f[$k] = mb_substr($v, 0, 190);
            }
        }
        return $f;
    }

    /**
     * Vocab Sync keeps its own record of what is done in it. Its newest rows are read
     * in here when the page is opened, so its part of the history is here without
     * anybody having to press Copy.
     */
    private function catchUp(): void
    {
        (new Copier($this->services->get('Omeka\Connection'), $this->services->get('Omeka\Settings')))->catchUp();
        $this->checkIfDue();
    }

    /**
     * A check of the data itself, started by the module and not by a person, when the
     * last one is older than the chosen number of days. Omeka has no clock of its own:
     * this is the archive's own pages standing in for one. A server with cron can call
     * watch.php instead, and then this finds nothing to do.
     */
    private function checkIfDue(): void
    {
        try {
            if (!$this->services->get('Omeka\Acl')->userIsAllowed(self::class, 'watch')) {
                return;
            }
            $watchman = new \RevisionHistory\Watch\Watchman($this->services->get('Omeka\Connection'), $this->services->get('Omeka\Settings'));
            if (!$watchman->due()) {
                return;
            }
            $watchman->sent();
            $this->jobDispatcher()->dispatch(\RevisionHistory\Job\WatchJob::class, []);
        } catch (\Throwable $e) {
            // The page opens whether or not a check could be started.
        }
    }

    public function browseAction()
    {
        $this->catchUp();
        $f = $this->filters();
        $reader = $this->reader();
        $page = $reader->events($f, $this->tz(), Reader::PAGE, true);
        $page['events'] = self::fold($page['events']);

        $view = new ViewModel([
            'filters' => $f,
            'page' => $page,
            'people' => $reader->people(),
            'fields' => $reader->fields(),
            'summary' => $reader->summary(),
            'settings' => $this->services->get('Omeka\Settings'),
            'isAdmin' => $this->services->get('Omeka\Acl')->userIsAllowed(self::class, 'copy'),
        ]);
        return $view;
    }

    public function itemAction()
    {
        $this->catchUp();
        $id = (int) $this->params('id');
        $f = $this->filters();
        unset($f['item'], $f['kind']);
        $f['resource'] = $id;
        $reader = $this->reader();
        $page = $reader->events($f, $this->tz());
        $page['events'] = self::fold($page['events']);

        $resource = null;
        try {
            $resource = $this->api()->read('resources', $id)->getContent();
        } catch (\Throwable $e) {
        }
        return new ViewModel([
            'id' => $id,
            'resource' => $resource,
            'filters' => $f,
            'page' => $page,
        ]);
    }

    /**
     * What this save would change, worked out before it is made.
     *
     * The admin form sends itself here first; this reads the resource as it is now,
     * reads the form as it would leave it, and runs them through the same Diff the
     * history is written from — so the dialog and the history page can never disagree.
     * Nothing is written, nothing is locked: if this page fails in any way the browser
     * lets the save through unchanged.
     */
    public function previewAction()
    {
        $this->getResponse()->getHeaders()->addHeaderLine('Cache-Control', 'no-store');
        $post = $this->params()->fromPost();
        $kind = (string) ($post['revision_history_kind'] ?? 'item');
        if (!in_array($kind, ['item', 'media', 'item_set'], true)) {
            $kind = 'item';
        }
        $id = (int) ($post['revision_history_id'] ?? 0);

        $reader = new \RevisionHistory\Capture\StateReader(
            $this->services->get('Omeka\Connection'),
            (string) $this->services->get('Omeka\Settings')->get('revision_history_title_term', 'dcterms:title')
        );
        $before = null;
        if ($id) {
            $states = $reader->read([$id]);
            $before = $states[$id] ?? null;
        }
        $making = $before === null;
        if ($making) {
            $identity = $this->services->get('Omeka\AuthenticationService')->getIdentity();
            $before = \RevisionHistory\Capture\FormState::blank($kind, $identity ? (int) $identity->getId() : null);
        }
        $after = \RevisionHistory\Capture\FormState::after($post, $before);
        $after['values'] = \RevisionHistory\Capture\FormState::vocabSync($after['values'], $this->vocabSyncTerm());
        $changes = \RevisionHistory\Capture\Diff::changes($making ? null : $before, $after);
        // A file the form no longer carries is one Omeka will delete with the item. In
        // the history that is an entry of its own, written when it happens; here, where
        // nothing has happened yet, it is said on the item's own card — losing a file is
        // the thing this dialog exists to catch.
        $changes = array_merge($changes, $this->mediaGoing($before, $after));

        // A save that changes nothing has nothing to show: the form goes through.
        if (!$changes) {
            $this->getResponse()->getHeaders()->addHeaderLine('X-Revision-History-Changes', '0');
        }

        $view = new ViewModel([
            'kind' => $kind,
            'id' => $id,
            'making' => $making,
            'leaving' => !empty($post['revision_history_leaving']),
            'before' => $before,
            'after' => $after,
            'changes' => $changes,
        ]);
        $view->setTerminal(true);
        return $view;
    }

    /**
     * Which Vocab Sync term the form is naming, asked the way Vocab Sync asks it: by the
     * label first, because a term may be called "1961", and only then by the number.
     * The same two queries its own data type runs, and nothing else.
     */
    private function vocabSyncTerm(): callable
    {
        $conn = $this->services->get('Omeka\Connection');
        $seen = [];
        $has = null;
        return function (int $vocabId, string $raw) use ($conn, &$seen, &$has): ?int {
            if ($has === null) {
                try {
                    $has = (bool) $conn->fetchOne("SHOW TABLES LIKE 'vocab_sync_term'");
                } catch (\Throwable $e) {
                    $has = false;
                }
            }
            if (!$has) {
                return null;
            }
            $key = $vocabId . "\0" . $raw;
            if (array_key_exists($key, $seen)) {
                return $seen[$key];
            }
            $seen[$key] = null;
            try {
                $id = $conn->fetchOne(
                    'SELECT id FROM vocab_sync_term WHERE vocab_id = ? AND label = ? ORDER BY id ASC LIMIT 1',
                    [$vocabId, $raw]
                );
                if ($id !== false && $id !== null) {
                    $seen[$key] = (int) $id;
                } elseif (ctype_digit($raw)) {
                    $id = $conn->fetchOne('SELECT id FROM vocab_sync_term WHERE id = ? AND vocab_id = ?', [(int) $raw, $vocabId]);
                    if ($id !== false && $id !== null) {
                        $seen[$key] = (int) $id;
                    }
                }
            } catch (\Throwable $e) {
            }
            return $seen[$key];
        };
    }

    /** Media the form would add to, or take from, the item. */
    private function mediaGoing(array $before, array $after): array
    {
        $was = $before['media_order'] ?? null;
        $will = $after['media_order'] ?? null;
        if ($was === null || $will === null || $was === $will) {
            return [];
        }
        $gone = array_values(array_diff($was, $will));
        $came = array_values(array_diff($will, $was));
        if (!$gone && !$came) {
            return [];
        }
        $names = [];
        $ids = array_merge($gone, $came);
        if ($ids) {
            $conn = $this->services->get('Omeka\Connection');
            foreach ($conn->fetchAllAssociative(
                'SELECT r.id, r.title, m.source FROM resource r JOIN media m ON m.id = r.id WHERE r.id IN (' . implode(',', array_map('intval', $ids)) . ')'
            ) as $r) {
                $name = (string) ($r['title'] !== null && $r['title'] !== '' ? $r['title'] : (string) $r['source']);
                $names[(int) $r['id']] = $name;
            }
        }
        $value = function (int $id) use ($names) {
            $name = $names[$id] ?? '';
            return ['type' => 'literal', 'value' => ($name !== '' ? $name . ' ' : '') . '#' . $id,
                'uri' => null, 'vr' => null, 'lang' => null, 'pub' => 1];
        };
        $rows = [];
        foreach ($gone as $id) {
            $rows[] = ['op' => 'removed', 'field' => 'o:media', 'property_id' => null,
                'before' => $value((int) $id), 'after' => null, 'place_before' => null, 'place_after' => null];
        }
        foreach ($came as $id) {
            $rows[] = ['op' => 'added', 'field' => 'o:media', 'property_id' => null,
                'before' => null, 'after' => $value((int) $id), 'place_before' => null, 'place_after' => null];
        }
        return $rows;
    }

    /**
     * Put one save back. Global administrators only (the ACL gives the others browse,
     * item and export), and never in one click: this page shows what would be put back
     * and what would not, and nothing is written until it is confirmed.
     */
    public function restoreAction()
    {
        $conn = $this->services->get('Omeka\Connection');
        $id = (int) $this->params('id');
        $event = $conn->fetchAssociative('SELECT * FROM revision_history_event WHERE id = ?', [$id]);
        if (!$event || !\RevisionHistory\Restore\Restorer::can($event)) {
            $this->messenger()->addWarning('This entry cannot be put back from here.');
            return $this->redirect()->toRoute('admin/revision-history');
        }
        $rid = (int) $event['resource_id'];
        $back = $this->url()->fromRoute('admin/revision-history', ['action' => 'item', 'id' => $event['resource_kind'] === 'media' && $event['item_id'] ? (int) $event['item_id'] : $rid]);
        $changes = [];
        foreach ($conn->fetchAllAssociative('SELECT op, field, property_id, before_json, after_json FROM revision_history_change WHERE event_id = ? ORDER BY ord', [$id]) as $c) {
            $changes[] = [
                'op' => $c['op'],
                'field' => $c['field'],
                'property_id' => $c['property_id'] === null ? null : (int) $c['property_id'],
                'before' => $c['before_json'] === null ? null : json_decode($c['before_json'], true),
                'after' => $c['after_json'] === null ? null : json_decode($c['after_json'], true),
            ];
        }
        $reader = new \RevisionHistory\Capture\StateReader($conn, (string) $this->services->get('Omeka\Settings')->get('revision_history_accession_term', 'dcterms:identifier'));
        $now = $reader->read([$rid])[$rid] ?? null;
        if (!$now) {
            $this->messenger()->addWarning('This no longer exists. Nothing can be put back into it.');
            return $this->redirect()->toUrl($back);
        }
        $restorer = new \RevisionHistory\Restore\Restorer($conn);
        $plan = \RevisionHistory\Restore\Planner::plan($changes, $now, function (int $rid) use ($conn) {
            return (bool) $conn->fetchOne('SELECT 1 FROM resource WHERE id = ?', [$rid]);
        });
        $built = $restorer->content($plan, $event['resource_kind']);
        $later = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM revision_history_event WHERE resource_kind IN ('item','media','item_set') AND resource_id = ? AND id > ? AND action <> 'created'",
            [$rid, $id]
        );

        if ($this->getRequest()->isPost()) {
            $csrf = new \Laminas\Validator\Csrf(['name' => 'revision_history_csrf', 'timeout' => 3600]);
            if (!$csrf->isValid($this->params()->fromPost('revision_history_csrf', ''))) {
                $this->messenger()->addError('The form expired. Nothing was put back — press the button again.');
                return $this->redirect()->toRoute('admin/revision-history', ['action' => 'restore', 'id' => $id]);
            }
            if (!$this->params()->fromPost('revision_history_sure')) {
                $this->messenger()->addWarning('Nothing was put back: "Are you sure?" was not ticked.');
                return $this->redirect()->toRoute('admin/revision-history', ['action' => 'restore', 'id' => $id]);
            }
            if (!$plan['any'] || $built['unsure']) {
                $this->messenger()->addWarning('Nothing was put back.');
                return $this->redirect()->toRoute('admin/revision-history', ['action' => 'restore', 'id' => $id]);
            }
            try {
                $this->api()->update(\RevisionHistory\Restore\Restorer::apiName($event['resource_kind']), $rid, $built['content'], [], [
                    'isPartial' => true,
                    'collectionAction' => 'replace',
                ]);
            } catch (\Throwable $e) {
                $this->messenger()->addError('Omeka did not save it: ' . $e->getMessage());
                return $this->redirect()->toRoute('admin/revision-history', ['action' => 'restore', 'id' => $id]);
            }
            // The save just made is in the history like any other; say what it was.
            $mine = $conn->fetchOne(
                'SELECT id FROM revision_history_event WHERE resource_kind IN (\'item\',\'media\',\'item_set\') AND resource_id = ? AND id > ? AND via_detail = ? ORDER BY id DESC LIMIT 1',
                [$rid, $id, (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)]
            );
            if ($mine) {
                $conn->executeStatement('UPDATE revision_history_event SET note = ? WHERE id = ?', ['Restore #' . $id, (int) $mine]);
            }
            $after = $reader->read([$rid])[$rid] ?? [];
            $diff = \RevisionHistory\Restore\Restorer::differences($plan, $after);
            if ($diff) {
                $this->messenger()->addWarning('Saved, but these fields do not read as they did before: ' . implode(', ', $diff) . '. Check them on the item.');
            } else {
                $this->messenger()->addSuccess('Restored.');
            }
            return $this->redirect()->toUrl($back . ($mine ? '#rh-' . (int) $mine : ''));
        }

        return new ViewModel([
            'event' => $event + ['changes' => $changes],
            'plan' => $plan,
            'unsure' => $built['unsure'],
            'later' => $later,
            'back' => $back,
            'csrf' => (new \Laminas\Validator\Csrf(['name' => 'revision_history_csrf', 'timeout' => 3600]))->getHash(),
        ]);
    }

    /**
     * Make a deleted item, media or item set again, under its old number. Global
     * administrators only, and never in one click: the page first shows what comes
     * back, what cannot and why — media by media, having tried each file's URL.
     */
    public function reviveAction()
    {
        $conn = $this->services->get('Omeka\Connection');
        $id = (int) $this->params('id');
        $event = $conn->fetchAssociative('SELECT * FROM revision_history_event WHERE id = ?', [$id]);
        if (!$event || !\RevisionHistory\Restore\Reviver::can($event)) {
            $this->messenger()->addWarning('This entry cannot be put back from here.');
            return $this->redirect()->toRoute('admin/revision-history');
        }
        $back = $this->url()->fromRoute('admin/revision-history', ['action' => 'item', 'id' => $event['resource_kind'] === 'media' && $event['item_id'] ? (int) $event['item_id'] : (int) $event['resource_id']]);
        $reviver = new \RevisionHistory\Restore\Reviver($this->services);
        $plan = $reviver->plan($event);

        if ($this->getRequest()->isPost()) {
            $csrf = new \Laminas\Validator\Csrf(['name' => 'revision_history_csrf', 'timeout' => 3600]);
            if (!$csrf->isValid($this->params()->fromPost('revision_history_csrf', ''))) {
                $this->messenger()->addError('The form expired. Nothing was put back — press the button again.');
                return $this->redirect()->toRoute('admin/revision-history', ['action' => 'revive', 'id' => $id]);
            }
            if (!$this->params()->fromPost('revision_history_sure')) {
                $this->messenger()->addWarning('Nothing was put back: "Are you sure?" was not ticked.');
                return $this->redirect()->toRoute('admin/revision-history', ['action' => 'revive', 'id' => $id]);
            }
            if ($plan['stop']) {
                foreach ($plan['stop'] as $w) {
                    $this->messenger()->addWarning($w);
                }
                return $this->redirect()->toRoute('admin/revision-history', ['action' => 'revive', 'id' => $id]);
            }
            try {
                $done = $reviver->apply($plan, $id);
            } catch (\Throwable $e) {
                $this->messenger()->addError('It was not put back: ' . $e->getMessage());
                return $this->redirect()->toRoute('admin/revision-history', ['action' => 'revive', 'id' => $id]);
            }
            foreach ($done['lines'] as $l) {
                $this->messenger()->addSuccess($l);
            }
            foreach ($done['warn'] as $w) {
                $this->messenger()->addWarning($w);
            }
            return $this->redirect()->toUrl($back);
        }

        return new ViewModel([
            'plan' => $plan,
            'back' => $back,
            'csrf' => (new \Laminas\Validator\Csrf(['name' => 'revision_history_csrf', 'timeout' => 3600]))->getHash(),
        ]);
    }

    public function copyAction()
    {
        $copier = new Copier($this->services->get('Omeka\Connection'), $this->services->get('Omeka\Settings'));
        if ($this->getRequest()->isPost()) {
            $form = $this->params()->fromPost();
            $csrf = new \Laminas\Validator\Csrf(['name' => 'revision_history_csrf', 'timeout' => 3600]);
            if (!$csrf->isValid($form['revision_history_csrf'] ?? '')) {
                $this->messenger()->addError('The form expired. Nothing was copied — press the button again.');
                return $this->redirect()->toRoute('admin/revision-history', ['action' => 'copy']);
            }
            $running = $copier->lastJob();
            if ($running && $running['running']) {
                $this->messenger()->addWarning(sprintf('A copy is already running (job #%d). Nothing new was started.', $running['id']));
                return $this->redirect()->toRoute('admin/revision-history', ['action' => 'copy']);
            }
            $job = $this->jobDispatcher()->dispatch(\RevisionHistory\Job\CopyJob::class, []);
            $this->messenger()->addSuccess(sprintf(
                'Copying started as background job #%d. This page shows the numbers when it is done.',
                $job->getId()
            ));
            return $this->redirect()->toRoute('admin/revision-history', ['action' => 'copy']);
        }
        return new ViewModel([
            'status' => $copier->status(),
            'csrf' => (new \Laminas\Validator\Csrf(['name' => 'revision_history_csrf', 'timeout' => 3600]))->getHash(),
        ]);
    }

    /**
     * The same entries the page is showing, as a file. Same filters, same words.
     */
    public function exportAction()
    {
        $f = $this->filters();
        unset($f['after']);
        $handle = fopen('php://temp/maxmemory:4194304', 'w+');
        $csv = new \RevisionHistory\Export\Csv(
            $this->services->get('Omeka\Connection'),
            $this->reader(),
            $this->tz()
        );
        $lines = $csv->write($handle, $f);
        rewind($handle);

        $name = 'history-' . gmdate('Y-m-d') . '.csv';
        $response = new \Laminas\Http\Response\Stream();
        $response->setStream($handle);
        $response->setStatusCode(200);
        $response->setStreamName($name);
        $headers = new \Laminas\Http\Headers();
        $headers->addHeaders([
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Content-Length' => (string) ftell($handle) ?: '0',
            'Cache-Control' => 'no-store',
            'X-Revision-History-Lines' => (string) $lines,
        ]);
        $stat = fstat($handle);
        $headers->addHeaderLine('Content-Length', (string) ($stat['size'] ?? 0));
        $response->setHeaders($headers);
        return $response;
    }

    /**
     * Saves the admin pages refused before they reached anything.
     *
     * A person sees only their own; a global admin sees everybody's, because finding
     * out what is doing this means looking across people and machines.
     */
    public function refusedAction()
    {
        $conn = $this->services->get('Omeka\Connection');
        $user = null;
        $role = '';
        try {
            $identity = $this->services->get('Omeka\AuthenticationService')->getIdentity();
            if ($identity) {
                $user = (int) $identity->getId();
                $role = (string) $identity->getRole();
            }
        } catch (\Throwable $e) {
        }
        $mine = $role !== 'global_admin';
        $rows = [];
        try {
            $rows = $mine
                ? $conn->fetchAllAssociative('SELECT * FROM `revision_history_refused` WHERE `user_id` <=> ? ORDER BY `id` DESC LIMIT 200', [$user])
                : $conn->fetchAllAssociative('SELECT * FROM `revision_history_refused` ORDER BY `id` DESC LIMIT 200');
        } catch (\Throwable $e) {
            // The table is made when the module is installed or upgraded; until then
            // there is nothing to show and nothing to say about it.
        }
        return new ViewModel(['rows' => $rows, 'mine' => $mine]);
    }

    public function watchAction()
    {
        $watchman = new \RevisionHistory\Watch\Watchman($this->services->get('Omeka\Connection'), $this->services->get('Omeka\Settings'));
        if ($this->getRequest()->isPost()) {
            $csrf = new \Laminas\Validator\Csrf(['name' => 'revision_history_csrf', 'timeout' => 3600]);
            if (!$csrf->isValid($this->params()->fromPost('revision_history_csrf', ''))) {
                $this->messenger()->addError('The form expired. Nothing was checked — press the button again.');
                return $this->redirect()->toRoute('admin/revision-history', ['action' => 'watch']);
            }
            $every = $this->params()->fromPost('revision_history_every', null);
            if ($every !== null && ctype_digit((string) $every)) {
                $this->services->get('Omeka\Settings')->set('revision_history_watch_hours', (int) $every);
                $this->messenger()->addSuccess((int) $every
                    ? sprintf('A check will happen by itself when the last one is more than %d hour%s old.', (int) $every, (int) $every === 1 ? '' : 's')
                    : 'Checks will only happen when somebody presses the button.');
                return $this->redirect()->toRoute('admin/revision-history', ['action' => 'watch']);
            }
            $copy = (string) $this->params()->fromPost('revision_history_copy', '');
            if ($copy === 'on' || $copy === 'off') {
                $watchman->keepCopy($copy === 'on');
                $this->messenger()->addSuccess($copy === 'on'
                    ? 'A copy of what each resource holds will be kept from the next check on. Press Check now to write it down.'
                    : 'The copy was thrown away. Checks still say that something changed, but not what.');
                return $this->redirect()->toRoute('admin/revision-history', ['action' => 'watch']);
            }
            $status = $watchman->status();
            if ($status['running']) {
                $this->messenger()->addWarning('A check is already running. Nothing new was started.');
                return $this->redirect()->toRoute('admin/revision-history', ['action' => 'watch']);
            }
            $job = $this->jobDispatcher()->dispatch(\RevisionHistory\Job\WatchJob::class, []);
            $this->messenger()->addSuccess(sprintf('Checking started as background job #%d. This page shows what it found when it is done.', $job->getId()));
            return $this->redirect()->toRoute('admin/revision-history', ['action' => 'watch']);
        }
        return new ViewModel([
            'status' => $watchman->status(),
            'csrf' => (new \Laminas\Validator\Csrf(['name' => 'revision_history_csrf', 'timeout' => 3600]))->getHash(),
        ]);
    }

    /**
     * A file saved together with its item is part of that item's story: shown
     * under the item, not as a card of its own next to it.
     */
    public static function fold(array $events): array
    {
        $parents = [];
        foreach ($events as $i => $e) {
            if ($e['resource_kind'] === 'item') {
                $parents[$e['save_key'] . '|' . $e['resource_id']] = $i;
            }
        }
        foreach ($events as $i => $e) {
            if ($e['resource_kind'] !== 'media' || !$e['item_id'] || $e['save_key'] === '') {
                continue;
            }
            $k = $e['save_key'] . '|' . $e['item_id'];
            if (isset($parents[$k])) {
                $events[$parents[$k]]['folded'][] = $e;
                unset($events[$i]);
            }
        }
        // One save of something that is not a resource can write a row in half a dozen
        // tables — making a site writes the site, its page, that page's blocks and who
        // may see it. That was one thing somebody did, so it is one card.
        // Rows that only exist as part of something else: a block belongs to a page,
        // a field to its template. The card is the thing itself.
        $part = ['site permission', 'page block', 'block attachment', 'site item set', 'resource template field', 'site setting', 'user setting'];
        $group = [];
        foreach ($events as $i => $e) {
            if ($e['save_key'] === '' || in_array($e['resource_kind'], ['item', 'media', 'item_set'], true)) {
                continue;
            }
            $group[$e['save_key']][] = $i;
        }
        foreach ($group as $rows) {
            if (count($rows) < 2) {
                continue;
            }
            // The bigger thing first: a site rather than one of its pages, a template
            // rather than one of its fields. But not a save that only says when it
            // happened: Omeka stamps `modified` on a site or a page whenever anything
            // inside it changes, and a card headed "modified 04:23 → 04:26" hides the
            // thing somebody actually did. Such an entry is still written, and still
            // shown — inside the card rather than on the front of it.
            $clock = function (array $e) {
                if ($e['action'] !== 'edited' || !$e['changes']) {
                    return false;
                }
                foreach ($e['changes'] as $c) {
                    if (!in_array($c['field'], ['modified', 'created'], true)) {
                        return false;
                    }
                }
                return true;
            };
            $rank = array_flip(['module', 'site', 'user', 'resource template', 'vocabulary', 'class', 'property', 'asset', 'API key', 'page', 'setting']);
            $head = null;
            $best = null;
            foreach ($rows as $i) {
                $kind = $events[$i]['resource_kind'];
                $score = [$clock($events[$i]) ? 1 : 0, in_array($kind, $part, true) ? 1 : 0, $rank[$kind] ?? 50, (int) $events[$i]['id']];
                if ($best === null || $score < $best) {
                    $best = $score;
                    $head = $i;
                }
            }
            foreach ($rows as $i) {
                if ($i === $head) {
                    continue;
                }
                $events[$head]['folded'][] = $events[$i];
                unset($events[$i]);
            }
        }
        return array_values($events);
    }
}
