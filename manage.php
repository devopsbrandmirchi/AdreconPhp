<?php
declare(strict_types=1);

// Manage schedules in bulk from the command line.
//
//   php manage.php list
//   php manage.php list --interval=1
//   php manage.php check --interval=1 --yes     run them right now
//   php manage.php start --interval=1
//   php manage.php start --interval=1 --yes
//   php manage.php stop --keyword="polaris for sale" --yes
//   php manage.php start --location=Lakeland --device=desktop --yes
//   php manage.php stop --all --yes
//
// Filters combine, and all of them are optional:
//   --interval=N     only schedules on that interval, one of 1 3 6 12 24
//   --keyword=TEXT   substring match on the keyword
//   --location=TEXT  substring match on the location
//   --device=X       desktop or mobile
//   --status=X       active, paused or error
//   --all            no filter, every schedule
//
// Nothing is changed without --yes. Without it you get a preview and the
// search cost of the change, which matters when starting twenty at once.
//
// See worker.php for why this checks the request context and not PHP_SAPI.
if (!empty($_SERVER['REQUEST_METHOD']) || !empty($_SERVER['REMOTE_ADDR'])
    || !empty($_SERVER['HTTP_HOST']) || !empty($_SERVER['REQUEST_URI'])) {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/scheduler.php';

$args   = array_slice($argv, 1);
$action = '';
$opts   = [];

foreach ($args as $a) {
    if (str_starts_with($a, '--')) {
        $bits = explode('=', substr($a, 2), 2);
        $opts[$bits[0]] = $bits[1] ?? true;
    } elseif ($action === '') {
        $action = $a;
    }
}

if (!in_array($action, ['list', 'start', 'stop', 'check'], true)) {
    echo "Usage: php manage.php list|start|stop|check [filters] [--yes]\n\n";
    echo "Filters: --interval=N --keyword=TEXT --location=TEXT --device=X --status=X --all\n";
    echo "Examples:\n";
    echo "  php manage.php list --interval=1\n";
    echo "  php manage.php start --interval=1 --yes\n";
    echo "  php manage.php stop --keyword=\"polaris for sale\" --yes\n";
    echo "  php manage.php check --interval=1 --yes\n";
    exit(1);
}

$where  = [];
$params = [];

if (isset($opts['interval'])) {
    $iv = (int)$opts['interval'];
    if (!in_array($iv, ALLOWED_INTERVALS, true)) {
        exit("Interval must be one of: " . implode(', ', ALLOWED_INTERVALS) . "\n");
    }
    $where[]  = 'interval_hours = ?';
    $params[] = $iv;
}
foreach (['keyword' => 'keyword', 'location' => 'location'] as $opt => $col) {
    if (isset($opts[$opt]) && is_string($opts[$opt])) {
        $where[]  = "$col LIKE ?";
        $params[] = '%' . $opts[$opt] . '%';
    }
}
if (isset($opts['device']) && is_string($opts['device'])) {
    $where[]  = 'device = ?';
    $params[] = $opts['device'] === 'mobile' ? 'mobile' : 'desktop';
}
if (isset($opts['status']) && is_string($opts['status'])) {
    $where[]  = 'status = ?';
    $params[] = $opts['status'];
}

if (!$where && empty($opts['all'])) {
    exit("Refusing to match every schedule by accident. Add a filter, or --all.\n");
}

$sql = 'SELECT * FROM trackers';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY keyword, location, device';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (!$rows) {
    exit("No schedules matched.\n");
}

$line = str_repeat('=', 78);
printf("%s\n MATCHED %d KEYWORD%s\n%s\n", $line, count($rows), count($rows) === 1 ? '' : 'S', $line);
printf("  %-28s %-28s %-5s %-4s %-9s %s\n", 'KEYWORD', 'LOCATION', 'EVERY', 'DEV', 'WINDOW', 'STATUS');
foreach ($rows as $r) {
    $win = $r['status'] === 'active' && !empty($r['runs_until'])
        ? max(0, (int)floor((strtotime($r['runs_until']) - time()) / 86400)) . 'd left'
        : (int)$r['duration_days'] . 'd';
    printf(
        "  %-28s %-28s %-5s %-4s %-9s %s\n",
        mb_substr($r['keyword'], 0, 28),
        mb_substr($r['location'], 0, 28),
        (int)$r['interval_hours'] === 0 ? 'once' : $r['interval_hours'] . 'h',
        $r['device'] === 'mobile' ? 'mob' : 'desk',
        $win,
        $r['status']
    );
}

if ($action === 'list') {
    exit(0);
}

if ($action === 'check') {
    $now = count($rows) * provider_cost();
    printf("\n%s\n IMPACT\n%s\n", $line, $line);
    printf("  action        : check %d keyword%s right now\n", count($rows), count($rows) === 1 ? '' : 's');
    printf("  one-off cost  : %s %s\n", number_format($now), provider_unit());
    printf("  spent so far  : %s %s this month\n", number_format(credits_used_this_month()), provider_unit());

    $ceil = (int)cfg('monthly_credit_ceiling', 0);
    if ($ceil > 0 && credits_used_this_month() + $now > $ceil) {
        echo "\n  WARNING: this would cross your monthly ceiling. The worker refuses\n";
        echo "  to spend past it, so some of these checks would be skipped.\n";
    }

    if (empty($opts['yes'])) {
        echo "\n  Nothing checked. Add --yes to run them.\n";
        exit(0);
    }

    printf("\n%s\n RUNNING\n%s\n", $line, $line);
    @set_time_limit(0);
    $ok = $fail = 0;
    foreach ($rows as $i => $t) {
        printf("  [%d/%d] %s @ %s ... ", $i + 1, count($rows),
            mb_substr($t['keyword'], 0, 24), mb_substr($t['location'], 0, 24));
        [$good, $msg] = run_tracker($t, 'manual');
        echo ($good ? 'OK ' : 'FAIL ') . $msg . "\n";
        $good ? $ok++ : $fail++;
        usleep(300000);
    }
    printf("\n  %d succeeded, %d failed. Spent about %s %s.\n",
        $ok, $fail, number_format(($ok + $fail) * provider_cost()), provider_unit());
    exit($fail > 0 ? 1 : 0);
}

// What this change costs, in the provider's own unit.
$perMonth = 0;
foreach ($rows as $r) {
    $perMonth += (int)floor(720 / max(1, (int)$r['interval_hours'])) * provider_cost();
}

$currentProjection = credits_projected_monthly();
$after = $action === 'start'
    ? $currentProjection + $perMonth
    : max(0, $currentProjection - $perMonth);

printf("\n%s\n IMPACT\n%s\n", $line, $line);
printf("  action              : %s %d keyword%s\n", $action, count($rows), count($rows) === 1 ? '' : 's');
printf("  these cost          : %s %s per month\n", number_format($perMonth), provider_unit());
printf("  projection now      : %s %s per month\n", number_format($currentProjection), provider_unit());
printf("  projection after    : %s %s per month\n", number_format($after), provider_unit());

$ceiling = (int)cfg('monthly_credit_ceiling', 0);
if ($ceiling > 0) {
    printf("  your ceiling        : %s\n", number_format($ceiling));
    if ($action === 'start' && $after > $ceiling) {
        printf("\n  WARNING: that would project %s over your ceiling. The worker stops\n",
            number_format($after - $ceiling));
        echo "  schedules rather than cross it, so some would pause part way through\n";
        echo "  the month. Raise monthly_credit_ceiling or start fewer.\n";
    }
}

if (empty($opts['yes'])) {
    echo "\n  Nothing changed. Add --yes to apply.\n";
    exit(0);
}

$ids = array_column($rows, 'id');
$in  = implode(',', array_fill(0, count($ids), '?'));

if ($action === 'start') {
    // Due immediately, same as pressing Start in the dashboard. The worker
    // takes them batch_size at a time, so a large batch simply spreads over
    // the next few cron passes.
    $upd = db()->prepare(
        "UPDATE trackers
         SET status = 'active', fail_streak = 0, next_run_at = UTC_TIMESTAMP(),
             runs_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL duration_days DAY)
         WHERE id IN ($in)"
    );
} else {
    $upd = db()->prepare("UPDATE trackers SET status = 'paused' WHERE id IN ($in)");
}
$upd->execute($ids);

printf("\n  %s %d keyword%s.\n", $action === 'start' ? 'Started' : 'Stopped',
    $upd->rowCount(), $upd->rowCount() === 1 ? '' : 's');

if ($action === 'start') {
    $batch = (int)cfg('batch_size', 10);
    $passes = (int)ceil(count($ids) / max(1, $batch));
    printf("  The worker handles %d per pass, so expect %d cron pass%s to clear them.\n",
        $batch, $passes, $passes === 1 ? '' : 'es');
}
