<?php
declare(strict_types=1);

/**
 * ScrapingDog Google Search API client.
 *
 * advance_search=true is mandatory. The ads array is empty without it,
 * and it costs 10 credits per request instead of 5.
 */

const SD_CREDIT_COST = 10;

/** Overridable so the client can be pointed at a mock or a proxy during testing. */
function sd_endpoint(): string {
    $custom = (string)cfg('api_endpoint', '');
    // No trailing slash. The documented example uses one, but the call that is
    // known to return ads does not, and a redirect can drop query parameters.
    return $custom !== '' ? $custom : 'https://api.scrapingdog.com/google';
}

/** Join a base URL and a query string without caring about trailing slashes. */
function sd_build_url(string $base, array $params): string {
    $sep = str_contains($base, '?') ? '&' : '?';
    // RFC3986 encodes spaces as %20 rather than +. The API is known to accept
    // %20, and a location value is the last place to gamble on plus decoding.
    return $base . $sep . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/**
 * ScrapingDog echoes the API key back inside the response, in the
 * scrapingdog_pagination URLs. Storing that would put the key in the database
 * and on screen in the raw viewer. Strip it before the payload goes anywhere.
 */
function sd_redact(?string $body, string $key): ?string {
    if ($body === null || $key === '') {
        return $body;
    }
    return str_replace(
        [$key, rawurlencode($key), urlencode($key)],
        'REDACTED_API_KEY',
        $body
    );
}

/**
 * Build the exact parameter set a check will send. Shared by the live client
 * and the diagnostic tools, so what the tools report is always what is sent.
 */
function sd_build_params(array $tracker, bool $maskKey = false): array {
    // location and uule cannot be combined. 'location' is the documented way,
    // but it has been observed producing a malformed uule and therefore results
    // for the wrong market. Set location_mode to 'uule' in the config to send a
    // correctly encoded uule instead.
    $geo = cfg('location_mode', 'location') === 'uule'
        ? ['uule' => sd_uule($tracker['location'])]
        : ['location' => $tracker['location']];

    // Optionally put the city into the query itself, the way a person types it.
    $query = cfg('append_location_to_query', false)
        ? sd_localized_query($tracker['keyword'], $tracker['location'])
        : $tracker['keyword'];

    // Deliberately minimal. This is the parameter set proven to return ads.
    // Every extra parameter is a chance for the upstream API or Google to serve
    // a different, sometimes ad free, page. In particular 'results' becomes
    // Google's num parameter, which Google has been deprecating and which can
    // return a stripped SERP with no ads at all.
    $params = [
        'api_key'        => $maskKey ? '***MASKED***' : (string)cfg('scrapingdog_key'),
        'query'          => $query,
        'country'        => $tracker['country'] ?: 'us',
        'domain'         => 'google.com',
        'advance_search' => 'true',
    ] + $geo;

    if (($tracker['device'] ?? 'desktop') === 'mobile') {
        $params['mob_search'] = 'true';
    }
    if (cfg('send_language', false)) {
        $params['language'] = 'en';
    }
    $results = (int)cfg('results_per_page', 0);
    if ($results > 0) {
        $params['results'] = (string)$results;
    }

    return $params;
}

function sd_fetch(array $tracker): array {
    $params = sd_build_params($tracker);

    $url = sd_build_url(sd_endpoint(), $params);

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
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'http' => 0, 'error' => 'Request failed: ' . $err, 'raw' => null];
    }

    $body = sd_redact((string)$body, (string)$params['api_key']);

    if ($status !== 200) {
        return ['ok' => false, 'http' => $status, 'error' => 'API returned HTTP ' . $status, 'raw' => $body];
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'http' => $status, 'error' => 'Response was not valid JSON', 'raw' => $body];
    }

    return ['ok' => true, 'http' => $status, 'error' => null, 'raw' => $body, 'json' => $json];
}

/**
 * Google's uule encodes a canonical location name. The correct format is a
 * fixed prefix followed by base64 of a single length byte plus the name. A
 * uule missing that length byte is malformed, and Google silently ignores it
 * and geolocates by the requesting IP instead, which is how a Florida query
 * comes back with Pennsylvania results.
 */

/**
 * Turn "Arlington, Texas, United States" plus a keyword into the phrasing a
 * person actually types: "rv for sale in arlington, tx".
 *
 * This is a workaround for geo targeting that does not work. It is NOT the
 * same search as a user in Arlington typing "rv for sale": it is a different
 * query, so it runs a different ad auction and can surface different
 * advertisers. Use it when the location parameter is unreliable, and know
 * that is the trade being made.
 */
function sd_localized_query(string $keyword, string $location): string {
    $parts = array_map('trim', explode(',', $location));
    if (count($parts) < 2) {
        return $keyword;
    }
    $city   = $parts[0];
    $region = $parts[count($parts) - 2];
    $abbr   = sd_state_abbr($region) ?: $region;

    return $keyword . ' in ' . strtolower($city) . ', ' . strtolower($abbr);
}

function sd_uule(string $location): string {
    return 'w+CAIQICI' . base64_encode(chr(strlen($location)) . $location);
}

/** Decode a uule back to its location name, for verification. */
function sd_uule_decode(string $uule): ?string {
    $prefix = 'w+CAIQICI';
    if (strpos($uule, $prefix) !== 0) {
        return null;
    }
    $raw = base64_decode(substr($uule, strlen($prefix)), false);
    if ($raw === false || $raw === '') {
        return null;
    }
    // Tolerate both the correct form and the one missing the length byte.
    $withoutLen = substr($raw, 1);
    if (ord($raw[0]) === strlen($withoutLen)) {
        return $withoutLen;
    }
    return $raw;
}

function sd_state_abbr(string $state): ?string {
    static $map = [
        'alabama'=>'AL','alaska'=>'AK','arizona'=>'AZ','arkansas'=>'AR','california'=>'CA',
        'colorado'=>'CO','connecticut'=>'CT','delaware'=>'DE','florida'=>'FL','georgia'=>'GA',
        'hawaii'=>'HI','idaho'=>'ID','illinois'=>'IL','indiana'=>'IN','iowa'=>'IA',
        'kansas'=>'KS','kentucky'=>'KY','louisiana'=>'LA','maine'=>'ME','maryland'=>'MD',
        'massachusetts'=>'MA','michigan'=>'MI','minnesota'=>'MN','mississippi'=>'MS','missouri'=>'MO',
        'montana'=>'MT','nebraska'=>'NE','nevada'=>'NV','new hampshire'=>'NH','new jersey'=>'NJ',
        'new mexico'=>'NM','new york'=>'NY','north carolina'=>'NC','north dakota'=>'ND','ohio'=>'OH',
        'oklahoma'=>'OK','oregon'=>'OR','pennsylvania'=>'PA','rhode island'=>'RI','south carolina'=>'SC',
        'south dakota'=>'SD','tennessee'=>'TN','texas'=>'TX','utah'=>'UT','vermont'=>'VT',
        'virginia'=>'VA','washington'=>'WA','west virginia'=>'WV','wisconsin'=>'WI','wyoming'=>'WY',
        'district of columbia'=>'DC',
    ];
    return $map[strtolower(trim($state))] ?? null;
}

/**
 * Did Google actually serve the location we asked for? Checks the uule in the
 * search URL the API reports back, and sanity checks the local result
 * addresses, which are the ground truth.
 */
function sd_valid_state_abbrs(): array {
    static $set = null;
    if ($set === null) {
        $set = [];
        foreach (['alabama','alaska','arizona','arkansas','california','colorado','connecticut',
                  'delaware','florida','georgia','hawaii','idaho','illinois','indiana','iowa',
                  'kansas','kentucky','louisiana','maine','maryland','massachusetts','michigan',
                  'minnesota','mississippi','missouri','montana','nebraska','nevada',
                  'new hampshire','new jersey','new mexico','new york','north carolina',
                  'north dakota','ohio','oklahoma','oregon','pennsylvania','rhode island',
                  'south carolina','south dakota','tennessee','texas','utah','vermont','virginia',
                  'washington','west virginia','wisconsin','wyoming','district of columbia'] as $st) {
            $abbr = sd_state_abbr($st);
            if ($abbr) {
                $set[$abbr] = true;
            }
        }
    }
    return $set;
}

/**
 * Did Google actually serve the location we asked for?
 *
 * Two independent signals are used, because either can be absent:
 *   1. The uule in the search URL, and whether it is even well formed.
 *   2. The state in any local result. Their local_results.address field is not
 *      consistent: sometimes it holds "Liberty, NY - (845) 292-3500", other
 *      times "4.6(185) - RV dealer". When no state can be found anywhere, the
 *      result is "unverifiable", NOT "wrong". Claiming a wrong market on no
 *      evidence would put a false alarm in the dashboard.
 */
function sd_verify_location(array $json, string $requested): array {
    $out = [
        'requested'       => $requested,
        'served'          => null,
        'uule_match'      => null,
        'uule_wellformed' => null,
        'local_hint'      => null,
        'states_found'    => [],
        'verifiable'      => false,
        'warning'         => null,
    ];

    $url = $json['search_information']['url'] ?? null;
    if (is_string($url) && preg_match('/[?&]uule=([^&]+)/', $url, $m)) {
        $uule    = urldecode($m[1]);
        $decoded = sd_uule_decode($uule);
        if ($decoded !== null) {
            $out['served']     = $decoded;
            $out['uule_match'] = strcasecmp(trim($decoded), trim($requested)) === 0;
        }
        $payload = base64_decode(substr($uule, strlen('w+CAIQICI')), false);
        if ($payload !== false && $payload !== '') {
            $out['uule_wellformed'] = ord($payload[0]) === strlen(substr($payload, 1));
        }
    }

    $parts  = array_map('trim', explode(',', $requested));
    $region = count($parts) >= 2 ? $parts[count($parts) - 2] : null;
    $abbr   = $region ? sd_state_abbr($region) : null;

    // Their address field is unreliable, so scan every field that might carry
    // a place name.
    $haystack = [];
    foreach (($json['local_results'] ?? []) as $lr) {
        foreach (['address', 'hours', 'title'] as $f) {
            if (!empty($lr[$f]) && is_string($lr[$f])) {
                $haystack[] = $lr[$f];
            }
        }
    }
    if ($haystack) {
        $out['local_hint'] = implode(' | ', array_slice($haystack, 0, 3));
    }

    // Only count genuine state abbreviations. "RV" is not Rhode Island.
    $valid  = sd_valid_state_abbrs();
    $found  = [];
    $joined = implode(' | ', $haystack);
    if (preg_match_all('/\b([A-Z]{2})\b/', $joined, $m2)) {
        foreach ($m2[1] as $tok) {
            if (isset($valid[$tok])) {
                $found[$tok] = true;
            }
        }
    }
    if ($region && stripos($joined, $region) !== false && $abbr) {
        $found[$abbr] = true;
    }
    $out['states_found'] = array_keys($found);
    $out['verifiable']   = (bool)$found;

    if ($found && $abbr && !isset($found[$abbr])) {
        $out['warning'] = 'Local results are in ' . implode(', ', array_keys($found))
            . ', not ' . $region . '. Location targeting did not take effect, so this data is '
            . 'for the wrong market.';
    }

    // The malformed uule is independent evidence and stands on its own.
    if ($out['uule_wellformed'] === false && !$out['warning']) {
        $out['warning'] = 'The uule sent to Google is malformed, it is missing its length prefix '
            . 'byte. Google discards a malformed uule and geolocates by the requesting IP, so '
            . 'these results are probably not for the location you asked for.';
    }
    if ($out['uule_match'] === false && !$out['warning']) {
        $out['warning'] = 'The served location does not match the requested location.';
    }

    return $out;
}

/**
 * Normalize whatever shape the ads come back in into a flat list of:
 *   [block => top|bottom, position, domain, display_url, landing_url, headline, description]
 *
 * The API response shape for ads is the one thing worth verifying against a
 * live call. Use raw.php to inspect an actual payload, then tighten this if
 * the field names differ. Until then the parser checks several candidates and
 * records how it decided in $meta['block_source'].
 */
function sd_parse_ads(array $json): array {
    $ads  = [];
    $meta = ['block_source' => 'none', 'keys_seen' => [], 'local_count' => 0];

    // Find every top level key that plausibly holds ads, rather than relying on
    // a fixed list. The API has renamed response keys before, and a rename that
    // silently produces zero ads is worse than an error.
    $topLists = [];
    $botLists = [];
    $locLists = [];

    foreach ($json as $key => $value) {
        if (!is_array($value) || !$value) {
            continue;
        }
        $first = reset($value);
        if (!is_array($first)) {
            continue;
        }
        $k = strtolower((string)$key);

        // Word boundary match on "ad" or "ads", so "ad_results", "top_ads" and
        // "paid_ads" match while "dmca_message" and "pagination" do not.
        if (!preg_match('/(^|_)(ads?|advertisements?|sponsored|promoted|paid)($|_)/', $k)
            && !in_array($k, ['ads', 'adresults', 'paidresults', 'sponsoredresults'], true)) {
            continue;
        }

        $meta['keys_seen'][] = $key;

        // Local Services Ads are a documented, separate placement. They are
        // not top or bottom of page text ads, and folding them in would
        // silently inflate the top block with a different ad product.
        if (str_contains($k, 'local')) {
            $locLists[$key] = $value;
        } elseif (str_contains($k, 'bottom')) {
            $botLists[$key] = $value;
        } else {
            $topLists[$key] = $value;
        }
    }

    // Local ads are recorded, but kept out of the top and bottom counts.
    $localAds = [];
    $i = 0;
    foreach ($locLists as $list) {
        foreach ($list as $ad) {
            $localAds[] = sd_map_ad($ad, 'local', ++$i);
        }
    }
    $meta['local_count'] = count($localAds);

    if (!$topLists && !$botLists) {
        return [$localAds, $meta];
    }

    // Case 1: the response splits top and bottom into separate keys.
    if ($botLists) {
        $meta['block_source'] = 'separate_key';
        $i = 0;
        foreach ($topLists as $list) {
            foreach ($list as $ad) { $ads[] = sd_map_ad($ad, 'top', ++$i); }
        }
        $j = 0;
        foreach ($botLists as $list) {
            foreach ($list as $ad) { $ads[] = sd_map_ad($ad, 'bottom', ++$j); }
        }
        return [array_merge($ads, $localAds), $meta];
    }

    $flat = [];
    foreach ($topLists as $list) {
        foreach ($list as $ad) { $flat[] = $ad; }
    }

    // Case 2: each ad carries its own placement marker.
    $blockKeys = ['block_position', 'block', 'ad_position', 'placement', 'position_type', 'location'];
    $found = null;
    foreach ($blockKeys as $bk) {
        foreach ($flat as $ad) {
            if (isset($ad[$bk]) && is_string($ad[$bk])) {
                $val = strtolower($ad[$bk]);
                if (str_contains($val, 'top') || str_contains($val, 'bottom')) {
                    $found = $bk;
                    break 2;
                }
            }
        }
    }

    if ($found !== null) {
        $meta['block_source'] = 'field:' . $found;
        $counters = ['top' => 0, 'bottom' => 0];
        foreach ($flat as $ad) {
            $val   = strtolower((string)($ad[$found] ?? 'top'));
            $block = str_contains($val, 'bottom') ? 'bottom' : 'top';
            $counters[$block]++;
            $ads[] = sd_map_ad($ad, $block, $counters[$block]);
        }
        return [array_merge($ads, $localAds), $meta];
    }

    // Case 3: no marker at all. Record them as top and flag it, so the UI can
    // warn that bottom block data is not trustworthy yet.
    $meta['block_source'] = 'assumed_top';
    foreach ($flat as $n => $ad) {
        $ads[] = sd_map_ad($ad, 'top', $n + 1);
    }
    return [array_merge($ads, $localAds), $meta];
}

function sd_map_ad(array $ad, string $block, int $position): array {
    $landing = sd_first($ad, ['link', 'url', 'destination_link', 'tracking_link', 'href']);
    $display = sd_first($ad, ['displayed_link', 'display_url', 'displayed_url', 'visible_link']);

    // Sitelink extensions are useful competitive intel: they show what an
    // advertiser is pushing, and changes to them signal campaign edits.
    $sitelinks = [];
    foreach ((array)($ad['sitelinks'] ?? $ad['extended_sitelinks'] ?? []) as $sl) {
        if (is_array($sl) && !empty($sl['title'])) {
            $sitelinks[] = ['title' => (string)$sl['title'], 'link' => (string)($sl['link'] ?? '')];
        }
    }

    return [
        'block'       => $block,
        'position'    => $position,
        'domain'      => sd_pick_domain([$display, sd_unwrap_click($landing)]),
        'display_url' => $display,
        'landing_url' => $landing,
        'headline'    => sd_first($ad, ['title', 'headline', 'heading']),
        'description' => sd_first($ad, ['description', 'snippet', 'text', 'body']),
        'sitelinks'   => $sitelinks,
    ];
}

function sd_first(array $arr, array $keys): ?string {
    foreach ($keys as $k) {
        if (!empty($arr[$k]) && is_string($arr[$k])) {
            return $arr[$k];
        }
    }
    return null;
}

/** Is this host Google itself rather than an advertiser? */
function sd_is_google(string $host): bool {
    return (bool)preg_match('/(^|\.)google(\.[a-z]{2,3}){1,2}$/i', $host);
}

/**
 * Ad links are often Google click trackers, google.com/aclk?...&adurl=THE_REAL_SITE.
 * Recording those would make every advertiser look like google.com, so unwrap
 * them to the real destination. Returns null when there is nothing to unwrap to.
 */
function sd_unwrap_click(?string $url): ?string {
    if (!$url) {
        return null;
    }
    $host = (string)parse_url($url, PHP_URL_HOST);
    if ($host === '' || !sd_is_google($host)) {
        return $url;
    }
    parse_str((string)parse_url($url, PHP_URL_QUERY), $params);
    foreach (['adurl', 'url', 'dest', 'q'] as $k) {
        if (!empty($params[$k]) && is_string($params[$k])
            && preg_match('#^https?://#i', $params[$k])) {
            return $params[$k];
        }
    }
    return null;
}

/**
 * Pick the best advertiser domain from whatever fields an ad carries.
 * Anything that resolves to Google itself is rejected, because it means the
 * link was a click tracker rather than the advertiser.
 */
function sd_pick_domain(array $candidates): string {
    foreach ($candidates as $c) {
        if (!$c || !is_string($c)) {
            continue;
        }
        $d = sd_domain($c);
        if ($d !== 'unknown' && !sd_is_google($d)) {
            return $d;
        }
    }
    return 'unknown';
}

/** Reduce a URL to a lowercase registrable-ish domain. */
function sd_domain(?string $url): string {
    if (!$url) {
        return 'unknown';
    }
    $url = trim($url);

    // Displayed links are breadcrumbs, not URLs: "https://www.brp.com > maverick > r".
    // Cut at the first separator so the domain is the domain.
    foreach ([' \u{203A} ', "\u{203A}", ' > ', ' / '] as $sep) {
        $pos = mb_strpos($url, $sep);
        if ($pos !== false && $pos > 0) {
            $url = mb_substr($url, 0, $pos);
            break;
        }
    }
    $url = trim($url);

    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) {
        return 'unknown';
    }
    $host = strtolower($host);
    $host = preg_replace('/^www\./', '', $host);

    // "Gables Motorsports" is a brand, not a host. Anything without a dot, or
    // with whitespace in it, is not a domain.
    if (!str_contains($host, '.') || preg_match('/\s/', $host)) {
        return 'unknown';
    }

    // Collapse obvious subdomains but keep two-part public suffixes intact.
    $parts = explode('.', $host);
    $n     = count($parts);
    if ($n > 2) {
        $twoPart = ['co.uk', 'com.au', 'co.nz', 'com.br', 'co.za', 'co.in', 'com.mx'];
        $tail    = $parts[$n - 2] . '.' . $parts[$n - 1];
        $host    = in_array($tail, $twoPart, true)
            ? $parts[$n - 3] . '.' . $tail
            : $tail;
    }
    return $host;
}

/** Stable hash of a run's ad set, used to detect "nothing changed". */
function sd_fingerprint(array $ads): string {
    $keys = array_map(fn($a) => $a['block'] . ':' . $a['position'] . ':' . $a['domain'], $ads);
    sort($keys);
    return substr(hash('sha256', implode('|', $keys)), 0, 32);
}
