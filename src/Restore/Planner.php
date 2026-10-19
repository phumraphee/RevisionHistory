<?php
namespace RevisionHistory\Restore;

/**
 * What putting one save back would do, worked out before anything is written.
 *
 * One entry of the history says, field by field, what a save did: a value added, a
 * value removed, a value edited, a field reordered, a setting of the resource changed.
 * Putting it back is doing the opposite of each — but only where the resource still
 * holds what that save left. A value somebody has edited since is theirs now: it is
 * not overwritten, and the page says so.
 *
 * Nothing here reads or writes the database. The state is the one StateReader reads.
 */
class Planner
{
    /** The resource's own settings, as Diff names them, and their key in the state. */
    const OWN = ['o:is_public' => 'is_public', 'o:resource_template' => 'template', 'o:resource_class' => 'class', 'o:owner' => 'owner',
        'o:thumbnail' => 'thumbnail', 'o:primary_media' => 'primary_media', 'o:is_open' => 'is_open'];

    /** A media's settings that Omeka's API takes back, and their key in the state. */
    const MEDIA = ['o:alt_text' => 'alt_text', 'o:lang' => 'lang'];

    /**
     * @param array $changes the entry's rows (op, field, property_id, before, after)
     * @param array $now what the resource holds now (StateReader)
     * @return array{rows: array, values: array, props: array, own: array, item_sets: ?array, fields: string[], any: bool}
     *   rows: one per change, with 'ok' true (will be put back) or a 'why' word
     *   values: every field of the resource as it would be afterwards (term => sigs)
     */
    public static function plan(array $changes, array $now, ?callable $exists = null): array
    {
        $values = $now['values'] ?? [];
        $props = $now['props'] ?? [];
        $own = [];
        $sets = null;
        $sites = null;
        $mediaOrder = null;
        $media = [];
        $fields = [];
        $rows = [];

        $key = function ($v) {
            return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        };
        $find = function (array $list, $v) use ($key) {
            foreach ($list as $i => $x) {
                if ($key($x) === $key($v)) {
                    return $i;
                }
            }
            return null;
        };

        foreach ($changes as $c) {
            $op = $c['op'];
            // The whole field, written down so the history can draw it. It says the same
            // thing as the lines beside it, and doing both would undo one with the other.
            if ($op === 'field') {
                continue;
            }
            $field = $c['field'];
            $b = $c['before'];
            $a = $c['after'];
            $row = $c + ['ok' => false, 'why' => ''];

            if (isset(self::OWN[$field])) {
                $k = self::OWN[$field];
                $cur = $now[$k] ?? null;
                if (($a['id'] ?? null) === $cur) {
                    $own[$field] = $b['id'] ?? null;
                    $row['ok'] = true;
                } else {
                    $row['why'] = 'changed since';
                }
                $rows[] = $row;
                continue;
            }
            if ($field === 'o:site') {
                $list = $sites ?? ($now['sites'] ?? []);
                if ($op === 'added') {
                    $i = array_search((int) ($a['id'] ?? 0), $list, true);
                    if ($i === false) {
                        $row['why'] = 'changed since';
                    } else {
                        unset($list[$i]);
                        $row['ok'] = true;
                    }
                } elseif ($op === 'removed') {
                    if (in_array((int) ($b['id'] ?? 0), $list, true)) {
                        $row['why'] = 'already there';
                    } else {
                        $list[] = (int) $b['id'];
                        $row['ok'] = true;
                    }
                }
                $sites = array_values($list);
                $rows[] = $row;
                continue;
            }
            if ($field === 'o:media' && $op === 'order') {
                $cur = $now['media_order'] ?? [];
                $after = array_map('intval', array_column((array) $a, 'id'));
                // The ones that are still there must still be in the order that save left,
                // whatever has been added since.
                if (array_values(array_intersect($cur, $after)) === array_values(array_intersect($after, $cur))) {
                    // The old order, for the media still there; any added since go last.
                    $want = array_values(array_intersect(array_map('intval', array_column((array) $b, 'id')), $cur));
                    $mediaOrder = array_merge($want, array_values(array_diff($cur, $want)));
                    $row['ok'] = true;
                } else {
                    $row['why'] = 'changed since';
                }
                $rows[] = $row;
                continue;
            }
            if (isset(self::MEDIA[$field])) {
                $cur = $now['media'][self::MEDIA[$field]] ?? null;
                if (($a['value'] ?? null) === $cur) {
                    $media[$field] = $b['value'] ?? null;
                    $row['ok'] = true;
                } else {
                    $row['why'] = 'changed since';
                }
                $rows[] = $row;
                continue;
            }
            if ($field === 'o:item_set') {
                $list = $sets ?? ($now['item_sets'] ?? []);
                if ($op === 'added') {
                    $i = array_search((int) ($a['id'] ?? 0), $list, true);
                    if ($i === false) {
                        $row['why'] = 'changed since';
                    } else {
                        unset($list[$i]);
                        $row['ok'] = true;
                    }
                } elseif ($op === 'removed') {
                    if (in_array((int) ($b['id'] ?? 0), $list, true)) {
                        $row['why'] = 'already there';
                    } else {
                        $list[] = (int) $b['id'];
                        $row['ok'] = true;
                    }
                }
                $sets = array_values($list);
                $rows[] = $row;
                continue;
            }
            if (strpos($field, ':') === false || strpos($field, 'o:') === 0 || strpos($field, 'o-module-') === 0) {
                // Image Annotate's notes and anything else that is not a value of a field.
                $row['why'] = 'cannot be put back here';
                $rows[] = $row;
                continue;
            }

            // A link to a resource that has since been deleted cannot be made again.
            if ($exists && in_array($op, ['removed', 'edited'], true) && is_array($b) && !empty($b['vr']) && !$exists((int) $b['vr'])) {
                $row['why'] = 'no longer exists';
                $rows[] = $row;
                continue;
            }

            $list = $values[$field] ?? [];
            if ($op === 'added') {
                $i = $find($list, $a);
                if ($i === null) {
                    $row['why'] = 'changed since';
                } else {
                    array_splice($list, $i, 1);
                    $row['ok'] = true;
                }
            } elseif ($op === 'removed') {
                if ($find($list, $b) !== null && self::count($list, $b, $key) >= self::count(self::wanted($changes, $field, 'before'), $b, $key)) {
                    $row['why'] = 'already there';
                } else {
                    $list[] = $b;
                    $row['ok'] = true;
                }
            } elseif ($op === 'edited') {
                $i = $find($list, $a);
                if ($i === null) {
                    $row['why'] = 'changed since';
                } else {
                    $list[$i] = $b;
                    $row['ok'] = true;
                }
            } elseif ($op === 'order') {
                if (array_map($key, $list) === array_map($key, (array) $a)) {
                    $list = array_values((array) $b);
                    $row['ok'] = true;
                } else {
                    $row['why'] = 'changed since';
                }
            }
            if ($row['ok']) {
                $values[$field] = array_values($list);
                if (!$values[$field]) {
                    unset($values[$field]);
                }
                if (!isset($props[$field]) && $c['property_id']) {
                    $props[$field] = (int) $c['property_id'];
                }
                $fields[$field] = true;
            }
            $rows[] = $row;
        }

        // Fields that come back go where they were: right after the field that came
        // before them in the entry (which lists fields in the resource's old order).
        // Omeka shows a resource's fields in the order of their values.
        $order = array_keys($now['values'] ?? []);
        $prev = null;
        foreach ($changes as $c) {
            $f = $c['field'];
            if (!isset($values[$f])) {
                continue;
            }
            if (!in_array($f, $order, true)) {
                $at = $prev === null ? 0 : array_search($prev, $order, true) + 1;
                array_splice($order, $at, 0, [$f]);
            }
            $prev = $f;
        }
        $sorted = [];
        foreach ($order as $f) {
            if (isset($values[$f])) {
                $sorted[$f] = $values[$f];
            }
        }
        $values = $sorted + $values;

        $any = false;
        foreach ($rows as $r) {
            $any = $any || $r['ok'];
        }
        return [
            'rows' => $rows,
            'values' => $values,
            'props' => $props,
            'own' => $own,
            'item_sets' => $sets,
            'sites' => $sites,
            'media_order' => $mediaOrder,
            'media' => $media,
            'fields' => array_keys($fields),
            'any' => $any,
        ];
    }

    private static function count(array $list, $v, callable $key): int
    {
        $n = 0;
        foreach ($list as $x) {
            if ($key($x) === $key($v)) {
                $n++;
            }
        }
        return $n;
    }

    /** The values of one field that the entry says were there before the save. */
    private static function wanted(array $changes, string $field, string $side): array
    {
        $out = [];
        foreach ($changes as $c) {
            if ($c['field'] === $field && $c['op'] === 'removed' && $c[$side] !== null) {
                $out[] = $c[$side];
            }
        }
        return $out;
    }

    /**
     * One value, the way Omeka's API takes it. Every data type reads the keys it knows:
     * text from @value, a link from @id and o:label, a linked resource from
     * value_resource_id. $label turns a stored value into what the data type expects to
     * be given (Vocab Sync is given a term's label and finds the term itself).
     */
    public static function apiValue(array $v, int $propertyId, ?callable $label = null, ?callable $propertyOf = null): array
    {
        $o = ['type' => $v['type'], 'property_id' => $propertyId, 'is_public' => (bool) ($v['pub'] ?? 1)];
        if (($v['vr'] ?? null) !== null) {
            $o['value_resource_id'] = (int) $v['vr'];
        }
        if (($v['uri'] ?? null) !== null) {
            $o['@id'] = $v['uri'];
            if (($v['value'] ?? null) !== null) {
                $o['o:label'] = $v['value'];
            }
        } elseif (($v['vr'] ?? null) === null) {
            $o['@value'] = $label ? $label($v) : $v['value'];
        }
        if (($v['lang'] ?? null) !== null) {
            $o['@language'] = $v['lang'];
        }
        // Its value annotations, each one a value of its own field.
        if (!empty($v['note'])) {
            $notes = [];
            foreach ($v['note'] as $n) {
                $term = (string) ($n['f'] ?? '');
                $pid = $propertyOf ? (int) $propertyOf($term) : 0;
                if ($term === '' || !$pid) {
                    continue;
                }
                $notes[$term][] = self::apiValue(array_diff_key($n, ['f' => 1]), $pid, $label, $propertyOf);
            }
            if ($notes) {
                $o['@annotation'] = $notes;
            }
        }
        return $o;
    }
}
