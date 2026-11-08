<?php
/**
 * entries.php — the entries other modules wrote into the history (src/Entries.php).
 *
 *   php modules/RevisionHistory/bin/entries.php list
 *   php modules/RevisionHistory/bin/entries.php remove <module> [ref prefix]          (shows what it would remove)
 *   php modules/RevisionHistory/bin/entries.php remove <module> [ref prefix] --go     (removes it)
 *
 * Entries are kept when the module that wrote them is uninstalled: they are the archive's history,
 * not the module's. Taking them away is only ever done here, by a person, after seeing how many.
 * A ref prefix limits it to one source (for example the entries one copy of an old log made:
 * "cons_event:"). Run from Omeka's folder or anywhere.
 */
if (PHP_SAPI !== 'cli') {
    exit(2);
}
$omeka = dirname(__DIR__, 3);
chdir($omeka);
require $omeka . '/bootstrap.php';
$application = Omeka\Mvc\Application::init(require $omeka . '/application/config/application.config.php');
$conn = $application->getServiceManager()->get('Omeka\Connection');

$cmd = $argv[1] ?? '';
$go = in_array('--go', $argv, true);
$args = array_values(array_filter(array_slice($argv, 2), function ($a) {
    return $a !== '--go';
}));

switch ($cmd) {
    case 'list':
        $rows = $conn->fetchAllAssociative(
            "SELECT e.module, MAX(x.label) AS label, e.kind, MAX(x.kind_label) AS kind_label, e.automatic, COUNT(*) AS n,
                    MIN(e.happened_at) AS first_at, MAX(e.happened_at) AS last_at, SUM(e.via = 'copy') AS copied
               FROM revision_history_event e LEFT JOIN revision_history_entry x ON x.event_id = e.id
              WHERE e.action = 'noted'
              GROUP BY e.module, e.kind, e.automatic
              ORDER BY e.module, e.kind"
        );
        $active = array_flip($conn->fetchFirstColumn('SELECT id FROM module WHERE is_active = 1'));
        $total = 0;
        foreach ($rows as $r) {
            $total += (int) $r['n'];
            printf("%-24s %-14s %-28s %6d%s%s  %s … %s UTC%s\n",
                $r['module'], $r['label'], $r['kind'] . ($r['kind_label'] ? ' (' . $r['kind_label'] . ')' : ''), $r['n'],
                (int) $r['automatic'] ? ' automatic' : '', (int) $r['copied'] ? ' · ' . (int) $r['copied'] . ' copied' : '',
                substr($r['first_at'], 0, 16), substr($r['last_at'], 0, 16),
                isset($active[$r['module']]) ? '' : '  · module not active');
        }
        echo "$total entries\n";
        exit(0);
    case 'remove':
        $module = (string) ($args[0] ?? '');
        $prefix = (string) ($args[1] ?? '');
        if ($module === '') {
            fwrite(STDERR, "remove <module> [ref prefix] [--go]\n");
            exit(2);
        }
        $where = "action = 'noted' AND module = ?";
        $p = [$module];
        if ($prefix !== '') {
            $where .= ' AND source_ref LIKE ?';
            $p[] = addcslashes($module . ':' . $prefix, '%_\\') . '%';
        }
        $n = (int) $conn->fetchOne("SELECT COUNT(*) FROM revision_history_event WHERE $where", $p);
        if (!$go) {
            echo "$n entries of $module" . ($prefix !== '' ? " with ref $prefix…" : '') . " would be removed. Add --go to remove them.\n";
            exit(0);
        }
        $done = 0;
        do {
            $ids = $conn->fetchFirstColumn("SELECT id FROM revision_history_event WHERE $where ORDER BY id LIMIT 1000", $p);
            if ($ids) {
                $in = implode(',', array_map('intval', $ids));
                $conn->beginTransaction();
                $conn->executeStatement("DELETE FROM revision_history_entry WHERE event_id IN ($in)");
                $conn->executeStatement("DELETE FROM revision_history_shared WHERE event_id IN ($in)");
                $done += (int) $conn->executeStatement("DELETE FROM revision_history_event WHERE id IN ($in)");
                $conn->commit();
            }
        } while ($ids);
        echo "$done entries removed\n";
        exit(0);
}
fwrite(STDERR, "usage: entries.php list | remove <module> [ref prefix] [--go]\n");
exit(2);
