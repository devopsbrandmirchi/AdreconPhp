<?php
declare(strict_types=1);

// Run the same keyword and location through two providers and compare what
// each one saw. This is the honest way to judge whether a cheaper provider is
// costing you data.
//
//   php compare_providers.php "polaris dealer" "Bartow, Florida, United States"
//   php compare_providers.php "..." "..." --a=dataforseo --b=serpapi
//
// COSTS ONE CHECK WITH EACH PROVIDER. Both must be configured in config.php.
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
$flags      = [];
$positional = [];
foreach ($args as $a) {
    if (str_starts_with($a, '--')) {
        $bits = explode('=', substr($a, 2), 2);
        $flags[$bits[0]] = $bits[1] ?? true;
    } else {
        $positional[] = $a;
    }
}

$keyword  = $positional[0] ?? '';
$location = $positional[1] ?? '';
$a        = (string)($flags['a'] ?? 'dataforseo');
$b        = (string)($flags['b'] ?? 'serpapi');

if ($keyword === '' || $location === '') {
    echo "Usage: php compare_providers.php \"keyword\" \"City, State, United States\" [--a=x --b=y]\n";
    echo "Providers: dataforseo, serpapi, scrapingdog\n";
    exit(1);
}

$line = str_repeat('=', 76);

/**
 * Force the provider for one call. cfg() reads a static config, so the
 * override is applied through a global the provider layer already consults.
 */
function with_provider(string $name, callable $fn) {
    global $CONFIG_OVERRIDE;
    $CONFIG_OVERRIDE = ['provider' => $name];

    // Prove the switch actually took effect before spending anything. If
    // inc/bootstrap.php is an older copy without the override, cfg() ignores
    // it and every run would silently hit the configured provider instead,
    // producing a comparison of one provider against itself.
    if (provider_name() !== $name) {
        $CONFIG_OVERRIDE = [];
        echo "\n  CANNOT SWITCH PROVIDER.\n\n";
        echo "  Asked for '$name' but the app still reports '" . provider_name() . "'.\n";
        echo "  inc/bootstrap.php on this server is an older copy. Upload the\n";
        echo "  current one and run this again.\n\n";
        echo "  Without that, this tool would compare a provider against itself\n";
        echo "  and tell you they agree, which would be worthless.\n";
        exit(1);
    }

    try {
        return $fn();
    } finally {
        $CONFIG_OVERRIDE = [];
    }
}

function run_one(string $provider, string $keyword, string $location): array {
    return with_provider($provider, function () use ($provider, $keyword, $location) {
        $tracker = ['keyword' => $keyword, 'location' => $location,
                    'country' => 'us', 'device' => 'desktop'];

        if (!provider_key_configured()) {
            return ['error' => 'not configured in config.php'];
        }

        if (provider_is_async()) {
            $posted = dfs_post_task($tracker, 0);
            if (!$posted['ok']) {
                return ['error' => $posted['error']];
            }
            for ($i = 0; $i < 30; $i++) {
                sleep(3);
                $got = dfs_task_get($posted['task_id']);
                if ($got['ok'] && !empty($got['json']['tasks'][0]['result'])) {
                    [$ads, $meta] = provider_parse_ads($got['json']);
                    $r = $got['json']['tasks'][0]['result'][0];
                    return ['ads' => $ads, 'meta' => $meta,
                            'types' => $r['item_types'] ?? [],
                            'results' => $r['se_results_count'] ?? null,
                            'check_url' => $r['check_url'] ?? null];
                }
            }
            return ['error' => 'still queued after 90 seconds, try again later'];
        }

        $res = provider_fetch($tracker);
        if (!$res['ok']) {
            return ['error' => $res['error']];
        }
        [$ads, $meta] = provider_parse_ads($res['json']);
        return ['ads' => $ads, 'meta' => $meta, 'types' => [], 'results' => null,
                'check_url' => $res['json']['search_metadata']['google_url'] ?? null];
    });
}

printf("%s\n SAME SEARCH, TWO PROVIDERS\n%s\n", $line, $line);
printf("  keyword  : %s\n  location : %s\n  comparing: %s against %s\n\n", $keyword, $location, $a, $b);
echo "  This costs one check with each. Running...\n";

$ra = run_one($a, $keyword, $location);
$rb = run_one($b, $keyword, $location);

foreach ([[$a, $ra], [$b, $rb]] as [$name, $r]) {
    printf("\n%s\n %s\n%s\n", $line, strtoupper($name), $line);
    if (isset($r['error'])) {
        echo "  FAILED: " . $r['error'] . "\n";
        continue;
    }
    $top  = array_filter($r['ads'], fn($x) => $x['block'] === 'top');
    $bot  = array_filter($r['ads'], fn($x) => $x['block'] === 'bottom');
    printf("  ads found : %d top, %d bottom\n", count($top), count($bot));
    if ($r['types']) {
        printf("  page held : %s\n", implode(', ', $r['types']));
    }
    if ($r['results'] !== null) {
        printf("  results   : %s%s\n", number_format((int)$r['results']),
            (int)$r['results'] < 10000 ? '   <- suspiciously low, a stripped page' : '');
    }
    foreach ($r['ads'] as $ad) {
        printf("    %-7s %-32s %s\n", $ad['block'], $ad['domain'], mb_substr((string)$ad['headline'], 0, 32));
    }
    if (!$r['ads']) {
        echo "    nothing\n";
    }
    if (!empty($r['check_url'])) {
        printf("  see it    : %s\n", $r['check_url']);
    }
}

printf("\n%s\n VERDICT\n%s\n", $line, $line);

if (!empty($ra['check_url']) && !empty($rb['check_url'])
    && $ra['check_url'] === $rb['check_url']) {
    echo "  Both sides returned the identical search URL, which two different\n";
    echo "  providers cannot do. The same provider answered twice, so there is\n";
    echo "  nothing here to compare. Upload the current inc/bootstrap.php.\n";
    exit(1);
}

$na = isset($ra['ads']) ? count($ra['ads']) : -1;
$nb = isset($rb['ads']) ? count($rb['ads']) : -1;

if ($na < 0 || $nb < 0) {
    echo "  One side failed, so there is nothing to compare yet.\n";
} elseif ($na === 0 && $nb === 0) {
    echo "  Neither found ads. Most likely nobody is bidding on this search in\n";
    echo "  this location right now. Open either link above to confirm.\n";
} elseif ($na === 0 && $nb > 0) {
    printf("  %s found %d ads that %s missed entirely.\n", $b, $nb, $a);
    echo "  That is a data quality difference, not a bug in this app. The cheaper\n";
    echo "  provider is not seeing the ads you are paying to track.\n";
} elseif ($nb === 0 && $na > 0) {
    printf("  %s found %d ads that %s missed entirely.\n", $a, $na, $b);
} else {
    $onlyA = array_diff(array_column($ra['ads'], 'domain'), array_column($rb['ads'], 'domain'));
    $onlyB = array_diff(array_column($rb['ads'], 'domain'), array_column($ra['ads'], 'domain'));
    printf("  Both found ads: %d and %d.\n", $na, $nb);
    if ($onlyA) { printf("  Only %s saw: %s\n", $a, implode(', ', $onlyA)); }
    if ($onlyB) { printf("  Only %s saw: %s\n", $b, implode(', ', $onlyB)); }
    if (!$onlyA && !$onlyB) { echo "  Same advertisers on both. The cheaper provider is doing the job.\n"; }
}
