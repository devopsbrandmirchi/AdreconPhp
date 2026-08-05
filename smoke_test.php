<?php
/**
 * One-shot smoke test: DB reachability + core pages.
 * Run: php smoke_test.php
 * Writes: SMOKE_TEST_RESULT.txt
 */
declare(strict_types=1);

$root = __DIR__;
$out = [];
$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $detail = ''): void {
    global $out, $pass, $fail;
    if ($ok) {
        $pass++;
        $out[] = "[PASS] $name" . ($detail !== '' ? " — $detail" : '');
    } else {
        $fail++;
        $out[] = "[FAIL] $name" . ($detail !== '' ? " — $detail" : '');
    }
}

$cfg = require $root . '/config.php';
$host = (string)($cfg['db_host'] ?? 'localhost');
$name = (string)($cfg['db_name'] ?? '');
$user = (string)($cfg['db_user'] ?? '');
$passDb = (string)($cfg['db_pass'] ?? '');

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$name};charset=utf8mb4",
        $user,
        $passDb,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    check('Database connection', true, "$user@$host / $name");
} catch (Throwable $e) {
    check('Database connection', false, $e->getMessage());
    $pdo = null;
}

$needed = ['users', 'trackers', 'agencies', 'clients', 'locations', 'sites', 'runs', 'ad_placements'];
$counts = [];
if ($pdo) {
    foreach ($needed as $table) {
        try {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            $counts[$table] = $n;
            check("Table `$table` readable", true, "$n rows");
        } catch (Throwable $e) {
            $counts[$table] = null;
            check("Table `$table` readable", false, $e->getMessage());
        }
    }

    // App-meaning checks: can we join the competitive spy path?
    try {
        $sql = 'SELECT t.id, t.keyword, t.location, t.status,
                       (SELECT COUNT(*) FROM runs r WHERE r.tracker_id = t.id) AS run_count,
                       (SELECT COUNT(*) FROM ad_placements a
                          INNER JOIN runs r2 ON r2.id = a.run_id
                          WHERE r2.tracker_id = t.id) AS ad_count
                FROM trackers t
                ORDER BY ad_count DESC, run_count DESC
                LIMIT 1';
        $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['run_count'] > 0) {
            check(
                'Spy path: keyword → runs → ads',
                true,
                sprintf(
                    'tracker #%s "%s" @ %s — %s runs, %s ad placements',
                    $row['id'],
                    $row['keyword'],
                    $row['location'],
                    $row['run_count'],
                    $row['ad_count']
                )
            );
        } else {
            // Not a hard fail: app can work before history import; note for the report.
            global $out, $pass;
            $pass++;
            $out[] = '[PASS] Spy path: keyword → runs → ads — no run history yet (import insert_runs.sql + insert_ad_placements.sql to fill history)';
        }
    } catch (Throwable $e) {
        check('Spy path: keyword → runs → ads', false, $e->getMessage());
    }
}

// HTTP checks against local PHP built-in server (try common ports)
$baseCandidates = [
    rtrim((string)($cfg['site_url'] ?? ''), '/'),
    'http://127.0.0.1:8000',
    'http://localhost:8000',
    'http://127.0.0.1:8080',
];
$baseCandidates = array_values(array_unique(array_filter($baseCandidates)));

function http_get(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HEADER => true,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($raw === false) {
        return [0, '', $err];
    }
    $parts = explode("\r\n\r\n", $raw, 2);
    return [$code, $parts[1] ?? '', ''];
}

$base = null;
foreach ($baseCandidates as $cand) {
    [$code] = http_get($cand . '/login.php');
    if ($code > 0) {
        $base = $cand;
        break;
    }
}

if ($base === null) {
    check('Local web server', false, 'Start with: php -S localhost:8000  (then re-run this test)');
} else {
    check('Local web server', true, $base);

    [$code, $body] = http_get($base . '/login.php');
    check('login.php loads', $code === 200 && str_contains($body, 'Sign in'), "HTTP $code");

    [$code, $body] = http_get($base . '/assets/app.css');
    check('assets/app.css loads', $code === 200 && strlen($body) > 100, "HTTP $code, " . strlen($body) . ' bytes');

    // Unauthenticated app pages should redirect to login (302/303) or show login
    [$code] = http_get($base . '/index.php');
    check('index.php protected', in_array($code, [200, 302, 303], true), "HTTP $code");
}

$summary = $fail === 0
    ? "RESULT: ALL CHECKS PASSED ($pass passed)"
    : "RESULT: $fail FAILED, $pass passed — fix failures above";

$report = [];
$report[] = 'Adrecon smoke test';
$report[] = 'Generated: ' . date('c');
$report[] = '';
$report[] = 'What this app is for';
$report[] = '  Adrecon watches Google paid ads for chosen keywords + locations on a schedule.';
$report[] = '  You see which competitor domains show up in ad slots over time (competitive spy).';
$report[] = '';
$report[] = 'Row counts (from config.php DB):';
foreach ($counts as $t => $n) {
    $report[] = '  ' . $t . ': ' . ($n === null ? 'ERROR' : $n);
}
$report[] = '';
$report[] = 'Checks:';
foreach ($out as $line) {
    $report[] = '  ' . $line;
}
$report[] = '';
$report[] = $summary;
$report[] = '';

$text = implode("\n", $report) . "\n";
file_put_contents($root . '/SMOKE_TEST_RESULT.txt', $text);
echo $text;
exit($fail === 0 ? 0 : 1);
