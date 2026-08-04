<?php
declare(strict_types=1);

/**
 * Sidebar navigation. Clients are always one click away, and the admin tools
 * are grouped rather than scattered across the pages they happen to touch.
 */
function render_sidebar(?array $user): void {
    if (!$user) {
        return;
    }
    $here = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $cid  = (int)($_GET['id'] ?? 0);

    [$scope, $params] = client_scope_sql('id');
    $stmt = db()->prepare("SELECT id, name FROM clients WHERE $scope ORDER BY name");
    $stmt->execute($params);
    $clients = $stmt->fetchAll();
    ?>
    <aside class="side">
      <div class="side-group">
        <div class="side-label">Clients</div>
        <a class="side-link<?= $here === 'index.php' ? ' on' : '' ?>" href="index.php">All clients</a>
        <?php foreach ($clients as $c): ?>
          <a class="side-link side-child<?= ($here === 'client.php' && $cid === (int)$c['id']) ? ' on' : '' ?>" href="client.php?id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a>
        <?php endforeach; ?>
        <?php if (!$clients): ?>
          <span class="side-empty">none yet</span>
        <?php endif; ?>
      </div>

      <?php if (is_admin()): ?>
      <div class="side-group">
        <div class="side-label">Settings</div>
        <a class="side-link<?= $here === 'users.php' ? ' on' : '' ?>" href="users.php">Accounts</a>
        <a class="side-link<?= $here === 'admin.php' ? ' on' : '' ?>" href="admin.php">Client access</a>
        <a class="side-link<?= $here === 'cron_setup.php' ? ' on' : '' ?>" href="cron_setup.php">Server checks</a>
      </div>
      <?php endif; ?>
    </aside>
    <?php
}

function render_head(string $title, ?array $user = null): void {
    $used      = credits_used_this_month();
    $ceiling   = (int)cfg('monthly_credit_ceiling', 0);
    $projected = credits_projected_monthly();
    $pct       = $ceiling > 0 ? min(100, (int)round($used / $ceiling * 100)) : 0;
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · Ads tracker</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="assets/app.css">
<script src="assets/app.js" defer></script>
</head>
<body>
<header class="bar">
  <a class="brand" href="index.php">Ads<span>tracker</span></a>
  <?php if ($user): ?>
  <div class="meter" title="Used this month against the limit set in your config">
    <span class="meter-label"><?= h(provider_unit()) ?></span>
    <span class="meter-track"><i style="width: <?= $pct ?>%"></i></span>
    <span class="mono"><?= number_format($used) ?><?= $ceiling ? ' / ' . number_format($ceiling) : '' ?></span>
    <span class="meter-proj mono">projected <?= number_format($projected) ?>/mo</span>
  </div>
  <nav>
    <span class="who"><?= h($user['username']) ?><?= is_admin() ? ' · admin' : '' ?></span>
    <a href="logout.php">Sign out</a>
  </nav>
  <?php endif; ?>
</header>
<div class="shell">
<?php render_sidebar($user); ?>
<main>
<?php
    $f = flash();
    if ($f) {
        echo '<div class="flash flash-' . h($f['type']) . '">' . h($f['msg']) . '</div>';
    }
}

function render_foot(): void {
    echo '</main></div></body></html>';
}

function status_pill(string $status): string {
    $map = [
        // Schedule states
        'active'  => ['Running', 'run'],
        'paused'  => ['Stopped', 'idle'],
        'done'    => ['Done, ran once', 'idle'],
        'expired' => ['Finished its run', 'idle'],
        'error'   => ['Needs attention', 'err'],
        // Individual check outcomes
        'success' => ['Ads found', 'run'],
        'empty'   => ['No ads', 'idle'],
        'failed'  => ['Check failed', 'err'],
        'running' => ['Checking now', 'idle'],
    ];
    [$label, $cls] = $map[$status] ?? [ucfirst($status), 'idle'];
    return '<span class="pill pill-' . $cls . '">' . h($label) . '</span>';
}
