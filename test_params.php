<?php
declare(strict_types=1);

// Isolate which request parameter is responsible for ads disappearing.
//
// Sends the same keyword and location several times, changing exactly one
// thing each time, starting from the minimal call known to return ads. The
// first variant that drops to zero ads names the culprit.
//
//   php test_params.php "rv for sale" "Arlington, Texas, United States"
//
// EACH VARIANT COSTS 10 CREDITS. Seven variants is 70 credits.
// Add --quick to run only the four most likely ones, 40 credits.
//
// See worker.php for why this checks the request context and not PHP_SAPI.
if (!empty($_SERVER['REQUEST_METHOD']) || !empty($_SERVER['REMOTE_ADDR'])
    || !empty($_SERVER['HTTP_HOST']) || !empty($_SERVER['REQUEST_URI'])) {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/scrapingdog.php';

$args       = array_slice($argv, 1);
$flags      = array_values(array_filter($args, fn($a) => str_starts_with($a, '--')));
$positional = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));

$keyword  = $positional[0] ?? '';
$location = $positional[1] ?? '';
$quick    = in_array('--quick', $flags, true);

if ($keyword === '' || $location === '') {
    exit("Usage: php test_params.php \"keyword\" \"City, State, United States\" [--quick]\n");
}

$key = (string)cfg('scrapingdog_key');
if ($key === '') {
    exit("No scrapingdog_key in the config.\n");
}

$base = [
    'api_key'        => $key,
    'query'          => $keyword,
    'country'        => 'us',
    'domain'         => 'google.com',
    'advance_search' => 'true',
    'location'       => $location,
];

$noSlash = rtrim(sd_endpoint(), '/');
$slash   = $noSlash . '/';

$variants = [
    'A  minimal, your working call' => ['url' => $noSlash, 'params' => $base,                              'enc' => PHP_QUERY_RFC3986],
    'B  A + results=20'             => ['url' => $noSlash, 'params' => $base + ['results' => '20'],        'enc' => PHP_QUERY_RFC3986],
    'C  A + language=en'            => ['url' => $noSlash, 'params' => $base + ['language' => 'en'],       'enc' => PHP_QUERY_RFC3986],
    'D  A + page=0'                 => ['url' => $noSlash, 'params' => $base + ['page' => '0'],            'enc' => PHP_QUERY_RFC3986],
];
if (!$quick) {
    $variants += [
        'E  old app, all extras'    => ['url' => $slash,   'params' => $base + ['language' => 'en', 'results' => '20', 'page' => '0'], 'enc' => PHP_QUERY_RFC1738],
        'F  A with trailing slash'  => ['url' => $slash,   'params' => $base,                              'enc' => PHP_QUERY_RFC3986],
        'G  A with + for spaces'    => ['url' => $noSlash, 'params' => $base,                              'enc' => PHP_QUERY_RFC1738],
        'H  city inside the query'  => ['url' => $noSlash,
            'params' => ['query' => sd_localized_query($keyword, $location)] + $base,
            'enc' => PHP_QUERY_RFC3986],
    ];
}

$line = str_repeat('=', 78);
echo "$line\n PARAMETER LADDER\n$line\n";
echo "  keyword  : $keyword\n";
echo "  location : $location\n";
echo "  variants : " . count($variants) . ", costing " . (count($variants) * 10) . " credits\n";
if (!$quick) {
    echo "  variant H sends: \"" . sd_localized_query($keyword, $location) . "\"\n";
}
echo "\n";

printf("  %-30s %-6s %-6s %-14s %s\n", 'VARIANT', 'HTTP', 'ADS', 'AD KEYS', 'LOCAL RESULTS');
echo "  " . str_repeat('-', 74) . "\n";

$results = [];

foreach ($variants as $label => $v) {
    $url = $v['url'] . (str_contains($v['url'], '?') ? '&' : '?')
         . http_build_query($v['params'], '', '&', $v['enc']);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'serp-ads-tracker/1.0',
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $adCount = 0;
    $adKeys  = [];
    $local   = 'n/a';

    if ($body !== false) {
        $json = json_decode(sd_redact((string)$body, $key), true);
        if (is_array($json)) {
            [$ads, $meta] = sd_parse_ads($json);
            $adCount = count($ads);
            $adKeys  = $meta['keys_seen'];
            $loc     = sd_verify_location($json, $location);
            $local   = $loc['local_hint'] ? substr($loc['local_hint'], 0, 26) : 'none returned';
        }
    }

    $results[$label] = $adCount;
    printf("  %-30s %-6d %-6d %-14s %s\n", $label, $status, $adCount,
        $adKeys ? implode(',', $adKeys) : 'none', $local);

    usleep(700000);
}

echo "\n$line\n VERDICT\n$line\n";

$baselineLabel = array_key_first($results);
$baseline      = $results[$baselineLabel];

$hKey = 'H  city inside the query';
$hWorks = isset($results[$hKey]) && $results[$hKey] > 0;

if ($baseline === 0 && $hWorks) {
    echo "  The location parameter returns no ads, but folding the city into the\n";
    echo "  query does: variant H returned " . $results[$hKey] . " ads.\n\n";
    echo "  Workaround: set 'append_location_to_query' => true in config.php.\n";
    echo "  Understand the trade first. \"rv for sale in arlington, tx\" is a\n";
    echo "  different search from a person in Arlington typing \"rv for sale\",\n";
    echo "  so it runs a different ad auction and can show different advertisers.\n";
    echo "  It is a usable proxy, not the same measurement.\n";
    exit(0);
}

if ($baseline === 0) {
    echo "  Even the minimal call returned no ads. The parameters are not the\n";
    echo "  problem. Either Google served no ads for this keyword from the proxy\n";
    echo "  used, or advanced search is not returning ads on this account.\n";
    echo "  Re-run with a keyword that always carries ads, such as\n";
    echo "  \"auto insurance quotes\", before contacting support.\n";
    exit(0);
}

echo "  Baseline: $baselineLabel returned $baseline ads.\n\n";
$culprits = [];
foreach ($results as $label => $count) {
    if ($label === $baselineLabel) {
        continue;
    }
    if ($count === 0) {
        $culprits[] = $label;
        echo "  KILLS ADS   $label\n";
    } elseif ($count < $baseline) {
        echo "  reduces     $label ($count vs $baseline)\n";
    } else {
        echo "  harmless    $label ($count ads)\n";
    }
}

if ($culprits) {
    echo "\n  Remove whatever those variants add. In config.php keep\n";
    echo "  results_per_page at 0 and send_language false, which is the default.\n";
} else {
    echo "\n  No single parameter kills ads outright. If the app still reports zero,\n";
    echo "  re-run this against the exact keyword and location that is failing.\n";
}
