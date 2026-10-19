<?php
namespace RevisionHistory\Capture;

use Doctrine\DBAL\Connection;

/**
 * One level of the item sets tree, before and after a save.
 *
 * Where an item set sits is kept by the ItemSetsTree module in `item_sets_tree_edge`,
 * one row per item set: which item set, which one it is under, and its `rank` in that
 * level. Item Sets Tree Manager writes those rows through Doctrine, so every one of
 * them is recorded like any other row — but putting one item set somewhere else
 * rewrites the rank of every item set in the level, and a card that says only
 * "rank 12 → 13", twenty times over, does not tell anybody what happened.
 *
 * So the level itself is read as well: the item sets of that level, in their order,
 * before the save and after it. That is the same shape a field of many values has, and
 * it is shown the same way — the whole level, both sides, with the one that really
 * moved marked. The rows of the single edges are still written; they sit inside the card.
 */
class Levels
{
    const EDGE = 'ItemSetsTree\Entity\ItemSetsTreeEdge';

    /** The top level has no parent. Its own word, from Item Sets Tree Manager. */
    const TOP = 'Top level';

    /** @var bool|null whether the ItemSetsTree module's table is here */
    private static $table;

    public static function has(Connection $conn): bool
    {
        if (self::$table === null) {
            try {
                self::$table = (bool) $conn->fetchOne("SHOW TABLES LIKE 'item_sets_tree_edge'");
            } catch (\Throwable $e) {
                self::$table = false;
            }
        }
        return self::$table;
    }

    /**
     * The levels this save touches: the one an edge is in now, and the one it was in
     * before, since moving an item set changes the order of both. 0 is the top level.
     *
     * @param object $entity one of the rows being written
     * @param array $data Doctrine's copy of the row as it was
     * @return int[]
     */
    public static function parentsOf($entity, array $data): array
    {
        if (Others::realClass(get_class($entity)) !== self::EDGE) {
            return [];
        }
        $out = [];
        try {
            if (method_exists($entity, 'getParentItemSet')) {
                $p = $entity->getParentItemSet();
                $out[] = $p && method_exists($p, 'getId') && is_numeric($p->getId()) ? (int) $p->getId() : 0;
            }
        } catch (\Throwable $e) {
        }
        // The parent it had: Doctrine keeps the row as it was read, with the entity
        // itself under the field's name.
        if (array_key_exists('parentItemSet', $data)) {
            $was = $data['parentItemSet'];
            if (is_object($was) && method_exists($was, 'getId')) {
                try {
                    $out[] = is_numeric($was->getId()) ? (int) $was->getId() : 0;
                } catch (\Throwable $e) {
                }
            } elseif (is_numeric($was)) {
                $out[] = (int) $was;
            } elseif ($was === null) {
                $out[] = 0;
            }
        }
        return $out;
    }

    /**
     * What each level holds, in its order, read from the table itself.
     *
     * @param int[] $parents 0 for the top level
     * @return array<int, int[]> parent => item set ids, in order
     */
    public static function read(Connection $conn, array $parents): array
    {
        $out = [];
        foreach (array_values(array_unique(array_map('intval', $parents))) as $parent) {
            try {
                $out[$parent] = array_map('intval', $conn->fetchFirstColumn(
                    $parent
                        ? 'SELECT item_set_id FROM item_sets_tree_edge WHERE parent_item_set_id = ? ORDER BY `rank`, item_set_id'
                        : 'SELECT item_set_id FROM item_sets_tree_edge WHERE parent_item_set_id IS NULL ORDER BY `rank`, item_set_id',
                    $parent ? [$parent] : []
                ));
            } catch (\Throwable $e) {
                // A level that cannot be read is simply not compared.
            }
        }
        return $out;
    }

    /**
     * The levels whose order this save changed, as one history line each.
     *
     * @return array<int, array> parent => the line (op order, field rank)
     */
    public static function changes(array $before, array $after): array
    {
        $out = [];
        foreach ($after as $parent => $now) {
            if (!array_key_exists($parent, $before) || $before[$parent] === $now) {
                continue;
            }
            $ids = function (array $list) {
                return array_map(function ($id) {
                    return ['id' => (int) $id];
                }, $list);
            };
            $out[$parent] = [
                'op' => 'order',
                'field' => 'rank',
                'property_id' => null,
                'before' => $ids($before[$parent]),
                'after' => $ids($now),
                'place_before' => null,
                'place_after' => null,
                'size_before' => count($before[$parent]),
                'size_after' => count($now),
            ];
        }
        return $out;
    }
}
