<?php
namespace RevisionHistory\Capture;

/**
 * What the admin form is about to save, in the same shape StateReader reads from the
 * tables — so the same Diff that writes the history can say, before anything is
 * written, what this save would change.
 *
 * Omeka's resource form sends every value of every field as one piece of JSON
 * (`values_json`), in the order the fields are shown, and the resource's own settings
 * as their own fields. Nothing here guesses: a key the form did not send is left as
 * the resource holds it now, because a save does not touch what the form does not carry.
 */
class FormState
{
    /** The form's fields for the resource's own settings, and their key in the state. */
    const OWN = [
        'o:resource_template' => 'template',
        'o:resource_class' => 'class',
        'o:owner' => 'owner',
        'o:thumbnail' => 'thumbnail',
        'o:primary_media' => 'primary_media',
    ];

    /**
     * @param array $post the form, as PHP read it
     * @param array $before the resource as it is now (StateReader), or an empty state
     * @return array the same shape, as the form would leave it
     */
    public static function after(array $post, array $before): array
    {
        $after = $before;

        if (array_key_exists('values_json', $post)) {
            [$values, $props] = self::values((string) $post['values_json']);
            $after['values'] = $values;
            $after['props'] = $props + ($before['props'] ?? []);
        }

        foreach (self::OWN as $field => $key) {
            if (!array_key_exists($field, $post) || !array_key_exists($key, $before)) {
                continue;
            }
            $id = is_array($post[$field]) ? ($post[$field]['o:id'] ?? null) : $post[$field];
            $after[$key] = ($id === null || $id === '') ? null : (int) $id;
        }
        if (array_key_exists('o:is_public', $post)) {
            $after['is_public'] = (int) (bool) $post['o:is_public'];
        }
        if (array_key_exists('o:is_open', $post) && array_key_exists('is_open', $before)) {
            $after['is_open'] = (int) (bool) $post['o:is_open'];
        }
        if (array_key_exists('o:item_set', $post)) {
            $after['item_sets'] = array_values(array_unique(array_map('intval', self::ids($post['o:item_set']))));
        }
        if (array_key_exists('o:site', $post)) {
            $after['sites'] = array_values(array_unique(array_map('intval', self::ids($post['o:site']))));
        }
        // Every media the form still carries, in the order it carries them. A media the
        // form leaves out is one Omeka will delete, so leaving it out here is the truth.
        if (array_key_exists('o:media', $post) && array_key_exists('media_order', $before)) {
            $order = [];
            foreach ((array) $post['o:media'] as $m) {
                $id = is_array($m) ? ($m['o:id'] ?? null) : $m;
                if ($id !== null && $id !== '') {
                    $order[] = (int) $id;
                }
            }
            $after['media_order'] = $order;
        }
        if (($before['kind'] ?? '') === 'media') {
            $media = $before['media'] ?? [];
            foreach (['o:alt_text' => 'alt_text', 'o:lang' => 'lang'] as $field => $key) {
                if (array_key_exists($field, $post)) {
                    $v = (string) $post[$field];
                    $media[$key] = $v === '' ? null : $v;
                }
            }
            $after['media'] = $media;
        }
        return $after;
    }

    /** An empty resource, for a form that is making one. */
    public static function blank(string $kind, ?int $owner = null): array
    {
        $state = [
            'id' => 0,
            'kind' => $kind,
            'title' => null,
            'accession' => '',
            'is_public' => 1,
            'owner' => $owner,
            'class' => null,
            'template' => null,
            'item' => null,
            'item_sets' => [],
            'created' => null,
            'thumbnail' => null,
            'media' => null,
            'values' => [],
            'props' => [],
        ];
        if ($kind === 'item') {
            $state['primary_media'] = null;
            $state['sites'] = [];
            $state['media_order'] = [];
        } elseif ($kind === 'item_set') {
            $state['is_open'] = 0;
        }
        return $state;
    }

    /** @return array{0: array, 1: array} the values by field, and each field's property id */
    public static function values(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [[], []];
        }
        $values = [];
        $props = [];
        foreach ($data as $term => $list) {
            if (!is_string($term) || !is_array($list)) {
                continue;
            }
            foreach ($list as $v) {
                if (!is_array($v)) {
                    continue;
                }
                $notes = [];
                foreach ((array) ($v['@annotation'] ?? []) as $noteTerm => $noteList) {
                    foreach ((array) $noteList as $n) {
                        if (is_array($n)) {
                            $notes[] = ['f' => (string) $noteTerm] + self::sig($n);
                        }
                    }
                }
                $sig = self::sig($v, $notes);
                // An empty box is not a value: Omeka drops it, and so does this.
                if ($sig['value'] === null && $sig['uri'] === null && $sig['vr'] === null) {
                    continue;
                }
                $values[$term][] = $sig;
                if (!isset($props[$term]) && !empty($v['property_id'])) {
                    $props[$term] = (int) $v['property_id'];
                }
            }
        }
        return [$values, $props];
    }

    /**
     * Vocab Sync values, as the tables keep them.
     *
     * The table keeps a Vocab Sync value as the term's number; the form sends back the
     * term's label. Read side by side they look like an edit nobody made, and every
     * Vocab Sync field of the resource reads as changed. So what the form sent is put
     * through the same question Vocab Sync itself asks — which term is this? — before
     * anything is compared. What no term answers to is left exactly as the form sent it:
     * that is a value Vocab Sync would not accept either, and showing it is the point.
     *
     * @param callable $find (int $vocabId, string $raw): ?int — Vocab Sync's own rule:
     *   the label first (a term may be called "1961"), then the number.
     */
    public static function vocabSync(array $values, callable $find): array
    {
        $one = function (array $v) use ($find, &$one) {
            if (!empty($v['note'])) {
                foreach ($v['note'] as $i => $n) {
                    $f = $n['f'] ?? '';
                    $fixed = $one(array_diff_key($n, ['f' => 1]));
                    $v['note'][$i] = ['f' => $f] + $fixed;
                }
            }
            if (!preg_match('/^vocabsync:(\d+)$/', (string) ($v['type'] ?? ''), $m)) {
                return $v;
            }
            $raw = trim((string) ($v['value'] ?? ''));
            if ($raw === '') {
                return $v;
            }
            $id = $find((int) $m[1], $raw);
            if ($id) {
                $v['value'] = (string) $id;
            }
            return $v;
        };
        foreach ($values as $term => $list) {
            foreach ($list as $i => $v) {
                $values[$term][$i] = $one($v);
            }
        }
        return $values;
    }

    /**
     * One value of the form, in the shape the tables are read in. A link carries its
     * address in @id and what it is called in o:label — which is the other way round
     * from the table, where the address is its own column and the label is the value.
     */
    private static function sig(array $v, array $notes = []): array
    {
        $uri = isset($v['@id']) && $v['@id'] !== '' ? (string) $v['@id'] : null;
        return StateReader::sig([
            'type' => (string) ($v['type'] ?? 'literal'),
            'value' => $uri !== null ? ($v['o:label'] ?? null) : ($v['@value'] ?? null),
            'uri' => $uri,
            'value_resource_id' => isset($v['value_resource_id']) && $v['value_resource_id'] !== '' ? (int) $v['value_resource_id'] : null,
            'lang' => $v['@language'] ?? null,
            'is_public' => array_key_exists('is_public', $v) ? (int) (bool) $v['is_public'] : 1,
        ], $notes);
    }

    private static function ids($v): array
    {
        $out = [];
        foreach ((array) $v as $one) {
            $id = is_array($one) ? ($one['o:id'] ?? null) : $one;
            if ($id !== null && $id !== '') {
                $out[] = (int) $id;
            }
        }
        return $out;
    }
}
