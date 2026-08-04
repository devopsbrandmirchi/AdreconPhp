<?php
declare(strict_types=1);

require_once __DIR__ . '/provider.php';

// 0 means a single check: it runs once, then marks itself done.
const ALLOWED_INTERVALS = [0, 1, 3, 6, 12, 24];
const ONCE = 0;

function interval_label(int $hours): string {
    return $hours === ONCE ? 'Once' : $hours . ' hours';
}

// A schedule runs for a bounded number of days and then stops itself. Without
// this, one careless bulk add runs forever and quietly spends every month.
const DEFAULT_DURATION_DAYS = 7;
const MAX_DURATION_DAYS     = 60;

function clamp_duration(int $days): int {
    return max(1, min(MAX_DURATION_DAYS, $days));
}

function duration_label(int $days): string {
    return match (true) {
        $days === 1  => '1 day',
        $days === 7  => '1 week',
        $days === 14 => '2 weeks',
        $days === 30 => '30 days',
        $days === 60 => '60 days, the maximum',
        default      => $days . ' days',
    };
}

/** Most schedules one bulk submission may create at once. */
const BULK_MAX = 250;

/** A run left in 'running' longer than this is treated as crashed. */
const RUN_STALE_SECONDS = 300;

/**
 * Next run time, anchored to interval boundaries from local midnight, so a
 * 3 hour tracker always lands on 00:00, 03:00, 06:00 and results stay
 * comparable day to day. Returned as a UTC 'Y-m-d H:i:s' string.
 */
function next_run_at(int $intervalHours, string $tz): string {
    // A one-off has no next slot. It becomes due immediately when started.
    if ($intervalHours === ONCE) {
        return gmdate('Y-m-d H:i:s');
    }
    try {
        $zone = new DateTimeZone($tz);
    } catch (Exception $e) {
        $zone = new DateTimeZone('UTC');
    }

    $now      = new DateTime('now', $zone);
    $midnight = (clone $now)->setTime(0, 0, 0);
    $minsIn   = ($now->getTimestamp() - $midnight->getTimestamp()) / 60;
    $slotMins = $intervalHours * 60;
    $nextSlot = (int)floor($minsIn / $slotMins) + 1;

    $next = (clone $midnight)->modify('+' . ($nextSlot * $slotMins) . ' minutes');
    $next->setTimezone(new DateTimeZone('UTC'));
    return $next->format('Y-m-d H:i:s');
}

/** Credits already spent this calendar month. */
function credits_used_this_month(): int {
    $stmt = db()->query(
        "SELECT COALESCE(SUM(credits_used), 0) AS c
         FROM runs
         WHERE started_at >= DATE_FORMAT(UTC_DATE(), '%Y-%m-01')"
    );
    return (int)$stmt->fetchColumn();
}

function credits_projected_monthly(): int {
    // Only count the days a schedule will actually still be running. A weekly
    // run should not be projected as if it lasted the whole month.
    $stmt = db()->query(
        "SELECT COALESCE(SUM(
            FLOOR((24 / interval_hours) *
                  LEAST(30, GREATEST(0, IFNULL(TIMESTAMPDIFF(DAY, UTC_TIMESTAMP(), runs_until), 30)))
            ) * " . provider_cost() . "
         ), 0)
         FROM trackers WHERE status = 'active' AND interval_hours > 0"
    );
    return (int)$stmt->fetchColumn();
}

/**
 * Execute one check for a tracker. Writes a run row and its ad placements.
 * Returns [bool ok, string message].
 */
function run_tracker(array $tracker, string $trigger = 'scheduled'): array {
    $pdo = db();

    $ceiling = (int)cfg('monthly_credit_ceiling', 20000);
    if ($ceiling > 0 && credits_used_this_month() + provider_cost() > $ceiling) {
        $pdo->prepare("UPDATE trackers SET status = 'paused' WHERE id = ?")
            ->execute([$tracker['id']]);
        return [false, 'Monthly credit ceiling reached. Tracker stopped.'];
    }

    $scheduledFor = $tracker['next_run_at'] ?: gmdate('Y-m-d H:i:s');

    $ins = $pdo->prepare(
        "INSERT INTO runs (tracker_id, scheduled_for, started_at, status, trigger_source)
         VALUES (?, ?, UTC_TIMESTAMP(), 'running', ?)"
    );
    $ins->execute([$tracker['id'], $scheduledFor, $trigger]);
    $runId = (int)$pdo->lastInsertId();

    $res = provider_fetch($tracker);

    if (!$res['ok']) {
        $pdo->prepare(
            "UPDATE runs SET status = 'failed', http_status = ?, error_message = ?,
                    credits_used = ?, raw_json = ?, finished_at = UTC_TIMESTAMP()
             WHERE id = ?"
        )->execute([
            $res['http'],
            substr((string)$res['error'], 0, 500),
            $res['http'] === 200 ? provider_cost() : 0,
            $res['raw'] ? substr((string)$res['raw'], 0, 200000) : null,
            $runId,
        ]);
        bump_failure($tracker);
        return [false, (string)$res['error']];
    }

    [$ads, $meta] = provider_parse_ads($res['json']);

    $top    = array_values(array_filter($ads, fn($a) => $a['block'] === 'top'));
    $bottom = array_values(array_filter($ads, fn($a) => $a['block'] === 'bottom'));
    $local  = array_values(array_filter($ads, fn($a) => $a['block'] === 'local'));
    $status = count($ads) > 0 ? 'success' : 'empty';

    $pdo->prepare(
        "UPDATE runs SET status = ?, http_status = 200, credits_used = ?,
                top_count = ?, bottom_count = ?, local_count = ?, fingerprint = ?, block_source = ?,
                raw_json = ?, finished_at = UTC_TIMESTAMP()
         WHERE id = ?"
    )->execute([
        $status, provider_cost(), count($top), count($bottom), count($local),
        provider_fingerprint($ads), $meta['block_source'],
        substr((string)$res['raw'], 0, 200000), $runId,
    ]);

    if ($ads) {
        $ph = $pdo->prepare(
            "INSERT INTO ad_placements
             (run_id, tracker_id, block, position, domain, display_url, landing_url, headline, description, sitelinks, captured_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        );
        foreach ($ads as $a) {
            $ph->execute([
                $runId, $tracker['id'], $a['block'], $a['position'], $a['domain'],
                $a['display_url'], $a['landing_url'],
                $a['headline'] ? mb_substr($a['headline'], 0, 255) : null,
                $a['description'] ? mb_substr($a['description'], 0, 500) : null,
                !empty($a['sitelinks']) ? json_encode($a['sitelinks']) : null,
            ]);
        }
    }

    if ((int)$tracker['interval_hours'] === ONCE) {
        $pdo->prepare(
            "UPDATE trackers
             SET last_run_at = UTC_TIMESTAMP(), next_run_at = NULL, fail_streak = 0, status = 'done'
             WHERE id = ?"
        )->execute([$tracker['id']]);
    } else {
        $pdo->prepare(
            "UPDATE trackers
             SET last_run_at = UTC_TIMESTAMP(), next_run_at = ?, fail_streak = 0
             WHERE id = ?"
        )->execute([next_run_at((int)$tracker['interval_hours'], $tracker['timezone']), $tracker['id']]);
    }

    $msg = sprintf('%d top, %d bottom', count($top), count($bottom));
    if ($local) {
        $msg .= sprintf(', %d local', count($local));
    }
    return [true, $msg];
}

/** Five consecutive failures stops the tracker so it cannot drain credits. */
function bump_failure(array $tracker): void {
    $pdo    = db();
    $streak = (int)$tracker['fail_streak'] + 1;

    if ($streak >= 5) {
        $pdo->prepare("UPDATE trackers SET fail_streak = ?, status = 'error' WHERE id = ?")
            ->execute([$streak, $tracker['id']]);
        return;
    }
    if ((int)$tracker['interval_hours'] === ONCE) {
        $pdo->prepare("UPDATE trackers SET fail_streak = ?, status = 'error', next_run_at = NULL WHERE id = ?")
            ->execute([$streak, $tracker['id']]);
        return;
    }
    $pdo->prepare("UPDATE trackers SET fail_streak = ?, next_run_at = ? WHERE id = ?")
        ->execute([$streak, next_run_at((int)$tracker['interval_hours'], $tracker['timezone']), $tracker['id']]);
}

/**
 * Stop schedules that have reached their end date. Called before any pass that
 * picks work, and on page load, so an expired schedule can never be selected
 * and can never spend again.
 */
function expire_finished_trackers(): int {
    $stmt = db()->prepare(
        "UPDATE trackers
         SET status = 'expired', next_run_at = NULL
         WHERE status = 'active' AND runs_until IS NOT NULL AND runs_until <= UTC_TIMESTAMP()"
    );
    $stmt->execute();
    return $stmt->rowCount();
}

/**
 * A worker that is killed mid-check leaves a run stuck on 'running' forever.
 * Anything older than RUN_STALE_SECONDS is closed out so the UI never shows a
 * check that is permanently in progress.
 */
function reap_stale_runs(): int {
    $stmt = db()->prepare(
        "UPDATE runs
         SET status = 'failed',
             error_message = 'Check did not finish. The worker was interrupted.',
             finished_at = UTC_TIMESTAMP()
         WHERE status = 'running'
           AND started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)"
    );
    $stmt->execute([RUN_STALE_SECONDS]);
    return $stmt->rowCount();
}

/**
 * Current state of one tracker, shaped for both the page and the JSON poller.
 */
function tracker_live_status(int $trackerId): array {
    $pdo = db();

    $t = $pdo->prepare('SELECT status, next_run_at, last_run_at, interval_hours, timezone FROM trackers WHERE id = ?');
    $t->execute([$trackerId]);
    $tracker = $t->fetch();
    if (!$tracker) {
        return ['exists' => false];
    }

    $r = $pdo->prepare(
        "SELECT id, started_at, TIMESTAMPDIFF(SECOND, started_at, UTC_TIMESTAMP()) AS age_seconds
         FROM runs WHERE tracker_id = ? AND status = 'running'
         ORDER BY id DESC LIMIT 1"
    );
    $r->execute([$trackerId]);
    $running = $r->fetch() ?: null;

    $l = $pdo->prepare(
        "SELECT id, status, top_count, bottom_count, started_at
         FROM runs WHERE tracker_id = ? AND status IN ('success','empty','failed')
         ORDER BY id DESC LIMIT 1"
    );
    $l->execute([$trackerId]);
    $last = $l->fetch() ?: null;

    $topN = $last ? (int)$last['top_count'] : 0;
    $botN = $last ? (int)$last['bottom_count'] : 0;

    if ($running) {
        $label = 'Checking now';
    } elseif (!$last) {
        $label = 'No data yet';
    } elseif ($last['status'] === 'failed') {
        $label = 'Last check failed';
    } elseif ($topN + $botN === 0) {
        $label = 'No ads';
    } else {
        $label = $topN . ' top, ' . $botN . ' bottom';
    }

    return [
        'exists'        => true,
        'id'            => $trackerId,
        'status'        => $tracker['status'],
        'running'       => (bool)$running,
        'run_id'        => $running ? (int)$running['id'] : ($last ? (int)$last['id'] : null),
        'running_for'   => $running ? (int)$running['age_seconds'] : 0,
        'last_status'   => $last['status'] ?? null,
        'top_count'     => $topN,
        'bottom_count'  => $botN,
        'has_ads'       => ($topN + $botN) > 0,
        'label'         => $label,
        'next_run_at'   => $tracker['next_run_at'],
        'last_run_at'   => $tracker['last_run_at'],
    ];
}

/** Compare the two most recent successful runs and label movement. */
function diff_last_runs(int $trackerId): array {
    $pdo  = db();
    $runs = $pdo->prepare(
        "SELECT id FROM runs
         WHERE tracker_id = ? AND status IN ('success','empty')
         ORDER BY started_at DESC LIMIT 2"
    );
    $runs->execute([$trackerId]);
    $ids = $runs->fetchAll(PDO::FETCH_COLUMN);

    if (count($ids) < 2) {
        return ['entered' => [], 'exited' => [], 'moved' => []];
    }

    $load = function (int $runId) use ($pdo): array {
        $s = $pdo->prepare("SELECT domain, block, position FROM ad_placements
                            WHERE run_id = ? AND block IN ('top','bottom')");
        $s->execute([$runId]);
        $out = [];
        foreach ($s->fetchAll() as $r) {
            $out[$r['domain']] = $r['block'] . ' ' . $r['position'];
        }
        return $out;
    };

    $now  = $load((int)$ids[0]);
    $prev = $load((int)$ids[1]);

    $entered = array_diff_key($now, $prev);
    $exited  = array_diff_key($prev, $now);
    $moved   = [];
    foreach ($now as $d => $pos) {
        if (isset($prev[$d]) && $prev[$d] !== $pos) {
            $moved[$d] = $prev[$d] . ' to ' . $pos;
        }
    }

    return [
        'entered' => array_keys($entered),
        'exited'  => array_keys($exited),
        'moved'   => $moved,
    ];
}

/**
 * Process every schedule that is due, up to $batch of them.
 *
 * Shared by worker.php (cron) and cron.php (an HTTP trigger, for hosts where
 * system cron does not work). Returns the log lines rather than printing, so
 * both callers can present them their own way.
 */
function run_due_trackers(int $batch, string $trigger = 'scheduled'): array {
    $lines = [];

    $stmt = db()->prepare(
        "SELECT * FROM trackers
         WHERE status = 'active' AND next_run_at IS NOT NULL AND next_run_at <= UTC_TIMESTAMP()
           AND (runs_until IS NULL OR runs_until > UTC_TIMESTAMP())
         ORDER BY next_run_at ASC
         LIMIT " . max(1, min(100, $batch))
    );
    $stmt->execute();
    $due = $stmt->fetchAll();

    if (!$due) {
        return [];
    }

    foreach ($due as $t) {
        // Claim the row first so two overlapping passes cannot double-spend.
        $claim = db()->prepare(
            "UPDATE trackers SET next_run_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)
             WHERE id = ? AND next_run_at <= UTC_TIMESTAMP()"
        );
        $claim->execute([$t['id']]);
        if ($claim->rowCount() === 0) {
            continue;
        }

        [$ok, $msg] = run_tracker($t, $trigger);
        $lines[] = sprintf(
            '[%s] #%d %s @ %s : %s %s',
            gmdate('Y-m-d H:i:s'), $t['id'], $t['keyword'], $t['location'],
            $ok ? 'OK' : 'FAIL', $msg
        );
    }

    return $lines;
}

/** Prune stored payloads. Safe to call from either trigger. */
function prune_raw_payloads(): void {
    $days = (int)cfg('raw_retention_days', 30);
    if ($days > 0) {
        db()->prepare(
            "UPDATE runs SET raw_json = NULL
             WHERE raw_json IS NOT NULL AND started_at < DATE_SUB(UTC_DATE(), INTERVAL ? DAY)"
        )->execute([$days]);
    }
}
