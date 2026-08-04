<?php
declare(strict_types=1);

// Make one live API call and show exactly what comes back. Use this when the
// app reports no ads and you want to know whether that is the truth or a
// parsing problem.
//
//   php test_api.php "polaris for sale" "Lakeland, Florida, United States"
//   php test_api.php "polaris for sale" "Lakeland, Florida, United States" --basic
//   php test_api.php "polaris for sale" "Lakeland, Florida, United States" --save
//
// --basic  sends advance_search=false, which costs 5 credits instead of 10 and
//          should return no ads at all. Useful as a control.
// --save   writes the full response to api_response.json next to this script.
//
// THIS SPENDS CREDITS. One call is 10 credits, or 5 with --basic.
//
// See worker.php for why this checks the request context and not PHP_SAPI.
if (!empty($_SERVER['REQUEST_METHOD']) || !empty($_SERVER['REMOTE_ADDR'])
    || !empty($_SERVER['HTTP_HOST']) || !empty($_SERVER['REQUEST_URI'])) {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/scrapingdog.php';


/** One API call, returned as a structured summary. */
function sd_probe(string $keyword, string $location, bool $advanced, bool $uule, bool $mobile): array {
    $geo = $uule ? ['uule' => sd_uule($location)] : ['location' => $location];
    $params = [
        'api_key'        => (string)cfg('scrapingdog_key'),
        'query'          => $keyword,
        'country'        => 'us',
        'domain'         => 'google.com',
        'language'       => 'en',
        'results'        => '20',
        'page'           => '0',
        'advance_search' => $advanced ? 'true' : 'false',
    ] + $geo;
    if ($mobile) {
        $params['mob_search'] = 'true';
    }

    $ch = curl_init(sd_build_url(sd_endpoint(), $params));
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

    $out = ['http' => $status, 'json' => null, 'ads' => 0, 'ad_keys' => [],
            'served' => null, 'wellformed' => null, 'local' => null,
            'organic' => [], 'warning' => null];

    if ($body === false) {
        return $out;
    }
    $body = sd_redact((string)$body, (string)$params['api_key']);
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return $out;
    }
    $out['json'] = $json;

    [$ads, $meta] = sd_parse_ads($json);
    $out['ads']     = count($ads);
    $out['ad_keys'] = $meta['keys_seen'];

    $loc = sd_verify_location($json, $location);
    $out['served']     = $loc['served'];
    $out['wellformed'] = $loc['uule_wellformed'];
    $out['local']      = $loc['local_hint'];
    $out['warning']    = $loc['warning'];

    foreach (array_slice($json['organic_results'] ?? $json['organic_data'] ?? [], 0, 3) as $o) {
        $out['organic'][] = sd_domain($o['displayed_link'] ?? $o['link'] ?? '');
    }
    return $out;
}

$args     = array_slice($argv, 1);
$flags    = array_values(array_filter($args, fn($a) => str_starts_with($a, '--')));
$positional = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));

$keyword  = $positional[0] ?? '';
$location = $positional[1] ?? '';
$basic    = in_array('--basic', $flags, true);
$useUule  = in_array('--uule', $flags, true);
$compare  = in_array('--compare', $flags, true);
$inQuery  = in_array('--inquery', $flags, true);
$save     = in_array('--save', $flags, true);
$mobile   = in_array('--mobile', $flags, true);

if ($keyword === '' || $location === '') {
    exit("Usage: php test_api.php \"keyword\" \"City, State, United States\" [--basic] [--mobile] [--save]\n");
}


if ($compare) {
    $line = str_repeat('=', 72);
    echo "$line\n LOCATION MODE COMPARISON\n$line\n";
    echo "  keyword  : $keyword\n";
    echo "  location : $location\n";
    echo "  cost     : 20 credits, two advanced calls\n\n";

    $a = sd_probe($keyword, $location, true, false, $mobile);
    $b = sd_probe($keyword, $location, true, true,  $mobile);

    $row = function (string $label, $x, $y) {
        printf("  %-20s | %-22s | %s\n", $label,
            substr((string)$x, 0, 22), substr((string)$y, 0, 30));
    };

    printf("  %-20s | %-22s | %s\n", '', 'location parameter', 'uule built by this app');
    echo "  " . str_repeat('-', 68) . "\n";
    $row('http', $a['http'], $b['http']);
    $row('uule wellformed', $a['wellformed'] === null ? 'n/a' : ($a['wellformed'] ? 'yes' : 'NO'),
                            $b['wellformed'] === null ? 'n/a' : ($b['wellformed'] ? 'yes' : 'NO'));
    $row('served location', $a['served'] ?? 'none', $b['served'] ?? 'none');
    $row('ads found', $a['ads'], $b['ads']);
    $row('ad keys', $a['ad_keys'] ? implode(',', $a['ad_keys']) : 'none',
                    $b['ad_keys'] ? implode(',', $b['ad_keys']) : 'none');
    $row('organic 1', $a['organic'][0] ?? '-', $b['organic'][0] ?? '-');
    $row('organic 2', $a['organic'][1] ?? '-', $b['organic'][1] ?? '-');
    $row('organic 3', $a['organic'][2] ?? '-', $b['organic'][2] ?? '-');

    echo "\n  local results with the location parameter:\n    " . ($a['local'] ?? 'none returned') . "\n";
    echo "\n  local results with our own uule:\n    " . ($b['local'] ?? 'none returned') . "\n";

    echo "\n$line\n VERDICT\n$line\n";
    if ($b['warning'] === null && $a['warning'] !== null) {
        echo "  The uule mode targets correctly and the location parameter does not.\n";
        echo "  Set 'location_mode' => 'uule' in config.php.\n";
    } elseif ($a['warning'] === null && $b['warning'] === null) {
        echo "  Both modes look correctly targeted. Leave the config as it is.\n";
    } else {
        echo "  Neither mode targeted correctly. This is a ScrapingDog side issue,\n";
        echo "  worth raising with their support with this output attached.\n";
        if ($a['warning']) { echo "\n  location mode: " . $a['warning'] . "\n"; }
        if ($b['warning']) { echo "\n  uule mode    : " . $b['warning'] . "\n"; }
    }
    if ($a['ads'] === 0 && $b['ads'] === 0) {
        echo "\n  Neither call returned ads. Re-run with a keyword that always carries\n";
        echo "  ads, for example \"auto insurance quotes\", to tell a dead keyword\n";
        echo "  apart from an account that is not receiving ads at all.\n";
    }
    exit(0);
}

$key = (string)cfg('scrapingdog_key');
if ($key === '') {
    exit("No scrapingdog_key in the config.\n");
}

$geo = $useUule ? ['uule' => sd_uule($location)] : ['location' => $location];

$sentQuery = $inQuery ? sd_localized_query($keyword, $location) : $keyword;

$params = [
    'api_key'        => $key,
    'query'          => $sentQuery,
    'country'        => 'us',
    'domain'         => 'google.com',
    'language'       => 'en',
    'results'        => '20',
    'page'           => '0',
    'advance_search' => $basic ? 'false' : 'true',
] + $geo;
if ($mobile) {
    $params['mob_search'] = 'true';
}

$masked = $params;
$masked['api_key'] = '***MASKED***';

$line = str_repeat('=', 72);
echo "$line\n LIVE API CALL" . ($basic ? ' (advance_search=false, control)' : '') . "\n$line\n";
echo "  keyword  : $keyword\n";
echo "  location : $location\n";
echo "  device   : " . ($mobile ? 'mobile' : 'desktop') . "\n";
echo "  geo mode : " . ($useUule ? 'uule, correctly encoded by this app' : 'location parameter') . "\n";
if ($inQuery) {
    echo "  query fmt: city folded into the query -> \"$sentQuery\"\n";
}
echo "  cost     : " . ($basic ? 5 : 10) . " credits\n";
echo "  url      : " . sd_build_url(sd_endpoint(), $masked) . "\n\n";

$start = microtime(true);
$ch = curl_init(sd_build_url(sd_endpoint(), $params));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT      => 'serp-ads-tracker/1.0',
]);
$body   = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err    = curl_error($ch);
curl_close($ch);

printf("  http     : %d  in %.1fs\n", $status, microtime(true) - $start);
if ($body === false) {
    exit("  curl error: $err\n");
}
printf("  bytes    : %s\n\n", number_format(strlen((string)$body)));

$json = json_decode((string)$body, true);
if (!is_array($json)) {
    echo "  Response is not JSON. First 800 characters:\n\n";
    echo substr((string)$body, 0, 800) . "\n";
    exit(1);
}

if ($save) {
    file_put_contents(__DIR__ . '/api_response.json',
        json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "  saved    : api_response.json\n\n";
}

echo "$line\n TOP LEVEL KEYS\n$line\n";
foreach ($json as $k => $v) {
    if (is_array($v)) {
        $isList = array_keys($v) === range(0, count($v) - 1);
        $type = $isList ? 'array[' . count($v) . ']' : 'object{' . count($v) . '}';
    } elseif (is_string($v)) {
        $type = 'string "' . substr($v, 0, 60) . (strlen($v) > 60 ? '...' : '') . '"';
    } elseif (is_bool($v)) {
        $type = $v ? 'true' : 'false';
    } else {
        $type = gettype($v);
    }
    $flag = stripos((string)$k, 'ad') !== false ? '   <-- ad related' : '';
    printf("  %-30s %s%s\n", $k, $type, $flag);
}

echo "\n$line\n LOCATION CHECK\n$line\n";
$loc = sd_verify_location($json, $location);
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

echo "\n$line\n WHAT THE PARSER WOULD DO\n$line\n";
[$ads, $meta] = sd_parse_ads($json);
printf("  block source : %s\n", $meta['block_source']);
printf("  ad keys seen : %s\n", $meta['keys_seen'] ? implode(', ', $meta['keys_seen']) : 'none in the response');
printf("  parsed       : %d ads\n", count($ads));
foreach ($ads as $a) {
    printf("    [%s %d] %-28s %s\n", $a['block'], $a['position'], $a['domain'],
        substr((string)$a['headline'], 0, 40));
}

echo "\n$line\n EVERY ARRAY OF OBJECTS IN THE RESPONSE\n$line\n";
foreach ($json as $k => $v) {
    if (!is_array($v) || !$v) {
        continue;
    }
    $first = reset($v);
    if (!is_array($first)) {
        continue;
    }
    printf("\n  %s  (%d items)\n    fields: %s\n", $k, count($v), implode(', ', array_keys($first)));
    $shown = 0;
    foreach ($v as $item) {
        if (!is_array($item) || $shown >= 3) {
            break;
        }
        $t = $item['title'] ?? $item['headline'] ?? '';
        $l = $item['displayed_link'] ?? $item['link'] ?? $item['url'] ?? '';
        printf("      - %s\n        %s\n", substr((string)$t, 0, 62), substr((string)$l, 0, 72));
        $shown++;
    }
}

echo "\n$line\n READING THIS\n$line\n";
echo "  An ad related key holding items  -> the parser needs that key name adding\n";
echo "                                      to sd_parse_ads() in inc/scrapingdog.php\n";
echo "  ads present but empty            -> Google served no ads for this query,\n";
echo "                                      or advance_search is not taking effect\n";
echo "  no ad keys at all                -> check the account has advanced search\n";
echo "  an error or message key          -> read it, usually credits or a bad key\n";
