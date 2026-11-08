<?php
namespace RevisionHistory;

use RevisionHistory\Capture\Recorder;

/**
 * WHAT ANOTHER MODULE SAYS HAPPENED TO A RECORD (1.2.0).
 *
 * The history hears every value that is saved. Some things that happen to a record are not a
 * value: a work came back from the conservator, a person checked that it is in the right drawer,
 * a set was moved in a tree. The module that did it can write it into this history, so the
 * record's page shows everything in one place:
 *
 *   if (class_exists(\RevisionHistory\Entries::class)) {
 *       \RevisionHistory\Entries::fromServices($services)->add([
 *           'resource' => 123,                  // an item, media or item set
 *           'module'   => 'MyModule',           // the module's folder name
 *           'label'    => 'Conservation',       // the word on the card and the filter
 *           'kind'     => 'returned',           // the module's own word, a-z 0-9 . _ -
 *           'kind_label' => 'Back in storage',  // that word as people read it
 *           'summary'  => 'Back in storage after treatment',
 *           'before'   => 'At the conservator', // optional, text
 *           'after'    => 'Drawer 4',           // optional, text
 *           'details'  => ['Treatment' => 'flattening'], // optional, label => text
 *           'link'     => '/admin/my-module/42',          // optional, "Open in …"
 *           'undo'     => '/admin/my-module/42#undo',     // optional, "Undo in …"
 *           'ref'      => 'my_log:42',          // the module's own row: one entry per ref
 *       ]);
 *   }
 *
 * A module that does not want to depend on this class sends the same keys as the event
 * `history.entry` on the identifier `HistoryEntry` (see the README); nothing happens when no
 * module listens.
 *
 * EVERY ENTRY IS A COPY THAT READS ON ITS OWN. The words, the two sides, the details, the name of
 * the person and of the record are written here as they are at that moment. Nothing on the
 * history page reads the module's tables, code or translations: when the module is uninstalled,
 * or the record deleted, the entry still says what happened. The links are an extra; a link to a
 * module that is no longer there is drawn as "module removed".
 *
 * Who did it, when, and how the request came are filled in here, exactly as for a save. A module
 * cannot set them. Only a script run from the command line that copies an old log may give the
 * time and the person of each old row ('at', 'by_id', 'by_name', 'by_email', with a 'ref').
 *
 * Something that happened with nobody behind it (a scheduled run, a file made again) is
 * 'automatic' => true, and 'actor' names the job or script. It is kept like everything else and
 * folded away on the page until asked for.
 *
 * add() never throws. If the entry cannot be written, the module's work stands and the failure
 * is logged. Inside the module's own transaction the entry is part of it: if the work is rolled
 * back, so is the entry.
 */
class Entries
{
    const KINDS = ['items' => 'item', 'media' => 'media', 'item_sets' => 'item_set'];

    /** How much one entry may carry. More is cut, and the cut is said. */
    const MAX_SUMMARY = 500;
    const MAX_SIDE = 16000;
    const MAX_DETAILS = 32000;

    /** More than this many in one request, and the rest are one entry that says how many. */
    const MAX_PER_REQUEST = 5000;

    private $services;

    /** @var PdoConnection|null the light way in (beside()) */
    private $pdo;

    /** @var array|null who and how, given by the code beside Omeka (beside()) */
    private $given;

    /** @var int entries written in this request */
    private static $written = 0;
    private static $folded = [];

    public function __construct($services)
    {
        $this->services = $services;
    }

    public static function fromServices($services): self
    {
        return new self($services);
    }

    /**
     * THE LIGHT WAY IN, for code that runs beside Omeka and shares its database without starting it
     * (a page of the site, a script): the same entries, written through a plain PDO, with who did it
     * and how the request came given by that code — which must already know who is signed in.
     * Starting Omeka only to write one entry would make every such request slower.
     *
     * A module whose code has Omeka's connection but not its services at hand may pass that connection
     * instead of a PDO.
     *
     * @param \PDO|\Doctrine\DBAL\Connection $db
     * @param array $who ['id' => int|null, 'name' => string, 'email' => string]
     * @param array $via ['web', '/the/page.php'], ['admin', '/admin/…'] or ['cli', 'script.php']
     */
    public static function beside($db, array $who, array $via): self
    {
        $self = new self(null);
        $self->pdo = $db instanceof \PDO ? new PdoConnection($db) : $db;
        $self->given = [
            'user_id' => isset($who['id']) && (int) $who['id'] > 0 ? (int) $who['id'] : null,
            'user_name' => self::text($who['name'] ?? '', 190),
            'user_email' => self::text($who['email'] ?? '', 190),
            'via' => self::word($via[0] ?? '', 12, '/[^a-z]/'),
            'via_detail' => self::text($via[1] ?? '', 255),
        ];
        return $self;
    }

    private function conn()
    {
        return $this->pdo ?: $this->services->get('Omeka\Connection');
    }

    /** Which field holds the record's identifier, and which its title (the module's settings). */
    private function terms($conn): array
    {
        $defaults = ['accession' => 'dcterms:identifier', 'name' => 'dcterms:title'];
        if (!$this->pdo) {
            $settings = $this->services->get('Omeka\Settings');
            return [
                'accession' => (string) $settings->get('revision_history_accession_term', $defaults['accession']),
                'name' => (string) $settings->get('revision_history_title_term', $defaults['name']),
            ];
        }
        $out = $defaults;
        foreach ($conn->fetchAllAssociative(
            "SELECT id, value FROM setting WHERE id IN ('revision_history_accession_term', 'revision_history_title_term')"
        ) as $r) {
            $v = json_decode((string) $r['value'], true);
            $out[$r['id'] === 'revision_history_accession_term' ? 'accession' : 'name'] = is_string($v) ? $v : '';
        }
        return $out;
    }

    /** Who, when and how: as for a save, or as the code beside Omeka said. */
    private function moment(): array
    {
        if (!$this->given) {
            return (new Recorder($this->services))->moment();
        }
        $t = microtime(true);
        return [
            'happened_at' => gmdate('Y-m-d H:i:s', (int) floor($t)) . sprintf('.%06d', (int) round(($t - floor($t)) * 1000000) % 1000000),
            'save_key' => '',
            'run_key' => '',
            'ip' => Capture\Where::ip(),
            'source' => 'here',
        ] + $this->given;
    }

    private function log(string $level, string $message): void
    {
        try {
            if ($this->services) {
                $this->services->get('Omeka\Logger')->$level($message);
            } else {
                error_log($message);
            }
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * @return int the entry's id; 0 when nothing was written
     */
    public function add(array $entry): int
    {
        $ids = $this->addMany([$entry]);
        return $ids[0] ?? 0;
    }

    /**
     * Several entries, one row each, written together. 'resources' => [ids] in one entry is the
     * same as that entry once for each.
     *
     * $opts['limit'] (1.2.1): how many entries this request may write before the rest are only
     * counted — MAX_PER_REQUEST unless given; 0 = no limit, for a job that knows it writes one entry
     * on each of many records (a term renamed on every record citing it).
     *
     * @return int[] ids of the entries written, in order (an entry already written for its ref
     *   gives the id it has)
     */
    public function addMany(array $entries, array $opts = []): array
    {
        try {
            return $this->write($entries, isset($opts['limit']) ? max(0, (int) $opts['limit']) : self::MAX_PER_REQUEST);
        } catch (\Throwable $e) {
            $this->failed($e);
            return [];
        }
    }

    /**
     * ONE ENTRY ABOUT MANY RECORDS (1.2.2): the same keys as add(), with 'resources' => [ids],
     * written as one entry instead of one per record — a term renamed on the 8,000 records citing
     * it is one row, and one row per record in revision_history_shared that names it, written
     * together in the database. Each record's page shows the entry; the Everything page shows it
     * once, with the records it is about. The words are the same for every record (the summary,
     * the two sides, the details); each record's own name is kept with its row.
     *
     * The ref is the entry's ('my_log:42', not one per record), so the same call made again
     * writes nothing. No limit per request: it is one entry.
     *
     * @return int the entry's id; 0 when nothing was written
     */
    public function addShared(array $entry): int
    {
        $conn = null;
        $own = false;
        try {
            $ids = isset($entry['resources']) && is_array($entry['resources']) ? $entry['resources'] : [$entry['resource'] ?? 0];
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            sort($ids);
            $conn = $this->conn();
            // the first record that is still there carries the entry, as for add()
            $head = 0;
            foreach (array_chunk($ids, 1000) as $chunk) {
                $head = (int) $conn->fetchOne("SELECT MIN(id) FROM resource WHERE id IN (" . implode(',', $chunk) . ")
                    AND resource_type IN ('Omeka\\\\Entity\\\\Item', 'Omeka\\\\Entity\\\\Media', 'Omeka\\\\Entity\\\\ItemSet')");
                if ($head) {
                    break;
                }
            }
            if (!$head) {
                return 0;
            }
            $one = $entry;
            unset($one['resources']);
            $one['resource'] = $head;
            $own = (int) $conn->getTransactionNestingLevel() === 0;
            if ($own) {
                $conn->beginTransaction();
            }
            $sourceRef = self::text($entry['ref'] ?? '', 120);
            $module = self::word($entry['module'] ?? '', 64, '/[^A-Za-z0-9_.-]/');
            $had = $sourceRef === '' ? false : $conn->fetchOne(
                "SELECT id FROM revision_history_event WHERE source = 'module' AND source_ref = ?", [mb_substr($module . ':' . $sourceRef, 0, 64)]);
            if ($had) {
                if ($own) {
                    $conn->commit();
                }
                return (int) $had;
            }
            $eventId = (int) ($this->write([$one], 0)[0] ?? 0);
            if (!$eventId) {
                if ($own) {
                    $conn->rollBack();
                }
                return 0;
            }
            $terms = $this->terms($conn);
            $pid = function (string $term) use ($conn): int {
                [$prefix, $local] = array_pad(explode(':', $term, 2), 2, '');
                return $prefix === '' || $local === '' ? 0 : (int) $conn->fetchOne(
                    'SELECT p.id FROM property p JOIN vocabulary vo ON vo.id = p.vocabulary_id WHERE vo.prefix = ? AND p.local_name = ?', [$prefix, $local]);
            };
            $acc = $pid($terms['accession']);
            $name = $pid($terms['name']);
            $n = 0;
            foreach (array_chunk($ids, 1000) as $chunk) {
                $n += (int) $conn->executeStatement("INSERT IGNORE INTO revision_history_shared (event_id, resource_id, resource_kind, item_id, title, accession, name, ords)
                    SELECT ?, r.id,
                           CASE r.resource_type WHEN 'Omeka\\\\Entity\\\\Item' THEN 'item' WHEN 'Omeka\\\\Entity\\\\Media' THEN 'media' ELSE 'item_set' END,
                           CASE r.resource_type WHEN 'Omeka\\\\Entity\\\\Item' THEN r.id WHEN 'Omeka\\\\Entity\\\\Media' THEN m.item_id ELSE NULL END,
                           r.title,
                           COALESCE((SELECT LEFT(v.value, 190) FROM value v WHERE v.resource_id = r.id AND v.property_id = ? AND v.value <> '' ORDER BY v.id LIMIT 1), ''),
                           (SELECT LEFT(v.value, 1000) FROM value v WHERE v.resource_id = r.id AND v.property_id = ? AND v.value <> '' ORDER BY v.id LIMIT 1),
                           NULL
                      FROM resource r LEFT JOIN media m ON m.id = r.id
                     WHERE r.id IN (" . implode(',', $chunk) . ")
                       AND r.resource_type IN ('Omeka\\\\Entity\\\\Item', 'Omeka\\\\Entity\\\\Media', 'Omeka\\\\Entity\\\\ItemSet')",
                    [$eventId, $acc, $name]);
            }
            $conn->executeStatement('UPDATE revision_history_event SET shared = ? WHERE id = ?', [$n, $eventId]);
            if ($own) {
                $conn->commit();
            }
            return $eventId;
        } catch (\Throwable $e) {
            if ($own && $conn && $conn->getTransactionNestingLevel() > 0) {
                $conn->rollBack();
            }
            $this->failed($e);
            return 0;
        }
    }

    private function write(array $entries, int $limit = self::MAX_PER_REQUEST): array
    {
        $conn = $this->conn();

        $rows = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $ids = isset($entry['resources']) && is_array($entry['resources']) ? $entry['resources'] : [$entry['resource'] ?? 0];
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            $refBase = self::text($entry['ref'] ?? '', 120);
            $undoes = self::text($entry['undoes'] ?? '', 100);
            // One entry about several records: each gets its own ref (ref#id), and an undo
            // written the same way names, for each record, that record's entry.
            $many = isset($entry['resources']) && is_array($entry['resources']);
            foreach ($ids as $i => $id) {
                $one = $entry;
                $one['resource'] = $id;
                $one['ref'] = $refBase === '' ? '' : ($many ? $refBase . '#' . $id : $refBase);
                $one['undoes'] = $undoes === '' ? '' : ($many ? $undoes . '#' . $id : $undoes);
                $rows[] = $one;
            }
        }
        if (!$rows) {
            return [];
        }

        $records = $this->records($conn, array_column($rows, 'resource'));
        $moment = $this->moment();
        $cli = PHP_SAPI === 'cli';
        $out = [];
        $made = [];
        $own = (int) $conn->getTransactionNestingLevel() === 0;
        if ($own) {
            $conn->beginTransaction();
        }
        try {
            foreach ($rows as $e) {
                $module = self::word($e['module'] ?? '', 64, '/[^A-Za-z0-9_.-]/');
                if ($module === '') {
                    continue;
                }
                $id = (int) $e['resource'];
                $rec = $records[$id] ?? null;
                if (!$rec) {
                    continue;
                }
                $ref = (string) $e['ref'];
                $sourceRef = $ref === '' ? null : mb_substr($module . ':' . $ref, 0, 64);
                if ($sourceRef !== null) {
                    $had = $conn->fetchOne("SELECT id FROM revision_history_event WHERE source = 'module' AND source_ref = ?", [$sourceRef]);
                    if ($had) {
                        $out[] = (int) $had;
                        continue;
                    }
                }
                if ($limit > 0 && self::$written >= $limit) {
                    $key = $module . "\0" . (string) ($e['kind'] ?? '');
                    self::$folded[$key] = (self::$folded[$key] ?? 0) + 1;
                    if (array_sum(self::$folded) === 1) {
                        $this->log('warn', 'RevisionHistory: more than ' . $limit
                            . ' entries from other modules in one request; the rest are counted, not written (Entries::folded()).');
                    }
                    continue;
                }
                $automatic = !empty($e['automatic']);
                $base = $moment;
                $base['save_key'] = bin2hex(random_bytes(8));
                // An old log copied from the command line keeps its own time and person.
                if ($cli && $sourceRef !== null && !empty($e['at']) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d(\.\d+)?$/', (string) $e['at'])) {
                    $base['happened_at'] = (string) $e['at'];
                    $base['user_id'] = isset($e['by_id']) && (int) $e['by_id'] > 0 ? (int) $e['by_id'] : null;
                    $base['user_name'] = self::text($e['by_name'] ?? '', 190);
                    $base['user_email'] = self::text($e['by_email'] ?? '', 190);
                    $base['via'] = 'copy';
                    $base['via_detail'] = self::text($e['copied_from'] ?? ('copied from ' . $module), 255);
                    $base['ip'] = '';
                }
                // Nobody signed in: the job or script that did it is the one named.
                if (!$base['user_id'] && $base['user_name'] === '') {
                    $base['user_name'] = self::text($e['actor'] ?? '', 190);
                }
                $summary = self::text($e['summary'] ?? '', self::MAX_SUMMARY);
                $label = self::text($e['label'] ?? $module, 64);
                $kind = self::word($e['kind'] ?? '', 40, '/[^a-z0-9._-]/');
                $conn->insert('revision_history_event', self::quoted(array_merge($base, [
                    // 'run' (1.2.1): one job's entries on many records are one card on the Everything
                    // page (Reader::GROUP reads run_key as it is; nothing is added to it).
                    'run_key' => self::text($e['run'] ?? '', 40),
                    'action' => 'noted',
                    'resource_id' => $id,
                    'resource_kind' => $rec['kind'],
                    'item_id' => $rec['item'],
                    'title' => $rec['title'],
                    'accession' => $rec['accession'],
                    'source' => 'module',
                    'source_ref' => $sourceRef,
                    'note' => $summary !== '' ? $summary : $label,
                    'module' => $module,
                    'kind' => $kind,
                    'automatic' => $automatic ? 1 : 0,
                ])));
                $eventId = (int) $conn->lastInsertId();
                $made[] = $eventId;
                $conn->insert('revision_history_entry', self::quoted([
                    'event_id' => $eventId,
                    'label' => $label,
                    'kind_label' => self::text($e['kind_label'] ?? '', 120),
                    'origin' => ($e['origin'] ?? 'module') === 'page' ? 'page' : 'module',
                    'record_name' => $rec['name'],
                    'before_text' => self::side($e['before'] ?? null),
                    'after_text' => self::side($e['after'] ?? null),
                    'details_json' => self::details($e['details'] ?? null),
                    'link' => self::path($e['link'] ?? ''),
                    'undo' => self::path($e['undo'] ?? ''),
                    'undoes' => self::text($e['undoes'] ?? '', 120),
                ]));
                self::$written++;
                $out[] = $eventId;
            }
            if ($own) {
                $conn->commit();
            }
        } catch (\Throwable $err) {
            if ($own) {
                $conn->rollBack();
            } else {
                // Inside somebody else's transaction a rollback would fail theirs (Store::write):
                // the entries of this call are taken back one by one instead.
                foreach ($made as $mid) {
                    try {
                        $conn->executeStatement('DELETE FROM revision_history_entry WHERE event_id = ?', [$mid]);
                        $conn->executeStatement('DELETE FROM revision_history_event WHERE id = ?', [$mid]);
                    } catch (\Throwable $ignored) {
                    }
                }
            }
            throw $err;
        }
        return $out;
    }

    /**
     * Entries that did not fit in one request, by module and kind, with how many. The first
     * one is logged; a module that writes this many can read the count and say it.
     */
    public static function folded(): array
    {
        return self::$folded;
    }

    /**
     * The name of each record as it is now, to be kept with the entry: Omeka's own title
     * (which may hold an identifier), the field people read as its title, and its identifier.
     */
    private function records($conn, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', $chunk);
            foreach ($conn->fetchAllAssociative(
                "SELECT r.id, r.resource_type, r.title, m.item_id FROM resource r LEFT JOIN media m ON m.id = r.id WHERE r.id IN ($in)"
            ) as $r) {
                $kind = [
                    'Omeka\Entity\Item' => 'item',
                    'Omeka\Entity\Media' => 'media',
                    'Omeka\Entity\ItemSet' => 'item_set',
                ][$r['resource_type']] ?? null;
                if (!$kind) {
                    continue;
                }
                $id = (int) $r['id'];
                $out[$id] = [
                    'kind' => $kind,
                    'item' => $kind === 'item' ? $id : ($kind === 'media' ? (int) $r['item_id'] : null),
                    'title' => $r['title'] === null ? null : mb_substr((string) $r['title'], 0, 60000),
                    'accession' => '',
                    'name' => null,
                ];
            }
        }
        if (!$out) {
            return [];
        }
        $terms = $this->terms($conn);
        foreach ($terms as $key => $term) {
            [$prefix, $local] = array_pad(explode(':', $term, 2), 2, '');
            if ($prefix === '' || $local === '') {
                continue;
            }
            $in = implode(',', array_keys($out));
            foreach ($conn->fetchAllAssociative(
                "SELECT v.resource_id, v.value FROM value v
                   JOIN property p ON p.id = v.property_id JOIN vocabulary vo ON vo.id = p.vocabulary_id
                  WHERE vo.prefix = ? AND p.local_name = ? AND v.resource_id IN ($in) AND v.value IS NOT NULL AND v.value <> ''
                  ORDER BY v.id",
                [$prefix, $local]
            ) as $v) {
                $rid = (int) $v['resource_id'];
                if ($out[$rid][$key] === '' || $out[$rid][$key] === null) {
                    $out[$rid][$key] = $key === 'accession' ? mb_substr((string) $v['value'], 0, 190) : mb_substr((string) $v['value'], 0, 1000);
                }
            }
        }
        return $out;
    }

    private static function text($v, int $max): string
    {
        if (!is_scalar($v)) {
            return '';
        }
        $s = trim(preg_replace('/\s+/u', ' ', (string) $v));
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }

    private static function word($v, int $max, string $bad): string
    {
        return is_scalar($v) ? mb_substr(preg_replace($bad, '', (string) $v), 0, $max) : '';
    }

    /** One side, before or after, as text people read. */
    private static function side($v): ?string
    {
        if ($v === null || $v === '' || $v === []) {
            return null;
        }
        if (is_array($v)) {
            $v = implode(' · ', array_map(function ($x) {
                return is_scalar($x) ? (string) $x : json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }, $v));
        }
        if (!is_scalar($v)) {
            return null;
        }
        $s = (string) $v;
        return mb_strlen($s) > self::MAX_SIDE ? mb_substr($s, 0, self::MAX_SIDE - 1) . '…' : $s;
    }

    /** Details as label => text pairs, in the order given. */
    private static function details($v): ?string
    {
        if (!is_array($v) || !$v) {
            return null;
        }
        $pairs = [];
        $size = 0;
        foreach ($v as $label => $text) {
            if (is_array($text)) {
                $text = implode(' · ', array_map('strval', array_filter($text, 'is_scalar')));
            }
            if (!is_scalar($text) || (string) $text === '') {
                continue;
            }
            $pair = [self::text($label, 120), (string) $text];
            $size += mb_strlen($pair[0]) + mb_strlen($pair[1]);
            if ($size > self::MAX_DETAILS) {
                $pairs[] = ['…', 'more details were cut here'];
                break;
            }
            $pairs[] = $pair;
        }
        return $pairs ? Store::json($pairs) : null;
    }

    /** A link inside this site only: a path, never an address elsewhere. */
    private static function path($v): string
    {
        $s = is_scalar($v) ? trim((string) $v) : '';
        if ($s === '' || $s[0] !== '/' || (isset($s[1]) && ($s[1] === '/' || $s[1] === '\\')) || preg_match('/[\s<>"]/', $s)) {
            return '';
        }
        return mb_substr($s, 0, 255);
    }

    private static function quoted(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $out['`' . $k . '`'] = $v;
        }
        return $out;
    }

    private function failed(\Throwable $e): void
    {
        $this->log('err', 'RevisionHistory: an entry from another module could not be written: ' . $e->getMessage());
    }
}
