<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

// mbstring is usually present but is not guaranteed on shared hosting, and a
// missing extension would otherwise be a blank 500. Fall back to the byte
// functions, which are correct for the ASCII data this app handles.
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
} else {
    function mb_substr($s, $start, $length = null, $enc = null) {
        return $length === null ? substr($s, $start) : substr($s, $start, $length);
    }
    function mb_strlen($s, $enc = null) { return strlen($s); }
}

define('APP_ROOT', dirname(__DIR__));

// Prefer a config that sits one level above the app folder, outside the
// webroot. That keeps the API key unreachable over HTTP even if .htaccess is
// ignored because AllowOverride is off. Falls back to the in-folder copy.
$configPath = null;
foreach ([dirname(APP_ROOT) . '/serp-tracker-config.php', APP_ROOT . '/config.php'] as $candidate) {
    if (is_readable($candidate)) {
        $configPath = $candidate;
        break;
    }
}

if ($configPath === null) {
    http_response_code(500);
    exit('config.php is missing. Copy config.example.php to config.php and fill it in.');
}
$CONFIG = require $configPath;

if (!is_array($CONFIG)) {
    http_response_code(500);
    exit('config.php must return an array.');
}

function cfg(string $key, $default = null) {
    global $CONFIG, $CONFIG_OVERRIDE;
    // The override exists so a diagnostic can ask one provider a question
    // without rewriting config.php. Nothing in the app itself sets it.
    if (!empty($CONFIG_OVERRIDE) && array_key_exists($key, $CONFIG_OVERRIDE)) {
        return $CONFIG_OVERRIDE[$key];
    }
    return $CONFIG[$key] ?? $default;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', cfg('db_host'), cfg('db_name'));
    $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    ensure_app_schema($pdo);
    return $pdo;
}

/**
 * Light Phase-1 schema bumps. Safe to run on every request; only ALTERs when
 * a column is missing. Keeps existing installs working without re-running install.
 */
function ensure_app_schema(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $check = $pdo->query("SHOW COLUMNS FROM trackers LIKE 'cluster'");
        if ($check && !$check->fetch()) {
            $pdo->exec(
                "ALTER TABLE trackers
                 ADD COLUMN `cluster` VARCHAR(100) NOT NULL DEFAULT '' AFTER keyword,
                 ADD KEY idx_cluster (client_id, `cluster`)"
            );
        }
    } catch (Throwable $e) {
        // Schema ensure must never take the app down; install/upgrade can fix later.
    }
}

/** Display label for a tracker cluster; empty means ungrouped. */
function cluster_label(?string $cluster): string {
    $c = trim((string)$cluster);
    return $c !== '' ? $c : 'No cluster';
}

/**
 * Default keyword library for the add-keywords builder (Phase 1).
 * Vertical packs the team can pick from; custom terms go into any cluster.
 *
 * @return array<string, list<string>> cluster name => keywords
 */
function keyword_library(): array {
    return [
        'Dealer intent' => [
            'polaris dealer',
            'polaris dealer near me',
            'polaris dealership',
            'rzr dealer near me',
            'ranger dealer near me',
        ],
        'Purchase intent' => [
            'polaris for sale',
            'polaris ranger for sale',
            'used polaris for sale',
            'polaris rzr for sale',
            'atv for sale near me',
        ],
        'Service' => [
            'polaris service near me',
            'polaris repair',
            'utv service near me',
        ],
        'Financing' => [
            'polaris financing',
            'atv financing near me',
        ],
    ];
}

/* ---------- session and auth ---------- */

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

function current_user(): ?array {
    start_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $user = null;
    if ($user === null) {
        $stmt = db()->prepare('SELECT id, username, email, role FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
    }
    return $user;
}

function require_login(): array {
    $user = current_user();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

/* ---------- csrf ---------- */

function csrf_token(): string {
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function check_csrf(): void {
    start_session();
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Your session expired. Go back, reload the page, and try again.');
    }
}

/* ---------- small helpers ---------- */

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function flash(?string $msg = null, string $type = 'ok'): ?array {
    start_session();
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $to): void {
    header('Location: ' . $to);
    exit;
}

/** Format a UTC datetime string in a tracker's local timezone. */
function local_time(?string $utc, string $tz, string $fmt = 'M j, H:i'): string {
    if (!$utc) {
        return '--';
    }
    try {
        $d = new DateTime($utc, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone($tz));
        return $d->format($fmt);
    } catch (Exception $e) {
        return $utc;
    }
}

function relative_time(?string $utc): string {
    if (!$utc) {
        return 'never';
    }
    $diff = strtotime($utc) - time();
    $abs  = abs($diff);
    if ($abs < 45) {
        return $diff >= 0 ? 'in a moment' : 'just now';
    }
    if ($abs < 60)    { $s = $abs . 's'; }
    elseif ($abs < 3600)  { $s = floor($abs / 60) . 'm'; }
    elseif ($abs < 86400) { $s = floor($abs / 3600) . 'h'; }
    else { $s = floor($abs / 86400) . 'd'; }
    return $diff >= 0 ? 'in ' . $s : $s . ' ago';
}

/* ---------- access control ---------- */

function is_admin(): bool {
    $u = current_user();
    return $u && ($u['role'] ?? 'member') === 'admin';
}

function require_admin(): array {
    $user = require_login();
    if (!is_admin()) {
        http_response_code(403);
        exit('That area is for administrators only.');
    }
    return $user;
}

/**
 * Which clients this user may see. Administrators see everything; everyone
 * else sees only what they have been assigned.
 *
 * Returns null for "no restriction", which callers treat as see-all. That is
 * deliberately distinct from an empty array, which means "assigned to nothing"
 * and must show nothing rather than everything.
 */
function visible_client_ids(): ?array {
    if (is_admin()) {
        return null;
    }
    $u = current_user();
    if (!$u) {
        return [];
    }
    static $cache = null;
    if ($cache === null) {
        $stmt = db()->prepare('SELECT client_id FROM user_clients WHERE user_id = ?');
        $stmt->execute([$u['id']]);
        $cache = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    return $cache;
}

function can_see_client(?int $clientId): bool {
    if ($clientId === null) {
        return is_admin();
    }
    $ids = visible_client_ids();
    return $ids === null || in_array($clientId, $ids, true);
}

/** Guard a tracker by the client it belongs to. */
function can_see_tracker(array $tracker): bool {
    return can_see_client($tracker['client_id'] === null ? null : (int)$tracker['client_id']);
}

/**
 * SQL fragment restricting a query to the visible clients.
 * Returns [sqlCondition, params]. The condition is always safe to AND.
 */
function client_scope_sql(string $column = 'client_id'): array {
    $ids = visible_client_ids();
    if ($ids === null) {
        return ['1=1', []];
    }
    if (!$ids) {
        return ['1=0', []];
    }
    return [$column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
}

/**
 * Reduce anything a person might paste into a bare, comparable domain.
 * "https://www.Example.com/path" becomes "example.com".
 *
 * Every domain is stored in this form, because ad domains are extracted this
 * way too. If the two forms drift apart, a client's own site never matches its
 * own ads and the "theirs" marker silently never appears.
 */
function normalise_domain(string $raw): string {
    $d = strtolower(trim($raw));
    $d = preg_replace('#^[a-z]+://#', '', $d);
    $d = preg_replace('#^www\.#', '', $d);
    $d = explode('/', $d)[0];
    $d = explode('?', $d)[0];
    $d = explode(':', $d)[0];
    return trim($d, " \t\n\r\0\x0B.");
}

/** Normalise a comma separated list, dropping blanks and duplicates. */
function normalise_domain_list(string $raw): string {
    $out = [];
    foreach (explode(',', $raw) as $part) {
        $d = normalise_domain($part);
        if ($d !== '' && !in_array($d, $out, true)) {
            $out[] = $d;
        }
    }
    return implode(', ', $out);
}

/**
 * Agencies a user may see: those holding at least one client they can reach.
 * Administrators see all of them, including empty ones.
 */
function visible_agency_ids(): ?array {
    if (is_admin()) {
        return null;
    }
    $clients = visible_client_ids();
    if ($clients === null) {
        return null;
    }
    if (!$clients) {
        return [];
    }
    $in = implode(',', array_fill(0, count($clients), '?'));
    $st = db()->prepare("SELECT DISTINCT agency_id FROM clients WHERE id IN ($in) AND agency_id IS NOT NULL");
    $st->execute($clients);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function can_see_agency(?int $agencyId): bool {
    if ($agencyId === null) {
        return is_admin();
    }
    $ids = visible_agency_ids();
    return $ids === null || in_array($agencyId, $ids, true);
}

/** Breadcrumb trail. Pass [label, href] pairs; the last one is plain text. */
function crumbs(array $trail): string {
    $out = [];
    $last = count($trail) - 1;
    foreach ($trail as $i => [$label, $href]) {
        $out[] = ($i === $last || !$href)
            ? '<span>' . h($label) . '</span>'
            : '<a href="' . h($href) . '">' . h($label) . '</a>';
    }
    return '<nav class="crumbs">' . implode('<span class="sep">/</span>', $out) . '</nav>';
}
