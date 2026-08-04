<?php
declare(strict_types=1);

// Cron worker. Runs schedules that are due.
//
// DirectAdmin cron, every 5 minutes:
//   */5 * * * * /usr/local/bin/php /home/USER/serp-tracker/worker.php >> /home/USER/serp-tracker/worker.log 2>&1
//
// A 5 minute cron gives at worst 5 minutes of drift against the scheduled
// time, which is fine at these intervals. Anything tighter is wasted cycles.
//
// Note for future edits: the cron expression above must stay inside line
// comments. Putting it in a /* */ block would end the comment at the */5.

// Block web access to this script.
//
// Checking PHP_SAPI === 'cli' is not enough. On some shared hosts the `php` in
// cron's PATH is the CGI binary, which reports 'cgi-fcgi' whether it is run by
// cron or by the web server, so a SAPI check would refuse legitimate cron runs.
// The reliable signal is the absence of an HTTP request context.
$fromWeb = !empty($_SERVER['REQUEST_METHOD'])
        || !empty($_SERVER['REMOTE_ADDR'])
        || !empty($_SERVER['HTTP_HOST'])
        || !empty($_SERVER['REQUEST_URI']);

if ($fromWeb) {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

// Make failures visible in the cron log.
//
// A CLI php.ini with display_errors off sends fatal errors to PHP's own error
// log instead of stderr. Cron captures stderr, not that log, so the run would
// fail silently and the cron log would stay empty, which is indistinguishable
// from a successful run that had nothing to do. Force errors to stderr and
// catch fatals explicitly so that can never happen.
ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');
error_reporting(E_ALL);

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        fwrite(STDERR, sprintf(
            "[%s] FATAL %s in %s line %d\n",
            gmdate('Y-m-d H:i:s'), $e['message'], $e['file'], $e['line']
        ));
    }
});

set_exception_handler(function (Throwable $t) {
    fwrite(STDERR, sprintf(
        "[%s] UNCAUGHT %s: %s in %s line %d\n",
        gmdate('Y-m-d H:i:s'), get_class($t), $t->getMessage(), $t->getFile(), $t->getLine()
    ));
    exit(1);
});

// A wrong binary in cron usually means an old PHP. Say so plainly instead of
// dying on an undefined function several files deep.
if (PHP_VERSION_ID < 80000) {
    echo 'This app needs PHP 8.0 or newer. This binary is ' . PHP_VERSION
       . ' at ' . (defined('PHP_BINARY') ? PHP_BINARY : 'unknown path')
       . ". Point the cron job at a PHP 8 binary.\n";
    exit(1);
}

// Extensions are the other common trap. A box can carry several PHP builds
// where the newest has no MySQL driver, and cron picking that one would fail
// on every run with a message from deep inside PDO.
$missing = [];
foreach (['pdo_mysql', 'curl'] as $ext) {
    if (!extension_loaded($ext)) {
        $missing[] = $ext;
    }
}
if ($missing) {
    echo 'This PHP binary is missing: ' . implode(', ', $missing) . "\n"
       . '  binary  : ' . (defined('PHP_BINARY') ? PHP_BINARY : 'unknown') . "\n"
       . '  version : ' . PHP_VERSION . "\n"
       . "Point the cron job at a binary that has them. Check candidates with:\n"
       . "  /path/to/php -m | grep -E '^(pdo_mysql|curl)$'\n";
    exit(1);
}

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/scheduler.php';

$started = microtime(true);
$batch   = (int)cfg('batch_size', 10);

// php worker.php --status  reports state without running or spending anything.
if (in_array('--status', array_slice($argv, 1), true)) {
    $row = db()->query(
        "SELECT
            (SELECT COUNT(*) FROM trackers) AS total,
            (SELECT COUNT(*) FROM trackers WHERE status = 'active') AS active,
            (SELECT COUNT(*) FROM trackers WHERE status = 'paused') AS paused,
            (SELECT COUNT(*) FROM trackers WHERE status = 'error') AS errored,
            (SELECT COUNT(*) FROM trackers WHERE status = 'active' AND next_run_at <= UTC_TIMESTAMP()) AS due,
            (SELECT MIN(next_run_at) FROM trackers WHERE status = 'active') AS next_due,
            (SELECT MAX(started_at) FROM runs WHERE trigger_source = 'scheduled') AS last_cron,
            (SELECT COUNT(*) FROM runs) AS runs"
    )->fetch();

    echo "worker status
";
    printf("  provider          : %s, %d %s per check
", provider_label(), provider_cost(), provider_unit());
    printf("  api key set       : %s
", provider_key_configured() ? 'yes' : 'NO');
    printf("  schedules         : %d total, %d started, %d stopped, %d errored
",
        $row['total'], $row['active'], $row['paused'], $row['errored']);
    printf("  due right now     : %d
", $row['due']);
    printf("  next one due at   : %s
", $row['next_due'] ? $row['next_due'] . ' UTC' : 'nothing scheduled');
    printf("  runs recorded     : %d
", $row['runs']);
    printf("  last cron run     : %s
", $row['last_cron'] ? $row['last_cron'] . ' UTC' : 'never');
    printf("  time now          : %s UTC
", gmdate('Y-m-d H:i:s'));

    if ((int)$row['active'] === 0) {
        echo "
  Nothing is started, so cron has nothing to do and will write nothing
";
        echo "  to the log. Press Start on a schedule in the dashboard.
";
    }
    exit(0);
}

reap_stale_runs();
reap_queued_runs();
expire_finished_trackers();

// Anything the push delivery missed is still waiting on the provider's side.
foreach (provider_collect_ready() as $line) {
    printf("[%s] %s\n", gmdate('Y-m-d H:i:s'), $line);
}

$lines = run_due_trackers((int)cfg('batch_size', 10), 'scheduled');

if (!$lines) {
    // Always leave a trace. Without this an empty log is ambiguous: it could
    // mean cron never fired, or that cron fired and nothing was due.
    if (cfg('worker_heartbeat', true)) {
        printf("[%s] nothing due\n", gmdate('Y-m-d H:i:s'));
    }
    exit(0);
}

echo implode("\n", $lines) . "\n";

prune_raw_payloads();

printf("[%s] processed %d in %.1fs\n", gmdate('Y-m-d H:i:s'), count($lines), microtime(true) - $started);
