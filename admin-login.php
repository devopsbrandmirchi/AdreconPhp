<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

/**
 * Admin-only sign-in (hardcoded credentials).
 * URL: /admin-login.php
 *
 * Defaults (override in config.php if you want):
 *   username: admin
 *   password: Adrecon@Admin1
 */

start_session();
if ($u = current_user()) {
    if (($u['role'] ?? '') === 'admin') {
        redirect('admin.php');
    }
    redirect('index.php');
}

/** Hardcoded admin credentials (config can override). */
const HARDCODED_ADMIN_USER = 'admin';
const HARDCODED_ADMIN_PASS = 'Adrecon@Admin1';

function admin_hardcoded_user(): string {
    $u = trim((string)cfg('admin_hardcoded_user', HARDCODED_ADMIN_USER));
    return $u !== '' ? $u : HARDCODED_ADMIN_USER;
}

function admin_hardcoded_pass(): string {
    $p = (string)cfg('admin_hardcoded_pass', HARDCODED_ADMIN_PASS);
    return $p !== '' ? $p : HARDCODED_ADMIN_PASS;
}

/**
 * Ensure a matching admin row exists in users so the rest of the app works.
 */
function ensure_hardcoded_admin_user(string $username, string $password): int {
    $pdo = db();
    $st = $pdo->prepare('SELECT id, role FROM users WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    $row = $st->fetch();
    if ($row) {
        $id = (int)$row['id'];
        if (($row['role'] ?? '') !== 'admin') {
            $pdo->prepare("UPDATE users SET role = 'admin', password_hash = ? WHERE id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        } else {
            // Keep DB hash in sync with hardcoded password so other tools stay consistent.
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        }
        return $id;
    }
    $pdo->prepare(
        "INSERT INTO users (username, email, password_hash, role) VALUES (?, NULL, ?, 'admin')"
    )->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
    return (int)$pdo->lastInsertId();
}

const LOGIN_WINDOW_MINUTES = 15;
const LOGIN_MAX_PER_IP     = 15;

function client_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function admin_login_failures(string $ip): int {
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE ip = ? AND attempted_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)'
    );
    $stmt->execute([$ip, LOGIN_WINDOW_MINUTES]);
    return (int)$stmt->fetchColumn();
}

function record_login_failure(string $ip, string $username): void {
    db()->prepare('INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, UTC_TIMESTAMP())')
        ->execute([$ip, mb_substr($username, 0, 60)]);
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

    if (admin_login_failures($ip) >= LOGIN_MAX_PER_IP) {
        $locked = true;
        $error  = 'Too many attempts. Wait ' . LOGIN_WINDOW_MINUTES . ' minutes, then try again.';
    } elseif (
        hash_equals(admin_hardcoded_user(), $username)
        && hash_equals(admin_hardcoded_pass(), $pass)
    ) {
        clear_login_failures($ip, $username);
        $_SESSION['user_id'] = ensure_hardcoded_admin_user($username, $pass);
        redirect('admin.php');
    } else {
        // Fallback: still allow a real DB admin (not hardcoded) to sign in here.
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $u = $stmt->fetch();
        if ($u && ($u['role'] ?? '') === 'admin' && password_verify($pass, (string)$u['password_hash'])) {
            clear_login_failures($ip, $username);
            $_SESSION['user_id'] = (int)$u['id'];
            redirect('admin.php');
        }
        record_login_failure($ip, $username);
        $error = 'That username and password do not match.';
        usleep(400000);
    }
}
?><!doctype html>
<html lang="en" data-theme="light"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin sign in · Adrecon</title>
<link rel="stylesheet" href="assets/app.css"></head>
<body class="login-page">
<div class="login">
  <div class="login-card card">
    <a class="login-logo wordmark" href="admin-login.php">Ad<span>recon</span></a>
    <h1 class="login-title">Admin sign in</h1>
    <p class="login-sub">Administrators only — create agencies and add clients.</p>

    <?php if ($error): ?><div class="flash flash-err"><?= h($error) ?></div><?php endif; ?>

    <form method="post" class="login-form">
      <?= csrf_field() ?>
      <div class="field">
        <label for="username">Username</label>
        <input class="input" type="text" id="username" name="username" required autofocus autocomplete="username">
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input class="input" type="password" id="password" name="password" required autocomplete="current-password">
      </div>
      <div class="login-actions">
        <button class="btn primary" type="submit"<?= $locked ? ' disabled' : '' ?>>Sign in as admin</button>
      </div>
    </form>

    <p class="login-foot">
      Not an admin? <a href="login.php">User sign in</a>
    </p>
  </div>
</div>
</body></html>
