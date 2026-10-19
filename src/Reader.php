<?php
namespace RevisionHistory;

use Doctrine\DBAL\Connection;

/**
 * Asks the history the five questions, and nothing else:
 * who created an item · who deleted one · who added a value ·
 * who removed a value · who edited a value — and when.
 */
class Reader
{
    const PAGE = 40;

    /** How many saves of one run are listed under its card; the count says how many there are. */
    const RUN_SHOWN = 500;

    /** How many different changes one run may list with their values before it says only which fields. */
    const RUN_SUM = 20;

    /**
     * What makes one card out of several entries: one run of Batch Values, or one API key
     * on one day when all the entry says is that the key was used (Omeka writes its
     * "lastAccessed" on every call). Everything else stands on its own.
     */
    const GROUP = "CASE
        WHEN e.resource_kind = 'API key' AND NOT EXISTS (
            SELECT 1 FROM revision_history_change c9 WHERE c9.event_id = e.id AND c9.field <> 'lastAccessed')
        THEN CONCAT('ak:', COALESCE(e.title, ''), ':', COALESCE(e.user_id, 0), ':', DATE(e.happened_at))
        ELSE e.run_key END";

    const WHAT = ['all', 'created', 'deleted', 'added', 'removed', 'edited'];

    /** @var Connection */
    private $conn;

    /** @var string the field holding the title people read */
    private $titleTerm;

    public function __construct(Connection $conn, string $titleTerm = 'dcterms:title')
    {
        $this->conn = $conn;
        $this->titleTerm = $titleTerm;
    }

    /**
     * @param array $f what · who · from · to (Y-m-d, local) · item · field · kind · after (cursor)
     * @param string $tz the archive's time zone, for turning local days into UTC
     * @return array{events: array, next: ?string}
     */
    public function events(array $f, string $tz, int $limit = self::PAGE, bool $runs = false): array
    {
        $where = ['1=1'];
        $p = [];

        // Value annotations were written down on their own by 1.1.0 to 1.5.0, when
        // Doctrine handed them over under a stand-in class name. Omeka stamps every one
        // of an item's value annotations with a new time on every save of that item,
        // changed or not, so those rows say nothing; what an annotation said, before and
        // after, is on the item's own card. The one kind still worth showing: a value
        // annotation deleted in a save that left no card for the item.
        $where[] = "NOT (e.resource_kind = 'ValueAnnotation' AND (e.action <> 'deleted'
            OR (e.save_key <> '' AND EXISTS (SELECT 1 FROM revision_history_event x WHERE x.save_key = e.save_key AND x.resource_kind = 'item'))))";
        $what = in_array($f['what'] ?? 'all', self::WHAT, true) ? ($f['what'] ?? 'all') : 'all';
        $field = trim((string) ($f['field'] ?? ''));

        if ($what === 'created' || $what === 'deleted') {
            $where[] = 'e.action = ?';
            $p[] = $what;
        } elseif ($what !== 'all') {
            $where[] = "e.action = 'edited'";
            $ops = $what === 'edited' ? ['edited', 'order'] : [$what];
            $in = implode(',', array_fill(0, count($ops), '?'));
            if ($field !== '') {
                $where[] = "EXISTS (SELECT 1 FROM revision_history_change c WHERE c.event_id = e.id AND c.op IN ($in) AND c.field = ?)";
                $p = array_merge($p, $ops, [$field]);
                $field = '';
            } else {
                $where[] = $what === 'added' ? 'e.n_added > 0' : ($what === 'removed' ? 'e.n_removed > 0' : 'e.n_edited > 0');
            }
        }
        if ($field !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM revision_history_change c WHERE c.event_id = e.id AND c.field = ?)';
            $p[] = $field;
        }

        $who = (string) ($f['who'] ?? '');
        if ($who !== '') {
            if (ctype_digit($who)) {
                $where[] = 'e.user_id = ?';
                $p[] = (int) $who;
            } elseif ($who === 'nobody') {
                $where[] = 'e.user_id IS NULL';
            }
        }

        foreach (['from' => '>=', 'to' => '<'] as $key => $cmp) {
            $day = (string) ($f[$key] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                try {
                    $d = new \DateTime($day . ' 00:00:00', new \DateTimeZone($tz));
                    if ($key === 'to') {
                        $d->modify('+1 day');
                    }
                    $d->setTimezone(new \DateTimeZone('UTC'));
                    $where[] = "e.happened_at $cmp ?";
                    $p[] = $d->format('Y-m-d H:i:s');
                } catch (\Exception $e) {
                }
            }
        }

        $item = trim((string) ($f['item'] ?? ''));
        if ($item !== '') {
            if (ctype_digit($item)) {
                $where[] = "((e.resource_kind IN ('item','media','item_set') AND (e.resource_id = ? OR e.item_id = ?)) OR (e.resource_kind = 'ValueAnnotation' AND e.via_detail = ?))";
                $p[] = (int) $item;
                $p[] = (int) $item;
                $p[] = '/admin/item/' . (int) $item . '/edit';
            } else {
                $like = '%' . addcslashes($item, '%_\\') . '%';
                [$prefix, $local] = array_pad(explode(':', $this->titleTerm, 2), 2, '');
                // Omeka's own title (here the accession number), or the title field —
                // as it is now, or as it was when the resource was deleted.
                $where[] = "(e.accession LIKE ? OR e.title LIKE ?
                    OR e.resource_id IN (SELECT v.resource_id FROM value v
                        JOIN property pr ON pr.id = v.property_id JOIN vocabulary vo ON vo.id = pr.vocabulary_id
                        WHERE vo.prefix = ? AND pr.local_name = ? AND v.value LIKE ?)
                    OR e.resource_id IN (SELECT de.resource_id FROM revision_history_event de JOIN revision_history_change dc ON dc.event_id = de.id
                        WHERE de.action = 'deleted' AND dc.field = ? AND dc.before_json LIKE ?))";
                array_push($p, addcslashes($item, '%_\\') . '%', $like, $prefix, $local, $like, $this->titleTerm, $like);
            }
        }
        if (!empty($f['resource'])) {
            $where[] = "((e.resource_kind IN ('item','media','item_set') AND (e.resource_id = ? OR e.item_id = ?)) OR (e.resource_kind = 'ValueAnnotation' AND e.via_detail = ?))";
            $p[] = (int) $f['resource'];
            $p[] = (int) $f['resource'];
            $p[] = '/admin/item/' . (int) $f['resource'] . '/edit';
        }

        $kind = (string) ($f['kind'] ?? '');
        if ($kind === 'resources') {
            // The archive itself, without the things around it.
            $where[] = "e.resource_kind IN ('item', 'media', 'item_set')";
        } elseif ($kind === 'around') {
            $where[] = "e.resource_kind NOT IN ('item', 'media', 'item_set')";
        } elseif (in_array($kind, ['item', 'media', 'item_set', 'vocabsync'], true)) {
            $where[] = 'e.resource_kind = ?';
            $p[] = $kind;
        }

        // What belongs together on one card: one run of Batch Values, and — for the rows
        // Omeka writes every time an API key is used (only its "lastAccessed") — one key
        // on one day. Nothing is left out of the history; it is read as one card.
        $filters = $where;
        $filterParams = $p;
        if ($runs) {
            $where[] = '(' . self::GROUP . " = '' OR NOT EXISTS (SELECT 1 FROM revision_history_event e2 WHERE "
                . self::other(self::GROUP) . ' = ' . self::GROUP . "
                AND (e2.happened_at > e.happened_at OR (e2.happened_at = e.happened_at AND e2.id > e.id))
                AND " . self::other(implode(' AND ', $filters)) . '))';
            $p = array_merge($p, $filterParams);
        }

        if (!empty($f['after']) && preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d(?:\.\d+)?)\|(\d+)$/', (string) $f['after'], $m)) {
            $where[] = '(e.happened_at < ? OR (e.happened_at = ? AND e.id < ?))';
            $p[] = $m[1];
            $p[] = $m[1];
            $p[] = (int) $m[2];
        }

        $rows = $this->conn->fetchAllAssociative(
            'SELECT e.*, ' . self::GROUP . ' AS grp FROM revision_history_event e WHERE ' . implode(' AND ', $where)
            . ' ORDER BY e.happened_at DESC, e.id DESC LIMIT ' . ($limit + 1),
            $p
        );
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $last = end($rows);
            $next = $last['happened_at'] . '|' . $last['id'];
        }

        $byId = [];
        foreach ($rows as $r) {
            $r['changes'] = [];
            $r = self::annotationItem($r);
            $byId[(int) $r['id']] = $r;
        }
        if ($byId) {
            $in = implode(',', array_keys($byId));
            foreach ($this->conn->fetchAllAssociative(
                "SELECT event_id, op, field, property_id, place_before, place_after, size_before, size_after, before_json, after_json FROM revision_history_change WHERE event_id IN ($in) ORDER BY event_id, ord"
            ) as $c) {
                $c['before'] = $c['before_json'] === null ? null : json_decode($c['before_json'], true);
                $c['after'] = $c['after_json'] === null ? null : json_decode($c['after_json'], true);
                unset($c['before_json'], $c['after_json']);
                $byId[(int) $c['event_id']]['changes'][] = $c;
            }
        }
        if ($runs) {
            foreach ($byId as $id => $r) {
                if (($r['grp'] ?? '') === '') {
                    continue;
                }
                $byId[$id]['run_touch'] = strpos((string) $r['grp'], 'ak:') === 0;
                $byId[$id]['run'] = $this->conn->fetchAllAssociative(
                    'SELECT e.* FROM revision_history_event e WHERE ' . implode(' AND ', $filters)
                    . ' AND ' . self::GROUP . ' = ? AND e.id <> ? ORDER BY e.happened_at DESC, e.id DESC LIMIT ' . self::RUN_SHOWN,
                    array_merge($filterParams, [$r['grp'], $id])
                );
                $byId[$id]['run_sum'] = $byId[$id]['run_touch'] ? [] : $this->runSum($filters, $filterParams, (string) $r['grp']);
                $members = [];
                foreach ($byId[$id]['run'] as $i => $m) {
                    $byId[$id]['run'][$i]['changes'] = [];
                    $members[(int) $m['id']] = $i;
                }
                if ($members) {
                    foreach ($this->conn->fetchAllAssociative(
                        'SELECT event_id, op, field, property_id, place_before, place_after, size_before, size_after, before_json, after_json FROM revision_history_change WHERE event_id IN ('
                        . implode(',', array_keys($members)) . ') ORDER BY event_id, ord'
                    ) as $c) {
                        $c['before'] = $c['before_json'] === null ? null : json_decode($c['before_json'], true);
                        $c['after'] = $c['after_json'] === null ? null : json_decode($c['after_json'], true);
                        unset($c['before_json'], $c['after_json']);
                        $byId[$id]['run'][$members[(int) $c['event_id']]]['changes'][] = $c;
                    }
                }
                $byId[$id]['run_total'] = count($byId[$id]['run']) < self::RUN_SHOWN
                    ? count($byId[$id]['run'])
                    : (int) $this->conn->fetchOne(
                        'SELECT COUNT(*) FROM revision_history_event e WHERE ' . implode(' AND ', $filters) . ' AND ' . self::GROUP . ' = ? AND e.id <> ?',
                        array_merge($filterParams, [$r['grp'], $id])
                    );
            }
        }
        return ['events' => array_values($byId), 'next' => $next, 'what' => $what];
    }

    /**
     * What one run did, over every resource in it (not only the ones listed): each
     * change once, with the number of resources it was made to. A reordering is the
     * same change wherever it was made, whatever the order was. When the run made too
     * many different changes to list, each field says only what was done to it.
     *
     * @return array<int, array{op:string, field:string, property_id:?int, before:?array, after:?array, n:int, values:bool}>
     */
    public function runSum(array $filters, array $params, string $runKey): array
    {
        $params[] = $runKey;
        $result = $this->conn->executeQuery(
            'SELECT e.id, c.op, c.field, c.property_id, c.ord, c.before_json, c.after_json
               FROM revision_history_event e JOIN revision_history_change c ON c.event_id = e.id
              WHERE ' . implode(' AND ', $filters) . ' AND ' . self::GROUP . ' = ?
              ORDER BY c.ord, e.id',
            $params
        );
        $full = [];
        $byField = [];
        while ($r = $result->fetchAssociative()) {
            // The whole field, kept so one save's card can draw it. A round of Batch
            // Values over a hundred items would put a hundred copies of it in the
            // summary, saying nothing the lines beside it do not already say.
            if ($r['op'] === 'field') {
                continue;
            }
            $b = $r['before_json'] === null ? null : json_decode($r['before_json'], true);
            $a = $r['after_json'] === null ? null : json_decode($r['after_json'], true);
            [$b, $a] = self::sameNote($b, $a);
            $f = $r['op'] . '|' . $r['field'];
            $k = $r['op'] === 'order' ? $f : $f . '|' . json_encode([$b, $a], JSON_UNESCAPED_UNICODE);
            foreach ([[&$full, $k, $b, $a], [&$byField, $f, null, null]] as $t) {
                $list = &$t[0];
                if (!isset($list[$t[1]])) {
                    $list[$t[1]] = [
                        'op' => $r['op'],
                        'field' => $r['field'],
                        'property_id' => $r['property_id'] === null ? null : (int) $r['property_id'],
                        'before' => $r['op'] === 'order' ? null : $t[2],
                        'after' => $r['op'] === 'order' ? null : $t[3],
                        'ids' => [],
                    ];
                }
                $list[$t[1]]['ids'][(int) $r['id']] = true;
                unset($list);
            }
        }
        $values = count($full) <= self::RUN_SUM;
        $out = [];
        foreach ($values ? $full : $byField as $x) {
            $x['n'] = count($x['ids']);
            $x['values'] = $values && $x['op'] !== 'order';
            unset($x['ids']);
            $out[] = $x;
        }
        return $out;
    }

    /**
     * A value annotation that was the same before and after is not part of the change:
     * "B → B replaced" is one change, whether or not some of the values had a note.
     */
    public static function sameNote($b, $a): array
    {
        if (is_array($b) && is_array($a) && isset($b['note'], $a['note']) && $b['note'] == $a['note']) {
            unset($b['note'], $a['note']);
        }
        return [$b, $a];
    }

    /**
     * The same conditions, said of another row of the history (e2) instead of e.
     * Only the alias "e." on its own is changed: "de." and the other aliases are left.
     */
    public static function other(string $sql): string
    {
        return preg_replace('/(?<![A-Za-z0-9_`])e\./', 'e2.', $sql);
    }

    /**
     * Which item a value annotation row belongs to. Those rows were written without it;
     * the page it was saved from still says: /admin/item/<id>/edit.
     */
    public static function annotationItem(array $r): array
    {
        if (($r['resource_kind'] ?? '') === 'ValueAnnotation' && empty($r['item_id'])
            && preg_match('#^/admin/item/(\d+)/edit#', (string) ($r['via_detail'] ?? ''), $m)
        ) {
            $r['item_id'] = (int) $m[1];
        }
        return $r;
    }

    /** Everyone who appears in the history, including people whose accounts are gone. */
    public function people(): array
    {
        return $this->conn->fetchAllAssociative(
            "SELECT e.user_id, MAX(e.user_name) AS name, COUNT(*) AS n
               FROM revision_history_event e
              GROUP BY e.user_id
              ORDER BY MAX(e.user_name)"
        );
    }

    /** Every field the history has a change for. */
    public function fields(): array
    {
        return $this->conn->fetchFirstColumn('SELECT DISTINCT field FROM revision_history_change ORDER BY field');
    }

    public function summary(): array
    {
        $row = $this->conn->fetchAssociative(
            "SELECT COUNT(*) AS n, MIN(happened_at) AS first_at,
                    SUM(source = 'here') AS here, MIN(CASE WHEN source = 'here' THEN happened_at END) AS here_from
               FROM revision_history_event"
        );
        return $row ?: [];
    }
}
