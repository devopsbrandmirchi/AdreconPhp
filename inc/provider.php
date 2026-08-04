<?php
declare(strict_types=1);

/**
 * Provider abstraction.
 *
 * Everything above this layer, the scheduler, the consistency reporting, the
 * presence grid, the dashboard, is vendor agnostic. Only these functions know
 * which API is being called. Adding a third provider means one more file and
 * one more case here.
 */

require_once __DIR__ . '/scrapingdog.php';
require_once __DIR__ . '/serpapi.php';
require_once __DIR__ . '/dataforseo.php';

function provider_name(): string {
    $p = strtolower((string)cfg('provider', 'serpapi'));
    return in_array($p, ['serpapi', 'scrapingdog', 'dataforseo'], true) ? $p : 'serpapi';
}

/**
 * Does this provider answer immediately, or queue the work and call back?
 * DataForSEO's Standard queue is the cheap option precisely because it does
 * not answer on the spot.
 */
function provider_is_async(): bool {
    return provider_name() === 'dataforseo';
}

function provider_label(): string {
    return match (provider_name()) {
        'serpapi'     => 'SerpApi',
        'dataforseo'  => 'DataForSEO',
        default       => 'ScrapingDog',
    };
}

/** What one check costs, in that provider's own billing unit. */
function provider_cost(): int {
    $custom = (int)cfg('cost_per_check', 0);
    if ($custom > 0) {
        return $custom;
    }
    // SerpApi bills one search per request, DataForSEO one task, ScrapingDog
    // 10 credits for an advanced search.
    return provider_name() === 'scrapingdog' ? 10 : 1;
}

/** The word to show in the interface for that billing unit. */
function provider_unit(): string {
    return match (provider_name()) {
        'serpapi'    => 'searches',
        'dataforseo' => 'tasks',
        default      => 'credits',
    };
}

function provider_key_configured(): bool {
    return match (provider_name()) {
        'serpapi'    => (string)cfg('serpapi_key', '') !== '',
        'dataforseo' => (string)cfg('dataforseo_login', '') !== ''
                        && (string)cfg('dataforseo_password', '') !== '',
        default      => (string)cfg('scrapingdog_key', '') !== '',
    };
}

/** Queue one check. Only meaningful when provider_is_async() is true. */
function provider_queue_check(array $tracker, int $runId): array {
    return dfs_post_task($tracker, $runId);
}

/** Collect anything the provider is holding for us. Returns log lines. */
function provider_collect_ready(): array {
    if (!provider_is_async()) {
        return [];
    }
    $lines = [];
    foreach (dfs_tasks_ready() as $taskId) {
        $waiting = db()->prepare("SELECT COUNT(*) FROM runs WHERE task_id = ? AND status = 'queued'");
        $waiting->execute([$taskId]);
        if ((int)$waiting->fetchColumn() === 0) {
            continue;   // not one of ours, or already collected
        }
        $res = dfs_task_get($taskId);
        if (!$res['ok']) {
            $lines[] = 'could not collect ' . $taskId . ': ' . $res['error'];
            continue;
        }
        [$ok, $msg] = dfs_store_result($res['json'], $taskId);
        $lines[] = ($ok ? 'collected ' : 'problem with ') . $taskId . ': ' . $msg;
    }
    return $lines;
}

function provider_fetch(array $tracker): array {
    return provider_name() === 'serpapi' ? sp_fetch($tracker) : sd_fetch($tracker);
}

function provider_parse_ads(array $json): array {
    return match (provider_name()) {
        'serpapi'    => sp_parse_ads($json),
        'dataforseo' => dfs_parse_ads($json),
        default      => sd_parse_ads($json),
    };
}

function provider_verify_location(array $json, string $requested): array {
    return match (provider_name()) {
        'serpapi'    => sp_verify_location($json, $requested),
        'dataforseo' => dfs_verify_location($json, $requested),
        default      => sd_verify_location($json, $requested),
    };
}

function provider_build_params(array $tracker, bool $maskKey = false): array {
    return provider_name() === 'serpapi'
        ? sp_build_params($tracker, $maskKey)
        : sd_build_params($tracker, $maskKey);
}

function provider_endpoint(): string {
    return provider_name() === 'serpapi' ? sp_endpoint() : sd_endpoint();
}

function provider_build_url(string $base, array $params): string {
    return provider_name() === 'serpapi'
        ? sp_build_url($base, $params)
        : sd_build_url($base, $params);
}

function provider_redact(?string $body, string $key): ?string {
    return provider_name() === 'serpapi' ? sp_redact($body, $key) : sd_redact($body, $key);
}

function provider_api_key(): string {
    return match (provider_name()) {
        'serpapi'    => (string)cfg('serpapi_key', ''),
        'dataforseo' => (string)cfg('dataforseo_login', ''),
        default      => (string)cfg('scrapingdog_key', ''),
    };
}

/** Stable hash of a run's ad set, used to detect "nothing changed". */
function provider_fingerprint(array $ads): string {
    return sd_fingerprint($ads);
}
