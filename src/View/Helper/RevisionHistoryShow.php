<?php
namespace RevisionHistory\View\Helper;

use Doctrine\DBAL\Connection;
use Laminas\View\Helper\AbstractHelper;

/**
 * Turns rows of history into things a person can read.
 *
 * The history keeps ids — of terms, of linked items, of templates, of people's
 * accounts — because ids stay true when a name is changed next week. The words
 * are looked up here, fresh, every time the page is drawn. When the thing an
 * id pointed at is gone, the page says so instead of printing a bare number.
 */
class RevisionHistoryShow extends AbstractHelper
{
    /** @var Connection */
    private $conn;

    /** @var string */
    private $tz;

    private $props = [];
    private $titles = [];
    private $templates = [];
    private $classes = [];
    private $users = [];
    private $sites = [];
    private $assets = [];
    private $keys = [];
    private $terms = [];
    private $hasVocabSync;

    /** @var callable|null path => the menu label a module gives that page, or null */
    private $menuLabel;

    private $menuLabels = [];

    /** @var string the field holding the title people read (resource.title holds the accession number here) */
    private $titleTerm;

    /** @var string the field holding the accession number */
    private $accessionTerm;

    /** @var array<int, array> what each Batch Values run says it did, once read */
    private $runs = [];

    /** @var bool|null whether Batch Values keeps its record here */
    private $hasBatchValues;

    public function __construct(Connection $conn, string $tz, ?callable $menuLabel = null, string $titleTerm = 'dcterms:title', string $accessionTerm = 'dcterms:identifier')
    {
        $this->conn = $conn;
        $this->titleTerm = $titleTerm;
        $this->accessionTerm = $accessionTerm;
        $this->tz = $tz ?: 'UTC';
        $this->menuLabel = $menuLabel;
    }

    public function __invoke()
    {
        return $this;
    }

    public function tz(): string
    {
        return $this->tz;
    }

    /** Look up, in a handful of queries, every word the page below will need. */
    public function prepare(array $events): void
    {
        $terms = [];
        $res = [];
        $tpl = [];
        $cls = [];
        $usr = [];
        $vs = [];
        $sites = [];
        $assets = [];
        $walk = function ($v, $field) use (&$res, &$tpl, &$cls, &$usr, &$vs, &$terms, &$sites, &$assets) {
            if (!is_array($v)) {
                return;
            }
            if (in_array($field, ['o:item_set', 'o:primary_media', 'o:item'], true) && isset($v['id'])) {
                $res[] = (int) $v['id'];
            } elseif ($field === 'o:media' || $field === 'rank') {
                foreach ((array) $v as $one) {
                    if (isset($one['id'])) {
                        $res[] = (int) $one['id'];
                    }
                }
                return;
            } elseif ($field === 'o:site' && isset($v['id'])) {
                $sites[] = (int) $v['id'];
            } elseif ($field === 'o:thumbnail' && isset($v['id'])) {
                $assets[] = (int) $v['id'];
            } elseif ($field === 'o:resource_template' && isset($v['id'])) {
                $tpl[] = (int) $v['id'];
            } elseif ($field === 'o:resource_class' && isset($v['id'])) {
                $cls[] = (int) $v['id'];
            } elseif ($field === 'o:owner' && isset($v['id'])) {
                $usr[] = (int) $v['id'];
            }
            $list = isset($v['type']) ? [$v] : (isset($v[0]) ? $v : []);
            foreach ($list as $one) {
                if (!is_array($one)) {
                    continue;
                }
                if (!empty($one['vr'])) {
                    $res[] = (int) $one['vr'];
                }
                if (isset($one['type']) && strpos($one['type'], 'vocabsync:') === 0 && ctype_digit((string) $one['value'])) {
                    $vs[] = (int) $one['value'];
                }
                foreach ($one['note'] ?? [] as $n) {
                    $terms[] = $n['f'] ?? '';
                    if (!empty($n['vr'])) {
                        $res[] = (int) $n['vr'];
                    }
                    if (isset($n['type']) && strpos($n['type'], 'vocabsync:') === 0 && ctype_digit((string) $n['value'])) {
                        $vs[] = (int) $n['value'];
                    }
                }
            }
        };
        foreach ($events as $e) {
            // An entry that is not about an item, a media or an item set (Vocab Sync's
            // own record) has no resource id: the number there means something else.
            if (!isset($e['resource_kind']) || in_array($e['resource_kind'], ['item', 'media', 'item_set'], true)) {
                $res[] = (int) $e['resource_id'];
            }
            if ($e['item_id']) {
                $res[] = (int) $e['item_id'];
            }
            foreach ($e['changes'] as $c) {
                $terms[] = $c['field'];
                $walk($c['before'], $c['field']);
                $walk($c['after'], $c['field']);
            }
            foreach ($e['run'] ?? [] as $m) {
                if (in_array($m['resource_kind'], ['item', 'media', 'item_set'], true)) {
                    $res[] = (int) $m['resource_id'];
                }
                if (!empty($m['item_id'])) {
                    $res[] = (int) $m['item_id'];
                }
                foreach ($m['changes'] ?? [] as $c) {
                    $terms[] = $c['field'];
                    $walk($c['before'], $c['field']);
                    $walk($c['after'], $c['field']);
                }
            }
            foreach ($e['run_sum'] ?? [] as $c) {
                $terms[] = $c['field'];
                $walk($c['before'], $c['field']);
                $walk($c['after'], $c['field']);
            }
            foreach ($e['folded'] ?? [] as $m) {
                if (!isset($m['resource_kind']) || in_array($m['resource_kind'], ['item', 'media', 'item_set'], true)) {
                    $res[] = (int) $m['resource_id'];
                }
                if (!empty($m['item_id'])) {
                    $res[] = (int) $m['item_id'];
                }
                foreach ($m['changes'] ?? [] as $c) {
                    $terms[] = $c['field'];
                    $walk($c['before'], $c['field']);
                    $walk($c['after'], $c['field']);
                }
            }
        }

        $terms = array_values(array_diff(array_unique(array_filter($terms)), array_keys($this->props)));
        foreach (array_chunk($terms, 300) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach ($this->conn->fetchAllAssociative(
                "SELECT CONCAT(vo.prefix, ':', p.local_name) AS term, p.label
                   FROM property p JOIN vocabulary vo ON vo.id = p.vocabulary_id
                  WHERE CONCAT(vo.prefix, ':', p.local_name) IN ($in)",
                $chunk
            ) as $r) {
                $this->props[$r['term']] = $r['label'];
            }
        }
        $this->loadTitles($res);
        $this->load('resource_template', 'label', $tpl, $this->templates);
        $this->load('user', 'name', $usr, $this->users);
        $this->load('site', 'title', $sites, $this->sites);
        $this->load('asset', 'name', $assets, $this->assets);
        if ($cls) {
            $in = implode(',', array_map('intval', array_unique($cls)));
            foreach ($this->conn->fetchAllAssociative("SELECT id, label FROM resource_class WHERE id IN ($in)") as $r) {
                $this->classes[(int) $r['id']] = $r['label'];
            }
        }
        if ($vs && $this->vocabSync()) {
            $in = implode(',', array_map('intval', array_unique($vs)));
            foreach ($this->conn->fetchAllAssociative("SELECT id, vocab_id, label FROM vocab_sync_term WHERE id IN ($in)") as $r) {
                $this->terms[(int) $r['vocab_id'] . ':' . (int) $r['id']] = $r['label'];
            }
        }
    }

    private function loadTitles(array $ids): void
    {
        $ids = array_values(array_diff(array_unique(array_filter($ids)), array_keys($this->titles)));
        if (!$ids) {
            return;
        }
        $in = implode(',', array_map('intval', $ids));
        foreach ($this->conn->fetchAllAssociative("SELECT id, title, resource_type FROM resource WHERE id IN ($in)") as $r) {
            $this->titles[(int) $r['id']] = ['title' => $r['title'], 'gone' => false, 'type' => $r['resource_type']];
        }
        // A resource whose title is empty now: the last title the history saw it with.
        $blank = [];
        foreach ($ids as $id) {
            if (isset($this->titles[$id]) && ($this->titles[$id]['title'] === null || $this->titles[$id]['title'] === '')) {
                $blank[] = (int) $id;
            }
        }
        if ($blank) {
            $in = implode(',', $blank);
            foreach ($this->conn->fetchAllAssociative(
                "SELECT e.resource_id, e.title FROM revision_history_event e
                  JOIN (SELECT resource_id, MAX(id) AS id FROM revision_history_event
                         WHERE resource_kind IN ('item','media','item_set') AND resource_id IN ($in) AND title IS NOT NULL AND title <> '' GROUP BY resource_id) last
                    ON last.id = e.id"
            ) as $r) {
                $this->titles[(int) $r['resource_id']]['was'] = $r['title'];
            }
        }
        // The title people read, and the accession number, from their own fields.
        $there = array_keys($this->titles);
        if ($there) {
            $in = implode(',', array_map('intval', $there));
            foreach ([['name', $this->titleTerm], ['accession', $this->accessionTerm]] as [$key, $term]) {
                if (strpos($term, ':') === false) {
                    continue;
                }
                [$prefix, $local] = explode(':', $term, 2);
                foreach ($this->conn->fetchAllAssociative(
                    "SELECT v.resource_id, v.value FROM value v
                       JOIN property p ON p.id = v.property_id
                       JOIN vocabulary vo ON vo.id = p.vocabulary_id
                      WHERE v.resource_id IN ($in) AND vo.prefix = ? AND p.local_name = ?
                        AND v.value IS NOT NULL AND v.value <> ''
                      ORDER BY v.resource_id, v.id",
                    [$prefix, $local]
                ) as $r) {
                    $rid = (int) $r['resource_id'];
                    if (!isset($this->titles[$rid][$key])) {
                        $this->titles[$rid][$key] = trim((string) $r['value']);
                    }
                }
            }
        }
        $missing = array_diff($ids, array_keys($this->titles));
        if ($missing) {
            $in = implode(',', array_map('intval', $missing));
            foreach ($this->conn->fetchAllAssociative(
                "SELECT resource_id, MAX(title) AS title, MAX(accession) AS accession FROM revision_history_event
                  WHERE resource_kind IN ('item','media','item_set') AND resource_id IN ($in) AND action = 'deleted' GROUP BY resource_id"
            ) as $r) {
                $this->titles[(int) $r['resource_id']] = ['title' => $r['title'], 'gone' => true, 'type' => null];
            }
            foreach (array_diff($missing, array_keys($this->titles)) as $id) {
                $this->titles[(int) $id] = ['title' => null, 'gone' => true, 'type' => null];
            }
            // Gone: the title field as it was when it was deleted.
            $in = implode(',', array_map('intval', $missing));
            foreach ($this->conn->fetchAllAssociative(
                "SELECT e.resource_id, c.before_json FROM revision_history_change c
                   JOIN revision_history_event e ON e.id = c.event_id
                  WHERE e.resource_kind IN ('item','media','item_set') AND e.resource_id IN ($in) AND e.action = 'deleted' AND c.field = ? AND c.before_json IS NOT NULL
                  ORDER BY e.id DESC, c.ord",
                [$this->titleTerm]
            ) as $r) {
                $rid = (int) $r['resource_id'];
                $v = json_decode((string) $r['before_json'], true);
                if (!isset($this->titles[$rid]['name']) && is_array($v) && ($v['value'] ?? '') !== '') {
                    $this->titles[$rid]['name'] = trim((string) $v['value']);
                }
            }
        }
    }

    private function load(string $table, string $col, array $ids, array &$into): void
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return;
        }
        $in = implode(',', array_map('intval', $ids));
        foreach ($this->conn->fetchAllAssociative("SELECT id, `$col` AS l FROM `$table` WHERE id IN ($in)") as $r) {
            $into[(int) $r['id']] = $r['l'];
        }
    }

    /** Whether the Vocab Sync module's tables are here; its parts of the pages are shown only then. */
    public function hasVocabSync(): bool
    {
        return $this->vocabSync();
    }

    private function vocabSync(): bool
    {
        if ($this->hasVocabSync === null) {
            try {
                $this->hasVocabSync = (bool) $this->conn->fetchOne("SHOW TABLES LIKE 'vocab_sync_term'");
            } catch (\Throwable $e) {
                $this->hasVocabSync = false;
            }
        }
        return $this->hasVocabSync;
    }

    /* ------------------------------------------------------------------ words */

    public function e($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function fieldLabel(string $field): string
    {
        $fixed = [
            'o:is_public' => 'Visibility',
            'o:resource_template' => 'Resource template',
            'o:resource_class' => 'Class',
            'o:owner' => 'Owner',
            'o:item_set' => 'Item set',
            'o:site' => 'Site',
            'o:thumbnail' => 'Thumbnail',
            'o:primary_media' => 'Primary media',
            'o:item' => 'Item',
            'o:media' => 'Media',
            'o:is_open' => 'Open to additions',
            'o:source' => 'Source',
            'o:media_type' => 'Media type',
            'o:alt_text' => 'Alt text',
            'o:lang' => 'Language',
            'o:file' => 'File',
            'o:data' => 'HTML',
            'o-module-image_annotate:annotation' => 'Image Annotate',
            'o-module-vocab_sync:term' => 'Term',
            // ItemSetsTree's own columns, by the names that module gives them.
            'rank' => 'Rank',
            'parentItemSet' => 'Parent item set',
            'itemSet' => 'Item set',
        ];
        if (isset($fixed[$field])) {
            return $fixed[$field];
        }
        // One line of what a media keeps besides its file, under the name the ingester
        // or module gives it.
        if (strpos($field, 'o:data:') === 0) {
            return substr($field, 7);
        }
        return $this->props[$field] ?? $field;
    }

    /**
     * The Field menu, laid out the way Omeka lays out its own property menus
     * (AbstractVocabularyMemberSelect): one group per vocabulary under the vocabulary's
     * label, Dublin Core first, each field by its label alone.
     *
     * @param string[] $fields
     * @return array [fields Omeka itself names, groups by vocabulary {label, options},
     *   terms whose vocabulary is no longer installed]
     */
    public function fieldMenu(array $fields): array
    {
        $own = [];
        $byVocab = [];
        $rows = [];
        $terms = array_values(array_filter($fields, function ($f) { return strpos($f, ':') !== false; }));
        foreach (array_chunk($terms, 300) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach ($this->conn->fetchAllAssociative(
                "SELECT CONCAT(vo.prefix, ':', p.local_name) AS term, p.label, vo.prefix, vo.label AS vocab
                   FROM property p JOIN vocabulary vo ON vo.id = p.vocabulary_id
                  WHERE CONCAT(vo.prefix, ':', p.local_name) IN ($in)",
                $chunk
            ) as $r) {
                $rows[$r['term']] = $r;
                $this->props[$r['term']] = $r['label'];
            }
        }
        $orphans = [];
        foreach ($fields as $f) {
            if (isset($rows[$f])) {
                $r = $rows[$f];
                $byVocab[$r['prefix']]['label'] = $r['vocab'];
                $byVocab[$r['prefix']]['options'][$f] = $r['label'];
            } elseif ($this->fieldLabel($f) !== $f) {
                $own[$f] = $this->fieldLabel($f);
            } else {
                // A property whose vocabulary is no longer in Omeka: nothing to name it by.
                $orphans[$f] = $f;
            }
        }
        // Fields that are not properties, in a fixed order; Image Annotate last.
        $order = array_flip(['o:is_public', 'o:resource_template', 'o:resource_class', 'o:owner', 'o:item_set', 'o-module-image_annotate:annotation']);
        uksort($own, function ($a, $b) use ($order) {
            return ($order[$a] ?? 99) <=> ($order[$b] ?? 99);
        });
        $first = ['dctype' => 0, 'dcterms' => 1];
        uksort($byVocab, function ($a, $b) use ($first, $byVocab) {
            $fa = $first[$a] ?? 2;
            $fb = $first[$b] ?? 2;
            return $fa <=> $fb ?: strnatcasecmp($byVocab[$a]['label'], $byVocab[$b]['label']);
        });
        foreach ($byVocab as &$g) {
            asort($g['options'], SORT_NATURAL | SORT_FLAG_CASE);
        }
        unset($g);
        return [$own, array_values($byVocab), $orphans];
    }

    /** DateTime in the archive's zone, from the UTC stored. */
    public function local(string $utc): \DateTime
    {
        $d = new \DateTime(substr($utc, 0, 19), new \DateTimeZone('UTC'));
        $d->setTimezone(new \DateTimeZone($this->tz));
        return $d;
    }

    public function kindWord(string $kind): string
    {
        return ['item' => 'item', 'media' => 'media', 'item_set' => 'item set', 'vocabsync' => 'Vocab Sync', 'ValueAnnotation' => 'Value annotations'][$kind] ?? $kind;
    }

    /** An API key by its identity: what it is called and whose it is. */
    private function apiKey(string $identity): ?array
    {
        if (!array_key_exists($identity, $this->keys)) {
            $this->keys[$identity] = null;
            try {
                $r = $this->conn->fetchAssociative(
                    'SELECT k.label, u.name FROM api_key k LEFT JOIN user u ON u.id = k.owner_id WHERE k.id = ?',
                    [$identity]
                );
                if ($r) {
                    $this->keys[$identity] = ['label' => (string) $r['label'], 'owner' => (string) ($r['name'] ?? '')];
                }
            } catch (\Throwable $e) {
            }
        }
        return $this->keys[$identity];
    }

    public function verb(string $action): string
    {
        return ['created' => 'created', 'deleted' => 'deleted', 'edited' => 'changed'][$action] ?? $action;
    }

    public function via(array $e): string
    {
        switch ($e['source']) {
            case 'historylog':
                return 'copied from History Log';
            case 'activitylog':
                return 'copied from Activity Log';
            case 'vocabsync':
                return 'recorded by Vocab Sync';
            case 'watch':
                return 'found by checking the data itself';
            case 'here':
            case '':
                break;
            // Anything else wrote its entry itself, through the same door the copies come
            // through (README, "An entry written by something else"): it is named by
            // the word it chose for itself.
            default:
                return 'recorded by ' . (string) $e['source']
                    . ((string) $e['via_detail'] !== '' ? ' · ' . (string) $e['via_detail'] : '');
        }
        $d = (string) $e['via_detail'];
        switch ($e['via']) {
            case 'admin':
                // Which admin page, in words, where the address says so plainly.
                $path = preg_replace('#^.*?/admin/#', '/admin/', $d);
                // Omeka's own title for the page, where the address names one of its pages.
                $pages = [
                    '#^/admin/item/\d+/edit#' => 'Edit item',
                    '#^/admin/media/\d+/edit#' => 'Edit media',
                    '#^/admin/item-set/\d+/edit#' => 'Edit item set',
                    '#^/admin/item/add#' => 'Add new item',
                    '#^/admin/item-set/add#' => 'Add new item set',
                    '#^/admin/item/batch-edit#' => 'Batch edit items',
                    '#^/admin/media/batch-edit#' => 'Batch edit media',
                    '#^/admin/item-set/batch-edit#' => 'Batch edit item sets',
                ];
                foreach ($pages as $re => $name) {
                    if (preg_match($re, $path)) {
                        return 'saved in the admin pages · ' . $name;
                    }
                }
                // A module's own page: the name that module gives it in the admin menu.
                if ($this->menuLabel && $d !== '') {
                    if (!array_key_exists($d, $this->menuLabels)) {
                        $this->menuLabels[$d] = ($this->menuLabel)($d);
                    }
                    if ($this->menuLabels[$d] !== null) {
                        $label = $this->menuLabels[$d];
                        if ($this->view && method_exists($this->view, 'translate')) {
                            $label = $this->view->translate($label);
                        }
                        return 'saved in the admin pages · ' . $label;
                    }
                }
                return 'saved in the admin pages' . ($path !== '' ? ' · ' . $path : '');
            case 'api':
                // Which key, and whose: every person has their own, and Omeka records the
                // save as the key's owner. The key's own name is what they called it.
                if (preg_match('/^key (\S+)/', $d, $m)) {
                    $key = $this->apiKey($m[1]);
                    if ($key) {
                        return 'saved through the API · key ' . $this->e($key['label'] !== '' ? $key['label'] : substr($m[1], 0, 6) . '…')
                            . ($key['owner'] !== '' ? ' of ' . $this->e($key['owner']) : '');
                    }
                    return 'saved through the API · key ' . substr($m[1], 0, 6) . '… <span class="rh-gone">no longer there</span>';
                }
                return 'saved through the API';
            case 'job':
                if (preg_match('/^#(\d+) (.*)$/', $d, $m)) {
                    $class = substr(strrchr('\\' . $m[2], '\\'), 1);
                    // A module's job: the name that module gives itself in the admin menu.
                    $module = strstr($m[2], '\\', true) ?: '';
                    if ($module !== '' && $module !== 'Omeka' && $this->menuLabel) {
                        if (!array_key_exists($module, $this->menuLabels)) {
                            $this->menuLabels[$module] = ($this->menuLabel)($module);
                        }
                        if ($this->menuLabels[$module] !== null) {
                            $label = $this->menuLabels[$module];
                            if ($this->view && method_exists($this->view, 'translate')) {
                                $label = $this->view->translate($label);
                            }
                            return 'saved by background job #' . $m[1] . ' · ' . $label;
                        }
                    }
                    return 'saved by background job #' . $m[1] . ($class ? ' · ' . $class : '');
                }
                return 'saved by a background job';
            case 'cli':
                return 'saved from the command line';
            case 'site':
                return 'saved from the public site';
            case 'web':
                // A web request that is none of those: Omeka's own login and logout
                // pages, or a page of a module outside the admin pages and the sites.
                return 'saved by a web request outside the admin pages, the API and the sites'
                    . ($d !== '' ? ' · ' . $d : '');
        }
        return (string) $e['via'];
    }

    public function titleOf(int $id): array
    {
        return $this->titles[$id] ?? ['title' => null, 'gone' => true, 'type' => null];
    }

    /**
     * What a card calls a resource: its title field; without one, Omeka's own title
     * (here the accession number), or the last title the history saw.
     */
    public function nameOf(int $id, string $savedTitle = ''): string
    {
        $t = $this->titleOf($id);
        foreach ([$t['name'] ?? '', $t['title'] ?? '', $savedTitle, $t['was'] ?? ''] as $x) {
            if ((string) $x !== '') {
                return (string) $x;
            }
        }
        return '[Untitled]';
    }

    /** One value as it read. */
    public function value($v, string $field): string
    {
        if (!is_array($v)) {
            return '<span class="rh-none">nothing</span>';
        }
        // A list: the item's media, in order.
        if (isset($v[0]) && is_array($v[0])) {
            $out = [];
            foreach ($v as $one) {
                $out[] = '<li>' . $this->value($one, $field) . '</li>';
            }
            return '<ol class="rh-order">' . implode('', $out) . '</ol>';
        }
        if (array_key_exists('id', $v) && !isset($v['type'])) {
            return $this->setting($field, $v['id']);
        }
        $out = $this->valueBody($v);
        if (!empty($v['lang'])) {
            $out .= ' <span class="rh-lang">' . $this->e($v['lang']) . '</span>';
        }
        if (isset($v['pub']) && !$v['pub']) {
            $out .= ' <span class="rh-private">Private</span>';
        }
        $out .= $this->notes($v);
        return $out;
    }

    /**
     * Where the annotation sits on the image: the selector exactly as Image Annotate
     * (Annotorious) stores it, e.g. xywh=percent:80,85,15,10. Not put into other words.
     */
    public function where(?string $w): string
    {
        if ($w === null || $w === '') {
            return '';
        }
        return strpos($w, 'svg:') === 0 ? 'SvgSelector' : $w;
    }

    private function valueBody(array $v): string
    {
        $type = (string) ($v['type'] ?? '');
        if ($type === 'o-module-image_annotate:annotation') {
            $text = isset($v['value']) && $v['value'] !== '' ? nl2br($this->e($v['value'])) : '<span class="rh-none">empty</span>';
            return $text . ($v['where'] ? ' <span class="rh-id">' . $this->e($this->where($v['where'])) . '</span>' : '');
        }
        if (!empty($v['vr'])) {
            $t = $this->titleOf((int) $v['vr']);
            $label = $this->nameOf((int) $v['vr']);
            $acc = (string) ($t['accession'] ?? '');
            $url = $this->view->url('admin/revision-history', ['action' => 'item', 'id' => (int) $v['vr']]);
            return '<span class="rh-link">→ <a href="' . $this->e($url) . '">' . $this->e($label) . '</a>'
                . ($acc !== '' && $acc !== $label ? ' <span class="rh-acc">' . $this->e($acc) . '</span>' : '')
                . ' <span class="rh-id">#' . (int) $v['vr'] . '</span>'
                . ($t['gone'] ? ' <span class="rh-gone">deleted</span>' : '') . '</span>';
        }
        if (strpos($type, 'vocabsync:') === 0 && isset($v['value']) && ctype_digit((string) $v['value'])) {
            $key = (int) substr($type, 10) . ':' . (int) $v['value'];
            if (isset($this->terms[$key])) {
                return $this->e($this->terms[$key]) . ' <span class="rh-id">term #' . (int) $v['value'] . '</span>';
            }
            return '<span class="rh-gone">term deleted</span> <span class="rh-id">#' . (int) $v['value'] . '</span>';
        }
        if (!empty($v['uri'])) {
            $label = (isset($v['value']) && $v['value'] !== '') ? $v['value'] : $v['uri'];
            return '<a href="' . $this->e($v['uri']) . '" rel="noopener" target="_blank">' . $this->e($label) . '</a>'
                . ($label !== $v['uri'] ? ' <span class="rh-id">' . $this->e($v['uri']) . '</span>' : '');
        }
        $text = isset($v['value']) ? (string) $v['value'] : '';
        $out = $text === '' ? '<span class="rh-none">empty</span>' : nl2br($this->e($text));
        if ($type !== '' && $type !== 'literal') {
            $out .= ' <span class="rh-type">' . $this->e($type) . '</span>';
        }
        return $out;
    }

    /**
     * What a Batch Values run says it did, in Batch Values' own words.
     *
     * A save writes down what the tables hold afterwards, and for some work that is
     * not the whole story: turning a picture leaves only a new file behind, so the
     * record can say the file changed but not that it was turned a quarter to the
     * right. Batch Values writes that down itself, on its own run, in the words it
     * shows on its own page. Those words are read here and shown with the run. This
     * only reads: nothing of Batch Values is written, and a line it did not write is
     * not shown.
     *
     * @return array<int, array{label: string, text: string}>
     */
    public function batchRun(int $id): array
    {
        if (array_key_exists($id, $this->runs)) {
            return $this->runs[$id];
        }
        $this->runs[$id] = [];
        if ($this->hasBatchValues === null) {
            try {
                $this->hasBatchValues = (bool) $this->conn->fetchOne("SHOW TABLES LIKE 'batch_values_change_set'");
            } catch (\Throwable $e) {
                $this->hasBatchValues = false;
            }
        }
        if (!$this->hasBatchValues) {
            return [];
        }
        try {
            $row = $this->conn->fetchAssociative('SELECT `params`, `reverses_id` FROM `batch_values_change_set` WHERE `id` = ?', [$id]);
        } catch (\Throwable $e) {
            return [];
        }
        if (!$row) {
            return [];
        }
        $params = $row['params'] ?? null;
        $data = is_string($params) ? json_decode($params, true) : null;
        $out = [];
        // A run that puts another run back says so, the way Batch Values says it.
        if (!empty($row['reverses_id'])) {
            $out[] = ['label' => 'Cancels', 'text' => '#' . (int) $row['reverses_id']];
        }
        foreach ((array) ($data['display'] ?? []) as $line) {
            $label = is_array($line) ? trim((string) ($line['label'] ?? '')) : '';
            $text = is_array($line) ? trim((string) ($line['text'] ?? '')) : '';
            if ($text !== '') {
                $out[] = ['label' => $label, 'text' => $text];
            }
        }
        return $this->runs[$id] = $out;
    }

    private function setting(string $field, $id): string
    {
        if ($id === null) {
            return '<span class="rh-none">none</span>';
        }
        $id = (int) $id;
        switch ($field) {
            case 'o:is_public':
                return $id ? 'Public' : '<span class="rh-private">Private</span>';
            case 'o:resource_template':
                return isset($this->templates[$id]) ? $this->e($this->templates[$id]) : '<span class="rh-gone">template deleted</span> <span class="rh-id">#' . $id . '</span>';
            case 'o:resource_class':
                return isset($this->classes[$id]) ? $this->e($this->classes[$id]) : '<span class="rh-id">class #' . $id . '</span>';
            case 'o:owner':
                return isset($this->users[$id]) ? $this->e($this->users[$id]) : '<span class="rh-gone">account removed</span> <span class="rh-id">#' . $id . '</span>';
            case 'o:item_set':
            case 'o:primary_media':
            case 'o:media':
            // The item a media was put into, or taken out of.
            case 'o:item':
            // One line of a level of the item sets tree: the item set that sits there.
            case 'rank':
                $t = $this->titleOf($id);
                return $this->e($this->nameOf($id)) . ' <span class="rh-id">#' . $id . '</span>' . ($t['gone'] ? ' <span class="rh-gone">deleted</span>' : '');
            case 'o:site':
                return isset($this->sites[$id]) ? $this->e($this->sites[$id]) : '<span class="rh-gone">site deleted</span> <span class="rh-id">#' . $id . '</span>';
            case 'o:thumbnail':
                return isset($this->assets[$id]) ? $this->e($this->assets[$id]) . ' <span class="rh-id">#' . $id . '</span>' : '<span class="rh-id">#' . $id . '</span>';
            case 'o:is_open':
                return $id ? 'Yes' : 'No';
        }
        return '#' . $id;
    }

    /**
     * One field, before and after, line by line: what leaves it struck through, what
     * comes into it in green, and — for the values somebody really moved — an arrow
     * saying how many places up or down. A value whose place changed only because
     * another value left or came in is not marked as moved; it says where it goes.
     *
     * The same drawing is used by the history page and by the dialog that asks before
     * a save, so what a person is shown before saving is what they will read afterwards.
     */
    public function fieldView(array $view, string $field, string $capBefore = 'Before', string $capAfter = 'After'): string
    {
        $cell = function (?array $one, string $side) use ($field) {
            if (!$one) {
                return '<div class="rh-fv-cell rh-fv-empty"></div>';
            }
            $mark = '';
            if ($one['state'] === 'moved' && !empty($one['move'])) {
                $up = $one['move'] > 0;
                $mark = ' <span class="rh-fv-mv ' . ($up ? 'rh-fv-up' : 'rh-fv-down') . '">'
                    . ($up ? '↑' : '↓') . ' ' . abs((int) $one['move']) . '</span>';
            } elseif ($one['state'] === 'stay' && isset($one['other'])) {
                $mark = ' <span class="rh-fv-mv rh-fv-quiet">' . ($side === 'b' ? '→ ' . (int) $one['other'] : 'was ' . (int) $one['other']) . '</span>';
            }
            return '<div class="rh-fv-cell rh-fv-' . $this->e($one['state']) . '">'
                . '<span class="rh-fv-n">' . (int) $one['n'] . '</span>'
                . '<span class="rh-fv-t">' . $this->value($one['v'], $field) . $mark . '</span></div>';
        };
        $n = max(count($view['before']), count($view['after']));
        $out = '<div class="rh-fv">'
            . '<div class="rh-fv-cap">' . $this->e($capBefore) . ' <b>' . count($view['before']) . '</b></div>'
            . '<div class="rh-fv-cap">' . $this->e($capAfter) . ' <b>' . count($view['after']) . '</b></div>';
        for ($i = 0; $i < $n; $i++) {
            $out .= $cell($view['before'][$i] ?? null, 'b') . $cell($view['after'][$i] ?? null, 'a');
        }
        $out .= '</div><div class="rh-fv-one">';
        foreach (\RevisionHistory\View\FieldView::merged($view) as $one) {
            $out .= $cell($one, $one['side']);
        }
        return $out . '</div>';
    }

    /** The value annotations hanging on one value, as Omeka names them. */
    public function notes(array $v): string
    {
        if (empty($v['note'])) {
            return '';
        }
        $notes = [];
        foreach ($v['note'] as $n) {
            // Its language and visibility as well: a value of an annotation is made
            // private with its own eye, and without the mark both sides read the same.
            $notes[] = '<span class="rh-note-f">' . $this->e($this->fieldLabel($n['f'] ?? '')) . ':</span> ' . $this->valueBody($n)
                . (!empty($n['lang']) ? ' <span class="rh-lang">' . $this->e($n['lang']) . '</span>' : '')
                . (isset($n['pub']) && !$n['pub'] ? ' <span class="rh-private">Private</span>' : '');
        }
        return '<div class="rh-note">Value annotations — ' . implode(' · ', $notes) . '</div>';
    }

    /**
     * What happened to the value annotations of one value, in words: each annotation
     * value added, removed, edited, made private or public, by the name of its field.
     * Values that are on both sides, exactly, are left out; what is left is paired by
     * field, in order — the same rule a field's own values are compared by.
     */
    public function noteWords(array $b, array $a): string
    {
        $key = function ($v) {
            return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        };
        $old = array_values((array) ($b['note'] ?? []));
        $new = array_values((array) ($a['note'] ?? []));
        foreach ($old as $i => $x) {
            foreach ($new as $j => $y) {
                if ($key($x) === $key($y)) {
                    unset($old[$i], $new[$j]);
                    break;
                }
            }
        }
        $out = [];
        $name = function (array $n) {
            return $this->fieldLabel((string) ($n['f'] ?? ''));
        };
        foreach ($old as $x) {
            $pair = null;
            foreach ($new as $j => $y) {
                if (($y['f'] ?? '') === ($x['f'] ?? '')) {
                    $pair = $j;
                    break;
                }
            }
            if ($pair === null) {
                $out[] = $name($x) . ' removed';
                continue;
            }
            $y = $new[$pair];
            unset($new[$pair]);
            $rest = function (array $n, array $except) {
                foreach ($except as $k) {
                    unset($n[$k]);
                }
                return $n;
            };
            if ($rest($x, ['pub']) == $rest($y, ['pub'])) {
                $out[] = $name($x) . ' ' . (!empty($y['pub']) ? 'Private → Public' : 'Public → Private');
            } elseif ($rest($x, ['lang']) == $rest($y, ['lang'])) {
                $out[] = $name($x) . ' language ' . (($x['lang'] ?? '') ?: '—') . ' → ' . (($y['lang'] ?? '') ?: '—');
            } else {
                $out[] = $name($x) . ' edited';
            }
        }
        foreach ($new as $y) {
            $out[] = $name($y) . ' added';
        }
        return 'Value annotations' . ($out ? ': ' . implode(' · ', $out) : '');
    }

    /**
     * Before and after of an edit, marked where they differ. When only the
     * visibility or the language changed, it says that in words instead.
     */
    /**
     * Which place in its field a value had, for a field that held more than one. A
     * value on its own needs no place; a value among five does.
     */
    public function place(array $c): string
    {
        $pb = $c['place_before'] ?? null;
        $pa = $c['place_after'] ?? null;
        $nb = (int) ($c['size_before'] ?? 0);
        $na = (int) ($c['size_after'] ?? 0);
        $chip = function (string $text) {
            return ' <span class="rh-fv-mv rh-fv-quiet">' . $this->e($text) . '</span>';
        };
        if ($c['op'] === 'added') {
            return ($pa !== null && $na > 1) ? $chip($pa . ' of ' . $na) : '';
        }
        if ($c['op'] === 'removed') {
            return ($pb !== null && $nb > 1) ? $chip('was ' . $pb . ' of ' . $nb) : '';
        }
        if ($c['op'] === 'edited' && $pb !== null && $pa !== null && max($nb, $na) > 1) {
            if ($pb === $pa && $nb === $na) {
                return $chip($pa . ' of ' . $na);
            }
            return $chip('was ' . $pb . ' of ' . $nb . ', now ' . $pa . ' of ' . $na);
        }
        return '';
    }

    public function edit(array $c): string
    {
        $b = $c['before'];
        $a = $c['after'];
        if ($c['op'] === 'order') {
            return $this->fieldView(
                \RevisionHistory\View\FieldView::build((array) $b, (array) $a),
                (string) ($c['field'] ?? '')
            );
        }
        if (is_array($b) && is_array($a) && ($b['type'] ?? '') === 'o-module-image_annotate:annotation' && ($a['type'] ?? '') === 'o-module-image_annotate:annotation') {
            if ((string) $b['value'] === (string) $a['value']) {
                return nl2br($this->e((string) $a['value'])) . ' <span class="rh-what">' . $this->e($this->where($b['where'])) . ' → ' . $this->e($this->where($a['where'])) . '</span>';
            }
            [$bh, $ah] = self::marked((string) $b['value'], (string) $a['value'], [$this, 'e']);
            return '<div class="rh-pair"><div class="rh-b"><span class="rh-cap">Before</span>' . $bh . ' <span class="rh-id">' . $this->e($this->where($b['where'])) . '</span></div>'
                . '<div class="rh-a"><span class="rh-cap">After</span>' . $ah . ' <span class="rh-id">' . $this->e($this->where($a['where'])) . '</span></div></div>';
        }
        if ((is_array($b) && array_key_exists('id', $b) && !isset($b['type'])) || (is_array($a) && array_key_exists('id', $a) && !isset($a['type']))) {
            return $this->value($b, $c['field']) . ' <span class="rh-arrow">→</span> ' . $this->value($a, $c['field']);
        }
        if (is_array($b) && is_array($a) && isset($b['type'], $a['type'])) {
            $same = function (array $x, array $y, array $except) {
                foreach ($except as $k) {
                    unset($x[$k], $y[$k]);
                }
                return $x == $y;
            };
            if ($same($b, $a, ['pub'])) {
                return $this->valueBody($a) . ' <span class="rh-what">Visibility: ' . ($a['pub'] ? 'Private → Public' : 'Public → Private') . '</span>';
            }
            if ($same($b, $a, ['lang'])) {
                return $this->value(array_merge($a, ['lang' => null]), $c['field'])
                    . ' <span class="rh-what">Language: ' . $this->e($b['lang'] ?: '—') . ' → ' . $this->e($a['lang'] ?: '—') . '</span>';
            }
            if ($same($b, $a, ['note'])) {
                return $this->valueBody($a) . ' <span class="rh-what">' . $this->e($this->noteWords($b, $a)) . '</span>'
                    . '<div class="rh-pair"><div class="rh-b"><span class="rh-cap">Before</span>' . $this->value($b, $c['field']) . '</div>'
                    . '<div class="rh-a"><span class="rh-cap">After</span>' . $this->value($a, $c['field']) . '</div></div>';
            }
            $plain = function (array $v) {
                return empty($v['vr']) && empty($v['uri']) && strpos((string) $v['type'], 'vocabsync:') !== 0;
            };
            if ($plain($b) && $plain($a) && $b['type'] === $a['type']) {
                [$bh, $ah] = self::marked((string) $b['value'], (string) $a['value'], [$this, 'e']);
                // Its value annotations are shown on both sides too: a value whose words
                // and whose annotation changed in the same save (a caption moved out of the
                // text into an annotation) must show both.
                $tail = function (array $v) {
                    return (!empty($v['lang']) ? ' <span class="rh-lang">' . $this->e($v['lang']) . '</span>' : '')
                        . (isset($v['pub']) && !$v['pub'] ? ' <span class="rh-private">Private</span>' : '')
                        . $this->notes($v);
                };
                // A short word or a number changed reads at a glance on one line; the
                // two boxes are for text long enough to need somewhere to look.
                $short = function (array $v) {
                    return mb_strlen((string) $v['value']) <= 24 && strpos((string) $v['value'], "\n") === false;
                };
                if ($short($b) && $short($a) && $tail($b) === '' && $tail($a) === '') {
                    return $bh . ' <span class="rh-arrow">→</span> ' . $ah;
                }
                return '<div class="rh-pair"><div class="rh-b"><span class="rh-cap">Before</span>' . $bh . $tail($b) . '</div>'
                    . '<div class="rh-a"><span class="rh-cap">After</span>' . $ah . $tail($a) . '</div></div>';
            }
        }
        $bHtml = $this->value($b, $c['field']);
        $aHtml = $this->value($a, $c['field']);
        if (is_array($b) && is_array($a) && $bHtml === $aHtml) {
            // Two sides that read the same on the page still differ in the record —
            // say in words what differs, rather than show two identical boxes.
            return $aHtml . ' <span class="rh-what">' . $this->e($this->differs($b, $a)) . '</span>';
        }
        return '<div class="rh-pair"><div class="rh-b"><span class="rh-cap">Before</span>' . $bHtml . '</div>'
            . '<div class="rh-a"><span class="rh-cap">After</span>' . $this->value($a, $c['field']) . '</div></div>';
    }

    /** The exact kinds of link Omeka has, beside the one that does not say which. */
    const EXACT = ['resource:item', 'resource:itemset', 'resource:media'];

    /**
     * A link saved with its exact data type, and nothing else about it changed.
     *
     * Omeka has two ways of saying the same thing: `resource`, which does not say what
     * kind of thing is linked, and `resource:item` and its two companions, which do.
     * Batch Values, when it is set to, makes the first into the second as a resource is
     * saved — so a person who changed one word can find a dozen lines under their name
     * saying the data type of a link changed, which they never asked for and cannot
     * read as anything they did. Every one of those is still written down; the page
     * gathers them into one line so they do not bury the change that was made.
     */
    public static function exactType(array $c): bool
    {
        if (($c['op'] ?? '') !== 'edited') {
            return false;
        }
        $b = $c['before'] ?? null;
        $a = $c['after'] ?? null;
        if (!is_array($b) || !is_array($a)) {
            return false;
        }
        if (($b['type'] ?? '') !== 'resource' || !in_array($a['type'] ?? '', self::EXACT, true)) {
            return false;
        }
        unset($b['type'], $a['type']);
        return $b == $a;
    }

    /** What differs between two values, in words, for differences the page does not draw. */
    public function differs(array $b, array $a): string
    {
        $out = [];
        $word = function ($x) {
            return ($x === null || $x === '') ? '—' : (string) $x;
        };
        if (($b['type'] ?? '') !== ($a['type'] ?? '')) {
            $out[] = 'Data types: ' . $word($b['type'] ?? '') . ' → ' . $word($a['type'] ?? '');
        }
        if (($b['vr'] ?? null) !== ($a['vr'] ?? null)) {
            $out[] = 'Resource: #' . $word($b['vr'] ?? null) . ' → #' . $word($a['vr'] ?? null);
        }
        if (($b['value'] ?? null) !== ($a['value'] ?? null)) {
            $out[] = 'Value: ' . $word($b['value'] ?? null) . ' → ' . $word($a['value'] ?? null);
        }
        if (($b['uri'] ?? null) !== ($a['uri'] ?? null)) {
            $out[] = 'URI: ' . $word($b['uri'] ?? null) . ' → ' . $word($a['uri'] ?? null);
        }
        if (($b['lang'] ?? null) !== ($a['lang'] ?? null)) {
            $out[] = 'Language: ' . $word($b['lang'] ?? null) . ' → ' . $word($a['lang'] ?? null);
        }
        if ((int) ($b['pub'] ?? 1) !== (int) ($a['pub'] ?? 1)) {
            $out[] = 'Visibility: ' . (!empty($a['pub']) ? 'Private → Public' : 'Public → Private');
        }
        if (($b['note'] ?? []) != ($a['note'] ?? [])) {
            $out[] = $this->noteWords($b, $a);
        }
        return $out ? implode(' · ', $out) : 'changed in a way this page cannot show';
    }

    /**
     * Two texts, with the words that differ wrapped in <del> and <ins>.
     * Thai has no spaces between words, so a run of Thai is split into
     * characters; everything else into words and spaces.
     *
     * @return array{0:string, 1:string}
     */
    public static function marked(string $b, string $a, callable $e): array
    {
        $split = function (string $s) {
            // A Thai letter together with the vowel and tone marks written on it, so a
            // change is never drawn through the middle of one written character.
            preg_match_all('/(?=[\x{0E00}-\x{0E7F}])\X|[^\s\x{0E00}-\x{0E7F}]+|\s+/u', $s, $m);
            return $m[0];
        };
        $x = $split($b);
        $y = $split($a);
        $n = count($x);
        $m = count($y);
        if ($n * $m > 250000) {
            return ['<del>' . nl2br($e($b)) . '</del>', '<ins>' . nl2br($e($a)) . '</ins>'];
        }
        // Common prefix and suffix first: most edits touch a few words in the middle.
        $pre = 0;
        while ($pre < $n && $pre < $m && $x[$pre] === $y[$pre]) {
            $pre++;
        }
        $suf = 0;
        while ($suf < $n - $pre && $suf < $m - $pre && $x[$n - 1 - $suf] === $y[$m - 1 - $suf]) {
            $suf++;
        }
        $xs = array_slice($x, $pre, $n - $pre - $suf);
        $ys = array_slice($y, $pre, $m - $pre - $suf);
        $p = count($xs);
        $q = count($ys);
        $L = array_fill(0, $p + 1, array_fill(0, $q + 1, 0));
        for ($i = $p - 1; $i >= 0; $i--) {
            for ($j = $q - 1; $j >= 0; $j--) {
                $L[$i][$j] = $xs[$i] === $ys[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
            }
        }
        $bo = [];
        $ao = [];
        $i = 0;
        $j = 0;
        while ($i < $p || $j < $q) {
            if ($i < $p && $j < $q && $xs[$i] === $ys[$j]) {
                $bo[] = [0, $xs[$i]];
                $ao[] = [0, $ys[$j]];
                $i++;
                $j++;
            } elseif ($j < $q && ($i >= $p || $L[$i][$j + 1] >= $L[$i + 1][$j])) {
                $ao[] = [1, $ys[$j]];
                $j++;
            } else {
                $bo[] = [1, $xs[$i]];
                $i++;
            }
        }
        $join = function (array $head, array $mid, array $tail, string $tag) use ($e) {
            $out = $e(implode('', $head));
            $run = '';
            $open = false;
            foreach ($mid as [$diff, $tok]) {
                if ($diff && !$open) {
                    $out .= $e($run);
                    $run = '';
                    $open = true;
                } elseif (!$diff && $open) {
                    $out .= "<$tag>" . $e($run) . "</$tag>";
                    $run = '';
                    $open = false;
                }
                $run .= $tok;
            }
            $out .= $open ? "<$tag>" . $e($run) . "</$tag>" : $e($run);
            $out .= $e(implode('', $tail));
            return nl2br($out === '' ? '' : $out);
        };
        $head = array_slice($x, 0, $pre);
        return [
            $b === '' ? '<span class="rh-none">empty</span>' : $join($head, $bo, array_slice($x, $n - $suf), 'del'),
            $a === '' ? '<span class="rh-none">empty</span>' : $join($head, $ao, array_slice($y, $m - $suf), 'ins'),
        ];
    }
}
