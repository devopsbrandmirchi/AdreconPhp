<?php
declare(strict_types=1);

// One live call through whichever provider is configured. Use this to check a
// new API key and a location string before committing any schedules.
//
//   php test_provider.php "rv for sale" "Arlington, Texas, United States"
//   php test_provider.php "..." "..." --mobile
//   php test_provider.php "..." "..." --save     write the payload to a file
//
// COSTS ONE CHECK, in whatever unit your provider bills.
//
// See worker.php for why this checks the request context and not PHP_SAPI.
if (!empty($_SERVER['REQUEST_METHOD']) || !empty($_SERVER['REMOTE_ADDR'])
    || !empty($_SERVER['HTTP_HOST']) || !empty($_SERVER['REQUEST_URI'])) {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/provider.php';

$args       = array_slice($argv, 1);
$flags      = array_values(array_filter($args, fn($a) => str_starts_with($a, '--')));
$positional = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));

$keyword  = $positional[0] ?? '';
$location = $positional[1] ?? '';
$mobile   = in_array('--mobile', $flags, true);
$save     = in_array('--save', $flags, true);

if ($keyword === '' || $location === '') {
    exit("Usage: php test_provider.php \"keyword\" \"City, State, United States\" [--mobile] [--save]\n");
}

$line = str_repeat('=', 76);
echo "$line\n PREFLIGHT CHECK\n$line\n";
printf("  provider  : %s\n", provider_label());
printf("  cost      : 1 check, billed as %d %s\n", provider_cost(), provider_unit());
printf("  keyword   : %s\n", $keyword);
printf("  location  : %s\n", $location);
printf("  device    : %s\n", $mobile ? 'mobile' : 'desktop');

if (!provider_key_configured()) {
    $need = match (provider_name()) {
        'serpapi'    => "'serpapi_key'",
        'dataforseo' => "'dataforseo_login' and 'dataforseo_password'",
        default      => "'scrapingdog_key'",
    };
    echo "\n  NOT CONFIGURED. Set " . $need . " in config.php.\n";
    exit(1);
}

$tracker = [
    'keyword'  => $keyword,
    'location' => $location,
    'country'  => 'us',
    'device'   => $mobile ? 'mobile' : 'desktop',
];

if (!provider_is_async()) {
    printf("\n  request   :\n    %s\n\n",
        provider_build_url(provider_endpoint(), provider_build_params($tracker, true)));
} else {
    printf("\n  endpoint  : %s/serp/google/organic/task_post\n", dfs_endpoint());
    printf("  location  : %s\n\n", dfs_location_name($location));
}

if (provider_is_async()) {
    echo "\n  This provider queues checks rather than answering immediately.\n";
    echo "  Posting one task now. The result arrives at postback.php, or is\n";
    echo "  collected by the next worker run.\n\n";

    $posted = dfs_post_task($tracker, 0);
    if (!$posted['ok']) {
        echo '  FAILED: ' . $posted['error'] . "\n";
        if ($posted['raw']) {
            echo "\n  First 400 characters of the response:\n\n  " . substr((string)$posted['raw'], 0, 400) . "\n";
        }
        echo "\n  Common causes: wrong login or password, an empty balance, or a\n";
        echo "  location string DataForSEO does not recognise.\n";
        exit(1);
    }
    printf("  task queued: %s\n", $posted['task_id']);
    printf("  cost       : %s\n", number_format((float)$posted['cost'], 5));

    echo "\n  Waiting up to 90 seconds for it to finish";
    $json = null;
    for ($i = 0; $i < 30; $i++) {
        sleep(3);
        echo '.';
        $got = dfs_task_get($posted['task_id']);
        if ($got['ok'] && (int)($got['json']['tasks'][0]['status_code'] ?? 0) === 20000
            && !empty($got['json']['tasks'][0]['result'])) {
            $json = $got['json'];
            break;
        }
    }
    echo "\n";
    if (!$json) {
        echo "\n  Still not ready. That is normal for the Standard queue at busy times.\n";
        echo "  Collect it later with:  php worker.php\n";
        exit(0);
    }
    $res = ['ok' => true, 'http' => 200, 'error' => null,
            'raw' => json_encode($json), 'json' => $json];
} else {
    $start = microtime(true);
    $res   = provider_fetch($tracker);
    printf("  http      : %d in %.1fs\n", $res['http'], microtime(true) - $start);
}

if (!$res['ok']) {
    echo "\n  FAILED: " . $res['error'] . "\n";
    if ($res['raw']) {
        echo "\n  First 400 characters of the response:\n\n  " . substr((string)$res['raw'], 0, 400) . "\n";
    }
    echo "\n  Common causes: a wrong or unactivated API key, a location string the\n";
    echo "  provider does not recognise, or an exhausted plan.\n";
    exit(1);
}

$json = $res['json'];

if ($save) {
    file_put_contents(__DIR__ . '/api_response.json',
        json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "  saved     : api_response.json\n";
}

echo "\n$line\n LOCATION\n$line\n";
$loc = provider_verify_location($json, $location);
printf("  requested : %s\n", $loc['requested']);
printf("  served    : %s\n", $loc['served'] ?? 'not reported by this provider');
if ($loc['warning']) {
    echo "\n  WARNING: " . $loc['warning'] . "\n";
} elseif ($loc['verifiable']) {
    echo "\n  Location confirmed.\n";
} else {
    echo "\n  The response carried nothing to verify the location against.\n";
}

echo "\n$line\n ADS\n$line\n";
[$ads, $meta] = provider_parse_ads($json);

$byBlock = [];
foreach ($ads as $a) {
    $byBlock[$a['block']][] = $a;
}

printf("  ad keys in response : %s\n", $meta['keys_seen'] ? implode(', ', $meta['keys_seen']) : 'NONE');
printf("  placement source    : %s\n\n", $meta['block_source']);

foreach (['top' => 'TOP OF PAGE', 'bottom' => 'BOTTOM OF PAGE',
          'middle' => 'MIDDLE', 'right' => 'RIGHT RAIL', 'local' => 'LOCAL SERVICES'] as $b => $label) {
    $rows = $byBlock[$b] ?? [];
    printf("  %-16s %d\n", $label, count($rows));
    foreach ($rows as $a) {
        printf("    %-2d %-28s %s\n", $a['position'], $a['domain'],
            substr((string)$a['headline'], 0, 40));
        if (!empty($a['sitelinks'])) {
            printf("       %d sitelinks\n", count($a['sitelinks']));
        }
    }
}

$tracked = count($byBlock['top'] ?? []) + count($byBlock['bottom'] ?? []);

echo "\n$line\n VERDICT\n$line\n";
if ($tracked > 0) {
    printf("  %d ads in the blocks this tool tracks. Everything is working, so\n", $tracked);
    echo "  create your schedules and press Start.\n";
} elseif ($ads) {
    echo "  Ads came back, but none in the top or bottom blocks. That is a real\n";
    echo "  result for this keyword and location, not a fault.\n";
} else {
    echo "  No ads at all for this keyword and location. Before concluding\n";
    echo "  anything, try a keyword that always carries ads:\n\n";
    echo "    php test_provider.php \"auto insurance quotes\" \"" . $location . "\"\n";
}
