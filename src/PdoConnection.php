<?php
namespace RevisionHistory;

/**
 * The few calls Entries makes of Omeka's database connection, answered by a plain PDO (1.2.0).
 *
 * For code that runs beside Omeka and shares its database without starting it — a page of the
 * site, a script — so that writing one entry does not cost starting the whole application.
 */
class PdoConnection
{
    /** @var \PDO */
    private $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function fetchOne(string $sql, array $params = [])
    {
        $st = $this->pdo->prepare($sql);
        $st->execute(array_values($params));
        return $st->fetchColumn();
    }

    public function fetchAllAssociative(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute(array_values($params));
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function executeStatement(string $sql, array $params = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute(array_values($params));
        return $st->rowCount();
    }

    /** @param array $row column (already quoted with backticks) => value */
    public function insert(string $table, array $row): int
    {
        $sql = 'INSERT INTO `' . str_replace('`', '', $table) . '` (' . implode(', ', array_keys($row)) . ') VALUES ('
            . implode(', ', array_fill(0, count($row), '?')) . ')';
        return $this->executeStatement($sql, array_values($row));
    }

    public function lastInsertId()
    {
        return $this->pdo->lastInsertId();
    }

    public function getTransactionNestingLevel(): int
    {
        return $this->pdo->inTransaction() ? 1 : 0;
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }
}
