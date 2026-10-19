<?php
namespace RevisionHistory\View;

/**
 * One field, before and after, line by line — the shape both the history page and the
 * "before you save" dialog draw.
 *
 * Which value went where is worked out the way a person reads it: the longest run of
 * values that are still in the same order is taken as the ones that stayed; only what
 * falls outside that run was really moved. A value whose number changed just because
 * another value left or came in did not move by itself, and is not marked as moved.
 */
class FieldView
{
    /**
     * @param array $before the field's values before (each as StateReader writes them)
     * @param array $after  the field's values after
     * @return array{before: array, after: array, moved: int, gone: int, came: int}
     *   Each list is the field as it reads on that side, in its own order:
     *   ['n' => place, 'v' => the value, 'state' => same|stay|moved|out|in,
     *    'other' => the place it has on the other side, 'move' => places moved (+ up, − down)]
     */
    public static function build(array $before, array $after): array
    {
        $key = function ($v) {
            return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        };
        $b = array_values($before);
        $a = array_values($after);

        // Pair each value with the same value on the other side, first with first.
        $pairs = [];
        $back = [];
        $used = [];
        foreach ($b as $i => $v) {
            foreach ($a as $j => $w) {
                if (!isset($used[$j]) && $key($v) === $key($w)) {
                    $used[$j] = true;
                    $pairs[$i] = $j;
                    $back[$j] = $i;
                    break;
                }
            }
        }
        // The longest run still in the same order: the values nobody moved.
        $kept = self::longestRun($pairs);

        $moved = $gone = $came = 0;
        $beforeList = [];
        foreach ($b as $i => $v) {
            if (!isset($pairs[$i])) {
                $beforeList[] = ['n' => $i + 1, 'v' => $v, 'state' => 'out'];
                $gone++;
                continue;
            }
            $j = $pairs[$i];
            $isMoved = !isset($kept[$i]);
            $moved += $isMoved ? 1 : 0;
            $beforeList[] = [
                'n' => $i + 1, 'v' => $v, 'other' => $j + 1,
                'state' => $isMoved ? 'moved' : ($i === $j ? 'same' : 'stay'),
                'move' => $isMoved ? $i - $j : 0,
            ];
        }
        $afterList = [];
        foreach ($a as $j => $w) {
            if (!isset($back[$j])) {
                $afterList[] = ['n' => $j + 1, 'v' => $w, 'state' => 'in'];
                $came++;
                continue;
            }
            $i = $back[$j];
            $isMoved = !isset($kept[$i]);
            $afterList[] = [
                'n' => $j + 1, 'v' => $w, 'other' => $i + 1,
                'state' => $isMoved ? 'moved' : ($i === $j ? 'same' : 'stay'),
                'move' => $isMoved ? $i - $j : 0,
            ];
        }
        return ['before' => $beforeList, 'after' => $afterList,
            'moved' => $moved, 'gone' => $gone, 'came' => $came];
    }

    /**
     * The same thing as one list, for a narrow screen: the field as it will read, with
     * the values that leave still in the place they sit in now, struck through.
     *
     * @return array each entry as in build(), with 'side' => 'b' (leaving) or 'a'
     */
    public static function merged(array $view): array
    {
        $buckets = [];
        $last = 0;
        foreach ($view['before'] as $one) {
            if ($one['state'] === 'out') {
                $buckets[$last][] = ['side' => 'b'] + $one;
            } else {
                $last = (int) $one['other'];
            }
        }
        $out = [];
        foreach ($view['after'] as $one) {
            foreach ($buckets[$one['n'] - 1] ?? [] as $gone) {
                $out[] = $gone;
            }
            $out[] = ['side' => 'a'] + $one;
        }
        foreach ($buckets as $at => $list) {
            if ($at >= count($view['after'])) {
                foreach ($list as $gone) {
                    $out[] = $gone;
                }
            }
        }
        return $out;
    }

    /**
     * The longest set of pairs whose order is the same on both sides (a longest
     * increasing run over the pairing). Those are the values nobody moved.
     *
     * @param array $pairs index before => index after
     * @return array index before => true
     */
    public static function longestRun(array $pairs): array
    {
        $keys = array_keys($pairs);
        sort($keys);
        $best = [];
        $tail = [];   // length => index in $keys of the smallest possible last value
        $prev = [];
        foreach ($keys as $pos => $i) {
            $v = $pairs[$i];
            $lo = 1;
            $hi = count($tail);
            while ($lo <= $hi) {
                $mid = intdiv($lo + $hi, 2);
                if ($pairs[$keys[$tail[$mid]]] < $v) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid - 1;
                }
            }
            $prev[$pos] = $lo > 1 ? $tail[$lo - 1] : null;
            $tail[$lo] = $pos;
        }
        $at = count($tail) ? $tail[count($tail)] : null;
        while ($at !== null) {
            $best[$keys[$at]] = true;
            $at = $prev[$at];
        }
        return $best;
    }
}
