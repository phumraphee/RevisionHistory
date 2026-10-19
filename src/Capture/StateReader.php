<?php
namespace RevisionHistory\Capture;

use Doctrine\DBAL\Connection;

/**
 * What a resource holds, read straight from the tables.
 *
 * Read from the database and not from the entities, because the entities are
 * what is about to change: by the time anyone could ask them, Omeka has already
 * written the new values over the old Value objects (it reuses them in order).
 * The row in the table is the only place where "before" still exists.
 *
 * Plain DBAL, so the visibility filters Omeka puts on Doctrine do not apply:
 * a private value is still a value somebody may have deleted.
 */
class StateReader
{
    /** @var Connection */
    private $conn;

    /** @var string the field whose first value is the accession number */
    private $accessionTerm;

    /** @var bool|null whether the Image Annotate module's table is here */
    private $imageNotes;

    const KINDS = [
        'Omeka\Entity\Item' => 'item',
        'Omeka\Entity\Media' => 'media',
        'Omeka\Entity\ItemSet' => 'item_set',
    ];

    public function __construct(Connection $conn, string $accessionTerm)
    {
        $this->conn = $conn;
        $this->accessionTerm = $accessionTerm;
    }

    /**
     * @param int[] $ids
     * @return array<int, array> id => state. Ids that are not an item, a media
     *   or an item set (or no longer exist) are simply absent.
     */
    public function read(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $out += $this->readChunk($chunk);
        }
        return $out;
    }

    private function readChunk(array $ids): array
    {
        $in = implode(',', $ids);

        $rows = $this->conn->fetchAllAssociative(
            "SELECT r.id, r.resource_type, r.is_public, r.title, r.owner_id,
                    r.resource_class_id, r.resource_template_id, r.thumbnail_id, r.created,
                    m.item_id AS media_item, m.source AS media_source, m.media_type, m.ingester, m.renderer,
                    m.data AS media_data, m.position AS media_position, m.lang AS media_lang, m.alt_text AS media_alt,
                    m.sha256 AS media_sha256, m.size AS media_size, m.extension AS media_extension,
                    m.storage_id AS media_storage, m.has_original AS media_original, m.has_thumbnails AS media_thumbs,
                    i.primary_media_id, s.is_open
               FROM resource r
               LEFT JOIN media m ON m.id = r.id
               LEFT JOIN item i ON i.id = r.id
               LEFT JOIN item_set s ON s.id = r.id
              WHERE r.id IN ($in)"
        );
        $out = [];
        foreach ($rows as $r) {
            $kind = self::KINDS[$r['resource_type']] ?? null;
            if (!$kind) {
                continue;
            }
            $id = (int) $r['id'];
            $out[$id] = [
                'id' => $id,
                'kind' => $kind,
                'title' => $r['title'] === null ? null : (string) $r['title'],
                'accession' => '',
                'is_public' => (int) $r['is_public'],
                'owner' => $r['owner_id'] === null ? null : (int) $r['owner_id'],
                'class' => $r['resource_class_id'] === null ? null : (int) $r['resource_class_id'],
                'template' => $r['resource_template_id'] === null ? null : (int) $r['resource_template_id'],
                'item' => $kind === 'item' ? $id : ($kind === 'media' ? (int) $r['media_item'] : null),
                'item_sets' => [],
                // Kept so a resource can be made again as it was; never compared.
                'created' => $r['created'],
                'thumbnail' => $r['thumbnail_id'] === null ? null : (int) $r['thumbnail_id'],
                'media' => $kind === 'media' ? [
                    'source' => $r['media_source'],
                    'media_type' => $r['media_type'],
                    'ingester' => $r['ingester'],
                    'renderer' => $r['renderer'],
                    'data' => self::json($r['media_data']),
                    'position' => $r['media_position'] === null ? null : (int) $r['media_position'],
                    'lang' => $r['media_lang'] === null || $r['media_lang'] === '' ? null : (string) $r['media_lang'],
                    'alt_text' => $r['media_alt'] === null || $r['media_alt'] === '' ? null : (string) $r['media_alt'],
                    'sha256' => $r['media_sha256'],
                    'size' => $r['media_size'] === null ? null : (int) $r['media_size'],
                    'extension' => $r['media_extension'],
                    'storage_id' => $r['media_storage'],
                    'has_original' => (int) $r['media_original'],
                    'has_thumbnails' => (int) $r['media_thumbs'],
                ] : null,
                'values' => [],
                'props' => [],
            ];
            if ($kind === 'item') {
                $out[$id]['primary_media'] = $r['primary_media_id'] === null ? null : (int) $r['primary_media_id'];
                $out[$id]['sites'] = [];
                $out[$id]['media_order'] = [];
            } elseif ($kind === 'item_set') {
                $out[$id]['is_open'] = (int) $r['is_open'];
            }
        }
        if (!$out) {
            return [];
        }
        $in = implode(',', array_keys($out));

        foreach ($this->conn->fetchAllAssociative(
            "SELECT item_id, item_set_id FROM item_item_set WHERE item_id IN ($in) ORDER BY item_id, item_set_id"
        ) as $r) {
            $out[(int) $r['item_id']]['item_sets'][] = (int) $r['item_set_id'];
        }

        foreach ($this->conn->fetchAllAssociative(
            "SELECT item_id, site_id FROM item_site WHERE item_id IN ($in) ORDER BY item_id, site_id"
        ) as $r) {
            $out[(int) $r['item_id']]['sites'][] = (int) $r['site_id'];
        }
        // The item's media in the order the item shows them.
        foreach ($this->conn->fetchAllAssociative(
            "SELECT item_id, id FROM media WHERE item_id IN ($in) ORDER BY item_id, position IS NULL, position, id"
        ) as $r) {
            if (isset($out[(int) $r['item_id']]['media_order'])) {
                $out[(int) $r['item_id']]['media_order'][] = (int) $r['id'];
            }
        }

        $mediaIds = [];
        foreach ($out as $id => $r) {
            if ($r['kind'] === 'media') {
                $mediaIds[] = $id;
            }
        }
        if ($mediaIds && $this->hasImageNotes()) {
            foreach ($this->conn->fetchAllAssociative(
                'SELECT media_id, annotations FROM image_annotate_media WHERE media_id IN (' . implode(',', $mediaIds) . ')'
            ) as $r) {
                $list = json_decode((string) $r['annotations'], true);
                $out[(int) $r['media_id']]['image_notes'] = self::imageNotes(is_array($list) ? $list : []);
            }
        }

        $values = $this->values(array_keys($out));
        $noteIds = [];
        foreach ($values as $v) {
            if ($v['value_annotation_id']) {
                $noteIds[] = (int) $v['value_annotation_id'];
            }
        }
        $notes = [];
        if ($noteIds) {
            foreach ($this->values($noteIds) as $n) {
                $notes[(int) $n['resource_id']][] = ['f' => $n['term']] + self::sig($n, []);
            }
        }
        foreach ($values as $v) {
            $id = (int) $v['resource_id'];
            $term = $v['term'];
            $out[$id]['values'][$term][] = self::sig($v, $notes[(int) $v['value_annotation_id']] ?? []);
            $out[$id]['props'][$term] = (int) $v['property_id'];
            if ($term === $this->accessionTerm && $out[$id]['accession'] === '' && $v['value'] !== null) {
                $out[$id]['accession'] = mb_substr(trim((string) $v['value']), 0, 190);
            }
        }
        return $out;
    }

    private function hasImageNotes(): bool
    {
        if ($this->imageNotes === null) {
            try {
                $this->imageNotes = (bool) $this->conn->fetchOne("SHOW TABLES LIKE 'image_annotate_media'");
            } catch (\Throwable $e) {
                $this->imageNotes = false;
            }
        }
        return $this->imageNotes;
    }

    /**
     * Image Annotate's annotations on one media (W3C Web Annotations, as Annotorious
     * writes them), in the shape the history compares: which annotation (its id), its
     * body text, and its selector value. The rest of the JSON — the image's source,
     * the @context — is the same for every annotation and says nothing.
     *
     * @return array<string, array{type:string, id:string, value:?string, where:?string}> keyed by the annotation's id
     */
    public static function imageNotes(array $annotations): array
    {
        $out = [];
        foreach ($annotations as $i => $a) {
            if (!is_array($a)) {
                continue;
            }
            $texts = [];
            $bodies = $a['body'] ?? [];
            if (isset($bodies['type']) || isset($bodies['value'])) {
                $bodies = [$bodies];
            }
            foreach ((array) $bodies as $b) {
                if (is_array($b) && isset($b['value']) && is_string($b['value']) && $b['value'] !== '') {
                    $texts[] = $b['value'];
                }
            }
            $sel = $a['target']['selector'] ?? null;
            if (is_array($sel) && isset($sel[0])) {
                $sel = $sel[0];
            }
            $where = null;
            if (is_array($sel)) {
                $where = ($sel['type'] ?? '') === 'SvgSelector' ? 'svg:' . md5((string) ($sel['value'] ?? '')) : (isset($sel['value']) ? (string) $sel['value'] : null);
            }
            $id = isset($a['id']) && is_string($a['id']) && $a['id'] !== '' ? $a['id'] : '#' . $i;
            $out[$id] = [
                'type' => 'o-module-image_annotate:annotation',
                'id' => $id,
                'value' => $texts ? implode("\n", $texts) : null,
                'where' => $where,
                // The annotation as Image Annotate keeps it, shape and all, so it can be put back.
                'raw' => $a,
            ];
        }
        return $out;
    }

    private static function json($text)
    {
        if ($text === null || $text === '') {
            return null;
        }
        $v = json_decode((string) $text, true);
        return $v === null ? (string) $text : $v;
    }

    private function values(array $resourceIds): array
    {
        $in = implode(',', array_map('intval', $resourceIds));
        return $this->conn->fetchAllAssociative(
            "SELECT v.id, v.resource_id, v.property_id, CONCAT(vo.prefix, ':', p.local_name) AS term,
                    v.type, v.lang, v.value, v.uri, v.value_resource_id, v.is_public, v.value_annotation_id
               FROM value v
               JOIN property p ON p.id = v.property_id
               JOIN vocabulary vo ON vo.id = p.vocabulary_id
              WHERE v.resource_id IN ($in)
              ORDER BY v.resource_id, v.id"
        );
    }

    /**
     * One value, in the one shape every comparison uses. Empty strings and
     * nulls mean the same thing on a value, so they are made the same here —
     * otherwise a form that sends "" where the table holds NULL reads as an edit
     * nobody made.
     */
    public static function sig(array $v, array $notes): array
    {
        $blank = function ($x) {
            return ($x === null || $x === '') ? null : (string) $x;
        };
        $s = [
            'type' => (string) $v['type'],
            'value' => $blank($v['value']),
            'uri' => $blank($v['uri']),
            'vr' => $v['value_resource_id'] === null ? null : (int) $v['value_resource_id'],
            'lang' => $blank($v['lang']),
            'pub' => (int) $v['is_public'],
        ];
        if ($notes) {
            $s['note'] = $notes;
        }
        return $s;
    }
}
