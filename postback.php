<?php
declare(strict_types=1);

/**
 * Where DataForSEO delivers finished checks.
 *
 * Their servers POST the result here, gzip compressed, as soon as a task is
 * done. Set 'postback_token' and 'site_url' in config.php and the worker will
 * include this address on every task it queues.
 *
 * If a delivery ever fails, DataForSEO keeps the task on its 'tasks ready'
 * list and the worker collects it on a later pass, so nothing is lost when
 * this endpoint is briefly unreachable.
 *
 * This is a public URL by necessity, so it is guarded three ways: a secret
 * token, a check that the task id belongs to a check we are actually waiting
 * for, and a refusal to do anything on a GET.
 */

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/scheduler.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

/** Keep a short log so a delivery problem is visible without a database dig. */
function pb_log(string $line): void {
    if (!cfg('postback_log', true)) {
        return;
    }
    $path = dirname(__DIR__) . '/serp-postback.log';
    @file_put_contents($path, gmdate('Y-m-d H:i:s') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

$token = (string)cfg('postback_token', '');

if ($token === '' || strlen($token) < 16) {
    http_response_code(503);
    echo "Not configured.\n\n";
    echo "Set a long random 'postback_token' in config.php, for example:\n";
    echo "  'postback_token' => '" . bin2hex(random_bytes(16)) . "',\n";
    echo "and set 'site_url' to https://your-site so tasks carry this address.\n";
    exit;
}

if (!hash_equals($token, (string)($_GET['token'] ?? ''))) {
    usleep(400000);
    http_response_code(403);
    pb_log('rejected a delivery with a bad token from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    echo "Forbidden\n";
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    // Useful for checking the URL is reachable before wiring it up.
    echo "Ready. DataForSEO should POST results here.\n";
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    http_response_code(400);
    pb_log('empty delivery');
    echo "Empty body\n";
    exit;
}

// They compress the payload. Some hosts decompress it first, so handle both.
if (substr($raw, 0, 2) === "\x1f\x8b") {
    $un = @gzdecode($raw);
    if ($un === false) {
        http_response_code(400);
        pb_log('could not decompress a delivery of ' . strlen($raw) . ' bytes');
        echo "Could not decompress\n";
        exit;
    }
    $raw = $un;
}

$json = json_decode($raw, true);
if (!is_array($json)) {
    http_response_code(400);
    pb_log('delivery was not valid json, ' . strlen($raw) . ' bytes');
    echo "Not valid JSON\n";
    exit;
}

// Their own id parameter is authoritative; fall back to the payload.
$taskId = (string)($_GET['id'] ?? ($json['tasks'][0]['id'] ?? ''));

try {
    [$ok, $msg] = dfs_store_result($json, $taskId ?: null);
    pb_log(($ok ? 'stored ' : 'ignored ') . ($taskId ?: 'unknown') . ': ' . $msg);
    // Always answer 200 once the payload is understood. A non-2xx makes them
    // queue it for redelivery, which is right for a real failure and wrong for
    // a duplicate we have already stored.
    echo $ok ? "OK\n" : "Ignored: $msg\n";
} catch (Throwable $e) {
    http_response_code(500);
    pb_log('error storing ' . $taskId . ': ' . $e->getMessage());
    echo "Error\n";
}
