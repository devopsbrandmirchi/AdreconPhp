<?php
declare(strict_types=1);

// Run the same request several times to find out whether ads and correct
// locations are intermittent. The proxy pool has already been observed serving
// three different regions for one unchanged request, so it is plausible that
// only some proxies get an ad-carrying SERP.
//
//   php test_repeat.php "rv dealer near me" "Lakeland, Florida, United States"
//   php test_repeat.php "..." "..." 10          run ten times
//   php test_repeat.php "..." "..." 5 --stream  use file_get_contents, not cURL
//   php test_repeat.php "..." "..." 5 --uule    send a correctly encoded uule
//
// EACH RUN COSTS 10 CREDITS. Five runs is 50 credits.
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
$times    = isset($positional[2]) ? max(1, min(20, (int)$positional[2])) : 5;
$stream   = in_array('--stream', $flags, true);
$useUule  = in_array('--uule', $flags, true);

if ($keyword === '' || $location === '') {
    exit("Usage: php test_repeat.php \"keyword\" \"City, State, United States\" [times] [--stream] [--uule]\n");
}

$key = (string)cfg('scrapingdog_key');
if ($key === '') {
    exit("No scrapingdog_key in the config.\n");
}

$params = [
    'api_key'        => $key,
    'query'          => $keyword,
    'country'        => 'us',
    'advance_search' => 'true',
    'domain'         => 'google.com',
];
$params += $useUule ? ['uule' => sd_uule($location)] : ['location' => $location];

$url = sd_build_url(sd_endpoint(), $params);

$line = str_repeat('=', 78);
echo "$line\n REPEATED IDENTICAL REQUESTS\n$line\n";
echo "  keyword   : $keyword\n";
echo "  location  : $location\n";
echo "  geo mode  : " . ($useUule ? 'uule encoded by this app' : 'location parameter') . "\n";
echo "  transport : " . ($stream ? 'file_get_contents, same as your snippet' : 'cURL, same as the app') . "\n";
echo "  runs      : $times, costing " . ($times * 10) . " credits\n\n";

printf("  %-5s %-6s %-5s %-13s %s\n", 'RUN', 'HTTP', 'ADS', 'AD KEYS', 'WHERE GOOGLE THOUGHT YOU WERE');
echo "  " . str_repeat('-', 74) . "\n";

$withAds = 0;
$regions = [];
$correct = 0;
$unverifiable = 0;

for ($i = 1; $i <= $times; $i++) {
    $status = 0;
    $body   = false;

    if ($stream) {
        // Mirror the snippet exactly: default stream wrapper, default headers.
        $body = @file_get_contents($url);
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int)$m[1];
            }
        }
    } else {
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
    }

    $adCount = 0;
    $adKeys  = [];
    $where   = 'n/a';

    if ($body !== false) {
        $json = json_decode(sd_redact((string)$body, $key), true);
        if (is_array($json)) {
            [$ads, $meta] = sd_parse_ads($json);
            $adCount = count(array_filter($ads, fn($a) => $a['block'] !== 'local'));
            $adKeys  = $meta['keys_seen'];

            $loc   = sd_verify_location($json, $location);
            $where = $loc['local_hint'] ? substr($loc['local_hint'], 0, 34) : 'no local results';
            if (!$loc['warning']) {
                $correct++;
            }
            // Only genuine state abbreviations. "RV" is not a state.
            foreach ($loc['states_found'] as $st) {
                $regions[$st] = ($regions[$st] ?? 0) + 1;
            }
            if (!$loc['verifiable']) {
                $unverifiable++;
            }
        }
    }

    if ($adCount > 0) {
        $withAds++;
    }

    printf("  %-5d %-6d %-5d %-13s %s\n", $i, $status, $adCount,
        $adKeys ? implode(',', $adKeys) : 'none', $where);

    if ($i < $times) {
        sleep(1);
    }
}

echo "\n$line\n SUMMARY\n$line\n";
printf("  runs returning ads       : %d of %d\n", $withAds, $times);
printf("  runs targeting correctly : %d of %d\n", $correct, $times);
if ($regions) {
    arsort($regions);
    $parts = [];
    foreach ($regions as $st => $n) {
        $parts[] = "$st x$n";
    }
    printf("  states seen in results   : %s\n", implode(', ', $parts));
}
if ($unverifiable) {
    printf("  runs with no location in the results : %d of %d\n", $unverifiable, $times);
    echo "    (their local_results.address sometimes holds a rating and category\n";
    echo "     instead of a city, so location cannot be confirmed from those runs)\n";
}

echo "\n";
if ($withAds === 0) {
    echo "  No run returned ads. Combined with ads appearing in their own dashboard\n";
    echo "  for the same parameters, that points at a difference between their GUI\n";
    echo "  and their API, not at your request. Send them this output.\n";
} elseif ($withAds < $times) {
    printf("  Ads are INTERMITTENT: %d%% of identical requests carried them.\n",
        (int)round($withAds / $times * 100));
    echo "  The proxy pool is not uniform, so a single check is unreliable and a\n";
    echo "  zero in the dashboard may mean nothing. This is worth raising with them.\n";
} else {
    echo "  Every run returned ads. Whatever was wrong is no longer reproducing,\n";
    echo "  so re-run a normal check and see if the dashboard fills in.\n";
}

if ($correct === 0 && $times > 0 && $regions) {
    echo "\n  No run targeted the requested location.";
    echo ($useUule ? " A correctly encoded uule did not help either,\n  so geo targeting is broken upstream regardless of how it is passed.\n"
                   : " Try again with --uule.\n");
}
