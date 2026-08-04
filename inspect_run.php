<?php
declare(strict_types=1);

// Inspect what the API actually returned for a stored run. Costs nothing,
// the payload is already in the database.
//
//   php inspect_run.php            latest run
//   php inspect_run.php 42         a specific run id
//   php inspect_run.php --list     recent runs and their ids
//   php inspect_run.php 42 --full  print the whole payload
//
// See worker.php for why this checks the request context and not PHP_SAPI.
if (!empty($_SERVER['REQUEST_METHOD']) || !empty($_SERVER['REMOTE_ADDR'])
    || !empty($_SERVER['HTTP_HOST']) || !empty($_SERVER['REQUEST_URI'])) {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/provider.php';

$args = array_slice($argv, 1);
$full = in_array('--full', $args, true);
$list = in_array('--list', $args, true);
$runId = 0;
foreach ($args as $a) {
    if (ctype_digit($a)) {
        $runId = (int)$a;
    }
}

if ($list) {
    $rows = db()->query(
        "SELECT r.id, r.started_at, r.status, r.top_count, r.bottom_count, r.block_source,
                t.keyword, t.location, t.device
         FROM runs r JOIN trackers t ON t.id = r.tracker_id
         ORDER BY r.id DESC LIMIT 25"
    )->fetchAll();
    printf("%-6s %-20s %-9s %5s %5s  %s\n", 'ID', 'STARTED (UTC)', 'STATUS', 'TOP', 'BOT', 'KEYWORD @ LOCATION');
    foreach ($rows as $r) {
        printf(
            "%-6d %-20s %-9s %5d %5d  %s @ %s [%s]\n",
            $r['id'], $r['started_at'], $r['status'],
            $r['top_count'], $r['bottom_count'], $r['keyword'], $r['location'], $r['device']
        );
    }
    exit(0);
}

$sql = "SELECT r.*, t.keyword, t.location, t.country, t.device
        FROM runs r JOIN trackers t ON t.id = r.tracker_id ";
$sql .= $runId ? "WHERE r.id = ? " : "WHERE r.raw_json IS NOT NULL ";
$sql .= "ORDER BY r.id DESC LIMIT 1";

$stmt = db()->prepare($sql);
$stmt->execute($runId ? [$runId] : []);
$run = $stmt->fetch();

if (!$run) {
    exit("No run found. Try: php inspect_run.php --list\n");
}

$line = str_repeat('=', 72);

echo "$line\n RUN #{$run['id']}\n$line\n";
printf("  keyword       : %s\n", $run['keyword']);
printf("  location      : %s\n", $run['location']);
printf("  device        : %s\n", $run['device']);
printf("  started       : %s UTC\n", $run['started_at']);
printf("  status        : %s\n", $run['status']);
printf("  http          : %s\n", $run['http_status'] ?: 'n/a');
printf("  parsed as     : %d top, %d bottom\n", $run['top_count'], $run['bottom_count']);
printf("  block source  : %s\n", $run['block_source'] ?: 'n/a');
if ($run['error_message']) {
    printf("  error         : %s\n", $run['error_message']);
}

// Rebuild the request exactly as the app builds it, with the key masked.
if (provider_is_async()) {
    printf("  endpoint      : %s/serp/google/organic/task_post\n", dfs_endpoint());
    printf("  task id       : %s\n", $run['task_id'] ?: 'not recorded');
    $params = [];
} else {
$params = provider_build_params([
    'keyword'  => $run['keyword'],
    'location' => $run['location'],
    'country'  => $run['country'],
    'device'   => $run['device'],
], true);
echo "\n  request sent  :\n    " . provider_build_url(provider_endpoint(), $params) . "\n";
}
printf("  provider      : %s\n", provider_label());
if (!provider_is_async()) {
    printf("  geo mode      : %s\n", isset($params['uule']) ? 'uule, encoded by this app' : 'location parameter');
}
if (isset($params['uule'])) {
    printf("  uule sent     : %s\n", $params['uule']);
}

if (!$run['raw_json']) {
    exit("\n  No payload stored for this run. Raw payloads are pruned after the\n"
       . "  retention window, so pick a more recent run.\n");
}

$json = json_decode($run['raw_json'], true);
if (!is_array($json)) {
    echo "\n  Payload is not valid JSON. First 500 characters:\n\n";
    echo substr($run['raw_json'], 0, 500) . "\n";
    exit(1);
}

echo "\n$line\n TOP LEVEL KEYS\n$line\n";
foreach ($json as $k => $v) {
    $type = gettype($v);
    if (is_array($v)) {
        $isList = array_keys($v) === range(0, count($v) - 1);
        $type = $isList ? 'array[' . count($v) . ']' : 'object{' . count($v) . '}';
    } elseif (is_string($v)) {
        $type = 'string "' . substr($v, 0, 50) . (strlen($v) > 50 ? '...' : '') . '"';
    } elseif (is_bool($v)) {
        $type = $v ? 'true' : 'false';
    }
    $flag = stripos((string)$k, 'ad') !== false ? '  <-- looks ad related' : '';
    printf("  %-28s %s%s\n", $k, $type, $flag);
}

echo "\n$line\n ARRAYS OF OBJECTS, AND THEIR FIELDS\n$line\n";
$foundAny = false;
foreach ($json as $k => $v) {
    if (!is_array($v) || !$v) {
        continue;
    }
    $first = reset($v);
    if (!is_array($first)) {
        continue;
    }
    $foundAny = true;
    printf("\n  %s  (%d items)\n    fields: %s\n", $k, count($v), implode(', ', array_keys($first)));

    // Show a couple of entries so ads are recognisable at a glance.
    $shown = 0;
    foreach ($v as $item) {
        if (!is_array($item) || $shown >= 2) {
            break;
        }
        $title = $item['title'] ?? $item['headline'] ?? '';
        $link  = $item['displayed_link'] ?? $item['link'] ?? $item['url'] ?? '';
        printf("      - %s\n        %s\n", substr((string)$title, 0, 60), substr((string)$link, 0, 70));
        $shown++;
    }
}
if (!$foundAny) {
    echo "  None. The response contained no arrays of objects at all, which\n";
    echo "  usually means the API returned an error rather than a SERP.\n";
}

// Anything that reads like an error or a quota message.
echo "\n$line\n LOCATION CHECK\n$line\n";
$loc = provider_verify_location($json, (string)$run['location']);
printf("  requested       : %s\n", $loc['requested']);
printf("  uule decoded to : %s\n", $loc['served'] ?? 'no uule in the response');
printf("  uule wellformed : %s\n", $loc['uule_wellformed'] === null ? 'n/a'
    : ($loc['uule_wellformed'] ? 'yes' : 'NO, missing the length prefix byte'));
printf("  local results   : %s\n", $loc['local_hint'] ?? 'none returned');
if ($loc['warning']) {
    echo "\n  WARNING: " . $loc['warning'] . "\n";
} else {
    echo "\n  Location looks correct.\n";
}

echo "\n$line\n MESSAGES\n$line\n";
$msgFound = false;
foreach (['error', 'message', 'msg', 'detail', 'status', 'warning'] as $k) {
    if (isset($json[$k]) && (is_string($json[$k]) || is_numeric($json[$k]))) {
        printf("  %-12s %s\n", $k, $json[$k]);
        $msgFound = true;
    }
}
if (!$msgFound) {
    echo "  None.\n";
}

echo "\n$line\n WHAT THE PARSER FOUND\n$line\n";
[$parsedAds, $parsedMeta] = provider_parse_ads($json);
printf("  ad keys in response : %s\n", $parsedMeta['keys_seen'] ? implode(', ', $parsedMeta['keys_seen']) : 'NONE');
printf("  block source        : %s\n", $parsedMeta['block_source']);
printf("  ads parsed          : %d\n", count($parsedAds));
if (!$parsedMeta['keys_seen']) {
    echo "\n  The response contains no ad key of any name. This is not a parsing\n";
    echo "  problem. Either Google served no ads for this query from the proxy\n";
    echo "  location used, or advanced search is not returning ads on this plan.\n";
}

if ($full) {
    echo "\n$line\n FULL PAYLOAD\n$line\n";
    echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}
