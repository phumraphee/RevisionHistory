<?php
namespace RevisionHistory;

use Doctrine\DBAL\Connection;

/**
 * The archive's own record of who changed what.
 *
 *   revision_history_event   one row per resource per save: created · edited · deleted
 *   revision_history_change  one row per value that was added · removed · edited (· reordered)
 *
 * Times are stored in UTC and shown in the archive's time zone.
 * Names are copied at the moment of the change, so a person whose account is
 * later removed still has a name in the history.
 */
class Store
{
    /** @var Connection */
    private $conn;

    public function __construct(Connection $conn)
    {
        $this->conn = $conn;
    }

    public function conn(): Connection
    {
        return $this->conn;
    }

    public static function schema(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS `revision_history_event` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `happened_at` DATETIME(6) NOT NULL,
                `save_key` VARCHAR(40) NOT NULL DEFAULT '',
                `run_key` VARCHAR(40) NOT NULL DEFAULT '',
                `action` VARCHAR(12) NOT NULL,
                `resource_id` INT NOT NULL,
                `resource_kind` VARCHAR(32) NOT NULL,
                `item_id` INT NULL,
                `title` TEXT NULL,
                `accession` VARCHAR(190) NOT NULL DEFAULT '',
                `user_id` INT NULL,
                `user_name` VARCHAR(190) NOT NULL DEFAULT '',
                `user_email` VARCHAR(190) NOT NULL DEFAULT '',
                `via` VARCHAR(12) NOT NULL DEFAULT '',
                `via_detail` VARCHAR(255) NOT NULL DEFAULT '',
                `ip` VARCHAR(45) NOT NULL DEFAULT '',
                `source` VARCHAR(16) NOT NULL DEFAULT 'here',
                `source_ref` VARCHAR(64) NULL,
                `note` TEXT NULL,
                `n_added` INT NOT NULL DEFAULT 0,
                `n_removed` INT NOT NULL DEFAULT 0,
                `n_edited` INT NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `k_when` (`happened_at`),
                KEY `k_resource` (`resource_id`),
                KEY `k_item` (`item_id`),
                KEY `k_user` (`user_id`),
                KEY `k_action` (`action`),
                KEY `k_save` (`save_key`),
                KEY `k_run` (`run_key`),
                UNIQUE KEY `u_source` (`source`, `source_ref`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS `revision_history_change` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `event_id` INT UNSIGNED NOT NULL,
                `ord` INT NOT NULL DEFAULT 0,
                `op` VARCHAR(8) NOT NULL,
                `field` VARCHAR(190) NOT NULL,
                `property_id` INT NULL,
                `before_json` MEDIUMTEXT NULL,
                `after_json` MEDIUMTEXT NULL,
                `place_before` SMALLINT UNSIGNED NULL,
                `place_after` SMALLINT UNSIGNED NULL,
                `size_before` SMALLINT UNSIGNED NULL,
                `size_after` SMALLINT UNSIGNED NULL,
                PRIMARY KEY (`id`),
                KEY `k_event` (`event_id`),
                KEY `k_field` (`field`),
                KEY `k_op` (`op`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            // What each resource held the last time this was checked, as one number.
            // Not the values themselves: this is only here to notice a change that
            // nothing in the history accounts for.
            "CREATE TABLE IF NOT EXISTS `revision_history_digest` (
                `resource_id` INT NOT NULL,
                `hash` BIGINT UNSIGNED NOT NULL,
                `at` DATETIME NOT NULL,
                PRIMARY KEY (`resource_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            // What each resource held when it was last checked, in full. Only kept when
            // the archive asks for it: this is a second copy of the values, and it is
            // what lets a change nobody recorded say WHAT changed, not only that it did.
            "CREATE TABLE IF NOT EXISTS `revision_history_shadow` (
                `resource_id` INT NOT NULL,
                `state` MEDIUMTEXT NOT NULL,
                `at` DATETIME NOT NULL,
                PRIMARY KEY (`resource_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            // Every table in this database and how many rows it had when last looked at,
            // so a table appearing, growing or vanishing can be seen — including the
            // tables of a module nobody here knows anything about.
            "CREATE TABLE IF NOT EXISTS `revision_history_table` (
                `name` VARCHAR(190) NOT NULL,
                `rows` BIGINT NOT NULL DEFAULT 0,
                `at` DATETIME NOT NULL,
                PRIMARY KEY (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            // What a resource held when it was deleted, exactly as it was read, so that it
            // can be made again as it was (1.7.0).
            "CREATE TABLE IF NOT EXISTS `revision_history_snapshot` (
                `event_id` INT UNSIGNED NOT NULL,
                `state` MEDIUMTEXT NOT NULL,
                PRIMARY KEY (`event_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            // A save the admin pages refused before it reached anything: the token check
            // failed, so nothing happened and nothing else would say it was tried. What
            // the person was saving is kept here with it (1.9.0).
            "CREATE TABLE IF NOT EXISTS `revision_history_refused` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `at` DATETIME(6) NOT NULL,
                `user_id` INT NULL,
                `user_name` VARCHAR(190) NOT NULL DEFAULT '',
                `path` VARCHAR(255) NOT NULL DEFAULT '',
                `came_from` VARCHAR(255) NOT NULL DEFAULT '',
                `token_sent` TINYINT NOT NULL DEFAULT 0,
                `token_known` TINYINT NOT NULL DEFAULT 0,
                `tokens` INT NOT NULL DEFAULT 0,
                `tokens_most` INT NOT NULL DEFAULT 0,
                `session_ref` VARCHAR(16) NOT NULL DEFAULT '',
                `session_at` DATETIME NULL,
                `vars` INT NOT NULL DEFAULT 0,
                `bytes` BIGINT NOT NULL DEFAULT 0,
                `limits` VARCHAR(190) NOT NULL DEFAULT '',
                `agent` VARCHAR(190) NOT NULL DEFAULT '',
                `kept` MEDIUMTEXT NULL,
                PRIMARY KEY (`id`),
                KEY `k_when` (`at`),
                KEY `k_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            // The module's own small notes — progress of a copy, its last report. Not
            // Omeka's settings: Activity Log writes a row for every setting that is saved,
            // and a copy that reports its progress there would keep making more to copy.
            "CREATE TABLE IF NOT EXISTS `revision_history_meta` (
                `name` VARCHAR(64) NOT NULL,
                `val` MEDIUMTEXT NULL,
                `at` DATETIME NOT NULL,
                PRIMARY KEY (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }

    public function meta(string $name)
    {
        try {
            $v = $this->conn->fetchOne('SELECT `val` FROM `revision_history_meta` WHERE `name` = ?', [$name]);
        } catch (\Throwable $e) {
            return null;
        }
        return $v === false || $v === null ? null : json_decode($v, true);
    }

    public function setMeta(string $name, $value): void
    {
        $this->conn->executeStatement(
            'INSERT INTO `revision_history_meta` (`name`, `val`, `at`) VALUES (?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE `val` = VALUES(`val`), `at` = VALUES(`at`)',
            [$name, self::json($value)]
        );
    }

    public function install(): void
    {
        foreach (self::schema() as $sql) {
            $this->conn->executeStatement($sql);
        }
        // 1.1.0 records things whose kind is named by a longer word than an item's.
        try {
            $this->conn->executeStatement("ALTER TABLE `revision_history_event` MODIFY `resource_kind` VARCHAR(32) NOT NULL");
        } catch (\Throwable $e) {
        }
        // 1.5.2 notes which run of Batch Values a save was part of, so one run is one card.
        try {
            $this->conn->executeStatement("ALTER TABLE `revision_history_event` ADD COLUMN `run_key` VARCHAR(40) NOT NULL DEFAULT '' AFTER `save_key`, ADD KEY `k_run` (`run_key`)");
        } catch (\Throwable $e) {
        }
        // 1.8.0 keeps which place in its field each value had, before and after, and
        // how many the field held on each side, so a line can say which of how many.
        try {
            $this->conn->executeStatement('ALTER TABLE `revision_history_change` ADD COLUMN `place_before` SMALLINT UNSIGNED NULL, ADD COLUMN `place_after` SMALLINT UNSIGNED NULL');
        } catch (\Throwable $e) {
        }
        try {
            $this->conn->executeStatement('ALTER TABLE `revision_history_change` ADD COLUMN `size_before` SMALLINT UNSIGNED NULL, ADD COLUMN `size_after` SMALLINT UNSIGNED NULL');
        } catch (\Throwable $e) {
        }
        // 1.10.0 keeps where each request came from, the one thing Activity Log had that
        // this did not. Rows written before it simply have none.
        try {
            $this->conn->executeStatement("ALTER TABLE `revision_history_event` ADD COLUMN `ip` VARCHAR(45) NOT NULL DEFAULT '' AFTER `via_detail`");
        } catch (\Throwable $e) {
        }
        $this->runsBefore();
        // 1.0.3 wrote Image Annotate changes under a name of its own making; 1.0.4 uses the
        // module's own key. Renamed in place, once (nothing matches the second time).
        $this->conn->executeStatement(
            "UPDATE `revision_history_change`
                SET `field` = 'o-module-image_annotate:annotation',
                    `before_json` = REPLACE(`before_json`, '\"type\":\"image-note\"', '\"type\":\"o-module-image_annotate:annotation\"'),
                    `after_json` = REPLACE(`after_json`, '\"type\":\"image-note\"', '\"type\":\"o-module-image_annotate:annotation\"')
              WHERE `field` = 'o:image_note'"
        );
    }

    /**
     * Saves made by Batch Values before 1.5.2 wrote down which run they were part of.
     * Its own record still says: a run done as a background job names the job, and a run
     * done at once took place between the moment its change set was made and the moment
     * it was finished, by the person who owns it. Only rows with no run yet are touched,
     * so doing this again changes nothing. Without Batch Values' table there is nothing to read.
     */
    public function runsBefore(): void
    {
        try {
            if (!$this->conn->fetchOne("SHOW TABLES LIKE 'batch_values_change_set'")) {
                return;
            }
            $this->conn->executeStatement(
                "UPDATE `revision_history_event` e JOIN `batch_values_change_set` s
                    ON s.`job_id` IS NOT NULL AND e.`via_detail` LIKE CONCAT('#', s.`job_id`, ' BatchValues%')
                    SET e.`run_key` = CONCAT('bv:', s.`id`)
                  WHERE e.`run_key` = '' AND e.`via` = 'job'"
            );
            $this->conn->executeStatement(
                "UPDATE `revision_history_event` e JOIN `batch_values_change_set` s
                    ON s.`job_id` IS NULL AND e.`user_id` = s.`owner_id`
                   AND e.`happened_at` >= s.`created` - INTERVAL 1 SECOND
                   AND e.`happened_at` < COALESCE(s.`finished`, s.`created`) + INTERVAL 2 SECOND
                    SET e.`run_key` = CONCAT('bv:', s.`id`)
                  WHERE e.`run_key` = '' AND e.`via` = 'admin' AND e.`via_detail` LIKE '/admin/batch-values/%'"
            );
        } catch (\Throwable $e) {
        }
    }

    /**
     * @param array $event keys of revision_history_event (no id)
     * @param array $changes rows from Diff::changes()
     */
    public function write(array $event, array $changes): int
    {
        $n = ['added' => 0, 'removed' => 0, 'edited' => 0];
        foreach ($changes as $c) {
            if (isset($n[$c['op']])) {
                $n[$c['op']]++;
            } elseif ($c['op'] === 'order') {
                $n['edited']++;
            }
        }
        $event['n_added'] = $n['added'];
        $event['n_removed'] = $n['removed'];
        $event['n_edited'] = $n['edited'];
        // One entry and its lines are written together or not at all: an entry that
        // says "changed" with nothing under it would be a history that lies by omission.
        if ((int) $this->conn->getTransactionNestingLevel() === 0) {
            $this->conn->beginTransaction();
            try {
                $id = $this->writeRows($event, $changes, $made);
                $this->conn->commit();
            } catch (\Throwable $e) {
                $this->conn->rollBack();
                throw $e;
            }
            return $id;
        }
        // Inside somebody else's transaction a rollback here would mark theirs as failed
        // and stop the save itself. So: write, and if the lines fail, take the entry back.
        $made = 0;
        try {
            return $this->writeRows($event, $changes, $made);
        } catch (\Throwable $e) {
            if ($made) {
                foreach (['DELETE FROM `revision_history_change` WHERE `event_id` = ?', 'DELETE FROM `revision_history_event` WHERE `id` = ?'] as $sql) {
                    try {
                        $this->conn->executeStatement($sql, [$made]);
                    } catch (\Throwable $ignored) {
                    }
                }
            }
            throw $e;
        }
    }

    private function writeRows(array $event, array $changes, &$made): int
    {
        $this->conn->insert('revision_history_event', self::quoted($event));
        $id = (int) $this->conn->lastInsertId();
        $made = $id;

        $ord = 0;
        foreach (array_chunk($changes, 200) as $chunk) {
            $marks = [];
            $params = [];
            foreach ($chunk as $c) {
                $marks[] = '(?,?,?,?,?,?,?,?,?,?,?)';
                array_push(
                    $params,
                    $id,
                    ++$ord,
                    $c['op'],
                    mb_substr($c['field'], 0, 190),
                    $c['property_id'],
                    $c['before'] === null ? null : self::json($c['before']),
                    $c['after'] === null ? null : self::json($c['after']),
                    $c['place_before'] ?? null,
                    $c['place_after'] ?? null,
                    $c['size_before'] ?? null,
                    $c['size_after'] ?? null
                );
            }
            $this->conn->executeStatement(
                'INSERT INTO `revision_history_change` (`event_id`,`ord`,`op`,`field`,`property_id`,`before_json`,`after_json`,`place_before`,`place_after`,`size_before`,`size_after`) VALUES ' . implode(',', $marks),
                $params
            );
        }
        return $id;
    }

    /**
     * AN API KEY BEING USED, ONE ENTRY PER KEY PER HOUR. 1.10.2, 2026-10-19.
     *
     * The first call in an hour writes the entry; every later call in the same hour adds
     * to it: how many calls, the first and the last time, and each address with its
     * count. The note is written so a person can read it as it is —
     *
     *     42 calls · 08:01:03–08:59:40 UTC · 192.168.1.50 ×41, 203.0.113.7 ×1
     *
     * — and is read back with the same pattern to add the next call. An hour that has
     * ended is never touched again: only the entry of the hour still running changes.
     * Hourly because that is the grain people speak in ("around eight", "at three"),
     * most time zones are a whole number of hours from UTC so the hours match the local
     * clock, and the exact second of each call is in the web server's access log if ever
     * needed.
     */
    public function keyUse(array $base, string $key, string $title): void
    {
        $at = (string) $base['happened_at'];
        $hour = substr($at, 0, 13) . ':00:00';
        $time = substr($at, 11, 8);
        $ip = (string) ($base['ip'] ?? '');
        $ip = $ip !== '' ? $ip : 'no address';
        /* An API key's id is its public identity, not a number, and several keys may
           share one label — four are called conservation_bot. So the key and the hour
           are written into source_ref, which revision_history_event already keeps unique with source:
           the table itself then holds one entry per key per hour. */
        $ref = mb_substr('use:' . $key . ':' . substr($hour, 0, 13), 0, 64);
        /* Inside somebody else's transaction a rollback here would fail theirs (see
           write()), so a transaction of its own only when there is none. */
        $own = (int) $this->conn->getTransactionNestingLevel() === 0;
        if ($own) {
            $this->conn->beginTransaction();
        }
        try {
            $row = $this->conn->fetchAssociative(
                "SELECT id, note FROM revision_history_event WHERE source = 'here' AND source_ref = ? FOR UPDATE",
                [$ref]
            );
            $n = 0; $first = $time; $ips = [];
            if ($row && preg_match('/^(\d+) calls? · (\d\d:\d\d:\d\d)–\d\d:\d\d:\d\d UTC · (.*)$/u', (string) $row['note'], $m)) {
                $n = (int) $m[1];
                $first = $m[2];
                foreach (explode(', ', $m[3]) as $one) {
                    if (preg_match('/^(.+) ×(\d+)$/u', $one, $mm)) {
                        $ips[$mm[1]] = (int) $mm[2];
                    }
                }
            }
            $n++;
            $ips[$ip] = ($ips[$ip] ?? 0) + 1;
            arsort($ips);
            $parts = [];
            foreach ($ips as $k => $c) {
                $parts[] = $k . ' ×' . $c;
            }
            $note = $n . ($n === 1 ? ' call' : ' calls') . ' · ' . $first . '–' . $time . ' UTC · ' . implode(', ', $parts);
            if ($row) {
                /* The count is in the note, not in n_edited: the page reads that as values
                   changed, and a key being used changes none. */
                $this->conn->update('revision_history_event', ['note' => mb_substr($note, 0, 60000)], ['id' => (int) $row['id']]);
            } else {
                $this->conn->insert('revision_history_event', self::quoted($base + [
                    'action' => 'used', 'resource_id' => 0, 'resource_kind' => 'API key', 'source_ref' => $ref,
                    'item_id' => null, 'title' => $title !== '' ? mb_substr($title, 0, 190) : null,
                    'accession' => '', 'note' => $note, 'n_added' => 0, 'n_removed' => 0, 'n_edited' => 0,
                ]));
            }
            if ($own) {
                $this->conn->commit();
            }
        } catch (\Throwable $e) {
            if ($own) {
                $this->conn->rollBack();
            }
            throw $e;
        }
    }

    public function snapshot(int $eventId, array $state): void
    {
        if ($eventId) {
            $this->conn->executeStatement(
                'INSERT INTO `revision_history_snapshot` (`event_id`, `state`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `state` = VALUES(`state`)',
                [$eventId, self::json($state)]
            );
        }
    }

    public function getSnapshot(int $eventId): ?array
    {
        $json = $this->conn->fetchOne('SELECT `state` FROM `revision_history_snapshot` WHERE `event_id` = ?', [$eventId]);
        $state = $json ? json_decode((string) $json, true) : null;
        return is_array($state) ? $state : null;
    }

    public static function json($v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function quoted(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $out['`' . $k . '`'] = $v;
        }
        return $out;
    }
}
