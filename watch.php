<?php
/**
 * A check of the data itself, run from the server's own clock.
 *
 *   php /path/to/omeka/modules/RevisionHistory/watch.php
 *
 * Put that line in cron — once a week is a sensible start — and nobody has to press
 * anything. Without cron the archive's own admin pages start a check when one is due
 * (see the Check page), so this file is a convenience, not a requirement.
 *
 * It reads the archive, writes the fingerprints, and writes an entry for what nothing
 * else accounts for. It changes nothing else.
 */
// Where Omeka is: two folders up from here, the folder this was run from, or wherever
// the first argument says. A module folder that is a link is followed either way.
$tries = [];
if (!empty($argv[1])) {
    $tries[] = rtrim($argv[1], '/');
}
$tries[] = dirname(__DIR__, 2);
$given = dirname(dirname(dirname((string) ($argv[0] ?? ''))));
if ($given !== '' && $given !== '.') {
    $tries[] = $given;
}
$tries[] = getcwd();
$root = null;
foreach ($tries as $try) {
    if ($try && file_exists($try . '/bootstrap.php') && file_exists($try . '/application/config/application.config.php')) {
        $root = $try;
        break;
    }
}
if ($root === null) {
    fwrite(STDERR, "Omeka was not found. Run this from the Omeka folder, or give it as the first argument:\n  php modules/RevisionHistory/watch.php /path/to/omeka\n");
    exit(1);
}
chdir($root);
require $root . '/bootstrap.php';
// This runs outside Omeka's own page handling, where the module's classes are not
// loaded for it.
spl_autoload_register(function ($class) {
    if (strpos($class, 'RevisionHistory\\') === 0) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 16)) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});
$app = Omeka\Mvc\Application::init(require $root . '/application/config/application.config.php');
$services = $app->getServiceManager();
$watchman = new RevisionHistory\Watch\Watchman(
    $services->get('Omeka\Connection'),
    $services->get('Omeka\Settings'),
    function ($msg) {
        fwrite(STDOUT, $msg . "\n");
    }
);
try {
    $result = $watchman->run();
} catch (\Throwable $e) {
    fwrite(STDERR, 'The check stopped: ' . $e->getMessage() . "\n");
    exit(1);
}
if (!empty($result['busy'])) {
    fwrite(STDOUT, "A check is already running. This one did nothing.\n");
}
