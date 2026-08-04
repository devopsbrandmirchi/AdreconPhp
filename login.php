<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

start_session();
if (current_user()) {
    redirect('index.php');
}

const LOGIN_WINDOW_MINUTES = 15;
const LOGIN_MAX_PER_USER   = 5;
const LOGIN_MAX_PER_IP     = 15;

function client_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function login_failures(string $ip, string $username): array {
    $stmt = db()->prepare(
        'SELECT
            SUM(ip = ?) AS by_ip,
            SUM(username = ?) AS by_user
         FROM login_attempts
         WHERE attempted_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)'
    );
    $stmt->execute([$ip, $username, LOGIN_WINDOW_MINUTES]);
    $row = $stmt->fetch() ?: [];
    return [(int)($row['by_ip'] ?? 0), (int)($row['by_user'] ?? 0)];
}

function record_login_failure(string $ip, string $username): void {
    db()->prepare('INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, UTC_TIMESTAMP())')
        ->execute([$ip, mb_substr($username, 0, 60)]);
    if (random_int(1, 20) === 1) {
        db()->exec('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)');
    }
}

function clear_login_failures(string $ip, string $username): void {
    db()->prepare('DELETE FROM login_attempts WHERE ip = ? OR username = ?')->execute([$ip, $username]);
}

$error  = null;
$locked = false;
$ip     = client_ip();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $username = trim((string)($_POST['username'] ?? ''));
    $pass     = (string)($_POST['password'] ?? '');

    [$byIp, $byUser] = login_failures($ip, $username);
    if ($byIp >= LOGIN_MAX_PER_IP || $byUser >= LOGIN_MAX_PER_USER) {
        $locked = true;
        $error  = 'Too many attempts. Wait ' . LOGIN_WINDOW_MINUTES . ' minutes, then try again.';
    } else {
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $u = $stmt->fetch();
        if ($u && password_verify($pass, (string)$u['password_hash'])) {
            clear_login_failures($ip, $username);
            $_SESSION['user_id'] = (int)$u['id'];
            redirect('index.php');
        }
        record_login_failure($ip, $username);
        $error = 'That username and password do not match. Check both and try again.';
        usleep(400000);
    }
}
?><!doctype html>
<html lang="en" data-theme="light"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · Adrecon</title>
<link rel="stylesheet" href="assets/app.css"></head>
<body><div class="login">
<a class="wordmark" href="login.php" style="display:inline-block;margin-bottom:18px;color:var(--ink)">Ad<span style="color:var(--muted)">recon</span></a>
<h1 class="page" style="font-size:26px">Sign in</h1>
<p class="sub" style="margin-bottom:16px">Competitive spy intelligence — see who is advertising on your keywords, and how often.</p>

<?php if ($error): ?><div class="flash flash-err"><?= h($error) ?></div><?php endif; ?>

<div class="card pad">
<form method="post" class="grid">
  <?= csrf_field() ?>
  <div class="field"><label for="username">Username</label>
    <input class="input" type="text" id="username" name="username" required autofocus autocomplete="username"></div>
  <div class="field"><label for="password">Password</label>
    <input class="input" type="password" id="password" name="password" required autocomplete="current-password"></div>
  <div><button class="btn primary" type="submit">Sign in</button></div>
</form>
</div>
</div></body></html>
