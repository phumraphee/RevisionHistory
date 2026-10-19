<?php
namespace RevisionHistory\Capture;

use Laminas\Mvc\MvcEvent;
use RevisionHistory\Store;

/**
 * The things that happen in the archive without a resource being saved. 2026-10-19.
 *
 * The owner asked for one module that records everything that can be recorded. Until
 * now this one recorded every save, and Activity Log was kept running beside it for the
 * rest — which meant two logs, and the rest only in somebody else's table. These are
 * that rest:
 *
 *   signed in     somebody signed in                      Omeka's own user.login
 *   signed out    somebody signed out                     Omeka's own user.logout
 *   refused       a sign-in that failed — the address typed, never the password
 *   created/edited/deleted  a setting, a site setting or a user setting
 *
 * Omeka has no event for a failed sign-in, so that one is read off the request as it
 * finishes: the sign-in form was sent and nobody is signed in afterwards.
 *
 * Settings are not entities — Omeka writes them with plain SQL — so the recorder's
 * Doctrine listener never saw them; Omeka's setting.* events are the only way in. They
 * carry the new value and not the old one, so the old one is taken from the last thing
 * this history recorded about the same setting. The first change of a setting after this
 * was installed therefore shows only what it became.
 *
 * Settings a module writes to itself to remember when it last ran are not news, and
 * would bury the rest: easyadmin_cron_last alone changed 156 times in one week.
 *
 * NOTHING HERE MAY STOP WHAT IT IS WATCHING. A sign-in, a sign-out and a setting all go
 * through whatever happens here; a failure is written to Omeka's log and forgotten.
 */
class Moments
{
    /** A setting a module keeps for itself rather than one somebody chose. */
    const QUIET = '/^revision_history_|_last$|_last_run$|_cron_last$/';

    const TABLES = ['setting' => 'setting', 'site_setting' => 'site setting', 'user_setting' => 'user setting'];

    /** @var \Laminas\ServiceManager\ServiceLocatorInterface */
    private $services;
    /** @var Recorder */
    private $recorder;

    public function __construct($services, Recorder $recorder)
    {
        $this->services = $services;
        $this->recorder = $recorder;
    }

    public function signedIn($event): void
    {
        $this->person('signed in', $event->getTarget());
    }

    public function signedOut($event): void
    {
        $this->person('signed out', $event->getTarget());
    }

    /** The sign-in form was sent, and nobody is signed in now. */
    public function finished(MvcEvent $e): void
    {
        try {
            $route = $e->getRouteMatch();
            $request = $e->getRequest();
            if (!$route || $route->getMatchedRouteName() !== 'login'
                || !method_exists($request, 'isPost') || !$request->isPost()) {
                return;
            }
            if ($this->services->get('Omeka\AuthenticationService')->hasIdentity()) {
                return;
            }
            $typed = mb_substr(trim((string) $request->getPost('email', '')), 0, 190);
            $this->write([
                'action' => 'refused',
                'resource_id' => 0,
                'resource_kind' => 'sign-in',
                'title' => $typed !== '' ? $typed : '(no address typed)',
                'user_id' => null, 'user_name' => '', 'user_email' => '',
            ], []);
        } catch (\Throwable $ex) {
            $this->failed('a refused sign-in', $ex);
        }
    }

    public function setting($event): void
    {
        try {
            $id = (string) ($event->getParam('id') ?? '');
            if ($id === '' || preg_match(self::QUIET, $id)) {
                return;
            }
            $target = $event->getTarget();
            $table = method_exists($target, 'getTableName') ? (string) $target->getTableName() : 'setting';
            $kind = self::TABLES[$table] ?? $table;
            $action = ['setting.insert' => 'created', 'setting.update' => 'edited', 'setting.delete' => 'deleted'][$event->getName()] ?? 'edited';
            $now = $action === 'deleted' ? null : $event->getParam('value');
            $was = $this->lastRecorded($kind, $id);
            $lines = Others::lines([['value', $was, $now]], $action === 'deleted' ? 'edited' : $action);
            /* Omeka itself already skips a set() to the value a setting holds
               (AbstractSettings::set), so this is reached only by a value that differs in
               type but reads the same — 5 and "5". NO TEST COVERS IT: t-tchmoments
               cannot make Omeka send one. Kept because a card saying a setting changed
               from 5 to 5 would be a history that says something happened when nothing did. */
            if (!$lines && $action !== 'deleted') {
                return;
            }
            $this->write([
                'action' => $action,
                'resource_id' => 0,
                'resource_kind' => $kind,
                'title' => mb_substr($id, 0, 190),
            ], $lines);
        } catch (\Throwable $ex) {
            $this->failed('a setting', $ex);
        }
    }

    private function person(string $action, $user): void
    {
        try {
            $who = ['id' => null, 'name' => '', 'email' => ''];
            if ($user && method_exists($user, 'getId')) {
                $who = ['id' => (int) $user->getId(), 'name' => mb_substr((string) $user->getName(), 0, 190),
                        'email' => mb_substr((string) $user->getEmail(), 0, 190)];
            }
            $this->write([
                'action' => $action,
                'resource_id' => (int) ($who['id'] ?? 0),
                'resource_kind' => 'user',
                'title' => $who['name'] !== '' ? $who['name'] : $who['email'],
                'user_id' => $who['id'], 'user_name' => $who['name'], 'user_email' => $who['email'],
            ], []);
        } catch (\Throwable $ex) {
            $this->failed($action, $ex);
        }
    }

    /** What this history last wrote down as the value of one setting, or null. */
    private function lastRecorded(string $kind, string $id)
    {
        $conn = $this->services->get('Omeka\Connection');
        $json = $conn->fetchOne(
            "SELECT c.after_json FROM revision_history_change c JOIN revision_history_event e ON e.id = c.event_id
              WHERE e.resource_kind = ? AND e.title = ? AND c.field = 'value'
              ORDER BY e.id DESC LIMIT 1",
            [$kind, $id]
        );
        if ($json === false || $json === null) {
            return null;
        }
        $v = json_decode((string) $json, true);
        return is_array($v) ? ($v['value'] ?? null) : null;
    }

    private function write(array $row, array $lines): void
    {
        (new Store($this->services->get('Omeka\Connection')))->write(array_merge($this->recorder->moment(), $row), $lines);
    }

    private function failed(string $what, \Throwable $e): void
    {
        try {
            $this->services->get('Omeka\Logger')->err(sprintf('Revision History could not record %s: %s', $what, $e->getMessage()));
        } catch (\Throwable $ignored) {
        }
    }
}
