<?php
declare(strict_types=1);

/**
 * DataForSEO, Standard queue.
 *
 * Unlike the other two providers this one is asynchronous, which changes the
 * shape of a check:
 *
 *   1. The worker POSTs a task and gets a UUID back. The run is stored as
 *      'queued' and the schedule is moved on immediately so it is not posted
 *      twice while it waits.
 *   2. DataForSEO completes the task and POSTs the result to postback.php.
 *   3. If that push ever fails, the task lands on their 'tasks ready' list and
 *      the worker collects it on a later pass. Both paths end in the same
 *      dfs_store_result().
 *
 * Ads arrive as 'paid' items inside the organic endpoint. There is no field
 * saying top or bottom, so position is derived from where a paid item sits
 * relative to the organic block. See dfs_parse_ads().
 */

const DFS_BASE = 'https://api.dataforseo.com/v3';

function dfs_endpoint(): string {
    $custom = (string)cfg('dataforseo_endpoint', '');
    return $custom !== '' ? rtrim($custom, '/') : DFS_BASE;
}

function dfs_credentials(): string {
    return (string)cfg('dataforseo_login', '') . ':' . (string)cfg('dataforseo_password', '');
}

/** Their location_name has no space after the comma: "Lakeland,Florida,United States". */
function dfs_location_name(string $location): string {
    return implode(',', array_map('trim', explode(',', $location)));
}

function dfs_redact(?string $body): ?string {
    if ($body === null) {
        return null;
    }
    $login = (string)cfg('dataforseo_login', '');
    $pass  = (string)cfg('dataforseo_password', '');
    foreach ([$pass, $login] as $secret) {
        if ($secret !== '') {
            $body = str_replace($secret, 'REDACTED', $body);
        }
    }
    return $body;
}

/** One HTTP call, with basic auth and JSON in and out. */
function dfs_call(string $method, string $path, ?array $payload = null): array {
    $url = dfs_endpoint() . $path;
    $ch  = curl_init($url);

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_USERPWD        => dfs_credentials(),
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_USERAGENT      => 'serp-ads-tracker/1.0',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);

    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'http' => 0, 'error' => 'Request failed: ' . $err, 'raw' => null];
    }
    $body = dfs_redact((string)$body);
    $json = json_decode($body, true);

    if (!is_array($json)) {
        return ['ok' => false, 'http' => $status, 'error' => 'Response was not valid JSON', 'raw' => $body];
    }
    // 20000 is their "Ok." for the envelope. Individual tasks carry their own.
    if ((int)($json['status_code'] ?? 0) !== 20000) {
        return ['ok' => false, 'http' => $status, 'raw' => $body,
                'error' => 'DataForSEO ' . ($json['status_code'] ?? '?') . ': '
                           . ($json['status_message'] ?? 'unknown error')];
    }
    return ['ok' => true, 'http' => $status, 'error' => null, 'raw' => $body, 'json' => $json];
}

/**
 * Queue one check. Returns the task id on success.
 * The run id travels in `tag` so a postback can be matched back to it.
 */
function dfs_post_task(array $tracker, int $runId): array {
    $task = [
        'keyword'       => $tracker['keyword'],
        'location_name' => dfs_location_name((string)$tracker['location']),
        'language_code' => (string)cfg('dataforseo_language', 'en'),
        'device'        => ($tracker['device'] ?? 'desktop') === 'mobile' ? 'mobile' : 'desktop',
        'priority'      => 1,   // normal: the Standard queue, and the cheapest
        'depth'         => (int)cfg('dataforseo_depth', 10),
        'tag'           => 'run-' . $runId,
    ];

    // Push delivery. Their servers POST the finished result straight to us.
    $token = (string)cfg('postback_token', '');
    $base  = rtrim((string)cfg('site_url', ''), '/');
    if ($token !== '' && $base !== '') {
        $task['postback_url']  = $base . '/postback.php?token=' . rawurlencode($token);
        $task['postback_data'] = 'advanced';
    }

    $res = dfs_call('POST', '/serp/google/organic/task_post', [$task]);
    if (!$res['ok']) {
        return $res;
    }

    $t = $res['json']['tasks'][0] ?? null;
    if (!is_array($t)) {
        return ['ok' => false, 'http' => $res['http'], 'error' => 'No task came back', 'raw' => $res['raw']];
    }
    // 20100 is "Task Created."
    $code = (int)($t['status_code'] ?? 0);
    if ($code !== 20100 && $code !== 20000) {
        return ['ok' => false, 'http' => $res['http'], 'raw' => $res['raw'],
                'error' => 'Task rejected (' . $code . '): ' . ($t['status_message'] ?? 'unknown')];
    }
    if (empty($t['id'])) {
        return ['ok' => false, 'http' => $res['http'], 'error' => 'Task had no id', 'raw' => $res['raw']];
    }

    return ['ok' => true, 'http' => $res['http'], 'error' => null, 'raw' => $res['raw'],
            'task_id' => (string)$t['id'], 'cost' => (float)($t['cost'] ?? 0)];
}

/** Ids of finished tasks they are holding for us. */
function dfs_tasks_ready(): array {
    $res = dfs_call('GET', '/serp/google/organic/tasks_ready');
    if (!$res['ok']) {
        return [];
    }
    $ids = [];
    foreach ($res['json']['tasks'] ?? [] as $t) {
        foreach ($t['result'] ?? [] as $r) {
            if (!empty($r['id'])) {
                $ids[] = (string)$r['id'];
            }
        }
    }
    return $ids;
}

function dfs_task_get(string $taskId): array {
    return dfs_call('GET', '/serp/google/organic/task_get/advanced/' . rawurlencode($taskId));
}

/**
 * Turn the items array into ads.
 *
 * DataForSEO does not label a paid item as top or bottom. What it gives is
 * rank_absolute, the position of every element on the page in order. Ads above
 * the first organic result are the top block; anything paid below it is the
 * bottom block. That is exactly how the page reads, and it is stable even when
 * Google inserts other features between them.
 */
function dfs_parse_ads(array $json): array {
    $meta = ['block_source' => 'none', 'keys_seen' => [], 'local_count' => 0];
    $ads  = [];

    $items = $json['tasks'][0]['result'][0]['items'] ?? null;
    if (!is_array($items)) {
        return [$ads, $meta];
    }

    // Where does the organic block start?
    $firstOrganic = null;
    foreach ($items as $item) {
        if (($item['type'] ?? '') === 'organic') {
            $rank = (int)($item['rank_absolute'] ?? 0);
            if ($firstOrganic === null || $rank < $firstOrganic) {
                $firstOrganic = $rank;
            }
        }
    }

    $counters = ['top' => 0, 'bottom' => 0, 'local' => 0];

    foreach ($items as $item) {
        $type = (string)($item['type'] ?? '');

        if ($type === 'paid') {
            $meta['keys_seen'][]  = 'paid';
            $meta['block_source'] = 'rank:relative_to_organic';

            $rank  = (int)($item['rank_absolute'] ?? 0);
            $block = ($firstOrganic === null || $rank < $firstOrganic) ? 'top' : 'bottom';
            $counters[$block]++;
            $ads[] = dfs_map_ad($item, $block, $counters[$block]);
            continue;
        }

        // Local services ads are a different Google product and are counted
        // separately, exactly as with the other providers.
        if ($type === 'local_services' || $type === 'paid_local_services') {
            $meta['keys_seen'][] = $type;
            foreach ($item['items'] ?? [$item] as $sub) {
                if (is_array($sub)) {
                    $counters['local']++;
                    $ads[] = dfs_map_ad($sub, 'local', $counters['local']);
                }
            }
            $meta['local_count'] = $counters['local'];
        }
    }

    $meta['keys_seen'] = array_values(array_unique($meta['keys_seen']));
    return [$ads, $meta];
}

function dfs_map_ad(array $item, string $block, int $position): array {
    $landing = sd_first($item, ['url', 'link']);
    $display = sd_first($item, ['breadcrumb', 'domain', 'display_url']);

    $sitelinks = [];
    foreach ((array)($item['links'] ?? []) as $sl) {
        if (is_array($sl) && !empty($sl['title'])) {
            $sitelinks[] = ['title' => (string)$sl['title'], 'link' => (string)($sl['url'] ?? '')];
        }
    }

    return [
        'block'       => $block,
        'position'    => $position,
        'domain'      => sd_pick_domain([
            $item['domain'] ?? null,
            sd_unwrap_click($landing),
            $display,
        ]),
        'display_url' => $display,
        'landing_url' => $landing,
        'headline'    => sd_first($item, ['title', 'ad_title']),
        'description' => sd_first($item, ['description', 'snippet', 'extended_snippet']),
        'sitelinks'   => $sitelinks,
    ];
}

/** DataForSEO echoes back the location it actually used. */
function dfs_verify_location(array $json, string $requested): array {
    $out = [
        'requested' => $requested, 'served' => null, 'uule_match' => null,
        'uule_wellformed' => null, 'local_hint' => null, 'states_found' => [],
        'verifiable' => false, 'warning' => null,
    ];

    $result = $json['tasks'][0]['result'][0] ?? [];
    $used   = $result['location_code'] ?? null;
    $check  = $result['check_url'] ?? null;

    // Their result carries a location_code, and the check_url shows the exact
    // search that was run. Between them the market can be confirmed.
    if ($check) {
        $out['served']     = (string)$check;
        $out['verifiable'] = true;
        $out['local_hint'] = (string)$check;
    }
    if ($used !== null) {
        $out['served']     = 'location code ' . $used;
        $out['verifiable'] = true;
    }

    $parts  = array_map('trim', explode(',', $requested));
    $region = count($parts) >= 2 ? $parts[count($parts) - 2] : null;
    $abbr   = $region ? sd_state_abbr($region) : null;
    if ($abbr) {
        $out['states_found'] = [$abbr];
    }
    return $out;
}

/**
 * Store a finished task. Shared by the postback endpoint and the fallback
 * poller so both paths behave identically.
 *
 * Returns [stored, message].
 */
function dfs_store_result(array $json, ?string $taskId = null): array {
    $task = $json['tasks'][0] ?? null;
    if (!is_array($task)) {
        return [false, 'No task in the payload'];
    }
    $taskId = $taskId ?: (string)($task['id'] ?? '');

    // Match on the tag first. We set it to the run id before posting, so it is
    // known even when a result is pushed back faster than the task id can be
    // written down. The task id is the fallback, and is filled in on arrival.
    $run = null;
    $tag = (string)($task['data']['tag'] ?? '');
    if (preg_match('/^run-(\d+)$/', $tag, $m)) {
        $st = db()->prepare("SELECT * FROM runs WHERE id = ? AND status = 'queued' LIMIT 1");
        $st->execute([(int)$m[1]]);
        $run = $st->fetch() ?: null;
    }
    if (!$run && $taskId !== '') {
        $st = db()->prepare("SELECT * FROM runs WHERE task_id = ? AND status = 'queued' LIMIT 1");
        $st->execute([$taskId]);
        $run = $st->fetch() ?: null;
    }
    if (!$run) {
        // Already collected, or not ours. Not worth shouting about.
        return [false, 'No queued check is waiting for ' . ($tag ?: $taskId ?: 'that task')];
    }
    if ($taskId !== '' && (string)$run['task_id'] !== $taskId) {
        db()->prepare('UPDATE runs SET task_id = ? WHERE id = ?')->execute([$taskId, $run['id']]);
    }

    $trk = db()->prepare('SELECT * FROM trackers WHERE id = ?');
    $trk->execute([$run['tracker_id']]);
    $tracker = $trk->fetch();
    if (!$tracker) {
        return [false, 'The keyword behind that check no longer exists'];
    }

    $code = (int)($task['status_code'] ?? 0);
    if ($code !== 20000) {
        db()->prepare(
            "UPDATE runs SET status = 'failed', finished_at = UTC_TIMESTAMP(), error_message = ?
             WHERE id = ?"
        )->execute([mb_substr('DataForSEO ' . $code . ': ' . ($task['status_message'] ?? ''), 0, 500), $run['id']]);
        mark_tracker_failed($tracker);
        return [false, 'Task ' . $taskId . ' came back as an error'];
    }

    [$ads, $meta] = dfs_parse_ads($json);
    $msg = finish_run((int)$run['id'], $tracker, $ads, $meta, dfs_redact(json_encode($json)));
    // advance_schedule already ran when the task was posted, so nothing more
    // to do here except for a one-off, which is retired the moment it returns.
    if ((int)$tracker['interval_hours'] === ONCE) {
        db()->prepare("UPDATE trackers SET status = 'done', next_run_at = NULL WHERE id = ?")
            ->execute([$tracker['id']]);
    }
    return [true, $msg];
}
