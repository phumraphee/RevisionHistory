<?php
namespace RevisionHistory\Restore;

use Doctrine\DBAL\Connection;

/**
 * Puts one save back, through Omeka's own API, so the putting back is itself a save
 * like any other: it is recorded, by the person who asked for it.
 *
 * Omeka keeps no id on a value: a save sends every value of the resource, and Omeka
 * reuses its rows in order and removes the rest. So the whole resource is sent — what
 * it holds now, with only the fields this save touched put back — never one field
 * alone (sending one field alone is what empties all the others).
 */
class Restorer
{
    /** @var Connection */
    private $conn;

    /** @var array term => property id */
    private $props = [];

    public function __construct(Connection $conn)
    {
        $this->conn = $conn;
    }

    /** Only saves recorded here, as they happened, say exactly what was there before. */
    public static function can(array $event): bool
    {
        return ($event['source'] ?? '') === 'here'
            && ($event['action'] ?? '') === 'edited'
            && in_array($event['resource_kind'] ?? '', ['item', 'media', 'item_set'], true);
    }

    public static function apiName(string $kind): string
    {
        return ['item' => 'items', 'media' => 'media', 'item_set' => 'item_sets'][$kind] ?? 'items';
    }

    public function propertyOf(string $term): int
    {
        if (!array_key_exists($term, $this->props)) {
            [$prefix, $local] = array_pad(explode(':', $term, 2), 2, '');
            $this->props[$term] = (int) $this->conn->fetchOne(
                'SELECT p.id FROM property p JOIN vocabulary v ON v.id = p.vocabulary_id WHERE v.prefix = ? AND p.local_name = ?',
                [$prefix, $local]
            );
        }
        return $this->props[$term];
    }

    /**
     * What a Vocab Sync value must be given as for it to find the same term again. It
     * looks a term up by its label first and by its number after, so the label is sent
     * when that label leads back to this term, the number when no label reads like
     * that number, and nothing (null) when neither is sure.
     */
    public function vocabSyncInput(array $v): ?string
    {
        if (!preg_match('/^vocabsync:(\d+)$/', (string) $v['type'], $m) || !ctype_digit((string) $v['value'])) {
            return (string) $v['value'];
        }
        $vocab = (int) $m[1];
        $id = (int) $v['value'];
        try {
            $label = $this->conn->fetchOne('SELECT label FROM vocab_sync_term WHERE id = ? AND vocab_id = ?', [$id, $vocab]);
            if ($label !== false && $label !== null) {
                $first = $this->conn->fetchOne('SELECT id FROM vocab_sync_term WHERE vocab_id = ? AND label = ? ORDER BY id ASC LIMIT 1', [$vocab, $label]);
                if ((int) $first === $id) {
                    return (string) $label;
                }
            }
            $clash = $this->conn->fetchOne('SELECT id FROM vocab_sync_term WHERE vocab_id = ? AND label = ? LIMIT 1', [$vocab, (string) $id]);
            if ($clash === false || $clash === null) {
                return (string) $id;
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    /**
     * The request to send: every field of the resource as it would be afterwards,
     * and the resource's own settings this save changed.
     *
     * @return array{content: array, unsure: array} unsure: values that could not be
     *   said in a way that brings them back exactly (nothing is sent when there are any)
     */
    public function content(array $plan, string $kind): array
    {
        $unsure = [];
        $label = function (array $v) use (&$unsure) {
            $in = $this->vocabSyncInput($v);
            if ($in === null) {
                $unsure[] = $v;
                return (string) $v['value'];
            }
            return $in;
        };
        $propertyOf = function (string $term) {
            return $this->propertyOf($term);
        };
        $content = [];
        foreach ($plan['values'] as $term => $list) {
            $pid = (int) ($plan['props'][$term] ?? 0) ?: $this->propertyOf($term);
            foreach ($list as $v) {
                $content[$term][] = Planner::apiValue($v, $pid, $label, $propertyOf);
            }
        }
        foreach ($plan['own'] as $field => $id) {
            if ($field === 'o:is_public' || $field === 'o:is_open') {
                $content[$field] = (bool) $id;
            } else {
                $content[$field] = $id === null ? null : ['o:id' => (int) $id];
            }
        }
        if ($plan['item_sets'] !== null && $kind === 'item') {
            $content['o:item_set'] = array_map(function ($id) {
                return ['o:id' => (int) $id];
            }, $plan['item_sets']);
        }
        if (($plan['sites'] ?? null) !== null && $kind === 'item') {
            $content['o:site'] = array_map(function ($id) {
                return ['o:id' => (int) $id];
            }, $plan['sites']);
        }
        // Every media of the item, in the order wanted: one left out would be deleted.
        if (($plan['media_order'] ?? null) !== null && $kind === 'item') {
            $content['o:media'] = array_map(function ($id) {
                return ['o:id' => (int) $id];
            }, $plan['media_order']);
        }
        foreach ($plan['media'] ?? [] as $field => $v) {
            if ($kind === 'media') {
                $content[$field] = $v;
            }
        }
        return ['content' => $content, 'unsure' => $unsure];
    }

    /**
     * After writing: every field that does not read the way it was meant to. Checked
     * over the whole resource, not only the fields put back, since every value went
     * through Omeka again.
     *
     * @return string[] the fields (terms) that differ
     */
    public static function differences(array $plan, array $after): array
    {
        $key = function ($v) {
            return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        };
        $out = [];
        $terms = array_unique(array_merge(array_keys($plan['values']), array_keys($after['values'] ?? [])));
        foreach ($terms as $t) {
            if (array_map($key, $plan['values'][$t] ?? []) !== array_map($key, $after['values'][$t] ?? [])) {
                $out[] = $t;
            }
        }
        foreach ($plan['own'] as $field => $id) {
            if (($after[Planner::OWN[$field]] ?? null) !== $id) {
                $out[] = $field;
            }
        }
        foreach (['sites' => 'o:site', 'media_order' => 'o:media'] as $key => $field) {
            if (($plan[$key] ?? null) !== null) {
                $want = $plan[$key];
                $have = $after[$key] ?? [];
                if ($key === 'sites') {
                    sort($want);
                    sort($have);
                }
                if ($want !== $have) {
                    $out[] = $field;
                }
            }
        }
        foreach ($plan['media'] ?? [] as $field => $v) {
            if (($after['media'][Planner::MEDIA[$field]] ?? null) !== $v) {
                $out[] = $field;
            }
        }
        if ($plan['item_sets'] !== null) {
            $want = $plan['item_sets'];
            $have = $after['item_sets'] ?? [];
            sort($want);
            sort($have);
            if ($want !== $have) {
                $out[] = 'o:item_set';
            }
        }
        return $out;
    }
}
