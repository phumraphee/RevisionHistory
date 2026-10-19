<?php
namespace RevisionHistory\Copy;

use Doctrine\DBAL\Connection;
use RevisionHistory\Capture\Diff;
use RevisionHistory\Capture\StateReader;
use RevisionHistory\Store;

/**
 * Keeps a copy of what History Log and Activity Log recorded, in this archive's
 * own tables, and turns it into entries the history page can show.
 *
 * Two steps, kept apart on purpose:
 *
 *   1. COPY — their rows, as they are, into revision_history_copy_* tables. Nothing is
 *      interpreted. If they are uninstalled (which drops their tables), the
 *      copy is still here, and step 2 can be run again from it.
 *   2. READ — the copy is turned into revision_history_event / revision_history_change rows marked with
 *      where they came from. This is interpretation, and it can be wrong; that
 *      is why step 1 exists.
 *
 * Only what happened before this archive started its own history
 * (setting revision_history_from) is turned into entries. After that moment the archive's
 * own record is the one shown, and theirs is only kept.
 */
class Copier
{
    const SOURCES = [
        'history_event' => 'revision_history_copy_history_event',
        'history_change' => 'revision_history_copy_history_change',
        'activity_log_event' => 'revision_history_copy_activity_log_event',
        'vocab_sync_event' => 'revision_history_copy_vocab_sync_event',
    ];

    /**
     * Vocab Sync writes one row per thing done in it, with its own sentence for each.
     * Its uninstall drops that table, so it is copied like the others.
     *
     * Its own words, from its Activity page, are kept as they are; only the action
     * is read, to say whether something was made, taken away, or changed.
     */
    const VS_ACTION = [
        'vocab.create' => 'created',
        'term.add' => 'created',
        'vocab.delete' => 'deleted',
        'term.delete' => 'deleted',
    ];

    const KIND = ['items' => 'item', 'media' => 'media', 'item_sets' => 'item_set'];

    /** @var Connection */
    private $conn;

    /** @var \Omeka\Settings\Settings */
    private $settings;

    /** @var callable|null */
    private $say;

    public function __construct(Connection $conn, $settings, ?callable $say = null)
    {
        $this->conn = $conn;
        $this->settings = $settings;
        $this->say = $say;
    }

    private function say(string $msg): void
    {
        if ($this->say) {
            ($this->say)($msg);
        }
    }

    private function exists(string $table): bool
    {
        return (bool) $this->conn->fetchOne('SHOW TABLES LIKE ' . $this->conn->quote($table));
    }

    public function status(): array
    {
        $out = [];
        foreach (self::SOURCES as $src => $copy) {
            $row = ['source' => $src, 'copy' => $copy, 'source_exists' => $this->exists($src), 'copy_exists' => $this->exists($copy)];
            $row['source_rows'] = $row['source_exists'] ? (int) $this->conn->fetchOne("SELECT COUNT(*) FROM `$src`") : null;
            $row['copy_rows'] = $row['copy_exists'] ? (int) $this->conn->fetchOne("SELECT COUNT(*) FROM `$copy`") : null;
            $mark = $row['copy_exists'] ? max((int) $this->settings->get('revision_history_copy_mark_' . $src, 0), (int) $this->conn->fetchOne("SELECT COALESCE(MAX(id), 0) FROM `$copy`")) : 0;
            $row['waiting'] = $row['source_exists'] ? (int) $this->conn->fetchOne("SELECT COUNT(*) FROM `$src` WHERE id > ?", [$mark]) : null;
            $row['restarted'] = $row['source_exists'] && $row['copy_exists'] && $this->restarted($src);
            $out['tables'][] = $row;
        }
        $out['events'] = [];
        foreach ($this->conn->fetchAllAssociative("SELECT source, COUNT(*) AS n FROM revision_history_event GROUP BY source") as $r) {
            $out['events'][$r['source']] = (int) $r['n'];
        }
        $store = new Store($this->conn);
        $last = $store->meta('copy_last');
        $out['last'] = $last ? [$last['finished'] ?? '', $last] : $this->settings->get('revision_history_copy_last');
        $out['progress'] = $store->meta('copy_progress');
        $out['job'] = $this->lastJob();
        $out['from'] = $this->settings->get('revision_history_from');
        return $out;
    }

    /** The newest copy job Omeka knows about, and why it stopped if it did. */
    public function lastJob(): ?array
    {
        try {
            $job = $this->conn->fetchAssociative(
                'SELECT id, status, started, ended, log FROM job WHERE class = ? ORDER BY id DESC LIMIT 1',
                [\RevisionHistory\Job\CopyJob::class]
            );
        } catch (\Throwable $e) {
            return null;
        }
        if (!$job) {
            return null;
        }
        // Omeka's status alone cannot be trusted here: a job whose process the server
        // ended stays "in progress" for ever. The lock a running copy holds can: it goes
        // away with the process.
        $held = (int) $this->conn->fetchOne("SELECT IS_USED_LOCK('revision_history_copy') IS NOT NULL");
        $fresh = $job['started'] && strtotime($job['started'] . ' UTC') > time() - 300;
        $job['running'] = $held || ($job['status'] === 'starting' && $fresh);
        $job['vanished'] = !$job['running'] && in_array($job['status'], ['starting', 'in_progress', 'stopping'], true);
        $job['error'] = '';
        if ($job['status'] === 'error' || $job['status'] === 'stopped') {
            try {
                if ($this->exists('log')) {
                    $job['error'] = (string) $this->conn->fetchOne(
                        'SELECT message FROM log WHERE job_id = ? AND severity <= 3 ORDER BY id DESC LIMIT 1',
                        [$job['id']]
                    );
                }
            } catch (\Throwable $e) {
            }
            if ($job['error'] === '' && $job['log']) {
                $lines = array_filter(explode("\n", (string) $job['log']));
                $job['error'] = (string) end($lines);
            }
        }
        unset($job['log']);
        return $job;
    }

    public function run(): array
    {
        $store = new Store($this->conn);
        $store->install();
        // One copy at a time. Two at once would both read the same rows and race to
        // make the same entries; the second would stop with a duplicate and look broken.
        if (!(int) $this->conn->fetchOne("SELECT GET_LOCK('revision_history_copy', 0)")) {
            $this->say('Another copy is already running. This one did nothing.');
            return ['busy' => true];
        }
        try {
            $report = ['started' => gmdate('Y-m-d H:i:s'), 'copied' => [], 'made' => ['historylog' => 0, 'activitylog' => 0, 'vocabsync' => 0], 'skipped_user_rows' => 0];
            $this->progress('copying', 0, 0, $report);
            foreach (self::SOURCES as $src => $copy) {
                $report['copied'][$src] = $this->copyTable($src, $copy, $report);
            }
            $report['made']['historylog'] = $this->readHistoryLog($report);
            $report['made']['activitylog'] = $this->readActivityLog($report);
            $report['made']['image_notes'] = $this->readImageNotes($report);
            $report['made']['vocabsync'] = $this->readVocabSync($report);
            $report['finished'] = gmdate('Y-m-d H:i:s');
            $store->setMeta('copy_last', $report);
            $store->setMeta('copy_progress', null);
            return $report;
        } finally {
            $this->conn->fetchOne("SELECT RELEASE_LOCK('revision_history_copy')");
        }
    }

    /**
     * The few rows Vocab Sync has written since the page was last opened. Nobody has
     * to press Copy for its record to be here; a copy that is running does this itself.
     *
     * @return int entries made
     */
    public function catchUp(int $limit = 300): int
    {
        try {
            if (!$this->exists('vocab_sync_event')) {
                return 0;
            }
            if (!(int) $this->conn->fetchOne("SELECT GET_LOCK('revision_history_copy', 0)")) {
                return 0;
            }
            try {
                $report = ['made' => []];
                $this->copyTable('vocab_sync_event', self::SOURCES['vocab_sync_event'], $report);
                return $this->readVocabSync($report, $limit);
            } finally {
                $this->conn->fetchOne("SELECT RELEASE_LOCK('revision_history_copy')");
            }
        } catch (\Throwable $e) {
            // The history page must open whether or not this worked.
            return 0;
        }
    }

    /** Where a running copy is, for the page to show while it waits. */
    private function progress(string $stage, int $done, int $total, array $report): void
    {
        try {
            (new Store($this->conn))->setMeta('copy_progress', [
                'stage' => $stage, 'done' => $done, 'total' => $total,
                'made' => $report['made'] ?? [], 'at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
        }
    }

    /** An entry that is already there is not an error: skip it, carry on. */
    private function writeOnce(Store $store, array $event, array $changes): bool
    {
        try {
            $store->write($event, $changes);
            return true;
        } catch (\Throwable $e) {
            $dup = $e instanceof \Doctrine\DBAL\Exception\UniqueConstraintViolationException
                || strpos($e->getMessage(), '1062') !== false;
            if ($dup) {
                return false;
            }
            throw $e;
        }
    }

    /* ================================================================ step 1 */

    /**
     * Is the table there now the same table the copy was taken from? The newest
     * copied row must still be there, saying the same thing. History Log's change
     * rows are rewritten while an event is saved, so that table is judged by its
     * events table, which is dropped and made again together with it.
     */
    private function restarted(string $src): bool
    {
        $table = $src === 'history_change' ? 'history_event' : $src;
        $copy = self::SOURCES[$table];
        if (!$this->exists($table) || !$this->exists($copy)) {
            return false;
        }
        $cols = '`id`, `event`, `timestamp`';
        if ($table === 'history_event') {
            $cols = '`id`, `entity_id`, `entity_name`, `operation`, `created`';
        } elseif ($table === 'vocab_sync_event') {
            $cols = '`id`, `created`, `action`, `summary`';
        }
        $last = $this->conn->fetchAssociative("SELECT $cols FROM `$copy` ORDER BY id DESC LIMIT 1");
        if (!$last) {
            return false;
        }
        $there = $this->conn->fetchAssociative("SELECT $cols FROM `$table` WHERE id = ?", [$last['id']]);
        return $there != $last;
    }

    private function copyTable(string $src, string $copy, array &$report): int
    {
        if (!$this->exists($src)) {
            $this->say("$src: not here (module not installed) — nothing to copy; $copy is kept as it is.");
            return 0;
        }
        if (!$this->exists($copy)) {
            // Same columns and indexes, no foreign keys: a copy must not vanish with its source.
            $this->conn->executeStatement("CREATE TABLE `$copy` LIKE `$src`");
        }
        $srcCols = $this->conn->fetchFirstColumn("SHOW COLUMNS FROM `$src`");
        $copyCols = $this->conn->fetchFirstColumn("SHOW COLUMNS FROM `$copy`");
        foreach (array_diff($srcCols, $copyCols) as $missing) {
            // Their module was upgraded and grew a column. Keep it too.
            $def = $this->conn->fetchAssociative("SHOW COLUMNS FROM `$src` LIKE " . $this->conn->quote($missing));
            $this->conn->executeStatement("ALTER TABLE `$copy` ADD COLUMN `$missing` " . $def['Type'] . ' NULL');
        }
        $cols = implode(',', array_map(function ($c) {
            return "`$c`";
        }, $srcCols));

        $where = '';
        if ($src === 'activity_log_event') {
            // Activity Log stores a user account's change set, and when the change is a
            // new password that set holds the old and the new password hash. Not copied.
            $where = " AND NOT (`resource` = 'Omeka\\\\Entity\\\\User' AND `event` = 'entity.update.post')";
            $report['skipped_user_rows'] = (int) $this->conn->fetchOne(
                "SELECT COUNT(*) FROM `$src` WHERE `resource` = 'Omeka\\\\Entity\\\\User' AND `event` = 'entity.update.post'"
            );
        }

        // How far the source has been read. Not MAX(id) of the copy: rows that are
        // deliberately not copied would otherwise be read again on every run.
        // The end is fixed before starting, and the mark is saved once at the end —
        // Activity Log records every change of a setting as a new row, so a mark saved
        // on each round would give it a new row to copy on each round, for ever.
        $markKey = 'revision_history_copy_mark_' . $src;
        $mark = max((int) $this->settings->get($markKey, 0), (int) $this->conn->fetchOne("SELECT COALESCE(MAX(id), 0) FROM `$copy`"));
        $end = (int) $this->conn->fetchOne("SELECT COALESCE(MAX(id), 0) FROM `$src`");
        if ($this->restarted($src)) {
            // The module was uninstalled and installed again: its table is new and its
            // numbering started over. Copying by number would put two different
            // histories under the same ids, so nothing more is copied from it.
            $this->say("$src: this is a new table — the module was installed again and its numbering started over. Nothing copied from it; the earlier copy is kept.");
            $report['restarted'][] = $src;
            return 0;
        }
        $total = 0;
        while ($mark < $end) {
            $top = (int) $this->conn->fetchOne("SELECT id FROM `$src` WHERE id > ? ORDER BY id LIMIT 1 OFFSET 4999", [$mark]);
            if (!$top || $top > $end) {
                $top = $end;
            }
            $total += $this->conn->executeStatement(
                "INSERT INTO `$copy` ($cols) SELECT $cols FROM `$src` WHERE id > ? AND id <= ?$where",
                [$mark, $top]
            );
            $mark = $top;
        }
        if ($end > (int) $this->settings->get($markKey, 0)) {
            $this->settings->set($markKey, $end);
        }

        if ($src === 'history_change' && $this->exists('history_event')) {
            // History Log rewrites an event's changes while it is being saved. While the
            // event still exists over there, its copy follows what it says now; once it is
            // gone over there, the copy is all that is left and it is not touched.
            $gone = $this->conn->executeStatement(
                'DELETE c FROM `revision_history_copy_history_change` c
                   JOIN `history_event` he ON he.id = c.event_id
              LEFT JOIN `history_change` s ON s.id = c.id
                  WHERE s.id IS NULL'
            );
            if ($gone) {
                $this->say("history_change: $gone copied rows no longer said by History Log for events it still has — refreshed.");
            }
        }
        $this->say("$src: $total new rows copied.");
        return $total;
    }

    /* ================================================================ step 2 */

    /**
     * History Log's own clock. Its `created` column is filled by the database
     * (CURRENT_TIMESTAMP), so it is in whatever zone the database session was in,
     * while Omeka writes resource.created in UTC. Comparing the two on the same
     * creations tells the difference, without guessing.
     */
    public function historyLogOffset(): array
    {
        $diffs = $this->conn->fetchFirstColumn(
            "SELECT TIMESTAMPDIFF(SECOND, r.created, he.created)
               FROM revision_history_copy_history_event he
               JOIN resource r ON r.id = he.entity_id
              WHERE he.operation = 'create' AND he.entity_name IN ('items', 'media', 'item_sets')
              ORDER BY he.id DESC LIMIT 500"
        );
        if (count($diffs) >= 1) {
            $rounded = array_map(function ($d) {
                return (int) (round($d / 900) * 900);
            }, $diffs);
            sort($rounded);
            $median = $rounded[intdiv(count($rounded), 2)];
            return [$median, 'measured on ' . count($diffs) . ' creations'];
        }
        $now = (int) $this->conn->fetchOne('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())');
        return [(int) (round($now / 900) * 900), "the database's zone today (no creation to measure against)"];
    }

    private function readHistoryLog(array &$report): int
    {
        if (!$this->exists('revision_history_copy_history_event') || !$this->exists('revision_history_copy_history_change')) {
            return 0;
        }
        [$offset, $how] = $this->historyLogOffset();
        $report['historylog_offset'] = [$offset, $how];
        $this->say(sprintf('History Log times are %+d minutes from UTC (%s).', $offset / 60, $how));

        $from = (string) $this->settings->get('revision_history_from', '9999-12-31 00:00:00');
        $accessionTerm = (string) $this->settings->get('revision_history_accession_term', 'dcterms:identifier');
        $store = new Store($this->conn);
        $made = 0;

        $users = [];
        foreach ($this->conn->fetchAllAssociative('SELECT id, name, email FROM user') as $u) {
            $users[(int) $u['id']] = $u;
        }
        $props = [];
        foreach ($this->conn->fetchAllAssociative("SELECT p.id, CONCAT(v.prefix, ':', p.local_name) AS term FROM property p JOIN vocabulary v ON v.id = p.vocabulary_id") as $p) {
            $props[$p['term']] = (int) $p['id'];
        }

        $total = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM revision_history_copy_history_event');
        $why = ['made' => 0, 'already' => 0, 'after_start' => 0, 'nothing_in_it' => 0, 'not_item_media_set' => 0];
        $seen = 0;
        $lastKey = ['', 0, 0];
        $entity = null;
        $state = null;
        while (true) {
            // Spelled out rather than (a, b, c) > (x, y, z), which MySQL reads without its index.
            $events = $this->conn->fetchAllAssociative(
                "SELECT * FROM revision_history_copy_history_event
                  WHERE entity_name > ?
                     OR (entity_name = ? AND (entity_id > ? OR (entity_id = ? AND id > ?)))
                  ORDER BY entity_name, entity_id, id LIMIT 2000",
                [$lastKey[0], $lastKey[0], $lastKey[1], $lastKey[1], $lastKey[2]]
            );
            $seen += count($events);
            $report['historylog_why'] = $why;
            $this->progress('historylog', $seen, $total, array_merge($report, ['made' => ['historylog' => $made, 'activitylog' => 0]]));
            if (!$events) {
                break;
            }
            $ids = implode(',', array_map(function ($e) {
                return (int) $e['id'];
            }, $events));
            $rows = [];
            foreach ($this->conn->fetchAllAssociative("SELECT * FROM revision_history_copy_history_change WHERE event_id IN ($ids) ORDER BY event_id, id") as $r) {
                $rows[(int) $r['event_id']][] = $r;
            }
            $done = array_flip($this->conn->fetchFirstColumn(
                "SELECT source_ref FROM revision_history_event WHERE source = 'historylog' AND source_ref IN ("
                . implode(',', array_map(function ($e) {
                    return "'hl:" . (int) $e['id'] . "'";
                }, $events)) . ')'
            ));

            foreach ($events as $ev) {
                $lastKey = [$ev['entity_name'], (int) $ev['entity_id'], (int) $ev['id']];
                $kind = self::KIND[$ev['entity_name']] ?? null;
                if (!$kind) {
                    $why['not_item_media_set']++;
                    continue;
                }
                $key = $ev['entity_name'] . '/' . $ev['entity_id'];
                if ($entity !== $key) {
                    $entity = $key;
                    $state = ['values' => [], 'known' => [], 'is_public' => null, 'created_seen' => false];
                }
                [$changes, $state, $note] = self::replay($ev, $rows[(int) $ev['id']] ?? [], $state, $props);

                $at = gmdate('Y-m-d H:i:s', strtotime($ev['created'] . ' UTC') - $offset);
                if ($at >= $from) {
                    $why['after_start']++;
                    continue;
                }
                if (isset($done['hl:' . $ev['id']])) {
                    $why['already']++;
                    continue;
                }
                $action = ['create' => 'created', 'update' => 'edited', 'delete' => 'deleted', 'undelete' => 'created'][$ev['operation']] ?? null;
                if (!$action || ($action === 'edited' && !$changes && !$note)) {
                    // History Log makes an event for a save that changed nothing it records.
                    $why['nothing_in_it']++;
                    continue;
                }
                $vals = $state['values'];
                if ($action === 'deleted') {
                    $vals = $state['last_values'] ?? [];
                }
                $title = null;
                foreach (['dcterms:title'] as $t) {
                    if (!empty($vals[$t][0]['value'])) {
                        $title = $vals[$t][0]['value'];
                    }
                }
                $u = $users[(int) $ev['user_id']] ?? null;
                $wrote = $this->writeOnce($store, [
                    'happened_at' => $at . '.000000',
                    'save_key' => 'hl:' . $ev['id'],
                    'action' => $action,
                    'resource_id' => (int) $ev['entity_id'],
                    'resource_kind' => $kind,
                    'item_id' => $kind === 'item' ? (int) $ev['entity_id'] : ($kind === 'media' && $ev['part_of'] ? (int) $ev['part_of'] : null),
                    'title' => $title,
                    'accession' => isset($vals[$accessionTerm][0]['value']) ? mb_substr((string) $vals[$accessionTerm][0]['value'], 0, 190) : '',
                    'user_id' => $ev['user_id'] ? (int) $ev['user_id'] : null,
                    'user_name' => $u ? mb_substr((string) $u['name'], 0, 190) : '',
                    'user_email' => $u ? mb_substr((string) $u['email'], 0, 190) : '',
                    'via' => '',
                    'via_detail' => '',
                    'source' => 'historylog',
                    'source_ref' => 'hl:' . $ev['id'],
                    'note' => $note,
                ], $changes);
                if ($wrote) {
                    $made++;
                    $why['made']++;
                } else {
                    $why['already']++;
                }
            }
        }
        $report['historylog_why'] = $why;
        $this->say("History Log: $made entries made.");
        return $made;
    }

    /**
     * One History Log event applied to what is known of the resource so far.
     *
     * History Log writes, for each field that changed, every value the field
     * holds afterwards (`none` for those that stayed, `update`/`create` for new
     * ones) and `delete` for what went. What it does not write is the value an
     * `update` replaced. When the field's earlier values are known — from the
     * creation, or from an earlier change to the same field — the replaced value
     * can be worked out. When they are not, the page says it was not recorded.
     *
     * @return array{0: array, 1: array, 2: ?string}
     */
    public static function replay(array $ev, array $rows, array $state, array $props): array
    {
        $byField = [];
        foreach ($rows as $r) {
            $byField[$r['field']][] = $r;
        }
        $sig = function (array $r) {
            $blank = function ($x) {
                return ($x === null || $x === '') ? null : (string) $x;
            };
            return [
                'type' => (string) $r['type'],
                'value' => $blank($r['value']),
                'uri' => $blank($r['uri']),
                'vr' => $r['value_resource_id'] === null ? null : (int) $r['value_resource_id'],
                'lang' => $blank($r['lang']),
                'pub' => $r['is_public'] === null ? 1 : (int) $r['is_public'],
            ];
        };
        // History Log can write the same block of rows two or three times for one save.
        $once = function (array $list) {
            $n = count($list);
            for ($len = 1; $len < $n; $len++) {
                if ($n % $len) {
                    continue;
                }
                $chunk = array_slice($list, 0, $len);
                $ok = true;
                for ($i = $len; $i < $n; $i += $len) {
                    if (array_slice($list, $i, $len) !== $chunk) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    return $chunk;
                }
            }
            return $list;
        };

        $changes = [];
        $note = null;
        $op = $ev['operation'];

        if ($op === 'create' || $op === 'undelete') {
            $state['created_seen'] = true;
        }
        if ($op === 'delete') {
            $state['last_values'] = [];
        }

        $unknown = false;
        foreach ($byField as $field => $list) {
            $list = $once(array_map(function ($r) {
                unset($r['id'], $r['event_id']);
                return [$r['action'], $r];
            }, $list));
            if (strpos($field, 'o:') === 0) {
                $last = end($list)[1];
                if ($field === 'o:is_public') {
                    $new = in_array(strtolower((string) $last['value']), ['1', 'true'], true) ? 1 : 0;
                    if ($op === 'update') {
                        $old = $state['is_public'];
                        if ($old !== $new) {
                            $changes[] = ['op' => 'edited', 'field' => 'o:is_public', 'property_id' => null, 'before' => ['id' => $old], 'after' => ['id' => $new]];
                        }
                    }
                    $state['is_public'] = $new;
                }
                continue;
            }
            $pid = $props[$field] ?? null;

            if ($op === 'delete') {
                $vals = array_map(function ($x) use ($sig) {
                    return $sig($x[1]);
                }, $list);
                $state['last_values'][$field] = $vals;
                foreach ($vals as $v) {
                    $changes[] = ['op' => 'removed', 'field' => $field, 'property_id' => $pid, 'before' => $v, 'after' => null];
                }
                unset($state['values'][$field], $state['known'][$field]);
                continue;
            }

            $new = [];
            $explicit = ['create' => [], 'update' => [], 'delete' => []];
            foreach ($list as [$action, $r]) {
                if ($action === 'delete') {
                    $explicit['delete'][] = $sig($r);
                } else {
                    $new[] = $sig($r);
                    if (isset($explicit[$action])) {
                        $explicit[$action][] = $sig($r);
                    }
                }
            }

            if ($op === 'create' || $op === 'undelete') {
                foreach ($new as $v) {
                    $changes[] = ['op' => 'added', 'field' => $field, 'property_id' => $pid, 'before' => null, 'after' => $v];
                }
            } elseif (!empty($state['known'][$field]) || ($state['created_seen'] && !isset($state['values'][$field]))) {
                // The field's earlier values are known (a field the creation did not
                // mention was empty then).
                foreach (Diff::field($state['values'][$field] ?? [], $new) as $c) {
                    $changes[] = ['op' => $c[0], 'field' => $field, 'property_id' => $pid, 'before' => $c[1], 'after' => $c[2]];
                }
            } else {
                foreach ($explicit['create'] as $v) {
                    $changes[] = ['op' => 'added', 'field' => $field, 'property_id' => $pid, 'before' => null, 'after' => $v];
                }
                foreach ($explicit['update'] as $v) {
                    $changes[] = ['op' => 'edited', 'field' => $field, 'property_id' => $pid, 'before' => null, 'after' => $v];
                    $unknown = true;
                }
                foreach ($explicit['delete'] as $v) {
                    $changes[] = ['op' => 'removed', 'field' => $field, 'property_id' => $pid, 'before' => $v, 'after' => null];
                }
            }
            $state['values'][$field] = $new;
            $state['known'][$field] = true;
        }
        if ($op === 'delete' && !$rows) {
            $note = 'History Log recorded the deletion without what the item held.';
        }
        if ($unknown) {
            $note = 'History Log did not keep the value that was replaced; it was recorded before this item\'s earlier values were known.';
        }
        return [$changes, $state, $note];
    }

    const HL_OPERATION = ['created' => 'create', 'edited' => 'update', 'deleted' => 'delete'];

    /** Did History Log record this same save — same resource, same kind of act, within two minutes? */
    private function historyLogHas(string $resource, int $rid, string $action, float $ts, int $offset): bool
    {
        return (bool) $this->conn->fetchOne(
            'SELECT 1 FROM revision_history_copy_history_event
              WHERE entity_name = ? AND entity_id = ? AND operation = ? AND created BETWEEN ? AND ? LIMIT 1',
            [
                $resource, $rid, self::HL_OPERATION[$action],
                gmdate('Y-m-d H:i:s', (int) floor($ts) + $offset - 120),
                gmdate('Y-m-d H:i:s', (int) floor($ts) + $offset + 120),
            ]
        );
    }

    /** Entries made from Activity Log for saves History Log also recorded. Returns how many went. */
    private function takeBackRepeats(int $offset): int
    {
        $ids = $this->conn->fetchFirstColumn(
            "SELECT e.id FROM revision_history_event e
              WHERE e.source = 'activitylog'
                AND e.source_ref NOT LIKE 'al-img:%'
                AND EXISTS (
                    SELECT 1 FROM revision_history_copy_history_event h
                     WHERE h.entity_id = e.resource_id
                       AND h.entity_name = CASE e.resource_kind WHEN 'item' THEN 'items' WHEN 'media' THEN 'media' ELSE 'item_sets' END
                       AND h.operation = CASE e.action WHEN 'created' THEN 'create' WHEN 'deleted' THEN 'delete' ELSE 'update' END
                       AND h.created BETWEEN DATE_ADD(e.happened_at, INTERVAL ? SECOND)
                                         AND DATE_ADD(e.happened_at, INTERVAL ? SECOND))",
            [$offset - 121, $offset + 120]
        );
        $n = 0;
        foreach (array_chunk($ids, 1000) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $this->conn->executeStatement("DELETE FROM revision_history_change WHERE event_id IN ($in)");
            $n += $this->conn->executeStatement("DELETE FROM revision_history_event WHERE id IN ($in) AND source = 'activitylog'");
        }
        return $n;
    }

    /**
     * Image Annotate annotations, from before this archive kept its own history.
     *
     * History Log does not see them at all. Activity Log does, by accident: it keeps
     * the whole request of every file save, and Image Annotate saves exactly the notes
     * that request carried — the whole set, every time. Reading one file's saves in
     * order and comparing each set with the one before gives what was added, edited
     * and removed. The first set seen for a file has nothing before it to compare with,
     * and its entry says so.
     */
    private function readImageNotes(array &$report): int
    {
        if (!$this->exists('revision_history_copy_activity_log_event')) {
            return 0;
        }
        $fromTs = strtotime((string) $this->settings->get('revision_history_from', '9999-12-31 00:00:00') . ' UTC');
        $store = new Store($this->conn);
        $users = [];
        foreach ($this->conn->fetchAllAssociative('SELECT id, name, email FROM user') as $u) {
            $users[(int) $u['id']] = $u;
        }
        $why = ['made' => 0, 'first_seen' => 0, 'no_change' => 0];
        $known = [];
        $last = 0;
        while (true) {
            $rows = $this->conn->fetchAllAssociative(
                "SELECT id, user_id, `timestamp`, resource_identifier, data FROM revision_history_copy_activity_log_event
                  WHERE id > ? AND `timestamp` < ? AND event = 'api.update.post' AND resource = 'media'
                    AND data LIKE '%image_annotate_annotations%'
                  ORDER BY id LIMIT 500",
                [$last, $fromTs]
            );
            if (!$rows) {
                break;
            }
            foreach ($rows as $r) {
                $last = (int) $r['id'];
                $mid = (int) $r['resource_identifier'];
                $data = json_decode((string) $r['data'], true);
                $sent = $data['request_content']['image_annotate_annotations'][(string) $mid] ?? null;
                $list = is_string($sent) ? json_decode($sent, true) : null;
                if (!$mid || !is_array($list)) {
                    // Image Annotate saves nothing in this case either.
                    continue;
                }
                $notes = StateReader::imageNotes($list);
                $first = !array_key_exists($mid, $known);
                $changes = Diff::changes(
                    ['values' => [], 'item_sets' => [], 'image_notes' => $first ? [] : $known[$mid]],
                    ['values' => [], 'item_sets' => [], 'image_notes' => $notes]
                );
                // Only the note changes: both sides above are otherwise empty on purpose.
                $changes = array_values(array_filter($changes, function ($c) {
                    return $c['field'] === 'o-module-image_annotate:annotation';
                }));
                $known[$mid] = $notes;
                if (!$changes) {
                    $why['no_change']++;
                    continue;
                }
                $about = $this->conn->fetchAssociative(
                    'SELECT m.item_id, r.title, m.source FROM media m JOIN resource r ON r.id = m.id WHERE m.id = ?',
                    [$mid]
                ) ?: $this->conn->fetchAssociative(
                    "SELECT item_id, title, NULL AS source FROM revision_history_event WHERE resource_kind IN ('item','media','item_set') AND resource_id = ? AND title IS NOT NULL AND title <> '' ORDER BY happened_at DESC LIMIT 1",
                    [$mid]
                ) ?: [];
                $ts = (float) $r['timestamp'];
                $u = $r['user_id'] ? ($users[(int) $r['user_id']] ?? null) : null;
                $wrote = $this->writeOnce($store, [
                    'happened_at' => gmdate('Y-m-d H:i:s', (int) floor($ts)) . sprintf('.%06d', (int) (($ts - floor($ts)) * 1000000)),
                    'save_key' => 'al:' . $r['id'],
                    'action' => 'edited',
                    'resource_id' => $mid,
                    'resource_kind' => 'media',
                    'item_id' => isset($about['item_id']) ? (int) $about['item_id'] : null,
                    'title' => ($about['title'] ?? '') !== '' ? $about['title'] : ($about['source'] ?? null),
                    'accession' => '',
                    'user_id' => $r['user_id'] ? (int) $r['user_id'] : null,
                    'user_name' => $u ? mb_substr((string) $u['name'], 0, 190) : '',
                    'user_email' => $u ? mb_substr((string) $u['email'], 0, 190) : '',
                    'via' => '',
                    'via_detail' => '',
                    'source' => 'activitylog',
                    'source_ref' => 'al-img:' . $r['id'],
                    'note' => $first
                        ? 'The first time Activity Log saw Image Annotate annotations on this media: annotations that were already there show as added; what came before was not recorded (Activity Log record #' . (int) $r['id'] . ').'
                        : 'Read from what the save sent to Image Annotate (Activity Log record #' . (int) $r['id'] . ').',
                ], $changes);
                if ($wrote) {
                    $why['made']++;
                    if ($first) {
                        $why['first_seen']++;
                    }
                }
            }
        }
        $report['image_notes_why'] = $why;
        $this->say('Image Annotate: ' . $why['made'] . ' entries made from Activity Log.');
        return $why['made'];
    }

    /**
     * What one Vocab Sync row becomes here: the action it says, and — where it kept
     * both sides — one line with the wording before and after.
     *
     * @param array $row a row of vocab_sync_event
     * @return array [action, changes]
     */
    public static function vocabSyncEntry(array $row): array
    {
        $action = self::VS_ACTION[(string) $row['action']] ?? 'edited';
        $detail = json_decode((string) ($row['detail'] ?? ''), true);
        $changes = [];
        if (is_array($detail) && (array_key_exists('before', $detail) || array_key_exists('after', $detail))) {
            $before = ($detail['before'] ?? null) === null ? null : (string) $detail['before'];
            $after = ($detail['after'] ?? null) === null ? null : (string) $detail['after'];
            if ($before !== null || $after !== null) {
                $val = function (?string $t) {
                    return $t === null ? null : ['type' => 'literal', 'value' => $t, 'uri' => null, 'vr' => null, 'lang' => null, 'pub' => 1];
                };
                $changes[] = [
                    'op' => $before === null ? 'added' : ($after === null ? 'removed' : 'edited'),
                    'field' => self::VS_FIELD,
                    'property_id' => null,
                    'before' => $val($before),
                    'after' => $val($after),
                ];
            }
        }
        return [$action, $changes];
    }

    const VS_FIELD = 'o-module-vocab_sync:term';

    /**
     * Entries from Vocab Sync's own record of what was done in it — vocabularies and
     * terms made, renamed, retired, deleted; values it moved with its own SQL, which
     * this archive's own recording cannot see.
     *
     * Every row is read, not only the ones from before this archive's history started:
     * nothing here is recorded twice, because Vocab Sync keeps these and this module
     * does not.
     */
    private function readVocabSync(array &$report, int $limit = 0): int
    {
        if (!$this->exists('revision_history_copy_vocab_sync_event')) {
            return 0;
        }
        $store = new Store($this->conn);
        $users = [];
        foreach ($this->conn->fetchAllAssociative('SELECT id, name, email FROM user') as $u) {
            $users[(int) $u['id']] = $u;
        }
        $vocabs = [];
        if ($this->exists('vocab_sync_vocab')) {
            foreach ($this->conn->fetchAllAssociative('SELECT id, label FROM vocab_sync_vocab') as $v) {
                $vocabs[(int) $v['id']] = (string) $v['label'];
            }
        }
        $why = ['made' => 0, 'already' => 0];
        // Vocab Sync writes NOW() — the database's own clock, in whatever zone it keeps.
        // This archive stores UTC, so the difference is asked of the same database.
        $offset = (int) $this->conn->fetchOne('SELECT TIMESTAMPDIFF(SECOND, NOW(), UTC_TIMESTAMP())');
        $report['vocabsync_offset'] = $offset;
        $made = 0;
        $last = (int) $this->conn->fetchOne(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(source_ref, 4) AS UNSIGNED)), 0) FROM revision_history_event WHERE source = 'vocabsync'"
        );
        $total = (int) $this->conn->fetchOne('SELECT COALESCE(MAX(id), 0) FROM revision_history_copy_vocab_sync_event');
        while (true) {
            $rows = $this->conn->fetchAllAssociative(
                'SELECT id, created, user_id, user_name, action, vocab_id, term_id, summary, detail
                   FROM revision_history_copy_vocab_sync_event WHERE id > ? ORDER BY id LIMIT ' . ($limit > 0 ? min($limit, 500) : 500),
                [$last]
            );
            if (!$rows) {
                break;
            }
            $this->progress('vocabsync', (int) end($rows)['id'], $total, $report);
            foreach ($rows as $r) {
                $last = (int) $r['id'];
                [$action, $changes] = self::vocabSyncEntry($r);
                $u = $r['user_id'] ? ($users[(int) $r['user_id']] ?? null) : null;
                $vocabId = $r['vocab_id'] === null ? 0 : (int) $r['vocab_id'];
                $wrote = $this->writeOnce($store, [
                    'happened_at' => gmdate('Y-m-d H:i:s', strtotime((string) $r['created'] . ' UTC') + $offset),
                    'save_key' => 'vs:' . $r['id'],
                    'action' => $action,
                    'resource_id' => $vocabId,
                    'resource_kind' => 'vocabsync',
                    'item_id' => null,
                    'title' => $vocabs[$vocabId] ?? null,
                    'accession' => '',
                    'user_id' => $r['user_id'] ? (int) $r['user_id'] : null,
                    'user_name' => mb_substr((string) ($r['user_name'] ?? ($u['name'] ?? '')), 0, 190),
                    'user_email' => $u ? mb_substr((string) $u['email'], 0, 190) : '',
                    'via' => '',
                    'via_detail' => '',
                    'source' => 'vocabsync',
                    'source_ref' => 'vs:' . $r['id'],
                    'note' => (string) $r['summary'],
                ], $changes);
                if ($wrote) {
                    $made++;
                    $why['made']++;
                } else {
                    $why['already']++;
                }
                if ($limit > 0 && $made >= $limit) {
                    break 2;
                }
            }
        }
        $report['vocabsync_why'] = $why;
        if ($made) {
            $this->say('Vocab Sync: ' . $made . ' entries made.');
        }
        return $made;
    }

    private function readActivityLog(array &$report): int
    {
        if (!$this->exists('revision_history_copy_activity_log_event')) {
            return 0;
        }
        $from = (string) $this->settings->get('revision_history_from', '9999-12-31 00:00:00');
        $fromTs = strtotime($from . ' UTC');
        $store = new Store($this->conn);
        $users = [];
        foreach ($this->conn->fetchAllAssociative('SELECT id, name, email FROM user') as $u) {
            $users[(int) $u['id']] = $u;
        }
        $made = 0;
        $last = 0;
        $why = ['made' => 0, 'said_by_another' => 0, 'said_by_history_log' => 0, 'taken_back' => 0];
        $total = (int) $this->conn->fetchOne('SELECT COALESCE(MAX(id), 0) FROM revision_history_copy_activity_log_event');

        // History Log keeps an event even for a save in which it found nothing changed,
        // and no entry is made from such an event. Activity Log records the same save as
        // "updated". Checked only against entries, that save looked unaccounted for and
        // became an entry that says "changed, but what is not known" — for a save that
        // History Log had already looked at and found empty. So Activity Log is checked
        // against every History Log event, and entries made before this was known are
        // taken back. They are readings of the copy, not the copy: the copy is untouched.
        $hl = $this->exists('revision_history_copy_history_event');
        $offset = 0;
        if ($hl) {
            [$offset] = $this->historyLogOffset();
            $why['taken_back'] = $this->takeBackRepeats($offset);
            if ($why['taken_back']) {
                $this->say('Activity Log: ' . $why['taken_back'] . ' entries taken back — History Log had already recorded those saves.');
            }
        }
        while (true) {
            $rows = $this->conn->fetchAllAssociative(
                "SELECT id, user_id, `timestamp`, event, resource, resource_identifier, data
                   FROM revision_history_copy_activity_log_event
                  WHERE id > ? AND `timestamp` < ?
                    AND (event IN ('api.create.post', 'api.update.post', 'api.delete.post',
                                   'api.batch_update.post', 'api.batch_delete.post')
                         AND resource IN ('items', 'media', 'item_sets'))
                  ORDER BY id LIMIT 2000",
                [$last, $fromTs]
            );
            if (!$rows) {
                break;
            }
            $this->progress('activitylog', (int) end($rows)['id'], $total, $report);
            foreach ($rows as $r) {
                $last = (int) $r['id'];
                $ref = 'al:' . $r['id'];
                $ids = [];
                $batch = strpos($r['event'], 'batch') !== false;
                if ($batch) {
                    $data = json_decode((string) $r['data'], true);
                    $ids = array_map('intval', (array) ($data['request_ids'] ?? []));
                } elseif (ctype_digit((string) $r['resource_identifier'])) {
                    $ids = [(int) $r['resource_identifier']];
                }
                $action = strpos($r['event'], 'create') !== false ? 'created' : (strpos($r['event'], 'delete') !== false ? 'deleted' : 'edited');
                $ts = (float) $r['timestamp'];
                $at = gmdate('Y-m-d H:i:s', (int) floor($ts)) . sprintf('.%06d', (int) (($ts - floor($ts)) * 1000000));
                $u = $r['user_id'] ? ($users[(int) $r['user_id']] ?? null) : null;
                foreach ($ids as $n => $rid) {
                    $sref = $batch ? $ref . ':' . $rid : $ref;
                    // Already said, better, by History Log or by this archive: within two minutes, same thing.
                    $said = $this->conn->fetchOne(
                        "SELECT id FROM revision_history_event WHERE resource_kind IN ('item','media','item_set') AND resource_id = ? AND action = ?
                            AND (source_ref = ? OR (source <> 'activitylog' AND happened_at BETWEEN ? AND ?)) LIMIT 1",
                        [$rid, $action, $sref, gmdate('Y-m-d H:i:s', (int) $ts - 120), gmdate('Y-m-d H:i:s', (int) $ts + 120)]
                    );
                    if ($said) {
                        $why['said_by_another']++;
                        continue;
                    }
                    if ($hl && $this->historyLogHas($r['resource'], $rid, $action, $ts, $offset)) {
                        $why['said_by_history_log']++;
                        continue;
                    }
                    $prev = $this->conn->fetchAssociative(
                        "SELECT title, accession, item_id FROM revision_history_event WHERE resource_kind IN ('item','media','item_set') AND resource_id = ? AND title IS NOT NULL AND title <> '' ORDER BY happened_at DESC LIMIT 1",
                        [$rid]
                    );
                    $wrote = $this->writeOnce($store, [
                        'happened_at' => $at,
                        'save_key' => $ref,
                        'action' => $action,
                        'resource_id' => $rid,
                        'resource_kind' => self::KIND[$r['resource']],
                        'item_id' => $r['resource'] === 'items' ? $rid : ($prev['item_id'] ?? null),
                        'title' => $prev['title'] ?? null,
                        'accession' => $prev['accession'] ?? '',
                        'user_id' => $r['user_id'] ? (int) $r['user_id'] : null,
                        'user_name' => $u ? mb_substr((string) $u['name'], 0, 190) : '',
                        'user_email' => $u ? mb_substr((string) $u['email'], 0, 190) : '',
                        'via' => '',
                        'via_detail' => '',
                        'source' => 'activitylog',
                        'source_ref' => $sref,
                        'note' => ($batch ? 'Batch ' . ($action === 'deleted' ? 'delete' : 'edit') . ' of ' . count($ids) . '. ' : '')
                            . 'Activity Log kept who and when, not what changed (its record #' . (int) $r['id'] . ').',
                    ], []);
                    if ($wrote) {
                        $made++;
                        $why['made']++;
                    } else {
                        $why['said_by_another']++;
                    }
                }
            }
        }
        $report['activitylog_why'] = $why;
        $this->say("Activity Log: $made entries made.");
        return $made;
    }
}
