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
