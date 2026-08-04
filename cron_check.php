<?php
/**
 * Cron diagnostic. Point cron at this file instead of worker.php to find out
 * whether cron fires at all, and what PHP it uses when it does.
 *
 * Deliberately written for PHP 5.6 and up, with no dependencies, so it still
 * reports even when the server's cron PHP is far too old to run the app.
 *
 * Cron command:
 *   /usr/local/bin/php /full/path/to/cron_check.php
 *
 * Then reload cron_setup.php in the browser to read the results.
 */

if (!empty($_SERVER['REQUEST_METHOD']) || !empty($_SERVER['REMOTE_ADDR'])
    || !empty($_SERVER['HTTP_HOST']) || !empty($_SERVER['REQUEST_URI'])) {
    header('HTTP/1.1 403 Forbidden');
    exit('This script runs from the command line only.');
}

$dir     = dirname(__FILE__);
$logFile = $dir . '/cron_check.log';

$bits = array();
$bits[] = 'time=' . gmdate('Y-m-d H:i:s') . 'Z';
$bits[] = 'php=' . PHP_VERSION;
$bits[] = 'sapi=' . PHP_SAPI;
$bits[] = 'binary=' . (defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'unknown');
$bits[] = 'user=' . (function_exists('get_current_user') ? get_current_user() : '?');
$bits[] = 'cwd=' . getcwd();
$bits[] = 'dir=' . $dir;

// Can the app's own files be reached from here?
$bits[] = 'worker_readable=' . (is_readable($dir . '/worker.php') ? 'yes' : 'NO');
$bits[] = 'config_readable=' . (
    is_readable($dir . '/config.php') ? 'yes'
    : (is_readable(dirname($dir) . '/serp-tracker-config.php') ? 'yes (outside webroot)' : 'NO')
);

// Are the extensions the app needs actually present in this binary? A CLI
// build often has a different extension set than the web build.
$need = array('pdo_mysql', 'curl');
$missing = array();
foreach ($need as $ext) {
    if (!extension_loaded($ext)) {
        $missing[] = $ext;
    }
}
$bits[] = 'extensions=' . ($missing ? 'MISSING ' . implode(',', $missing) : 'ok');

// Version gate, the most common cause of a silent failure.
$bits[] = 'version_ok=' . (PHP_VERSION_ID >= 80000 ? 'yes' : 'NO, needs 8.0+');

// Can it reach the database with the app's own credentials?
$dbStatus = 'not tested';
$configPath = null;
foreach (array(dirname($dir) . '/serp-tracker-config.php', $dir . '/config.php') as $c) {
    if (is_readable($c)) { $configPath = $c; break; }
}
if ($configPath && PHP_VERSION_ID >= 50600) {
    $cfg = @include $configPath;
    if (is_array($cfg) && extension_loaded('pdo_mysql')) {
        try {
            $dsn = 'mysql:host=' . $cfg['db_host'] . ';dbname=' . $cfg['db_name'];
            $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass']);
            $n = $pdo->query('SELECT COUNT(*) FROM trackers')->fetchColumn();
            $dbStatus = 'ok, ' . (int)$n . ' schedules';
        } catch (Exception $e) {
            $dbStatus = 'FAILED: ' . substr($e->getMessage(), 0, 120);
        }
    } elseif (!is_array($cfg)) {
        $dbStatus = 'config did not return an array';
    }
}
$bits[] = 'database=' . $dbStatus;

$line = implode(' | ', $bits) . "\n";

$written = @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
echo $line;

if ($written === false) {
    echo "COULD NOT WRITE " . $logFile . " - check directory permissions\n";
}
