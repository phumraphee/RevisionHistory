<?php
namespace RevisionHistory;

use Doctrine\DBAL\Connection;

/**
 * A FIELD THAT HAS A NEW NAME. The history writes down each change under the field's term as it was
 * that day (revision_history_change.field) and never rewrites it. When a property is renamed or moved to
 * another vocabulary, or its values are merged into another property, its old changes would stop being
 * found under the field it is now: the Field filter would miss them, they would be named by a term that
 * no longer exists, and undoing one would find nothing to undo.
 *
 * An alias says "old term is now new term" (revision_history_field_alias). The history reads every
 * change under the current name — the filter asks for the old names too, the Field menu lists the
 * field once, labels come from the current property, undo and restore work on the current field —
 * while the rows themselves keep what they said. Chains are followed (a → b → c is c); a loop stops.
 *
 *   php modules/RevisionHistory/bin/field-alias.php list | add <old> <new> [note] | remove <old>
 */
class FieldAlias
{
    const TABLE = 'revision_history_field_alias';

    /** @var Connection */
    private $conn;

    /** @var array<string,string>|null old → current (chains followed) */
    private $map = null;

    /** @var array<string,?int> a term's property id now */
    private $pids = [];

    public function __construct(Connection $conn)
    {
        $this->conn = $conn;
    }

    public static function schema(): string
    {
        return "CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
                `old_field` VARCHAR(190) NOT NULL,
                `new_field` VARCHAR(190) NOT NULL,
                `since` DATETIME NOT NULL,
                `note` VARCHAR(255) NULL,
                PRIMARY KEY (`old_field`),
                KEY `k_new` (`new_field`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    /** old → current, chains followed; [] when the table is not there yet (an older install). */
    public function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }
        $direct = [];
        try {
            foreach ($this->conn->fetchAllAssociative('SELECT old_field, new_field FROM `' . self::TABLE . '`') as $r) {
                $direct[(string) $r['old_field']] = (string) $r['new_field'];
            }
        } catch (\Throwable $e) {
            $direct = [];
        }
        $map = [];
        foreach ($direct as $old => $new) {
            $seen = [$old => true];
            while (isset($direct[$new]) && !isset($seen[$new])) {
                $seen[$new] = true;
                $new = $direct[$new];
            }
            if ($new !== $old) {
                $map[$old] = $new;
            }
        }
        return $this->map = $map;
    }

    /** The name a field goes by now. */
    public function current(string $field): string
    {
        return $this->map()[$field] ?? $field;
    }

    /** The field and every old name that leads to it. */
    public function names(string $field): array
    {
        $cur = $this->current($field);
        $out = [$cur => true];
        foreach ($this->map() as $old => $new) {
            if ($new === $cur) {
                $out[$old] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * A change row read under the current name: `field` is the current one, `field_was` the one written
     * down that day (only when they differ).
     */
    public function row(array $c): array
    {
        if (isset($c['field'])) {
            $cur = $this->current((string) $c['field']);
            if ($cur !== $c['field']) {
                $c['field_was'] = $c['field'];
                $c['field'] = $cur;
                // values merged into another property: undo and restore write to the property the field is
                // now (a moved property keeps its id, and this finds the same one)
                if (array_key_exists('property_id', $c) && $c['property_id'] !== null) {
                    $c['property_id'] = $this->propertyId($cur) ?? $c['property_id'];
                }
            }
        }
        return $c;
    }

    /** The property a term names now, or null. */
    public function propertyId(string $term): ?int
    {
        if (!array_key_exists($term, $this->pids)) {
            $id = strpos($term, ':') === false ? false : $this->conn->fetchOne(
                "SELECT p.id FROM property p JOIN vocabulary v ON v.id = p.vocabulary_id WHERE CONCAT(v.prefix, ':', p.local_name) = ?",
                [$term]
            );
            $this->pids[$term] = $id ? (int) $id : null;
        }
        return $this->pids[$term];
    }

    public function all(): array
    {
        try {
            return $this->conn->fetchAllAssociative('SELECT old_field, new_field, since, note FROM `' . self::TABLE . '` ORDER BY new_field, old_field');
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Say that $old is now $new. Refused when it would make a loop or names the same field twice. */
    public function add(string $old, string $new, string $note = ''): void
    {
        $old = trim($old);
        $new = trim($new);
        if ($old === '' || $new === '' || $old === $new || strlen($old) > 190 || strlen($new) > 190) {
            throw new \InvalidArgumentException('An alias needs two different field names.');
        }
        $this->map = null;
        if ($this->current($new) === $old) {
            throw new \InvalidArgumentException("$new already leads to $old: that would be a loop.");
        }
        $this->conn->executeStatement(self::schema());
        $this->conn->executeStatement(
            'INSERT INTO `' . self::TABLE . '` (old_field, new_field, since, note) VALUES (?, ?, UTC_TIMESTAMP(), ?)
             ON DUPLICATE KEY UPDATE new_field = VALUES(new_field), note = VALUES(note)',
            [$old, $new, $note === '' ? null : mb_substr($note, 0, 255)]
        );
        $this->map = null;
    }

    public function remove(string $old): int
    {
        $this->map = null;
        try {
            return (int) $this->conn->executeStatement('DELETE FROM `' . self::TABLE . '` WHERE old_field = ?', [$old]);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
