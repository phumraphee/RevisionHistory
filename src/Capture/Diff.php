<?php
namespace RevisionHistory\Capture;

use RevisionHistory\View\FieldView;

/**
 * Before and after, turned into the sentences a person asks about:
 * a value was added, a value was removed, a value was edited.
 *
 * Omeka does not say which value became which — it reuses its Value rows in
 * order and writes whatever comes next into them. So the question is answered
 * per field, by what the field held:
 *
 *   1. a value that is on both sides, exactly, did not change (wherever it moved);
 *   2. what is left on the old side and what is left on the new side are paired
 *      in order, and each pair is an edit;
 *   3. anything still left over was added, or removed.
 *
 * If nothing is left over and the order is different, the field was reordered.
 */
class Diff
{
    /**
     * @param array|null $before state from StateReader, null when it did not exist
     * @param array|null $after  state from StateReader, null when it no longer exists
     * @return array<int, array{op:string, field:string, property_id:?int, before:?array, after:?array}>
     */
    public static function changes(?array $before, ?array $after): array
    {
        $out = [];
        $b = $before ?: ['values' => [], 'props' => [], 'item_sets' => []];
        $a = $after ?: ['values' => [], 'props' => [], 'item_sets' => []];

        // The resource's own settings. When it existed on both sides, what changed. When
        // it was deleted, what it held — so that it can be made again as it was. (A new
        // resource "changing" from nothing to public is not something anybody did.)
        $own = ['is_public' => 'o:is_public', 'template' => 'o:resource_template', 'class' => 'o:resource_class', 'owner' => 'o:owner',
            'thumbnail' => 'o:thumbnail', 'primary_media' => 'o:primary_media', 'is_open' => 'o:is_open'];
        foreach ($own as $key => $field) {
            $bv = $b[$key] ?? null;
            $av = $a[$key] ?? null;
            if ($before && $after) {
                if (array_key_exists($key, $b) && array_key_exists($key, $a) && $bv !== $av) {
                    $out[] = self::row('edited', $field, null, ['id' => $bv], ['id' => $av]);
                }
            } elseif ($before && $bv !== null) {
                $out[] = self::row('removed', $field, null, ['id' => $bv], null);
            }
        }

        // A media put into another item. Splitting an item, or putting the media back,
        // moves the file from one item to the other without deleting anything, so
        // nothing else here would say it happened. Only a media can have this: for an
        // item the number is its own, and an item set has none.
        $kind = $b['kind'] ?? ($a['kind'] ?? null);
        if ($kind === 'media' && $before && $after
            && array_key_exists('item', $b) && array_key_exists('item', $a)
            && $b['item'] !== $a['item'] && $b['item'] !== null && $a['item'] !== null
        ) {
            $out[] = self::row('edited', 'o:item', null, ['id' => (int) $b['item']], ['id' => (int) $a['item']]);
        }

        // Sites the item is in: a list of ids with no order.
        $bsi = $b['sites'] ?? [];
        $asi = $a['sites'] ?? [];
        foreach (array_diff($asi, $bsi) as $id) {
            $out[] = self::row('added', 'o:site', null, null, ['id' => (int) $id]);
        }
        foreach (array_diff($bsi, $asi) as $id) {
            $out[] = self::row('removed', 'o:site', null, ['id' => (int) $id], null);
        }

        // The order of the item's media. A media added or removed is its own entry; here
        // only whether the ones on both sides were put in a different order.
        if ($before && $after && isset($b['media_order'], $a['media_order'])) {
            $common = array_values(array_intersect($b['media_order'], $a['media_order']));
            $inAfter = array_values(array_intersect($a['media_order'], $b['media_order']));
            if ($common !== $inAfter) {
                $ids = function (array $list) {
                    return array_map(function ($id) {
                        return ['id' => (int) $id];
                    }, $list);
                };
                $out[] = self::row('order', 'o:media', null, $ids($b['media_order']), $ids($a['media_order']));
            }
        } elseif ($before && !$after && !empty($b['media_order'])) {
            $out[] = self::row('removed', 'o:media', null, array_map(function ($id) {
                return ['id' => (int) $id];
            }, $b['media_order']), null);
        }

        // A media's own file and settings.
        $bm = self::mediaFields($b['media'] ?? null);
        $am = self::mediaFields($a['media'] ?? null);
        foreach (array_unique(array_merge(array_keys($bm), array_keys($am))) as $field) {
            $bv = $bm[$field] ?? null;
            $av = $am[$field] ?? null;
            if ($bv === $av) {
                continue;
            }
            if ($bv === null) {
                $out[] = self::row('added', $field, null, null, $av);
            } elseif ($av === null) {
                $out[] = self::row('removed', $field, null, $bv, null);
            } else {
                $out[] = self::row('edited', $field, null, $bv, $av);
            }
        }

        // Item sets are a list of ids with no order: added and removed only.
        $bs = $b['item_sets'] ?? [];
        $as = $a['item_sets'] ?? [];
        foreach (array_diff($as, $bs) as $id) {
            $out[] = self::row('added', 'o:item_set', null, null, ['id' => (int) $id]);
        }
        foreach (array_diff($bs, $as) as $id) {
            $out[] = self::row('removed', 'o:item_set', null, ['id' => (int) $id], null);
        }

        // Image Annotate annotations on a media. Each has its own id, so each is followed
        // by its id: same id, different body or selector = edited.
        $bn = $b['image_notes'] ?? [];
        $an = $a['image_notes'] ?? [];
        foreach ($an as $id => $note) {
            if (!isset($bn[$id])) {
                $out[] = self::row('added', 'o-module-image_annotate:annotation', null, null, $note);
            } elseif ($bn[$id] !== $note) {
                $out[] = self::row('edited', 'o-module-image_annotate:annotation', null, $bn[$id], $note);
            }
        }
        foreach ($bn as $id => $note) {
            if (!isset($an[$id])) {
                $out[] = self::row('removed', 'o-module-image_annotate:annotation', null, $note, null);
            }
        }

        // Fields, in the order the item shows them: the old order first, then any new field.
        $terms = array_keys($b['values']);
        foreach (array_keys($a['values']) as $t) {
            if (!in_array($t, $terms, true)) {
                $terms[] = $t;
            }
        }
        foreach ($terms as $term) {
            $old = $b['values'][$term] ?? [];
            $new = $a['values'][$term] ?? [];
            $pid = $a['props'][$term] ?? ($b['props'][$term] ?? null);
            foreach (self::field($old, $new) as $c) {
                $out[] = self::row($c[0], $term, $pid, $c[1], $c[2], $c[3] ?? null, $c[4] ?? null, $c[5] ?? null, $c[6] ?? null);
            }
        }
        return $out;
    }

    /**
     * A media's file and settings as history lines. The file itself is known by its
     * fingerprint (sha256) and size: a different file is a different fingerprint.
     *
     * @return array<string, array> field => a value in the shape every line uses
     */
    public static function mediaFields(?array $m): array
    {
        if (!$m) {
            return [];
        }
        $text = function ($v) {
            return ($v === null || $v === '') ? null : ['type' => 'literal', 'value' => (string) $v, 'uri' => null, 'vr' => null, 'lang' => null, 'pub' => 1];
        };
        $out = [
            'o:source' => $text($m['source'] ?? null),
            'o:media_type' => $text($m['media_type'] ?? null),
            'o:alt_text' => $text($m['alt_text'] ?? null),
            'o:lang' => $text($m['lang'] ?? null),
        ];
        if (!empty($m['sha256'])) {
            $out['o:file'] = $text($m['sha256'] . (isset($m['size']) ? ' · ' . $m['size'] . ' bytes' : ''));
        }
        // What a media keeps besides its file. For an HTML media that is the HTML
        // itself, which Omeka's own form calls HTML. For everything else it is a list
        // kept by whichever ingester or module put it there — one line each, under the
        // name that module gives it, so a card says what changed instead of showing a
        // line of JSON.
        $data = $m['data'] ?? null;
        if (is_array($data) && isset($data['html'])) {
            $out['o:data'] = $text($data['html']);
        } elseif (is_array($data) && $data !== []) {
            foreach ($data as $key => $one) {
                $out['o:data:' . $key] = $text(is_scalar($one) || $one === null
                    ? $one
                    : json_encode($one, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        } elseif (!is_array($data) && $data !== null && $data !== '') {
            $out['o:data'] = $text($data);
        }
        return array_filter($out, function ($v) {
            return $v !== null;
        });
    }

    /**
     * @return array<int, array{0:string, 1:?array, 2:?array}>
     */
    public static function field(array $old, array $new): array
    {
        $key = function (array $v) {
            return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        };
        if (array_map($key, $old) === array_map($key, $new)) {
            return [];
        }
        // Which value is which is worked out once, in the place both the history page and
        // the dialog before a save read it from. What is left over on one side only is
        // what left the field or came into it; a value's place in the field is kept with
        // it, so an entry can say which one of four it was.
        $view = \RevisionHistory\View\FieldView::build($old, $new);
        $leftOld = [];
        $leftNew = [];
        foreach ($view['before'] as $one) {
            if ($one['state'] === 'out') {
                $leftOld[] = $one;
            }
        }
        foreach ($view['after'] as $one) {
            if ($one['state'] === 'in') {
                $leftNew[] = $one;
            }
        }
        if (!$leftOld && !$leftNew) {
            return [['order', $old, $new, null, null, count($old), count($new)]];
        }

        // How many the field held on each side, so a line can say "the second of five"
        // rather than a place with nothing to measure it against.
        $nOld = count($old);
        $nNew = count($new);
        $out = [];
        // A field of several values is written down whole as well, both sides, before
        // the lines that say what happened to each one. A line on its own cannot answer
        // the question somebody reading the history actually has — what does that field
        // hold now — and when the values look alike, reading one line tells you nothing.
        // The lines themselves are untouched: this is one row more, not one row fewer.
        // It is the same picture the dialog draws before a save, so the history page can
        // draw it too, from what was written down rather than from the resource as it is
        // today.
        // Only where the field had values before and still has some: a field that is all
        // new, or one that has been emptied, is already told in full by the lines alone.
        if ($nOld > 0 && $nNew > 0 && ($nOld > 1 || $nNew > 1)) {
            $out[] = ['field', $old, $new, null, null, $nOld, $nNew];
        }
        $pairs = min(count($leftOld), count($leftNew));
        for ($i = 0; $i < $pairs; $i++) {
            $out[] = ['edited', $leftOld[$i]['v'], $leftNew[$i]['v'], $leftOld[$i]['n'], $leftNew[$i]['n'], $nOld, $nNew];
        }
        for ($i = $pairs; $i < count($leftNew); $i++) {
            $out[] = ['added', null, $leftNew[$i]['v'], null, $leftNew[$i]['n'], $nOld, $nNew];
        }
        for ($i = $pairs; $i < count($leftOld); $i++) {
            $out[] = ['removed', $leftOld[$i]['v'], null, $leftOld[$i]['n'], null, $nOld, $nNew];
        }
        return $out;
    }

    private static function row(string $op, string $field, ?int $pid, ?array $before, ?array $after, ?int $placeBefore = null, ?int $placeAfter = null, ?int $sizeBefore = null, ?int $sizeAfter = null): array
    {
        return ['op' => $op, 'field' => $field, 'property_id' => $pid, 'before' => $before, 'after' => $after,
            'place_before' => $placeBefore, 'place_after' => $placeAfter,
            'size_before' => $sizeBefore, 'size_after' => $sizeAfter];
    }
}
