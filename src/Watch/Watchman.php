<?php
namespace RevisionHistory\Watch;

use Doctrine\DBAL\Connection;
use RevisionHistory\Capture\Diff;
use RevisionHistory\Capture\StateReader;
use RevisionHistory\Store;

/**
 * Looks at the data itself and asks one question: is anything different from what
 * this history accounts for?
 *
 * The recorder hears every save that goes through Omeka. It cannot hear a module that
 * writes the tables with its own SQL, a change made in phpMyAdmin or at the command
 * line, or a table put back from a backup. Nothing inside Omeka can. What can be done
 * is to look afterwards: keep one number for what each resource holds, and compare.
 *
 *   - the number for a resource changed, and no entry was written since it was taken
 *     → an entry saying so: changed, by nobody this history knows
 *   - a resource is gone, and nothing says it was deleted → the same, for a deletion
 *   - a resource is here that was not, and nothing says it was created → the same
 *
 * The number alone says THAT something changed, not what. To say what changed as well,
 * the archive can be asked to keep a copy of what each resource holds (revision_history_shadow) —
 * a second copy of the values, roughly the size of the value table again. When that copy
 * is kept, an unaccounted change is written down the way any other change is: this value
 * was added, that one was taken away, this one used to say something else.
 *
 * The first run writes the fingerprints and reports nothing — there is nothing to
 * compare against yet. It says so on the page.
 *
 * Every table in the database is counted as well. A module that keeps its own record
 * cannot be read without someone looking at it first (that is how Vocab Sync's record
 * came to be read here), but a table appearing or growing is something this can see by
 * itself, and name, so that nobody has to notice it by accident.
 */
class Watchman
{
    /** Which way the number of each resource is made; a new way means writing them all down again. */
    const HASH = 2;

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

    /**
     * One number per resource, from everything it holds: its own row, its values, and
     * the notes drawn on it if Image Annotate is installed. Order does not matter to
     * the number, because each line is hashed with its own id in it.
     */
    private function hashes(int $from, int $to): array
    {
        $row = "CAST(CONV(SUBSTRING(MD5(%s), 1, 15), 16, 10) AS UNSIGNED)";
        $self = sprintf($row, "CONCAT_WS('\\t', r.id, r.resource_type, r.is_public, COALESCE(r.title, ''), COALESCE(r.owner_id, ''), COALESCE(r.resource_class_id, ''), COALESCE(r.resource_template_id, ''), COALESCE(r.thumbnail_id, ''))");
        $value = sprintf($row, "CONCAT_WS('\\t', v.id, v.property_id, v.type, COALESCE(v.value, ''), COALESCE(v.uri, ''), COALESCE(v.value_resource_id, ''), COALESCE(v.lang, ''), v.is_public, COALESCE(v.value_annotation_id, ''))");
        // Items, media and item sets. A value annotation is part of the value it hangs
        // on, and is counted with the resource holding that value, below.
        $sql = "SELECT r.id AS id, ($self ^ COALESCE(BIT_XOR($value), 0)) AS h
                  FROM resource r
                  LEFT JOIN value v ON v.resource_id = r.id
                 WHERE r.id BETWEEN ? AND ?
                   AND r.resource_type IN (?, ?, ?)
                 GROUP BY r.id, r.resource_type, r.is_public, r.title, r.owner_id, r.resource_class_id, r.resource_template_id, r.thumbnail_id";
        $out = [];
        $kinds = ['Omeka\\Entity\\Item', 'Omeka\\Entity\\Media', 'Omeka\\Entity\\ItemSet'];
        foreach ($this->conn->fetchAllAssociative($sql, array_merge([$from, $to], $kinds)) as $r) {
            $out[(int) $r['id']] = (string) $r['h'];
        }
        // The notes written on values. Asked for on their own, not joined: the same row
        // counted twice would cancel itself out of the number.
        $annotation = sprintf($row, "CONCAT_WS('\\t', av.id, av.property_id, av.type, COALESCE(av.value, ''), COALESCE(av.uri, ''), COALESCE(av.value_resource_id, ''), COALESCE(av.lang, ''), av.is_public)");
        foreach ($this->conn->fetchAllAssociative(
            "SELECT v.resource_id AS id, BIT_XOR($annotation) AS h
               FROM value v JOIN value av ON av.resource_id = v.value_annotation_id
              WHERE v.resource_id BETWEEN ? AND ? AND v.value_annotation_id IS NOT NULL
              GROUP BY v.resource_id",
            [$from, $to]
        ) as $r) {
            $id = (int) $r['id'];
            if (isset($out[$id])) {
                $out[$id] = (string) (((int) $out[$id]) ^ ((int) $r['h']));
            }
        }
        // The rest of each resource (HASH 2): an item's primary media, item sets and
        // sites; a media's file, place in its item and settings; an item set's openness.
        $parts = [
            "SELECT i.id AS id, " . sprintf($row, "CONCAT_WS('\\t', 'pm', i.id, COALESCE(i.primary_media_id, ''))") . " AS h FROM item i WHERE i.id BETWEEN ? AND ?",
            "SELECT s.id AS id, " . sprintf($row, "CONCAT_WS('\\t', 'open', s.id, s.is_open)") . " AS h FROM item_set s WHERE s.id BETWEEN ? AND ?",
            "SELECT m.id AS id, " . sprintf($row, "CONCAT_WS('\\t', 'm', m.id, m.item_id, m.ingester, m.renderer, COALESCE(m.data, ''), COALESCE(m.source, ''), COALESCE(m.media_type, ''), COALESCE(m.storage_id, ''), COALESCE(m.extension, ''), COALESCE(m.sha256, ''), COALESCE(m.size, ''), m.has_original, m.has_thumbnails, COALESCE(m.position, ''), COALESCE(m.lang, ''), COALESCE(m.alt_text, ''))") . " AS h FROM media m WHERE m.id BETWEEN ? AND ?",
            "SELECT x.item_id AS id, BIT_XOR(" . sprintf($row, "CONCAT_WS('\\t', 'set', x.item_id, x.item_set_id)") . ") AS h FROM item_item_set x WHERE x.item_id BETWEEN ? AND ? GROUP BY x.item_id",
            "SELECT x.item_id AS id, BIT_XOR(" . sprintf($row, "CONCAT_WS('\\t', 'site', x.item_id, x.site_id)") . ") AS h FROM item_site x WHERE x.item_id BETWEEN ? AND ? GROUP BY x.item_id",
        ];
        foreach ($parts as $sql) {
            foreach ($this->conn->fetchAllAssociative($sql, [$from, $to]) as $r) {
                $id = (int) $r['id'];
                if (isset($out[$id])) {
                    $out[$id] = (string) (((int) $out[$id]) ^ ((int) $r['h']));
                }
            }
        }
        if ($this->exists('image_annotate_media')) {
            $note = sprintf($row, "CONCAT_WS('\\t', ia.media_id, COALESCE(ia.annotations, ''))");
            foreach ($this->conn->fetchAllAssociative(
                "SELECT ia.media_id AS id, $note AS h FROM image_annotate_media ia WHERE ia.media_id BETWEEN ? AND ?",
                [$from, $to]
            ) as $r) {
                $id = (int) $r['id'];
                if (isset($out[$id])) {
                    // Both are unsigned 60-bit numbers; PHP's ints hold them.
                    $out[$id] = (string) (((int) $out[$id]) ^ ((int) $r['h']));
                }
            }
        }
        return $out;
    }

    /**
     * Did this history record anything about this resource after the given moment?
     * $others: only what came from elsewhere (Vocab Sync's own record, a copy). A save
     * recorded here writes the resource's number down itself (afterSave), so a number
     * that still differs is a change nothing recorded — even if something else was
     * recorded about the same resource in the meantime.
     */
    private function accounted(int $id, string $since, bool $others = false): bool
    {
        return (bool) $this->conn->fetchOne(
            'SELECT 1 FROM revision_history_event WHERE resource_kind IN (\'item\',\'media\',\'item_set\') AND (resource_id = ? OR item_id = ?) AND happened_at >= ?' . ($others ? " AND source <> 'here'" : '') . ' LIMIT 1',
            [$id, $id, $since]
        );
    }

    /**
     * Right before a save: the resources that already differ from what was last
     * written down — changed by something nobody recorded. They are left for the next
     * check to report, not written over by this save.
     *
     * @return int[]
     */
    public function beforeSave(array $ids): array
    {
        $store = new Store($this->conn);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !$store->meta('watch_baseline') || (int) $store->meta('watch_hash') !== self::HASH) {
            return [];
        }
        $stored = [];
        foreach ($this->conn->fetchAllAssociative('SELECT resource_id, hash FROM revision_history_digest WHERE resource_id IN (' . implode(',', $ids) . ')') as $r) {
            $stored[(int) $r['resource_id']] = (string) $r['hash'];
        }
        $stale = [];
        foreach ($ids as $id) {
            if (!isset($stored[$id])) {
                continue;
            }
            $h = $this->hashes($id, $id);
            if (isset($h[$id]) && (string) $h[$id] !== $stored[$id]) {
                $stale[] = $id;
            }
        }
        return $stale;
    }

    /**
     * A resource about to be saved already differed from what was last written down:
     * something changed it without being recorded. Say so now, with what changed when a
     * copy is kept (the copy against what the tables held just before this save), so the
     * save that follows is not blamed for it and does not hide it.
     */
    public function reportStale(int $id, ?array $before): void
    {
        $store = new Store($this->conn);
        $at = (string) $this->conn->fetchOne('SELECT `at` FROM revision_history_digest WHERE resource_id = ?', [$id]);
        $kept = $this->keepingCopy() ? $this->kept($id) : null;
        $changes = ($kept && $before) ? Diff::changes($kept, $before) : [];
        $note = 'Found when this was about to be saved: what it held was not what it held when it was last written down (' . $at . ' UTC), and nothing in this history says it changed. Who changed it is not known.';
        if (!$changes) {
            $note .= ' What changed is not known either' . ($kept ? '' : ' — there was no copy of this one to compare against') . '.';
        }
        $this->write($store, $id, 'edited', $note, $changes);
    }

    /**
     * Right after a save is recorded: the resources it touched are written down as they
     * now are, so the next check compares against the recorded state and anything that
     * differs from it is something nobody recorded. Only once checks have started.
     *
     * @param int[] $ids resources that exist after the save
     * @param int[] $gone resources the save deleted
     * @param array $states what they hold now (StateReader), when a copy is kept
     */
    public function afterSave(array $ids, array $gone = [], array $states = []): void
    {
        $store = new Store($this->conn);
        if (!$store->meta('watch_baseline') || (int) $store->meta('watch_hash') !== self::HASH) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $hashes = [];
        sort($ids);
        foreach ($ids as $id) {
            $hashes += $this->hashes($id, $id);
        }
        $this->remember($hashes, $now);
        if ($this->keepingCopy() && $states) {
            $this->keep(array_intersect_key($states, $hashes), $now);
        }
        foreach ($gone as $id) {
            $this->conn->executeStatement('DELETE FROM revision_history_digest WHERE resource_id = ?', [(int) $id]);
            $this->conn->executeStatement('DELETE FROM revision_history_shadow WHERE resource_id = ?', [(int) $id]);
        }
    }

    private function about(int $id): array
    {
        $r = $this->conn->fetchAssociative(
            'SELECT r.id, r.resource_type, r.title, m.item_id FROM resource r LEFT JOIN media m ON m.id = r.id WHERE r.id = ?',
            [$id]
        );
        if ($r) {
            return [
                'kind' => self::KIND[$this->shortType((string) $r['resource_type'])] ?? 'item',
                'title' => $r['title'],
                'item_id' => $r['item_id'] === null ? ($this->shortType((string) $r['resource_type']) === 'items' ? $id : null) : (int) $r['item_id'],
            ];
        }
        $last = $this->conn->fetchAssociative(
            "SELECT resource_kind, title, item_id FROM revision_history_event WHERE resource_kind IN ('item','media','item_set') AND resource_id = ? ORDER BY happened_at DESC LIMIT 1",
            [$id]
        );
        return [
            'kind' => $last['resource_kind'] ?? 'item',
            'title' => $last['title'] ?? null,
            'item_id' => $last['item_id'] ?? null,
        ];
    }

    private function shortType(string $class): string
    {
        return ['Omeka\Entity\Item' => 'items', 'Omeka\Entity\Media' => 'media', 'Omeka\Entity\ItemSet' => 'item_sets'][$class] ?? 'items';
    }

    private function reader(): StateReader
    {
        return new StateReader($this->conn, (string) $this->settings->get('revision_history_accession_term', 'dcterms:identifier'));
    }

    /** Is a full copy of what each resource holds being kept? */
    public function keepingCopy(): bool
    {
        return (bool) $this->settings->get('revision_history_shadow_on', false);
    }

    /** What was written down for this resource last time, if a copy is kept. */
    private function kept(int $id): ?array
    {
        $json = $this->conn->fetchOne('SELECT `state` FROM `revision_history_shadow` WHERE `resource_id` = ?', [$id]);
        if ($json === false || $json === null) {
            return null;
        }
        $state = json_decode((string) $json, true);
        return is_array($state) ? $state : null;
    }

    private function keep(array $states, string $now): void
    {
        foreach ($states as $id => $state) {
            $this->conn->executeStatement(
                'INSERT INTO `revision_history_shadow` (`resource_id`, `state`, `at`) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE `state` = VALUES(`state`), `at` = VALUES(`at`)',
                [(int) $id, Store::json($state), $now]
            );
        }
    }

    private function write(Store $store, int $id, string $action, string $note, array $changes = []): int
    {
        $about = $this->about($id);
        return $store->write([
            'happened_at' => gmdate('Y-m-d H:i:s'),
            'save_key' => '',
            'action' => $action,
            'resource_id' => $id,
            'resource_kind' => $about['kind'],
            'item_id' => $about['item_id'],
            'title' => $about['title'],
            'accession' => '',
            'user_id' => null,
            'user_name' => '',
            'user_email' => '',
            'via' => '',
            'via_detail' => '',
            'source' => 'watch',
            'source_ref' => 'w:' . $id . ':' . gmdate('YmdHis'),
            'note' => $note,
        ], $changes);
    }

    /**
     * One pass over everything.
     *
     * @return array what was found, for the page and for the report
     */
    public function run(): array
    {
        $store = new Store($this->conn);
        $store->install();
        if (!(int) $this->conn->fetchOne("SELECT GET_LOCK('revision_history_watch', 0)")) {
            return ['busy' => true];
        }
        try {
            $baseline = $store->meta('watch_baseline');
            $first = !$baseline;
            // The number now covers more of each resource than it used to. Numbers made the
            // old way would all differ, and say nothing: this pass writes them down again.
            if (!$first && (int) $store->meta('watch_hash') !== self::HASH) {
                $first = true;
                $this->say('The check now looks at more of each resource (its media, file, sites, thumbnail). This pass writes everything down again and reports nothing.');
            }
            $copy = $this->keepingCopy();
            // Asked for after the first pass: there is nothing written down yet, so this
            // pass writes it all down, the way the very first pass does.
            if ($copy && !$first && !(int) $this->conn->fetchOne('SELECT COUNT(*) FROM `revision_history_shadow`')) {
                $first = true;
                $this->say('A copy of what each resource holds was asked for and there is none yet: this pass writes it down.');
            }
            $now = gmdate('Y-m-d H:i:s');
            $found = ['changed' => [], 'gone' => [], 'appeared' => []];
            $checked = 0;

            $max = (int) $this->conn->fetchOne('SELECT COALESCE(MAX(id), 0) FROM resource');
            $seen = [];
            for ($from = 1; $from <= $max; $from += 2000) {
                $to = $from + 1999;
                $hashes = $this->hashes($from, $to);
                $was = [];
                foreach ($this->conn->fetchAllAssociative(
                    'SELECT resource_id, hash, at FROM revision_history_digest WHERE resource_id BETWEEN ? AND ?',
                    [$from, $to]
                ) as $d) {
                    $was[(int) $d['resource_id']] = $d;
                }
                // Only what is different has to be read in full; everything else is
                // already known to be the same, from its number alone.
                $differs = [];
                foreach ($hashes as $id => $hash) {
                    $old = $was[$id] ?? null;
                    if ($old === null || (string) $old['hash'] !== (string) $hash) {
                        $differs[] = $id;
                    }
                }
                $states = ($copy && $differs) ? $this->reader()->read($differs) : [];
                $fresh = [];
                foreach ($hashes as $id => $hash) {
                    $checked++;
                    $seen[$id] = true;
                    $old = $was[$id] ?? null;
                    if ($old === null) {
                        // Made after this started watching, and no entry for it: it came
                        // from outside. Older than that and merely unwritten-down (a lost
                        // line, an earlier version that did not keep it) is not news.
                        // On the first pass there is no such moment yet, and nothing is
                        // asked of the database with an empty date.
                        $madeSince = !$first && (bool) $this->conn->fetchOne(
                            'SELECT 1 FROM resource WHERE id = ? AND created >= ?',
                            [$id, (string) $baseline]
                        );
                        if ($madeSince && !$this->accounted($id, (string) $baseline)) {
                            $found['appeared'][] = $id;
                            $this->write(
                                $store,
                                $id,
                                'created',
                                'This is here, and nothing in this history says it was created. It was made by something this archive does not hear — a module writing the tables with its own SQL, a change made straight in the database, or a table put back from a backup.',
                                isset($states[$id]) ? Diff::changes(null, $states[$id]) : []
                            );
                        }
                    } elseif ((string) $old['hash'] !== (string) $hash) {
                        // A first pass reports nothing: numbers left behind by a pass that
                        // stopped halfway are not something anybody did.
                        if (!$first && !$this->accounted($id, (string) $old['at'], true)) {
                            $found['changed'][] = $id;
                            $changes = [];
                            $note = 'What this holds is not what it held when it was last checked (' . $old['at'] . ' UTC), and nothing in this history says it changed. Who changed it is not known.';
                            $kept = $copy ? $this->kept($id) : null;
                            if ($kept && isset($states[$id])) {
                                $changes = Diff::changes($kept, $states[$id]);
                            }
                            if (!$changes) {
                                $note .= ' What changed is not known either' . ($copy ? ' — there was no copy of this one to compare against' : ' — only that it did') . '.';
                            }
                            $this->write($store, $id, 'edited', $note, $changes);
                        }
                    }
                    if ($old === null || (string) $old['hash'] !== (string) $hash) {
                        // Only what is new or different is written down again. A check of
                        // an archive where nothing changed writes nothing at all.
                        $fresh[$id] = (string) $hash;
                    }
                }
                $this->remember($fresh, $now);
                $fresh = [];
                if ($copy) {
                    if ($first) {
                        // The first pass writes down everything, so that from now on a
                        // difference can say what it is.
                        foreach (array_chunk(array_keys($hashes), 500) as $chunk) {
                            $this->keep($this->reader()->read($chunk), $now);
                        }
                    } elseif ($states) {
                        $this->keep($states, $now);
                    }
                }
                foreach ($was as $id => $d) {
                    if (isset($hashes[$id])) {
                        continue;
                    }
                    // Still in the tables, only not one of the three kinds that are
                    // watched (an earlier version wrote down value annotations too).
                    if ($this->conn->fetchOne('SELECT 1 FROM resource WHERE id = ?', [$id])) {
                        $this->conn->executeStatement('DELETE FROM revision_history_digest WHERE resource_id = ?', [$id]);
                        continue;
                    }
                    if (!$first && !$this->accounted($id, (string) $d['at'])) {
                        $found['gone'][] = $id;
                        $kept = $copy ? $this->kept($id) : null;
                        $eventId = $this->write(
                            $store,
                            $id,
                            'deleted',
                            $kept
                                ? 'This is no longer in the archive, and nothing in this history says it was deleted. What it held when it was last checked is below.'
                                : 'This is no longer in the archive, and nothing in this history says it was deleted. What it held is not known beyond what earlier entries kept.',
                            $kept ? Diff::changes($kept, null) : []
                        );
                        if ($kept) {
                            $store->snapshot($eventId, $kept);
                        }
                    }
                    $this->conn->executeStatement('DELETE FROM revision_history_digest WHERE resource_id = ?', [$id]);
                    $this->conn->executeStatement('DELETE FROM revision_history_shadow WHERE resource_id = ?', [$id]);
                }
            }

            $tables = $this->tables();
            $store->setMeta('watch_last', [
                'at' => $now,
                'checked' => $checked,
                'first' => $first,
                'found' => ['changed' => count($found['changed']), 'gone' => count($found['gone']), 'appeared' => count($found['appeared'])],
                'examples' => ['changed' => array_slice($found['changed'], 0, 20), 'gone' => array_slice($found['gone'], 0, 20), 'appeared' => array_slice($found['appeared'], 0, 20)],
                'tables' => $tables,
            ]);
            $store->setMeta('watch_hash', self::HASH);
            if ($first) {
                $store->setMeta('watch_baseline', $now);
                $this->say('First check: ' . $checked . ' resources written down as they are now. From here on, a difference can be seen.');
            } else {
                $this->say('Checked ' . $checked . ' resources. Not accounted for: '
                    . count($found['changed']) . ' changed, ' . count($found['gone']) . ' gone, ' . count($found['appeared']) . ' new.');
            }
            return ['checked' => $checked, 'first' => $first, 'found' => $found, 'tables' => $tables];
        } finally {
            $this->conn->fetchOne("SELECT RELEASE_LOCK('revision_history_watch')");
        }
    }

    /**
     * Write down the numbers, many at a time.
     *
     * @param array<int,string> $hashes resource id => number
     */
    private function remember(array $hashes, string $now): void
    {
        foreach (array_chunk($hashes, 500, true) as $chunk) {
            $marks = [];
            $params = [];
            foreach ($chunk as $id => $hash) {
                $marks[] = '(?,?,?)';
                array_push($params, (int) $id, $hash, $now);
            }
            $this->conn->executeStatement(
                'INSERT INTO `revision_history_digest` (`resource_id`, `hash`, `at`) VALUES ' . implode(',', $marks)
                . ' ON DUPLICATE KEY UPDATE `hash` = VALUES(`hash`), `at` = VALUES(`at`)',
                $params
            );
        }
    }

    /**
     * Every table in this database, how many rows it has, and how that compares with
     * the last look. New tables are named: a module that has just started keeping its
     * own record shows up here first.
     */
    public function tables(): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $out = [];
        $was = [];
        foreach ($this->conn->fetchAllAssociative('SELECT `name`, `rows`, `at` FROM `revision_history_table`') as $r) {
            $was[(string) $r['name']] = $r;
        }
        foreach ($this->conn->fetchFirstColumn('SHOW TABLES') as $name) {
            $name = (string) $name;
            try {
                $rows = (int) $this->conn->fetchOne("SELECT COUNT(*) FROM `$name`");
            } catch (\Throwable $e) {
                continue;
            }
            $old = $was[$name] ?? null;
            $out[] = [
                'name' => $name,
                'rows' => $rows,
                'was' => $old === null ? null : (int) $old['rows'],
                'at' => $old['at'] ?? null,
                'new' => $old === null,
            ];
            $this->conn->executeStatement(
                'INSERT INTO `revision_history_table` (`name`, `rows`, `at`) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE `rows` = VALUES(`rows`), `at` = VALUES(`at`)',
                [$name, $rows, $now]
            );
            unset($was[$name]);
        }
        foreach ($was as $name => $old) {
            $out[] = ['name' => (string) $name, 'rows' => null, 'was' => (int) $old['rows'], 'at' => $old['at'], 'new' => false, 'gone' => true];
            $this->conn->executeStatement('DELETE FROM `revision_history_table` WHERE `name` = ?', [$name]);
        }
        usort($out, function ($a, $b) {
            return [$a['new'] ? 0 : 1, $a['name']] <=> [$b['new'] ? 0 : 1, $b['name']];
        });
        return $out;
    }

    /** How much room the copy takes, or would take. Read from the database itself. */
    public function room(): array
    {
        $size = function (string $table) {
            try {
                return (int) $this->conn->fetchOne(
                    'SELECT DATA_LENGTH + INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                    [$table]
                );
            } catch (\Throwable $e) {
                return 0;
            }
        };
        $values = 0;
        $length = 0;
        try {
            $r = $this->conn->fetchAssociative("SELECT COUNT(*) n, COALESCE(AVG(CHAR_LENGTH(COALESCE(`value`, '')) + CHAR_LENGTH(COALESCE(`uri`, ''))), 0) len FROM `value`");
            $values = (int) $r['n'];
            $length = (int) round((float) $r['len']);
        } catch (\Throwable $e) {
        }
        return [
            'value_table' => $size('value'),
            'resource_table' => $size('resource'),
            'copy_now' => $size('revision_history_shadow'),
            'values' => $values,
            'value_length' => $length,
            // About ninety bytes of wrapping around each value, measured against the
            // values this archive already holds.
            'estimate' => $values * (90 + $length),
        ];
    }

    /** Start or stop keeping the copy. Stopping throws away what was kept. */
    public function keepCopy(bool $on): void
    {
        $this->settings->set('revision_history_shadow_on', $on);
        if (!$on) {
            try {
                $this->conn->executeStatement('TRUNCATE TABLE `revision_history_shadow`');
            } catch (\Throwable $e) {
            }
        }
    }

    /**
     * How often a check should happen, in hours. 0 means only when somebody asks for it.
     * A check of an archive this size takes a couple of seconds, so often is cheap.
     */
    public function every(): int
    {
        return max(0, (int) $this->settings->get('revision_history_watch_hours', 1));
    }

    /**
     * Is a check due? Says yes when the last one is older than the chosen number of
     * hours — or when there has never been one.
     *
     * Asked on every admin page, so it is one small query: both notes in one read, and
     * nothing else touched unless the answer is going to be yes.
     */
    public function due(): bool
    {
        $every = $this->every();
        if (!$every) {
            return false;
        }
        try {
            $rows = $this->conn->fetchAllKeyValue(
                "SELECT `name`, `val` FROM `revision_history_meta` WHERE `name` IN ('watch_last', 'watch_sent')"
            );
        } catch (\Throwable $e) {
            return false;
        }
        $last = json_decode((string) ($rows['watch_last'] ?? ''), true);
        $when = is_array($last) ? ($last['at'] ?? null) : null;
        if ($when && strtotime((string) $when . ' UTC') > time() - $every * 3600) {
            return false;
        }
        // Two pages opened at the same moment must not start two checks. Five minutes is
        // longer than a check takes and shorter than the shortest round.
        $sent = json_decode((string) ($rows['watch_sent'] ?? ''), true);
        if ($sent && strtotime((string) $sent . ' UTC') > time() - 300) {
            return false;
        }
        // One is running right now.
        return !(int) $this->conn->fetchOne("SELECT IS_USED_LOCK('revision_history_watch') IS NOT NULL");
    }

    /** Written down when a check is started, so two pages do not start two. */
    public function sent(): void
    {
        (new Store($this->conn))->setMeta('watch_sent', gmdate('Y-m-d H:i:s'));
    }

    /** What the page shows without running anything. */
    public function status(): array
    {
        $store = new Store($this->conn);
        $running = false;
        try {
            $running = (bool) (int) $this->conn->fetchOne("SELECT IS_USED_LOCK('revision_history_watch') IS NOT NULL");
        } catch (\Throwable $e) {
        }
        $job = null;
        try {
            $job = $this->conn->fetchAssociative(
                'SELECT id, status, started, ended FROM job WHERE class = ? ORDER BY id DESC LIMIT 1',
                [\RevisionHistory\Job\WatchJob::class]
            ) ?: null;
        } catch (\Throwable $e) {
        }
        return [
            'last' => $store->meta('watch_last'),
            'baseline' => $store->meta('watch_baseline'),
            'running' => $running,
            'job' => $job,
            'resources' => (int) $this->conn->fetchOne('SELECT COUNT(*) FROM resource'),
            'remembered' => (int) $this->conn->fetchOne('SELECT COUNT(*) FROM revision_history_digest'),
            'every' => $this->every(),
            'copy' => $this->keepingCopy(),
            'copied' => (int) $this->conn->fetchOne('SELECT COUNT(*) FROM revision_history_shadow'),
            'room' => $this->room(),
        ];
    }
}
