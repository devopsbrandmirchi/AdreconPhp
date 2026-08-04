<?php
declare(strict_types=1);

// Look at one DataForSEO task in detail.
//
//   php dfs_task.php 07292034-2186-0066-0000-d9466dd5566e
//   php dfs_task.php <id> --save      write the full payload to dfs-task.json
//
// Collecting a result costs nothing: you are billed when the task is posted,
// and can fetch it again for 30 days.
//
// See worker.php for why this checks the request context and not PHP_SAPI.
if (!empty($_SERVER['REQUEST_METHOD']) || !empty($_SERVER['REMOTE_ADDR'])
    || !empty($_SERVER['HTTP_HOST']) || !empty($_SERVER['REQUEST_URI'])) {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/provider.php';

$args   = array_slice($argv, 1);
$taskId = '';
$save   = false;
foreach ($args as $a) {
    if ($a === '--save') { $save = true; } elseif ($taskId === '') { $taskId = $a; }
}

if ($taskId === '') {
    echo "Usage: php dfs_task.php <task-id> [--save]\n\n";
    echo "The task id is printed by test_provider.php, and stored on every\n";
    echo "queued check. Recent ones:\n\n";
    try {
        $rows = db()->query(
            "SELECT r.task_id, r.status, t.keyword, t.location
             FROM runs r JOIN trackers t ON t.id = r.tracker_id
             WHERE r.task_id IS NOT NULL AND r.task_id != ''
             ORDER BY r.id DESC LIMIT 10"
        )->fetchAll();
        foreach ($rows as $r) {
            printf("  %s  %-8s %s @ %s\n", $r['task_id'], $r['status'],
                mb_substr($r['keyword'], 0, 22), mb_substr($r['location'], 0, 26));
        }
        if (!$rows) {
            echo "  none yet\n";
        }
    } catch (Throwable $e) {
        echo "  (could not read the database: " . $e->getMessage() . ")\n";
    }
    exit(1);
}

$line = str_repeat('=', 74);
echo "$line\n TASK $taskId\n$line\n";

$res = dfs_task_get($taskId);
if (!$res['ok']) {
    echo '  FAILED: ' . $res['error'] . "\n";
    if ($res['raw']) {
        echo "\n  " . substr((string)$res['raw'], 0, 500) . "\n";
    }
    exit(1);
}

$json = $res['json'];
$task = $json['tasks'][0] ?? [];

printf("  task status : %s %s\n", $task['status_code'] ?? '?', $task['status_message'] ?? '');
printf("  cost        : %s\n", $task['cost'] ?? 0);

$sent = $task['data'] ?? [];
printf("  keyword     : %s\n", $sent['keyword'] ?? '?');
printf("  location    : %s\n", $sent['location_name'] ?? ($sent['location_code'] ?? '?'));
printf("  device      : %s\n", $sent['device'] ?? '?');
printf("  depth       : %s\n", $sent['depth'] ?? 'default');

if ($save) {
    file_put_contents(__DIR__ . '/dfs-task.json',
        json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "  saved       : dfs-task.json\n";
}

$result = $task['result'][0] ?? null;
if (!$result) {
    echo "\n  No result yet. If the status above says the task is still in the\n";
    echo "  queue, wait and run this again. Nothing extra is charged.\n";
    exit(0);
}

echo "\n$line\n WHAT GOOGLE SERVED\n$line\n";
printf("  checked at  : %s\n", $result['datetime'] ?? '?');
printf("  elements    : %s\n", $result['items_count'] ?? 0);
printf("  see it live : %s\n", $result['check_url'] ?? 'not provided');

// This is the fastest answer to "were there ads at all". DataForSEO lists
// every element type it saw on the page.
$types = $result['item_types'] ?? [];
printf("\n  element types on the page:\n    %s\n", $types ? implode(', ', $types) : 'none listed');

$adTypes = ['paid', 'shopping', 'commercial_units', 'local_services', 'popular_products', 'hotels_pack'];
$found   = array_values(array_intersect($adTypes, $types));
printf("\n  paid element types present: %s\n", $found ? implode(', ', $found) : 'NONE');

echo "\n$line\n EVERY ELEMENT, IN PAGE ORDER\n$line\n";
printf("  %-5s %-22s %s\n", 'RANK', 'TYPE', 'DOMAIN OR TITLE');
$counts = [];
foreach ($result['items'] ?? [] as $item) {
    $type = (string)($item['type'] ?? '?');
    $counts[$type] = ($counts[$type] ?? 0) + 1;
    printf("  %-5s %-22s %s\n",
        $item['rank_absolute'] ?? '-',
        $type,
        mb_substr((string)($item['domain'] ?? $item['title'] ?? ''), 0, 44));
}
if (!$counts) {
    echo "  no elements at all\n";
}

echo "\n  totals: ";
foreach ($counts as $t => $n) {
    echo "$t=$n  ";
}
echo "\n";

echo "\n$line\n WHAT THIS APP MAKES OF IT\n$line\n";
[$ads, $meta] = dfs_parse_ads($json);
printf("  placement source : %s\n", $meta['block_source']);
printf("  ad keys seen     : %s\n", $meta['keys_seen'] ? implode(', ', $meta['keys_seen']) : 'none');

if (!$ads) {
    echo "\n  No ads recorded.\n";
    if ($found) {
        echo "  But the page DID carry " . implode(', ', $found) . ".\n";
        echo "  That is a parser gap, not an empty result. Send this output on.\n";
    } else {
        echo "  The page carried no paid elements either, so this is a real\n";
        echo "  finding: nobody was bidding on that search in that location.\n";
        echo "  Open the check_url above to see the same page Google served.\n";
    }
    exit(0);
}

printf("\n  %-8s %-4s %-30s %s\n", 'BLOCK', 'POS', 'DOMAIN', 'HEADLINE');
foreach ($ads as $a) {
    printf("  %-8s %-4d %-30s %s\n", $a['block'], $a['position'],
        $a['domain'], mb_substr((string)$a['headline'], 0, 34));
}
