<?php
/**
 * field-alias.php — the history's field aliases (src/FieldAlias.php): an old field name that now goes by another,
 * after a property was renamed, moved to another vocabulary, or merged into another property. The history then
 * reads its old changes under the new name; the changes themselves are never rewritten.
 *
 *   php modules/RevisionHistory/bin/field-alias.php list
 *   php modules/RevisionHistory/bin/field-alias.php add <old term> <new term> [note]
 *   php modules/RevisionHistory/bin/field-alias.php remove <old term>
 *
 * Add an alias at the moment the field gets its new name (not before: the new name must exist for undo to write
 * to it). Run from Omeka's folder or anywhere: the Omeka folder is the one this module is installed in.
 */
if (PHP_SAPI !== 'cli') {
    exit(2);
}
$omeka = dirname(__DIR__, 3);
chdir($omeka);
require $omeka . '/bootstrap.php';
$application = Omeka\Mvc\Application::init(require $omeka . '/application/config/application.config.php');
$conn = $application->getServiceManager()->get('Omeka\Connection');
require_once dirname(__DIR__) . '/src/FieldAlias.php';
$alias = new RevisionHistory\FieldAlias($conn);

$cmd = $argv[1] ?? '';
try {
    switch ($cmd) {
        case 'list':
            foreach ($alias->all() as $r) {
                printf("%s → %s  (since %s UTC)%s\n", $r['old_field'], $r['new_field'], $r['since'], $r['note'] ? '  ' . $r['note'] : '');
            }
            $n = (int) $conn->fetchOne('SELECT COUNT(*) FROM revision_history_change WHERE field IN (SELECT old_field FROM `' . RevisionHistory\FieldAlias::TABLE . '`)');
            echo count($alias->all()) . " aliases · $n changes written down under an old name\n";
            exit(0);
        case 'add':
            if (($argv[2] ?? '') === '' || ($argv[3] ?? '') === '') {
                fwrite(STDERR, "add <old term> <new term> [note]\n");
                exit(2);
            }
            $alias->add($argv[2], $argv[3], implode(' ', array_slice($argv, 4)));
            $n = (int) $conn->fetchOne('SELECT COUNT(*) FROM revision_history_change WHERE field = ?', [$argv[2]]);
            echo "{$argv[2]} → " . $alias->current($argv[2]) . " ($n changes under the old name)\n";
            if ($alias->propertyId($alias->current($argv[2])) === null && strpos($argv[3], ':') !== false) {
                echo "note: no property is called {$argv[3]} yet — add the alias when the field has its new name\n";
            }
            exit(0);
        case 'remove':
            echo $alias->remove((string) ($argv[2] ?? '')) ? "removed\n" : "no such alias\n";
            exit(0);
    }
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
fwrite(STDERR, "usage: field-alias.php list | add <old> <new> [note] | remove <old>\n");
exit(2);
