<?php
namespace RevisionHistory\Capture;

use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Entity\Item;
use Omeka\Entity\ItemSet;
use Omeka\Entity\Media;
use Omeka\Entity\Value;
use Omeka\Entity\ValueAnnotation;
use RevisionHistory\Store;

/**
 * Listens to Doctrine, which every save in Omeka goes through — the admin form,
 * the REST API, batch edit, background jobs, other modules using the API.
 * It does not see a module that writes the `value` table with its own SQL.
 *
 * onFlush  — the list of what is about to be written is known, the tables still
 *            hold the old values: read "before".
 * postFlush — the rows are committed: read "after", compare, write the history.
 *
 * Nothing here may stop a save. Every failure is caught, written to Omeka's
 * log, and counted where the history page can say so.
 */
class Recorder
{
    /** @var ServiceLocatorInterface */
    private $services;

    /** @var array frames pushed by onFlush, popped by postFlush */
    private $stack = [];

    /**
     * What a resource held at the moment somebody asked for it to be removed.
     *
     * Needed because the flush that deletes an item is not always the first flush
     * to touch it: History Log saves its own row from inside Doctrine's preRemove,
     * and that save commits the item's values being deleted — one flush early,
     * while the item itself still exists. Read at that point, the item "had" no
     * values. So the snapshot is taken before anybody else gets the chance.
     *
     * @var array id => ['state' => array, 'flushes' => int]
     */
    private $removing = [];

    /** @var string|null which run of Batch Values this request or job is; '' when none */
    private $run;

    /** @var int|null the run Batch Values says this request belongs to (Batch Values 0.7.2) */
    private $batchSet;

    /** @var string|null|false what the form said its buttons did; false until read */
    private $said = false;

    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
    }

    /**
     * api.delete.pre / api.batch_delete.pre — before anything of the item is removed.
     *
     * entity.remove.pre alone is too late for an item that has files: Doctrine removes
     * the files first (cascade), History Log flushes from each file's preRemove, and
     * that flush already deletes the item's own values — before the item's preRemove
     * ever runs. The API's own "about to delete" comes before all of it.
     */
    public function beforeApiDelete($event): void
    {
        try {
            $request = $event->getParam('request');
            $kind = ['items' => 'item', 'media' => 'media', 'item_sets' => 'item_set'][$request->getResource()] ?? null;
            if (!$kind) {
                return;
            }
            $ids = $request->getOperation() === 'batch_delete' ? (array) $request->getIds() : [$request->getId()];
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if (!$ids) {
                return;
            }
            $conn = $this->services->get('Omeka\Connection');
            if ($kind === 'item') {
                foreach ($conn->fetchFirstColumn('SELECT id FROM media WHERE item_id IN (' . implode(',', $ids) . ')') as $mid) {
                    $ids[] = (int) $mid;
                }
            }
            $ids = array_values(array_diff($ids, array_keys($this->removing)));
            foreach ($this->reader($conn)->read($ids) as $id => $state) {
                // Cleared by afterApiDelete; the limit only matters if that never comes.
                $this->removing[$id] = ['state' => $state, 'flushes' => 0, 'limit' => 500];
            }
        } catch (\Throwable $ex) {
            $this->failed('before deleting', $ex);
        }
    }

    /** api.delete.post / api.batch_delete.post — the deletion is done; nothing left to hold. */
    public function afterApiDelete($event): void
    {
        try {
            $request = $event->getParam('request');
            $ids = $request->getOperation() === 'batch_delete' ? (array) $request->getIds() : [$request->getId()];
            foreach ($ids as $id) {
                $id = (int) $id;
                if (isset($this->removing[$id])) {
                    $state = $this->removing[$id]['state'];
                    unset($this->removing[$id]);
                    // Its files went with it.
                    if ($state['kind'] === 'item') {
                        foreach ($this->removing as $mid => $r) {
                            if ($r['state']['kind'] === 'media' && (int) $r['state']['item'] === $id) {
                                unset($this->removing[$mid]);
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $ex) {
        }
    }

    /** Laminas event entity.remove.pre, attached at a priority that runs before other modules. */
    public function beforeRemove($event): void
    {
        try {
            $e = $event->getTarget();
            if (!($e instanceof Item || $e instanceof Media || $e instanceof ItemSet) || !$e->getId()) {
                return;
            }
            $id = (int) $e->getId();
            if (isset($this->removing[$id])) {
                return;
            }
            $em = $this->services->get('Omeka\EntityManager');
            $state = $this->reader($em->getConnection())->read([$id]);
            if (isset($state[$id])) {
                $this->removing[$id] = ['state' => $state[$id], 'flushes' => 0, 'limit' => 20];
            }
        } catch (\Throwable $ex) {
            $this->failed('before removing', $ex);
        }
    }

    public function onFlush($args): void
    {
        try {
            $em = method_exists($args, 'getObjectManager') ? $args->getObjectManager() : $args->getEntityManager();
            $uow = $em->getUnitOfWork();
            $level = (int) $em->getConnection()->getTransactionNestingLevel();

            // A frame at this depth or deeper is left over from a flush that failed:
            // Doctrine rolled back and never said postFlush. It is not coming back.
            while ($this->stack && end($this->stack)['level'] >= $level) {
                array_pop($this->stack);
            }
            // A flush that starts while another is still writing (History Log saves its
            // own rows from inside Omeka's flush) sees the outer flush's half-written
            // tables. The outer frame already holds the true "before" and will read
            // "after" once everything is committed, so the inner one records nothing.
            if ($this->stack) {
                $this->stack[] = ['level' => $level, 'skip' => true];
                return;
            }

            $created = [];
            $touched = [];
            $deleted = [];
            $notes = [];

            $isResource = function ($e) {
                return $e instanceof Item || $e instanceof Media || $e instanceof ItemSet;
            };
            $fromValue = function (Value $v) use (&$touched, &$notes) {
                $r = $v->getResource();
                if ($r instanceof ValueAnnotation) {
                    if ($r->getId()) {
                        $notes[$r->getId()] = true;
                    }
                } elseif ($r && $r->getId()) {
                    $touched[$r->getId()] = true;
                }
            };

            // Image Annotate keeps a media's annotations in an entity of its own, saved in
            // a flush after the media's. Its class is named, not imported: the module may
            // not be installed.
            $imageNote = function ($e) use (&$touched) {
                if (get_class($e) === 'ImageAnnotate\\Entity\\ImageAnnotateMedia' || is_a($e, 'ImageAnnotate\\Entity\\ImageAnnotateMedia')) {
                    $m = $e->getMedia();
                    if ($m && $m->getId()) {
                        $touched[$m->getId()] = true;
                    }
                    return true;
                }
                return false;
            };
            foreach (array_merge($uow->getScheduledEntityInsertions(), $uow->getScheduledEntityUpdates(), $uow->getScheduledEntityDeletions()) as $e) {
                $imageNote($e);
            }

            foreach ($uow->getScheduledEntityInsertions() as $e) {
                if ($isResource($e)) {
                    $created[] = $e;
                } elseif ($e instanceof Value) {
                    $fromValue($e);
                }
            }
            // A media's position is part of its item: the item is read too, so the order
            // of its media is compared there.
            // The item it was in before it was moved is read as well, so that item's
            // own record is not left saying nothing happened to it.
            $itemOf = function ($e) use (&$touched, $uow) {
                if ($e instanceof Media) {
                    try {
                        $item = $e->getItem();
                        if ($item && $item->getId()) {
                            $touched[$item->getId()] = true;
                        }
                    } catch (\Throwable $ignored) {
                    }
                    try {
                        $was = $uow->getOriginalEntityData($e)['item'] ?? null;
                        if (is_object($was) && method_exists($was, 'getId') && $was->getId()) {
                            $touched[(int) $was->getId()] = true;
                        } elseif (is_numeric($was)) {
                            $touched[(int) $was] = true;
                        }
                    } catch (\Throwable $ignored) {
                    }
                }
            };
            foreach ($uow->getScheduledEntityUpdates() as $e) {
                $itemOf($e);
                if ($isResource($e)) {
                    $touched[$e->getId()] = true;
                } elseif ($e instanceof Value) {
                    $fromValue($e);
                } elseif ($e instanceof ValueAnnotation && $e->getId()) {
                    $notes[$e->getId()] = true;
                }
            }
            foreach ($uow->getScheduledEntityDeletions() as $e) {
                if ($isResource($e)) {
                    $deleted[$e->getId()] = true;
                } elseif ($e instanceof Value) {
                    $fromValue($e);
                } elseif ($e instanceof ValueAnnotation && $e->getId()) {
                    $notes[$e->getId()] = true;
                }
            }
            // Everything else Omeka saves — a template, a vocabulary, an account, a site
            // page, a setting, a module. Read from Doctrine's own change set, here, while
            // it still holds both sides.
            $others = [];
            $levels = [];
            $ignore = (array) $this->services->get('Omeka\Settings')->get('revision_history_ignore', []);
            // Where an item set sits in the tree: which levels this save reorders.
            $levelOf = function ($e) use ($uow, &$levels) {
                foreach (Levels::parentsOf($e, (array) $uow->getOriginalEntityData($e)) as $parent) {
                    $levels[$parent] = true;
                }
            };
            $other = function ($e, string $action) use ($uow, $ignore, &$others) {
                if (Others::skip(get_class($e), $ignore)) {
                    return;
                }
                $entry = Others::entry(
                    $e,
                    $action,
                    $action === 'deleted' ? [] : $uow->getEntityChangeSet($e),
                    $uow->getOriginalEntityData($e),
                    $ignore
                );
                if ($entry) {
                    $others[] = ['e' => $e, 'entry' => $entry];
                }
            };
            foreach ($uow->getScheduledEntityInsertions() as $e) {
                $other($e, 'created');
                $levelOf($e);
            }
            foreach ($uow->getScheduledEntityUpdates() as $e) {
                $other($e, 'edited');
                $levelOf($e);
            }
            foreach ($uow->getScheduledEntityDeletions() as $e) {
                $other($e, 'deleted');
                $levelOf($e);
            }

            foreach (array_merge($uow->getScheduledCollectionUpdates(), $uow->getScheduledCollectionDeletions()) as $coll) {
                $owner = $coll->getOwner();
                if ($isResource($owner) && $owner->getId()) {
                    $touched[$owner->getId()] = true;
                }
            }

            $conn = $em->getConnection();

            // Things whose removal the database carries into the archive's resources by
            // itself (ON DELETE CASCADE / SET NULL), without Doctrine saying so: an item
            // set leaves its items, a site its items, a template, class, thumbnail or
            // account leaves the resources that used it, a field (and a vocabulary with
            // its fields) takes its values away. Those resources change in this same save;
            // read them too.
            $cascade = [
                'Omeka\\Entity\\ItemSet' => 'SELECT item_id FROM item_item_set WHERE item_set_id IN (%s)',
                'Omeka\\Entity\\Site' => 'SELECT item_id FROM item_site WHERE site_id IN (%s)',
                'Omeka\\Entity\\Asset' => 'SELECT id FROM resource WHERE thumbnail_id IN (%s)',
                'Omeka\\Entity\\ResourceTemplate' => 'SELECT id FROM resource WHERE resource_template_id IN (%s)',
                'Omeka\\Entity\\ResourceClass' => 'SELECT id FROM resource WHERE resource_class_id IN (%s)',
                'Omeka\\Entity\\User' => 'SELECT id FROM resource WHERE owner_id IN (%s)',
                'Omeka\\Entity\\Property' => 'SELECT DISTINCT COALESCE(o.resource_id, v.resource_id) FROM value v LEFT JOIN value o ON o.value_annotation_id = v.resource_id WHERE v.property_id IN (%s)',
                'Omeka\\Entity\\Vocabulary' => 'SELECT DISTINCT COALESCE(o.resource_id, v.resource_id) FROM value v JOIN property p ON p.id = v.property_id LEFT JOIN value o ON o.value_annotation_id = v.resource_id WHERE p.vocabulary_id IN (%s)',
            ];
            $gone = [];
            foreach ($uow->getScheduledEntityDeletions() as $e) {
                $class = Others::realClass(get_class($e));
                if (isset($cascade[$class]) && method_exists($e, 'getId') && is_numeric($e->getId())) {
                    $gone[$class][] = (int) $e->getId();
                }
            }
            foreach ($gone as $class => $ids) {
                try {
                    foreach ($conn->fetchFirstColumn(sprintf($cascade[$class], implode(',', $ids))) as $rid) {
                        if ($rid !== null) {
                            $touched[(int) $rid] = true;
                        }
                    }
                } catch (\Throwable $ignored) {
                }
            }

            if ($notes) {
                $in = implode(',', array_map('intval', array_keys($notes)));
                foreach ($conn->fetchFirstColumn("SELECT DISTINCT resource_id FROM value WHERE value_annotation_id IN ($in)") as $rid) {
                    $touched[(int) $rid] = true;
                }
            }
            if ($deleted) {
                // A value that links to a deleted resource is removed by the database
                // itself (ON DELETE CASCADE), without Doctrine knowing. The resources
                // holding those links lose a value in this same save; read them too.
                $in = implode(',', array_map('intval', array_keys($deleted)));
                foreach ($conn->fetchFirstColumn("SELECT DISTINCT resource_id FROM value WHERE value_resource_id IN ($in)") as $rid) {
                    $touched[(int) $rid] = true;
                }
            }
            foreach (array_keys($deleted) as $id) {
                unset($touched[$id]);
            }
            // Parts of a removal that other code flushed early are not edits.
            foreach (array_keys($this->removing) as $id) {
                if (!isset($deleted[$id])) {
                    unset($touched[$id]);
                }
            }
            unset($touched[0]);

            if (!$created && !$touched && !$deleted && !$others && !$levels) {
                $this->stack[] = ['level' => $level, 'skip' => true];
                return;
            }

            $reader = $this->reader($conn);
            $this->stack[] = [
                'level' => $level,
                'skip' => false,
                'at' => self::now(),
                'key' => bin2hex(random_bytes(8)),
                'who' => $this->who(),
                'via' => $this->via($conn),
                'created' => $created,
                'touched' => array_keys($touched),
                'deleted' => array_keys($deleted),
                'others' => $others,
                'levels' => $levels ? Levels::read($conn, array_keys($levels)) : [],
                'before' => $reader->read(array_merge(array_keys($touched), array_keys($deleted))),
                'stale' => $this->stale($conn, array_keys($touched)),
            ];
        } catch (\Throwable $e) {
            $this->stack[] = ['level' => isset($level) ? $level : 0, 'skip' => true];
            $this->failed('before', $e);
        }
    }

    public function postFlush($args): void
    {
        if (!$this->stack) {
            return;
        }
        $frame = array_pop($this->stack);
        if (!$frame || $frame['skip']) {
            $this->age();
            return;
        }
        try {
            $em = method_exists($args, 'getObjectManager') ? $args->getObjectManager() : $args->getEntityManager();
            $conn = $em->getConnection();
            $store = new Store($conn);

            $createdIds = [];
            foreach ($frame['created'] as $e) {
                if ($e->getId()) {
                    $createdIds[] = (int) $e->getId();
                }
            }
            $after = $this->reader($conn)->read(array_merge($createdIds, $frame['touched']));

            $base = [
                'happened_at' => $frame['at'],
                'save_key' => $frame['key'],
                'run_key' => $this->run($conn, $frame['via'], $frame['who']['id']),
                'user_id' => $frame['who']['id'],
                'user_name' => $frame['who']['name'],
                'user_email' => $frame['who']['email'],
                'via' => $frame['via'][0],
                'via_detail' => $frame['via'][1],
                'ip' => Where::ip(),
                'source' => 'here',
            ];
            // What the buttons another module puts on Omeka's own edit page did before
            // this save, in that module's words. Read from the form, once.
            $said = $this->saidByForm();
            if ($said !== null) {
                $base['note'] = $said;
            }

            // Items before their media, so a page reading in id order meets the item first.
            $order = function (array $ids, array $states) {
                usort($ids, function ($x, $y) use ($states) {
                    $rank = ['item' => 0, 'item_set' => 1, 'media' => 2];
                    return [$rank[$states[$x]['kind']] ?? 3, $x] <=> [$rank[$states[$y]['kind']] ?? 3, $y];
                });
                return $ids;
            };

            foreach ($order(array_values(array_filter($createdIds, function ($id) use ($after) {
                return isset($after[$id]);
            })), $after) as $id) {
                $s = $after[$id];
                $store->write($base + self::about('created', $s), Diff::changes(null, $s));
            }
            foreach ($frame['touched'] as $id) {
                $b = $frame['before'][$id] ?? null;
                $a = $after[$id] ?? null;
                if (!$b) {
                    continue;
                }
                if (!$a) {
                    // Gone without being scheduled for deletion: removed by the database itself.
                    $store->write($base + self::about('deleted', $b), Diff::changes($b, null));
                    continue;
                }
                $changes = Diff::changes($b, $a);
                if ($changes) {
                    $about = self::about('edited', $a);
                    // An edit that emptied the title still needs a name on the page.
                    $was = self::about('edited', $b);
                    if (($about['title'] ?? '') === '') {
                        $about['title'] = $was['title'];
                    }
                    if ($about['accession'] === '') {
                        $about['accession'] = $was['accession'];
                    }
                    $store->write($base + $about, $changes);
                }
            }
            // A level of the item sets tree whose order this save changed. Written before
            // the rows of the single edges, so the card people read first is the level.
            if ($frame['levels']) {
                $now = Levels::read($conn, array_keys($frame['levels']));
                foreach (Levels::changes($frame['levels'], $now) as $parent => $line) {
                    $name = $parent ? (string) $conn->fetchOne('SELECT title FROM resource WHERE id = ?', [$parent]) : Levels::TOP;
                    $store->write($base + [
                        'action' => 'edited',
                        'resource_id' => $parent,
                        'resource_kind' => Others::kind(Levels::EDGE),
                        'item_id' => $parent ?: null,
                        'title' => $name !== '' ? mb_substr($name, 0, 190) : Levels::TOP,
                        'accession' => '',
                    ], [$line]);
                }
            }
            foreach ($frame['others'] ?? [] as $o) {
                $about = $o['entry'];
                $id = (int) $about['id'];
                $named = '';
                if (method_exists($o['e'], 'getId')) {
                    try {
                        $was = $o['e']->getId();
                        if (!$id && is_numeric($was)) {
                            $id = (int) $was;
                        }
                        $named = is_string($was) ? $was : '';
                    } catch (\Throwable $ignored) {
                    }
                }
                // An API key being used, not changed: folded into its hour (1.10.2).
                if (Others::isUse($o['e'], $about)) {
                    /* Omeka moves lastAccessed while it is still checking the key, before
                       anybody is signed in for the request — so the person is the key's
                       owner. Several keys share a label; each belongs to someone. 1.10.3 */
                    $useBase = $base;
                    try {
                        $owner = method_exists($o['e'], 'getOwner') ? $o['e']->getOwner() : null;
                        if ($owner && empty($useBase['user_id'])) {
                            $useBase['user_id'] = (int) $owner->getId();
                            $useBase['user_name'] = mb_substr((string) $owner->getName(), 0, 190);
                            $useBase['user_email'] = mb_substr((string) $owner->getEmail(), 0, 190);
                        }
                    } catch (\Throwable $ignored) {
                    }
                    $store->keyUse($useBase, $named !== '' ? $named : (string) $id, (string) $about['title']);
                    continue;
                }
                // Now that everything is written, the row and the rows it points at can
                // say which ones they are.
                // A save that turns out to have changed nothing in this row leaves no
                // card: an entry with no lines says only that Doctrine looked at it.
                $lines = Others::lines($about['raw'], $about['action']);
                if (!$lines && $about['action'] === 'edited') {
                    continue;
                }
                $title = $about['title'] !== '' ? $about['title'] : Others::title($o['e'], []);
                // A row that only says something about a resource (where an item set
                // sits in the tree) is named after that resource and belongs on its
                // history page, not under a number nobody has ever seen.
                $of = Others::about($o['e']);
                if ($of) {
                    if ($of['title'] !== '') {
                        $title = $of['title'];
                    }
                }
                $store->write($base + [
                    'action' => $about['action'],
                    'resource_id' => $id,
                    'resource_kind' => $about['kind'],
                    'item_id' => ($of && $of['resource']) ? $of['id'] : null,
                    'title' => $title !== '' ? $title : ($named !== '' ? mb_substr($named, 0, 190) : null),
                    'accession' => '',
                ], $lines);
            }

            $before = $frame['before'];
            foreach ($frame['deleted'] as $id) {
                if (isset($this->removing[$id])) {
                    $before[$id] = $this->removing[$id]['state'];
                    unset($this->removing[$id]);
                }
            }
            $gone = array_values(array_filter($frame['deleted'], function ($id) use ($before) {
                return isset($before[$id]);
            }));
            foreach ($order($gone, $before) as $id) {
                $b = $before[$id];
                $eventId = $store->write($base + self::about('deleted', $b), Diff::changes($b, null));
                // Everything it held, exactly as it was read, so it can be made again.
                $store->snapshot($eventId, $b);
            }

            // What was just recorded is what the data check compares against from now on.
            // A resource that had already been changed by something unrecorded is reported
            // first, with what that something did.
            try {
                $watchman = new \RevisionHistory\Watch\Watchman($conn, $this->services->get('Omeka\Settings'));
                foreach ($frame['stale'] ?? [] as $sid) {
                    $watchman->reportStale((int) $sid, $frame['before'][$sid] ?? null);
                }
                $watchman->afterSave(array_keys($after), $frame['deleted'], $after);
            } catch (\Throwable $e) {
                $this->failed('after, writing down the new state', $e);
            }
        } catch (\Throwable $e) {
            $this->failed('after', $e);
        }
        $this->age();
    }

    /**
     * A removal that was asked for and then never flushed (it threw, somebody
     * changed their mind) must not keep hiding that resource's real edits.
     * History Log flushes once per file of an item being deleted, so the count is generous.
     */
    private function age(): void
    {
        if ($this->stack) {
            return;
        }
        foreach ($this->removing as $id => $r) {
            if (++$this->removing[$id]['flushes'] > ($r['limit'] ?? 20)) {
                unset($this->removing[$id]);
            }
        }
    }

    private static function now(): string
    {
        $t = microtime(true);
        return gmdate('Y-m-d H:i:s', (int) floor($t)) . sprintf('.%06d', (int) round(($t - floor($t)) * 1000000) % 1000000);
    }

    private static function about(string $action, array $s): array
    {
        $title = $s['title'];
        if (($title === null || $title === '') && $s['kind'] === 'media' && !empty($s['media']['source'])) {
            $title = (string) $s['media']['source'];
        }
        return [
            'action' => $action,
            'resource_id' => $s['id'],
            'resource_kind' => $s['kind'],
            'item_id' => $s['item'],
            'title' => $title,
            'accession' => $s['accession'],
        ];
    }

    /**
     * api.execute.pre — the request says which run it belongs to.
     *
     * Batch Values sends the number of the run ("change set") with every request it
     * makes (0.7.2). Taking it from the request is the only way that is certain: the
     * page it came from and who is signed in can both be read wrong when two runs
     * happen at once. Nothing else is read here, and nothing is changed.
     */
    public function noteRun($event): void
    {
        try {
            $request = $event->getParam('request');
            if (!$request || !method_exists($request, 'getOption')) {
                return;
            }
            $id = $request->getOption('batchvalues_change_set');
            if ($id !== null && $id !== '' && (int) $id > 0) {
                $this->batchSet = (int) $id;
            }
        } catch (\Throwable $ex) {
            // Nothing here may stop a save.
        }
    }

    /**
     * Which page or job of Batch Values this is, from how the save arrived: its admin
     * pages, or its background job (with the job's number). Null when it is neither.
     */
    public static function batchRun(array $via): ?array
    {
        if ($via[0] === 'admin' && strpos((string) $via[1], '/admin/batch-values/') === 0) {
            return ['job' => 0];
        }
        if ($via[0] === 'job' && preg_match('/^#(\d+) BatchValues\\\\/', (string) $via[1], $m)) {
            return ['job' => (int) $m[1]];
        }
        return null;
    }

    /**
     * One run of Batch Values saves each resource on its own, one after the other, in
     * one request or one background job. They share a key so the history can show the
     * run as one thing. The key is Batch Values' own number for the run ("change set")
     * when it can be read — the one it is running for this person, or the one its job
     * was started for — and otherwise a number that only this request or job has.
     * Read once: a request, or a job, is one run.
     */
    private function run($conn, array $via, ?int $userId): string
    {
        // What the request itself said, when Batch Values sends it (0.7.2). Nothing to
        // look up and nothing to guess.
        if ($this->batchSet) {
            return $this->run = 'bv:' . $this->batchSet;
        }
        if ($this->run !== null) {
            return $this->run;
        }
        $this->run = '';
        $b = self::batchRun($via);
        if (!$b) {
            return $this->run;
        }
        $this->run = 'bv-' . bin2hex(random_bytes(8));
        try {
            if ($conn->fetchOne("SHOW TABLES LIKE 'batch_values_change_set'")) {
                $id = $b['job']
                    ? $conn->fetchOne('SELECT id FROM batch_values_change_set WHERE job_id = ? ORDER BY id DESC LIMIT 1', [$b['job']])
                    : $conn->fetchOne("SELECT id FROM batch_values_change_set WHERE status = 'running' AND owner_id <=> ? ORDER BY id DESC LIMIT 1", [$userId]);
                if ($id) {
                    $this->run = 'bv:' . (int) $id;
                }
            }
        } catch (\Throwable $e) {
        }
        return $this->run;
    }

    /**
     * What the form said its buttons did, once per request.
     *
     * Only read, never believed: the lines of the entry are still what really changed.
     */
    private function saidByForm(): ?string
    {
        if ($this->said !== false) {
            return $this->said;
        }
        $this->said = null;
        try {
            $request = $this->services->get('Application')->getMvcEvent()->getRequest();
            if ($request && method_exists($request, 'isPost') && $request->isPost()) {
                $this->said = FormActions::sentence($request->getPost(FormActions::FIELD));
            }
        } catch (\Throwable $e) {
        }
        return $this->said;
    }

    /** Resources that already differ from what the data check last wrote down. */
    private function stale($conn, array $ids): array
    {
        try {
            return (new \RevisionHistory\Watch\Watchman($conn, $this->services->get('Omeka\Settings')))->beforeSave($ids);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function reader($conn): StateReader
    {
        $settings = $this->services->get('Omeka\Settings');
        return new StateReader($conn, (string) $settings->get('revision_history_accession_term', 'dcterms:identifier'));
    }

    /**
     * The opening of one entry that is not a save: when, who, how it arrived, and from
     * where. Used by Moments for sign-ins, sign-outs and settings.
     */
    public function moment(): array
    {
        $conn = $this->services->get('Omeka\Connection');
        $who = $this->who();
        $via = $this->via($conn);
        return [
            'happened_at' => self::now(),
            'save_key' => bin2hex(random_bytes(8)),
            'run_key' => '',
            'user_id' => $who['id'],
            'user_name' => $who['name'],
            'user_email' => $who['email'],
            'via' => $via[0],
            'via_detail' => $via[1],
            'ip' => Where::ip(),
            'source' => 'here',
        ];
    }

    private function who(): array
    {
        $user = null;
        try {
            $user = $this->services->get('Omeka\AuthenticationService')->getIdentity();
        } catch (\Throwable $e) {
        }
        if (!$user) {
            return ['id' => null, 'name' => '', 'email' => ''];
        }
        return ['id' => (int) $user->getId(), 'name' => mb_substr((string) $user->getName(), 0, 190), 'email' => mb_substr((string) $user->getEmail(), 0, 190)];
    }

    /**
     * How the save arrived. Never the API key's secret half — only which key.
     * @return array{0:string, 1:string}
     */
    private function via($conn): array
    {
        if (PHP_SAPI === 'cli') {
            $argv = $_SERVER['argv'] ?? [];
            $jobId = 0;
            foreach ($argv as $i => $arg) {
                if ($arg === '--job-id' && isset($argv[$i + 1])) {
                    $jobId = (int) $argv[$i + 1];
                } elseif (strpos((string) $arg, '--job-id=') === 0) {
                    $jobId = (int) substr($arg, 9);
                }
            }
            if ($jobId) {
                $class = (string) $conn->fetchOne('SELECT class FROM job WHERE id = ?', [$jobId]);
                return ['job', mb_substr('#' . $jobId . ' ' . $class, 0, 255)];
            }
            return ['cli', mb_substr(basename((string) ($argv[0] ?? '')), 0, 255)];
        }
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        try {
            $status = $this->services->get('Omeka\Status');
            if ($status->isApiRequest()) {
                $key = isset($_GET['key_identity']) ? preg_replace('/[^A-Za-z0-9]/', '', (string) $_GET['key_identity']) : '';
                return ['api', mb_substr(($key !== '' ? 'key ' . $key . ' · ' : '') . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . $path, 0, 255)];
            }
            if ($status->isAdminRequest()) {
                return ['admin', mb_substr($path, 0, 255)];
            }
            if ($status->isSiteRequest()) {
                return ['site', mb_substr($path, 0, 255)];
            }
        } catch (\Throwable $e) {
        }
        return ['web', mb_substr($path, 0, 255)];
    }

    private function failed(string $when, \Throwable $e): void
    {
        try {
            $this->services->get('Omeka\Logger')->err(sprintf(
                'Revision History could not record a change (%s): %s',
                $when,
                $e->getMessage()
            ));
            $settings = $this->services->get('Omeka\Settings');
            $settings->set('revision_history_failures', (int) $settings->get('revision_history_failures', 0) + 1);
            $settings->set('revision_history_failure_last', [gmdate('Y-m-d H:i:s'), $when, mb_substr($e->getMessage(), 0, 500)]);
        } catch (\Throwable $ignored) {
        }
    }
}
