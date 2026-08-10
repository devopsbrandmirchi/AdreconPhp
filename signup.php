<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

start_session();
if (current_user()) {
    redirect('index.php');
}

/** Turn a display name into a valid username (letters, numbers, . _ -). */
function signup_username_from_name(string $name): string {
    $u = strtolower(trim($name));
    $u = preg_replace('/\s+/', '_', $u) ?? '';
    $u = preg_replace('/[^a-z0-9._-]/', '', $u) ?? '';
    $u = trim($u, '._-');
    return mb_substr($u, 0, 60);
}

$error = null;
$old = ['name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $name = trim((string)($_POST['name'] ?? ''));
    $em   = trim((string)($_POST['email'] ?? ''));
    $pw   = (string)($_POST['password'] ?? '');
    $old  = ['name' => $name, 'email' => $em];
    $un   = signup_username_from_name($name);

    if ($name === '' || $un === '' || !preg_match('/^[a-z0-9._-]{3,60}$/', $un)) {
        $error = 'Enter a name (at least 3 letters/numbers). It becomes your sign-in username.';
    } elseif ($pw === '' || strlen($pw) < 8) {
        $error = 'Choose a password of at least 8 characters.';
    } elseif ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) {
        $error = 'That email address is not valid. Leave it blank if you prefer.';
    } else {
        try {
            db()->prepare(
                "INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'member')"
            )->execute([$un, $em !== '' ? $em : null, password_hash($pw, PASSWORD_DEFAULT)]);
            $newUser = (int)db()->lastInsertId();

            $_SESSION['user_id'] = $newUser;
            flash('Account created. You are signed in.');
            redirect('index.php');
        } catch (PDOException $e) {
            $error = (int)$e->getCode() === 23000
                ? 'That name/username is already taken. Try another.'
                : 'Could not create the account. Try again.';
        }
    }
}
?><!doctype html>
<html lang="en" data-theme="light"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign up · Adrecon</title>
<link rel="stylesheet" href="assets/app.css"></head>
<body class="login-page">
<div class="login">
  <div class="signup-card card">
    <a class="login-logo wordmark" href="login.php">Ad<span>recon</span></a>
    <h1 class="signup-title signup-title-center">Sign up</h1>
    <p class="signup-sub signup-sub-center">Create your account. After that you can add an agency or clients.</p>

    <?php if ($error): ?><div class="flash flash-err"><?= h($error) ?></div><?php endif; ?>

    <form method="post" class="signup-form" id="signupForm">
      <?= csrf_field() ?>

      <div class="field">
        <label for="su_name">Name</label>
        <input class="input" type="text" id="su_name" name="name"
               value="<?= h($old['name']) ?>"
               placeholder="Your name"
               autocomplete="name" autofocus>
      </div>
      <div class="field">
        <label for="su_email">Email <span class="opt">(optional)</span></label>
        <input class="input" type="email" id="su_email" name="email"
               value="<?= h($old['email']) ?>"
               placeholder="you@example.com"
               autocomplete="email">
      </div>
      <div class="field">
        <label for="su_password">Password</label>
        <input class="input" type="password" id="su_password" name="password"
               placeholder="At least 8 characters"
               autocomplete="new-password">
      </div>

      <div class="signup-actions signup-actions-center">
        <button class="btn primary" type="submit">Sign up</button>
      </div>
    </form>

    <p class="signup-foot">
      Already have an account? <a href="login.php">Sign in</a>
    </p>
  </div>
</div>
</body></html>
