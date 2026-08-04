<?php
declare(strict_types=1);

/**
 * SerpApi client, using the dedicated Google Ads engine.
 *
 *   https://serpapi.com/search?engine=google_ads
 *
 * Documented parameters: q and location are required, plus hl, device, safe,
 * nfpr, no_cache, async, output, api_key. Only the ones that matter here are
 * sent, for the same reason as with the previous provider: every extra
 * parameter is another way for the upstream to serve a different page.
 *
 * Billing is per search, not per credit.
 */

const SP_ENDPOINT = 'https://serpapi.com/search';

function sp_endpoint(): string {
    $custom = (string)cfg('serpapi_endpoint', '');
    return $custom !== '' ? $custom : SP_ENDPOINT;
}

/** SerpApi does not echo the key back, but redact defensively anyway. */
function sp_redact(?string $body, string $key): ?string {
    if ($body === null || $key === '') {
        return $body;
    }
    return str_replace([$key, rawurlencode($key), urlencode($key)], 'REDACTED_API_KEY', $body);
}

function sp_build_params(array $tracker, bool $maskKey = false): array {
    $params = [
        'engine'   => 'google_ads',
        'q'        => $tracker['keyword'],
        'location' => $tracker['location'],
        'hl'       => 'en',
        'device'   => ($tracker['device'] ?? 'desktop') === 'mobile' ? 'mobile' : 'desktop',
        'api_key'  => $maskKey ? '***MASKED***' : (string)cfg('serpapi_key'),
    ];

    // A cached result is free but up to an hour stale. For a monitor that is
    // the wrong trade, so fresh data is the default.
    if (cfg('serpapi_no_cache', true)) {
        $params['no_cache'] = 'true';
    }

    return $params;
}

function sp_build_url(string $base, array $params): string {
    $sep = str_contains($base, '?') ? '&' : '?';
    return $base . $sep . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function sp_fetch(array $tracker): array {
    $params = sp_build_params($tracker);
    $key    = (string)$params['api_key'];
    $url    = sp_build_url(sp_endpoint(), $params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
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

    $body = sp_redact((string)$body, $key);
    $json = json_decode($body, true);

    // SerpApi reports failures inside the JSON as well as by status code.
    if (is_array($json) && !empty($json['error'])) {
        return ['ok' => false, 'http' => $status, 'error' => (string)$json['error'], 'raw' => $body];
    }
    if ($status !== 200) {
        return ['ok' => false, 'http' => $status, 'error' => 'API returned HTTP ' . $status, 'raw' => $body];
    }
    if (!is_array($json)) {
        return ['ok' => false, 'http' => $status, 'error' => 'Response was not valid JSON', 'raw' => $body];
    }

    $searchStatus = $json['search_metadata']['status'] ?? 'Success';
    if (strcasecmp((string)$searchStatus, 'Error') === 0) {
        return ['ok' => false, 'http' => $status, 'error' => 'Search failed at SerpApi', 'raw' => $body];
    }

    return ['ok' => true, 'http' => $status, 'error' => null, 'raw' => $body, 'json' => $json];
}

/**
 * Normalize the ads array.
 *
 * block_position is documented as one of top, middle, bottom, right. Only top
 * and bottom are what this tool reports on, so middle and right are recorded
 * under their own names rather than being folded into top, which would inflate
 * the top block with placements that are not in it.
 *
 * local_ads is an OBJECT here, with the ads nested inside it, not a bare array.
 */
function sp_parse_ads(array $json): array {
    $ads  = [];
    $meta = ['block_source' => 'none', 'keys_seen' => [], 'local_count' => 0];

    $counters = ['top' => 0, 'bottom' => 0, 'middle' => 0, 'right' => 0];

    if (!empty($json['ads']) && is_array($json['ads'])) {
        $meta['keys_seen'][]   = 'ads';
        $meta['block_source']  = 'field:block_position';

        foreach ($json['ads'] as $ad) {
            if (!is_array($ad)) {
                continue;
            }
            $raw   = strtolower((string)($ad['block_position'] ?? 'top'));
            $block = 'top';
            foreach (['bottom', 'middle', 'right', 'top'] as $candidate) {
                if (str_contains($raw, $candidate)) {
                    $block = $candidate;
                    break;
                }
            }
            $counters[$block]++;
            $ads[] = sp_map_ad($ad, $block, $counters[$block]);
        }
    }

    // Local services ads, kept separate from the text ad blocks.
    $localBlock = $json['local_ads'] ?? null;
    $localList  = [];
    if (is_array($localBlock)) {
        if (!empty($localBlock['ads']) && is_array($localBlock['ads'])) {
            $localList = $localBlock['ads'];
        } elseif (array_keys($localBlock) === range(0, count($localBlock) - 1)) {
            $localList = $localBlock;
        }
    }
    if ($localList) {
        $meta['keys_seen'][] = 'local_ads';
        $i = 0;
        foreach ($localList as $ad) {
            if (is_array($ad)) {
                $ads[] = sp_map_ad($ad, 'local', ++$i);
            }
        }
        $meta['local_count'] = $i;
    }

    return [$ads, $meta];
}

function sp_map_ad(array $ad, string $block, int $position): array {
    $landing = sd_first($ad, ['link', 'url', 'tracking_link']);
    $display = sd_first($ad, ['displayed_link', 'source']);

    // displayed_link is the advertiser's own breadcrumb, which is the most
    // reliable identity. link is frequently a google.com/aclk click tracker,
    // so it is unwrapped and only used as a fallback. source is sometimes a
    // brand name rather than a host, so it is only used when it looks like one.
    $sourceHost = (!empty($ad['source']) && is_string($ad['source'])
                   && str_contains($ad['source'], '.')) ? $ad['source'] : null;

    $domain = sd_pick_domain([
        $ad['displayed_link'] ?? null,
        sd_unwrap_click($ad['link'] ?? null),
        sd_unwrap_click($ad['tracking_link'] ?? null),
        $sourceHost,
    ]);

    $sitelinks = [];
    foreach ((array)($ad['sitelinks'] ?? []) as $sl) {
        if (is_array($sl) && !empty($sl['title'])) {
            $sitelinks[] = ['title' => (string)$sl['title'], 'link' => (string)($sl['link'] ?? '')];
        }
    }

    // Vehicle ads carry their inventory inline, which is genuinely useful for
    // a dealer. Keep a compact summary rather than the whole listing.
    if (!empty($ad['vehicles_for_sale']) && is_array($ad['vehicles_for_sale'])) {
        foreach (array_slice($ad['vehicles_for_sale'], 0, 5) as $v) {
            if (is_array($v) && !empty($v['title'])) {
                $sitelinks[] = [
                    'title' => trim((string)$v['title'] . ' ' . (string)($v['price'] ?? '')),
                    'link'  => (string)($v['link'] ?? ''),
                ];
            }
        }
        if (!$display && !empty($ad['vehicles_for_sale'][0]['dealership'])) {
            $display = (string)$ad['vehicles_for_sale'][0]['dealership'];
        }
    }

    return [
        'block'       => $block,
        'position'    => $position,
        'domain'      => $domain,
        'display_url' => $display,
        'landing_url' => $landing,
        'headline'    => sd_first($ad, ['title', 'ad_title', 'headline']),
        'description' => sd_first($ad, ['description', 'snippet', 'service_area', 'type']),
        'sitelinks'   => $sitelinks,
    ];
}

/**
 * SerpApi reports exactly which location it used, so verification is a direct
 * comparison rather than guesswork from result addresses.
 */
function sp_verify_location(array $json, string $requested): array {
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

    $used = $json['search_parameters']['location_used']
        ?? $json['search_parameters']['location_requested']
        ?? null;

    if (is_string($used) && $used !== '') {
        $out['served']     = $used;
        $out['verifiable'] = true;

        // "Austin,Texas,United States" and "Austin, Texas, United States" are
        // the same place written two ways.
        $norm = fn($s) => strtolower(preg_replace('/\s*,\s*/', ',', trim($s)));
        $out['uule_match'] = $norm($used) === $norm($requested);

        if (!$out['uule_match']) {
            $out['warning'] = 'SerpApi used the location "' . $used . '" for a request asking for "'
                . $requested . '". Check the spelling against their locations API.';
        }

        $parts  = array_map('trim', explode(',', $used));
        $region = count($parts) >= 2 ? $parts[count($parts) - 2] : null;
        $abbr   = $region ? sd_state_abbr($region) : null;
        if ($abbr) {
            $out['states_found'] = [$abbr];
        }
        $out['local_hint'] = $used;
    }

    return $out;
}

/**
 * SerpApi's Locations API. Free, no search credit, no key required.
 *
 * This is the authoritative list of what their location parameter accepts,
 * covering cities, counties, DMA regions, postal codes and more. Looking a
 * place up here rather than guessing removes a whole class of silent
 * mis-targeting, where a location string is accepted but not understood.
 */
function sp_locations_search(string $q, int $limit = 10): array {
    $q = trim($q);
    if (mb_strlen($q) < 2) {
        return [];
    }

    $base = (string)cfg('serpapi_locations_endpoint', '') ?: 'https://serpapi.com/locations.json';
    $url = $base . '?' . http_build_query([
        'q'     => $q,
        'limit' => max(1, min(10, $limit)),
    ], '', '&', PHP_QUERY_RFC3986);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'serp-ads-tracker/1.0',
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    if ($body === false) {
        return [];
    }
    $json = json_decode((string)$body, true);
    if (!is_array($json)) {
        return [];
    }

    $out = [];
    foreach ($json as $loc) {
        if (!is_array($loc) || empty($loc['canonical_name'])) {
            continue;
        }
        // Their canonical form has no spaces after commas. Store the spaced
        // form for readability; the API accepts either.
        $canon = implode(', ', array_map('trim', explode(',', (string)$loc['canonical_name'])));
        $parts = explode(', ', $canon);

        $out[] = [
            'canonical' => $canon,
            'name'      => (string)($loc['name'] ?? $parts[0]),
            'region'    => count($parts) >= 3 ? $parts[count($parts) - 2] : null,
            'type'      => (string)($loc['target_type'] ?? 'Location'),
            'reach'     => (int)($loc['reach'] ?? 0),
        ];
    }
    return $out;
}
