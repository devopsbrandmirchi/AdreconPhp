<?php
declare(strict_types=1);

/**
 * HTTP trigger for hosts where system cron does not work.
 *
 * Point an external cron service at:
 *   https://your-site/cron.php?token=YOUR_SECRET
 *
 * Free services that will call a URL on a schedule include cron-job.org,
 * EasyCron and UptimeRobot. Five minute intervals are plenty; the worker only
 * acts on schedules that are actually due.
 *
 * The token must be set in config.php as 'cron_token'. Without it this file
 * refuses to do anything, so uploading it is not a risk in itself.
 */

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/scheduler.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$token = (string)cfg('cron_token', '');

if ($token === '' || strlen($token) < 16) {
    http_response_code(503);
    echo "Not configured.\n\n";
    echo "Set a long random 'cron_token' in config.php, for example:\n";
    echo "  'cron_token' => '" . bin2hex(random_bytes(16)) . "',\n";
    exit;
}

$given = (string)($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '');

if (!hash_equals($token, $given)) {
    // Say as little as possible, and slow down guessing.
    usleep(500000);
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

// A browser or monitoring service may give up before a batch finishes, so keep
// working regardless and cap the runtime well under a typical 60s timeout.
ignore_user_abort(true);
@set_time_limit(0);

$deadline = microtime(true) + (float)cfg('cron_http_seconds', 45);
$batch    = (int)cfg('batch_size', 10);

echo "serp-tracker cron trigger\n";
printf("started  : %s UTC\n", gmdate('Y-m-d H:i:s'));
printf("provider : %s\n", provider_label());

reap_stale_runs();
reap_queued_runs();
expire_finished_trackers();

foreach (provider_collect_ready() as $line) {
    echo $line . "\n";
}

$total = 0;
$lines = [];

// Work in small slices so the deadline is respected between checks rather than
// in the middle of one.
while (microtime(true) < $deadline) {
    $slice = run_due_trackers(min(3, $batch), 'scheduled');
    if (!$slice) {
        break;
    }
    $lines  = array_merge($lines, $slice);
    $total += count($slice);
    if ($total >= $batch) {
        break;
    }
}

prune_raw_payloads();

if ($lines) {
    echo "\n" . implode("\n", $lines) . "\n";
}

$remaining = (int)db()->query(
    "SELECT COUNT(*) FROM trackers WHERE status = 'active' AND next_run_at <= UTC_TIMESTAMP()"
)->fetchColumn();

printf("\nprocessed: %d\n", $total);
printf("still due: %d\n", $remaining);
printf("finished : %s UTC\n", gmdate('Y-m-d H:i:s'));
echo $total === 0 && $remaining === 0 ? "\nNothing was due. That is a healthy result.\n" : "";
