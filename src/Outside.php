<?php
namespace RevisionHistory;

use RevisionHistory\Capture\Diff;
use RevisionHistory\Capture\Recorder;
use RevisionHistory\Capture\StateReader;
use RevisionHistory\Watch\Watchman;

/**
 * A write that does not go through Omeka's entities — SQL of a module or a page, a column
 * Omeka's API cannot set — recorded the way a save is (1.0.10).
 *
 * The recorder hears Doctrine. A write in plain SQL it cannot hear, and the data check later
 * finds the change and can only say "nobody this history knows". Code that has to write that
 * way says which resources it is about to change and hands over the write:
 *
 *   if (class_exists(\RevisionHistory\Outside::class)) {
 *       \RevisionHistory\Outside::fromServices($services)->around([$mediaId], function () use ($conn) {
 *           $conn->executeStatement('UPDATE media SET …');
 *       }, 'the file was replaced');
 *   }
 *
 * What the resources hold is read before and after; what changed is written as an entry with
 * who was signed in and how the request arrived (web and the page's path, cli and the script's
 * name), exactly as for a save. A resource that had already changed without a record is reported
 * first, as before a save. Then the data check's number and copy are written again.
 *
 * Whatever the write returns is returned. If the write throws, nothing is recorded and the
 * exception goes on. If the recording fails, the write still stands, and the failure is logged.
 *
 * 1.2.1 — $opts on around(): 'run' (up to 40 characters: the entries of one job, written in
 * several calls, are one card on the history page) and 'automatic' (nobody was behind it: kept,
 * and folded away on the page until asked for). And valuesRewritten(): many values rewritten in
 * one statement, recorded without reading every record twice — see there.
 */
class Outside
{
    private $services;

    public function __construct($services)
    {
        $this->services = $services;
    }

    public static function fromServices($services): self
    {
        return new self($services);
    }

    /**
     * @param int[] $ids items, media or item sets the write changes
     * @return mixed what $write returned
     */
    public function around(array $ids, callable $write, string $note = '', array $opts = [])
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $ready = false;
        try {
            $conn = $this->services->get('Omeka\Connection');
            $settings = $this->services->get('Omeka\Settings');
            $reader = new StateReader($conn, (string) $settings->get('revision_history_accession_term', 'dcterms:identifier'));
            $watchman = new Watchman($conn, $settings);
            $base = self::opts((new Recorder($this->services))->moment(), $opts);
            $stale = $ids ? $watchman->beforeSave($ids) : [];
            $before = $ids ? $reader->read($ids) : [];
            $ready = true;
        } catch (\Throwable $e) {
            $this->failed('before', $e);
        }
        $result = $write();
        if (!$ready || !$ids) {
            return $result;
        }
        try {
            $after = $reader->read($ids);
            foreach ($stale as $sid) {
                $watchman->reportStale((int) $sid, $before[$sid] ?? null);
            }
            $store = new Store($conn);
            if ($note !== '') {
                $base['note'] = $note;
            }
            $gone = [];
            // the entries of one call in one transaction (1.2.1): one commit, not one per record
            $own = (int) $conn->getTransactionNestingLevel() === 0;
            if ($own) {
                $conn->beginTransaction();
            }
            foreach ($ids as $id) {
                $b = $before[$id] ?? null;
                $a = $after[$id] ?? null;
                if (!$b && !$a) {
                    continue;
                }
                $changes = Diff::changes($b, $a);
                if (!$changes) {
                    continue;
                }
                $action = !$b ? 'created' : (!$a ? 'deleted' : 'edited');
                $eventId = $store->write($base + self::about($action, $a ?: $b), $changes);
                if ($action === 'deleted') {
                    $store->snapshot($eventId, $b);
                    $gone[] = $id;
                }
            }
            if ($own) {
                $conn->commit();
                $own = false;
            }
            $watchman->afterSave(array_keys($after), $gone, $after);
        } catch (\Throwable $e) {
            if (!empty($own) && $conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->failed('after', $e);
        }
        return $result;
    }

    /** run and automatic, from $opts, on an entry's common part. */
    private static function opts(array $base, array $opts): array
    {
        $run = isset($opts['run']) && is_scalar($opts['run']) ? mb_substr(trim((string) $opts['run']), 0, 40) : '';
        if ($run !== '') {
            $base['run_key'] = $run;
        }
        if (!empty($opts['automatic'])) {
            $base['automatic'] = 1;
        }
        return $base;
    }

    /**
     * MANY VALUES REWRITTEN IN ONE STATEMENT, RECORDED AFTERWARDS (1.2.1).
     *
     * A module that rewrites thousands of values at once (a vocabulary import, its undo) and keeps
     * each value's old content itself hands this a query over that record. One entry per record is
     * written, with the same lines a save would have — the before is the record as it is now with
     * the logged values put back, the after is the record as it is now, and the history's own
     * comparison (Diff) says what changed. Records are read 500 at a time, once; the data check's
     * numbers are written down for all of them together.
     *
     * Call it right after the write, in the same request. $sql returns one row per value:
     *   value_id, resource_id, property_id, before_type, before_value
     * and, when the module kept them: before_uri, before_value_resource_id, before_lang, before_is_public
     * (a column left out keeps what the value holds now). A row whose before_type is NULL was a value
     * the write added. A value the write deleted is put back from the row (the columns left out are
     * empty, public).
     *
     * A record that had already changed without a record before this write is reported first, as
     * around() does — found by working out, from the logged rows, the number it had before.
     *
     * @return array{rows:int, records:int, entries:int, stale:int, ms:int}
     */
    public function valuesRewritten(string $sql, array $params = [], string $note = '', array $opts = []): array
    {
        $t0 = microtime(true);
        $stats = ['rows' => 0, 'records' => 0, 'entries' => 0, 'stale' => 0, 'ms' => 0];
        try {
            $conn = $this->services->get('Omeka\Connection');
            $settings = $this->services->get('Omeka\Settings');
            $reader = new StateReader($conn, (string) $settings->get('revision_history_accession_term', 'dcterms:identifier'));
            $watchman = new Watchman($conn, $settings);
            $store = new Store($conn);
            $base = self::opts((new Recorder($this->services))->moment(), $opts);
            if ($note !== '') {
                $base['note'] = $note;
            }
            $by = [];
            foreach ($conn->fetchAllAssociative($sql, $params) as $r) {
                $rid = (int) ($r['resource_id'] ?? 0);
                if ($rid > 0 && (int) ($r['value_id'] ?? 0) > 0 && (int) ($r['property_id'] ?? 0) > 0) {
                    $by[$rid][] = $r;
                    $stats['rows']++;
                }
            }
            if (!$by) {
                return $stats;
            }
            $stats['records'] = count($by);
            $pids = [];
            foreach ($by as $rows) {
                foreach ($rows as $r) {
                    $pids[(int) $r['property_id']] = true;
                }
            }
            $terms = [];
            foreach ($conn->fetchAllAssociative(
                "SELECT p.id, CONCAT(vo.prefix, ':', p.local_name) AS term FROM property p JOIN vocabulary vo ON vo.id = p.vocabulary_id WHERE p.id IN ("
                . implode(',', array_map('intval', array_keys($pids))) . ')'
            ) as $p) {
                $terms[(int) $p['id']] = (string) $p['term'];
            }
            $watching = $watchman->watching();
            $ids = array_keys($by);
            sort($ids);
            foreach (array_chunk($ids, 500) as $chunk) {
                $after = $reader->read($chunk);
                $vids = $reader->valueIds($chunk);
                $vidList = [];
                foreach ($chunk as $rid) {
                    foreach ($by[$rid] as $r) {
                        $vidList[] = (int) $r['value_id'];
                    }
                }
                $cur = [];
                foreach (array_chunk($vidList, 1000) as $vc) {
                    foreach ($conn->fetchAllAssociative(
                        'SELECT id, property_id, type, value, uri, value_resource_id, lang, is_public, value_annotation_id FROM value WHERE id IN (' . implode(',', $vc) . ')'
                    ) as $v) {
                        $cur[(int) $v['id']] = $v;
                    }
                }
                $now = $watching ? $watchman->hashesOf($chunk) : [];
                $stored = $watching ? $watchman->storedOf($chunk) : [];
                $written = [];
                // One transaction per 500 records, not one per record: a commit each is most of the time.
                $own = (int) $conn->getTransactionNestingLevel() === 0;
                if ($own) {
                    $conn->beginTransaction();
                }
                foreach ($chunk as $rid) {
                    if (!isset($after[$rid])) {
                        continue;
                    }
                    [$before, $known, $expected] = self::putBack($after[$rid], $vids[$rid] ?? [], $by[$rid], $cur, $terms, isset($now[$rid]) ? (int) $now[$rid] : null);
                    if ($watching && $known && $expected !== null && isset($stored[$rid]) && (string) $expected !== $stored[$rid]) {
                        $watchman->reportStale($rid, $before);
                        $stats['stale']++;
                    }
                    $changes = Diff::changes($before, $after[$rid]);
                    if (!$changes) {
                        continue;
                    }
                    $store->write($base + self::about('edited', $after[$rid]), $changes);
                    $stats['entries']++;
                    $written[] = $rid;
                }
                if ($own) {
                    $conn->commit();
                }
                if ($written) {
                    $watchman->afterSave($written, [], array_intersect_key($after, array_flip($written)));
                }
            }
        } catch (\Throwable $e) {
            if (!empty($own) && $conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->failed('valuesRewritten', $e);
        }
        $stats['ms'] = (int) round((microtime(true) - $t0) * 1000);
        return $stats;
    }

    /**
     * MANY VALUES REWRITTEN IN ONE STATEMENT, AS ONE ENTRY (1.2.2).
     *
     * The same question as valuesRewritten() — the same $sql over the module's own copy of each
     * value as it was, called right after the write — answered with ONE entry for the whole
     * statement instead of one per record. Each different change (field, the value before, the
     * value after) is one line of that entry, however many records it was made to; each record is
     * one row of revision_history_shared that says which lines are its own. So records whose
     * changes differ (another field, another term, a language, a link instead of words) keep
     * exactly their own lines: a record's page shows the entry with only its lines, and Restore
     * there puts back only those. Nothing is read record by record: the lines, the records and
     * their names are worked out in the database, and the data check's numbers of all of them are
     * read together and written down.
     *
     * The entry is a copy that reads on its own, like every other: what each line held, each
     * record's name as it was, who did it. Nothing of the calling module is read to show it.
     *
     * A record that had already changed without a record before this write is reported first, as
     * valuesRewritten() does.
     *
     * $opts: 'run', 'automatic' as for around().
     *
     * @return array{rows:int, records:int, lines:int, entry:int, stale:int, ms:int}
     */
    public function valuesShared(string $sql, array $params = [], string $note = '', array $opts = []): array
    {
        $t0 = microtime(true);
        $stats = ['rows' => 0, 'records' => 0, 'lines' => 0, 'entry' => 0, 'stale' => 0, 'ms' => 0];
        $tmp = 'rh_shared_' . bin2hex(random_bytes(4));
        $map = $tmp . '_l';
        $own = false;
        $conn = null;
        try {
            $conn = $this->services->get('Omeka\Connection');
            $settings = $this->services->get('Omeka\Settings');
            $first = $conn->fetchAssociative("SELECT * FROM ($sql) q LIMIT 1", $params);
            if (!$first) {
                return $stats;
            }
            // what the module kept of each value; a column it did not keep holds what the value holds now
            $before = [];
            foreach (['value' => 'value', 'uri' => 'uri', 'value_resource_id' => 'vr', 'lang' => 'lang', 'is_public' => 'pub'] as $col => $short) {
                $before[$short] = array_key_exists('before_' . $col, $first) ? "q.before_$col" : "v.$col";
            }
            $conn->executeStatement("CREATE TEMPORARY TABLE $tmp (
                value_id INT NOT NULL PRIMARY KEY, resource_id INT NOT NULL, property_id INT NOT NULL, k CHAR(32) NOT NULL DEFAULT '',
                b_type VARCHAR(190) NULL, b_value LONGTEXT NULL, b_uri LONGTEXT NULL, b_vr INT NULL, b_lang VARCHAR(190) NULL, b_pub TINYINT NULL,
                is_now TINYINT NOT NULL, a_type VARCHAR(190) NULL, a_value LONGTEXT NULL, a_uri LONGTEXT NULL, a_vr INT NULL, a_lang VARCHAR(190) NULL,
                a_pub TINYINT NULL, a_ann INT NULL, KEY k_k (k), KEY k_r (resource_id)) ENGINE = InnoDB");
            $was = 'q.before_type IS NOT NULL';
            $stats['rows'] = (int) $conn->executeStatement("INSERT IGNORE INTO $tmp
                SELECT q.value_id, q.resource_id, q.property_id, '',
                       q.before_type, IF($was, {$before['value']}, NULL), IF($was, {$before['uri']}, NULL), IF($was, {$before['vr']}, NULL),
                       IF($was, {$before['lang']}, NULL), IF($was, COALESCE({$before['pub']}, 1), NULL),
                       v.id IS NOT NULL, v.type, v.value, v.uri, v.value_resource_id, v.lang, v.is_public, v.value_annotation_id
                  FROM ($sql) q LEFT JOIN value v ON v.id = q.value_id
                 WHERE q.value_id > 0 AND q.resource_id > 0 AND q.property_id > 0", $params);
            // one key per different change; NULL and '' are told apart here and made one below (sig)
            $z = "CHAR(0)";
            $conn->executeStatement("UPDATE $tmp SET k = MD5(CONCAT_WS(CHAR(31), property_id,
                COALESCE(b_type, $z), COALESCE(b_value, $z), COALESCE(b_uri, $z), COALESCE(b_vr, $z), COALESCE(b_lang, $z), COALESCE(b_pub, $z),
                IF(is_now, COALESCE(a_type, $z), $z), IF(is_now, COALESCE(a_value, $z), $z), IF(is_now, COALESCE(a_uri, $z), $z),
                IF(is_now, COALESCE(a_vr, $z), $z), IF(is_now, COALESCE(a_lang, $z), $z), IF(is_now, COALESCE(a_pub, $z), $z)))");

            $terms = [];
            $lines = [];
            $ordOf = [];
            $byKey = [];
            foreach ($conn->fetchAllAssociative("SELECT k, MIN(property_id) AS property_id, MIN(b_type) AS b_type, MIN(b_value) AS b_value,
                    MIN(b_uri) AS b_uri, MIN(b_vr) AS b_vr, MIN(b_lang) AS b_lang, MIN(b_pub) AS b_pub, MIN(is_now) AS is_now, MIN(a_type) AS a_type,
                    MIN(a_value) AS a_value, MIN(a_uri) AS a_uri, MIN(a_vr) AS a_vr, MIN(a_lang) AS a_lang, MIN(a_pub) AS a_pub
                    FROM $tmp GROUP BY k ORDER BY MIN(value_id)") as $g) {
                $pid = (int) $g['property_id'];
                if (!isset($terms[$pid])) {
                    $terms[$pid] = (string) $conn->fetchOne(
                        "SELECT CONCAT(vo.prefix, ':', p.local_name) FROM property p JOIN vocabulary vo ON vo.id = p.vocabulary_id WHERE p.id = ?", [$pid]);
                }
                if ($terms[$pid] === '') {
                    continue;
                }
                $b = $g['b_type'] === null ? null : StateReader::sig(['type' => $g['b_type'], 'value' => $g['b_value'], 'uri' => $g['b_uri'],
                    'value_resource_id' => $g['b_vr'], 'lang' => $g['b_lang'], 'is_public' => $g['b_pub']], []);
                $a = !(int) $g['is_now'] ? null : StateReader::sig(['type' => $g['a_type'], 'value' => $g['a_value'], 'uri' => $g['a_uri'],
                    'value_resource_id' => $g['a_vr'], 'lang' => $g['a_lang'], 'is_public' => $g['a_pub']], []);
                if ($b === $a) {
                    continue;   // matched by the module's query, not changed by its write
                }
                $op = $b === null ? 'added' : ($a === null ? 'removed' : 'edited');
                $line = json_encode([$pid, $op, $b, $a], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!isset($ordOf[$line])) {
                    $lines[] = ['op' => $op, 'field' => $terms[$pid], 'property_id' => $pid, 'before' => $b, 'after' => $a];
                    $ordOf[$line] = count($lines);
                }
                $byKey[$g['k']] = $ordOf[$line];
            }
            if (!$lines) {
                return $stats;
            }
            $stats['lines'] = count($lines);
            $conn->executeStatement("CREATE TEMPORARY TABLE $map (k CHAR(32) NOT NULL PRIMARY KEY, ord INT NOT NULL) ENGINE = InnoDB");
            foreach (array_chunk($byKey, 500, true) as $chunk) {
                $marks = [];
                $p = [];
                foreach ($chunk as $k => $ord) {
                    $marks[] = '(?,?)';
                    array_push($p, (string) $k, (int) $ord);
                }
                $conn->executeStatement("INSERT INTO $map (k, ord) VALUES " . implode(',', $marks), $p);
            }

            // the data check: each record's number now, and the one it had before this write
            // (its number now with each changed value put back), against the one written down
            $watchman = new Watchman($conn, $settings);
            $watching = $watchman->watching();
            $ids = array_map('intval', $conn->fetchFirstColumn("SELECT DISTINCT c.resource_id FROM $tmp c JOIN $map l ON l.k = c.k ORDER BY c.resource_id"));
            $stats['records'] = count($ids);
            $now = $watching ? $watchman->hashesOf($ids) : [];
            if ($watching) {
                $stored = $watchman->storedOf($ids);
                $expected = [];
                $unknown = [];
                $rows = $conn->executeQuery("SELECT c.* FROM $tmp c JOIN $map l ON l.k = c.k");
                while ($r = $rows->fetchAssociative()) {
                    $rid = (int) $r['resource_id'];
                    if (!isset($now[$rid])) {
                        continue;
                    }
                    $vid = (int) $r['value_id'];
                    $h = $expected[$rid] ?? (int) $now[$rid];
                    if ((int) $r['is_now']) {
                        $h ^= self::rowHash($vid, ['property_id' => $r['property_id'], 'type' => $r['a_type'], 'value' => $r['a_value'], 'uri' => $r['a_uri'],
                            'value_resource_id' => $r['a_vr'], 'lang' => $r['a_lang'], 'is_public' => $r['a_pub'], 'value_annotation_id' => $r['a_ann']]);
                    } else {
                        $unknown[$rid] = true;   // deleted: its note, if it had one, was not kept
                    }
                    if ($r['b_type'] !== null) {
                        $h ^= self::rowHash($vid, ['property_id' => $r['property_id'], 'type' => $r['b_type'], 'value' => $r['b_value'], 'uri' => $r['b_uri'],
                            'value_resource_id' => $r['b_vr'], 'lang' => $r['b_lang'], 'is_public' => $r['b_pub'], 'value_annotation_id' => $r['a_ann']]);
                    }
                    $expected[$rid] = $h;
                }
                $stale = [];
                foreach ($expected as $rid => $h) {
                    if (!isset($unknown[$rid]) && isset($stored[$rid]) && (string) $h !== $stored[$rid]) {
                        $stale[] = $rid;
                    }
                }
                if ($stale) {
                    $this->reportStaleShared($conn, $settings, $watchman, $tmp, $map, $stale, $terms);
                    $stats['stale'] = count($stale);
                }
            }

            // the entry: one row, its lines, and one row per record, all or nothing
            $reader = new StateReader($conn, (string) $settings->get('revision_history_accession_term', 'dcterms:identifier'));
            $head = $reader->read([$ids[0]])[$ids[0]] ?? null;
            if (!$head) {
                return $stats;
            }
            $base = self::opts((new Recorder($this->services))->moment(), $opts);
            if ($note !== '') {
                $base['note'] = $note;
            }
            $base['shared'] = count($ids);
            $own = (int) $conn->getTransactionNestingLevel() === 0;
            if ($own) {
                $conn->beginTransaction();
            }
            $store = new Store($conn);
            $eventId = $store->write($base + self::about('edited', $head), $lines);
            $names = self::nameFields($conn, $settings);
            $len = (int) $conn->fetchOne('SELECT @@SESSION.group_concat_max_len');
            $conn->executeStatement('SET SESSION group_concat_max_len = 16777216');
            try {
                $conn->executeStatement("INSERT INTO revision_history_shared (event_id, resource_id, resource_kind, item_id, title, accession, name, ords)
                    SELECT ?, c.resource_id,
                           CASE r.resource_type WHEN 'Omeka\\\\Entity\\\\Item' THEN 'item' WHEN 'Omeka\\\\Entity\\\\Media' THEN 'media' ELSE 'item_set' END,
                           CASE r.resource_type WHEN 'Omeka\\\\Entity\\\\Item' THEN r.id WHEN 'Omeka\\\\Entity\\\\Media' THEN m.item_id ELSE NULL END,
                           r.title,
                           COALESCE((SELECT LEFT(n.value, 190) FROM value n WHERE n.resource_id = c.resource_id AND n.property_id = ? AND n.value <> '' ORDER BY n.id LIMIT 1), ''),
                           (SELECT LEFT(n.value, 1000) FROM value n WHERE n.resource_id = c.resource_id AND n.property_id = ? AND n.value <> '' ORDER BY n.id LIMIT 1),
                           GROUP_CONCAT(l.ord ORDER BY c.value_id)
                      FROM $tmp c JOIN $map l ON l.k = c.k JOIN resource r ON r.id = c.resource_id LEFT JOIN media m ON m.id = r.id
                     WHERE r.resource_type IN ('Omeka\\\\Entity\\\\Item', 'Omeka\\\\Entity\\\\Media', 'Omeka\\\\Entity\\\\ItemSet')
                     GROUP BY c.resource_id, r.resource_type, r.title, m.item_id",
                    [$eventId, $names['accession'], $names['name']]);
            } finally {
                $conn->executeStatement('SET SESSION group_concat_max_len = ' . max(1024, $len));
            }
            if ($own) {
                $conn->commit();
                $own = false;
            }
            $stats['entry'] = $eventId;
            $watchman->afterShared($now);
        } catch (\Throwable $e) {
            if ($own && $conn && $conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->failed('valuesShared', $e);
        } finally {
            if ($conn) {
                foreach ([$tmp, $map] as $t) {
                    try {
                        $conn->executeStatement("DROP TEMPORARY TABLE IF EXISTS $t");
                    } catch (\Throwable $ignored) {
                    }
                }
            }
        }
        $stats['ms'] = (int) round((microtime(true) - $t0) * 1000);
        return $stats;
    }

    /** The property ids of the two fields a record is named by (0 when not set). */
    private static function nameFields($conn, $settings): array
    {
        $out = [];
        foreach (['accession' => ['revision_history_accession_term', 'dcterms:identifier'], 'name' => ['revision_history_title_term', 'dcterms:title']] as $k => [$key, $default]) {
            [$prefix, $local] = array_pad(explode(':', (string) $settings->get($key, $default), 2), 2, '');
            $out[$k] = $prefix === '' || $local === '' ? 0 : (int) $conn->fetchOne(
                'SELECT p.id FROM property p JOIN vocabulary vo ON vo.id = p.vocabulary_id WHERE vo.prefix = ? AND p.local_name = ?', [$prefix, $local]);
        }
        return $out;
    }

    /** The few records valuesShared() found already changed: each reported with what it held just before. */
    private function reportStaleShared($conn, $settings, Watchman $watchman, string $tmp, string $map, array $stale, array $terms): void
    {
        $reader = new StateReader($conn, (string) $settings->get('revision_history_accession_term', 'dcterms:identifier'));
        foreach (array_chunk($stale, 500) as $chunk) {
            $after = $reader->read($chunk);
            $vids = $reader->valueIds($chunk);
            $by = [];
            $cur = [];
            foreach ($conn->fetchAllAssociative("SELECT c.* FROM $tmp c JOIN $map l ON l.k = c.k WHERE c.resource_id IN (" . implode(',', $chunk) . ')') as $r) {
                $by[(int) $r['resource_id']][] = ['value_id' => $r['value_id'], 'resource_id' => $r['resource_id'], 'property_id' => $r['property_id'],
                    'before_type' => $r['b_type'], 'before_value' => $r['b_value'], 'before_uri' => $r['b_uri'], 'before_value_resource_id' => $r['b_vr'],
                    'before_lang' => $r['b_lang'], 'before_is_public' => $r['b_pub']];
                if ((int) $r['is_now']) {
                    $cur[(int) $r['value_id']] = ['id' => $r['value_id'], 'property_id' => $r['property_id'], 'type' => $r['a_type'], 'value' => $r['a_value'],
                        'uri' => $r['a_uri'], 'value_resource_id' => $r['a_vr'], 'lang' => $r['a_lang'], 'is_public' => $r['a_pub'], 'value_annotation_id' => $r['a_ann']];
                }
            }
            foreach ($chunk as $rid) {
                if (isset($after[$rid])) {
                    [$before] = self::putBack($after[$rid], $vids[$rid] ?? [], $by[$rid] ?? [], $cur, $terms, null);
                    $watchman->reportStale($rid, $before);
                }
            }
        }
    }

    /**
     * A record as it was before the logged rows were written: its state now, each logged value put
     * back where it stands (by id, as StateReader orders them). Also the data check's number it had
     * then, worked out from its number now; $known is false when a deleted value's whole row was not
     * logged, and that number cannot be worked out.
     *
     * @return array{0: array, 1: bool, 2: ?int}
     */
    private static function putBack(array $state, array $vids, array $rows, array $cur, array $terms, ?int $hash): array
    {
        $known = $hash !== null;
        $blank = function ($x) {
            return ($x === null || $x === '') ? null : (string) $x;
        };
        foreach ($rows as $r) {
            $vid = (int) $r['value_id'];
            $pid = (int) $r['property_id'];
            $term = $terms[$pid] ?? null;
            if ($term === null) {
                continue;
            }
            $wasThere = ($r['before_type'] ?? null) !== null;
            $c = $cur[$vid] ?? null;
            $old = null;
            if ($wasThere) {
                $old = [
                    'type' => (string) $r['before_type'],
                    'value' => array_key_exists('before_value', $r) ? $r['before_value'] : ($c['value'] ?? null),
                    'uri' => array_key_exists('before_uri', $r) ? $r['before_uri'] : ($c['uri'] ?? null),
                    'value_resource_id' => array_key_exists('before_value_resource_id', $r) ? $r['before_value_resource_id'] : ($c['value_resource_id'] ?? null),
                    'lang' => array_key_exists('before_lang', $r) ? $r['before_lang'] : ($c['lang'] ?? null),
                    'is_public' => array_key_exists('before_is_public', $r) ? $r['before_is_public'] : ($c['is_public'] ?? 1),
                ];
            }
            $list = $vids[$term] ?? [];
            if ($c) {
                $pos = array_search($vid, $list, true);
                if ($pos === false) {
                    continue;
                }
                $sigNow = $state['values'][$term][$pos];
                if ($known) {
                    $hash ^= self::rowHash($vid, $c);
                }
                if ($old === null) {
                    array_splice($state['values'][$term], $pos, 1);
                    array_splice($list, $pos, 1);
                    if (!$state['values'][$term]) {
                        unset($state['values'][$term], $state['props'][$term]);
                    }
                } else {
                    $sig = StateReader::sig($old, []);
                    if (isset($sigNow['note'])) {
                        $sig['note'] = $sigNow['note'];
                    }
                    $state['values'][$term][$pos] = $sig;
                    if ($known) {
                        $hash ^= self::rowHash($vid, ['property_id' => $c['property_id'], 'value_annotation_id' => $c['value_annotation_id']] + $old);
                    }
                }
            } elseif ($old !== null) {
                // deleted by the write: put back in its place by id
                $pos = 0;
                while ($pos < count($list) && $list[$pos] < $vid) {
                    $pos++;
                }
                $state['values'][$term] = $state['values'][$term] ?? [];
                array_splice($state['values'][$term], $pos, 0, [StateReader::sig($old, [])]);
                array_splice($list, $pos, 0, [$vid]);
                $state['props'][$term] = $pid;
                $known = false;
            }
            $vids[$term] = $list;
        }
        return [$state, $known, $known ? $hash : null];
    }

    /** One value's part of the data check's number, as Watchman's query works it out. */
    private static function rowHash(int $vid, array $v): int
    {
        $s = implode("\t", [$vid, (int) $v['property_id'], (string) $v['type'], (string) ($v['value'] ?? ''), (string) ($v['uri'] ?? ''),
            (string) ($v['value_resource_id'] ?? ''), (string) ($v['lang'] ?? ''), (int) $v['is_public'], (string) ($v['value_annotation_id'] ?? '')]);
        return (int) hexdec(substr(md5($s), 0, 15));
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

    private function failed(string $when, \Throwable $e): void
    {
        try {
            $this->services->get('Omeka\Logger')->err('RevisionHistory: a write from outside Omeka could not be recorded (' . $when . '): ' . $e->getMessage());
        } catch (\Throwable $ignored) {
        }
    }
}
