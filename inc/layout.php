<?php
declare(strict_types=1);

/**
 * Adrecon chrome — topbar + .wrap, with flat dealer switcher (no agency layer).
 */
function nav_dealers(): array {
    static $dealers = null;
    if ($dealers !== null) {
        return $dealers;
    }
    [$scope, $params] = client_scope_sql('c.id');
    $stmt = db()->prepare(
        "SELECT c.id, c.name FROM clients c WHERE $scope ORDER BY c.name"
    );
    $stmt->execute($params);
    $dealers = [];
    foreach ($stmt->fetchAll() as $r) {
        $dealers[] = [
            'id'   => (int)$r['id'],
            'name' => (string)$r['name'],
        ];
    }
    return $dealers;
}

/** Resolve what the topbar context label should show on this request. */
function nav_current_context(): array {
    $script  = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dealers = nav_dealers();

    $clientId = 0;
    if ($script === 'client.php') {
        $clientId = (int)($_GET['id'] ?? 0);
    } elseif ($script === 'tracker.php') {
        $tid = (int)($_GET['id'] ?? 0);
        if ($tid > 0) {
            $st = db()->prepare('SELECT client_id FROM trackers WHERE id = ?');
            $st->execute([$tid]);
            $clientId = (int)$st->fetchColumn();
        }
    }

    $clientName = '';
    foreach ($dealers as $c) {
        if ($c['id'] === $clientId) {
            $clientName = $c['name'];
            break;
        }
    }

    $onDealers = in_array($script, ['index.php', 'clients.php'], true) && $clientId === 0;
    $label = $clientName !== '' ? $clientName : 'All dealers';

    return [
        'label'       => $label,
        'client_id'   => $clientId,
        'client_name' => $clientName,
        'dealers'     => $dealers,
        'on_dealers'  => $onDealers,
        // Keep old key so meter visibility stays tied to the home list.
        'on_agencies' => $onDealers,
    ];
}

function render_head(string $title, ?array $user = null): void {
    $used      = credits_used_this_month();
    $ceiling   = (int)cfg('monthly_credit_ceiling', 0);
    $projected = credits_projected_monthly();
    $pct       = $ceiling > 0 ? min(100, (int)round($used / $ceiling * 100)) : 0;
    $over      = $ceiling > 0 && $projected > $ceiling;
    $nearly    = !$over && $ceiling > 0 && $projected > $ceiling * 0.85;
    $here      = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $ctx       = $user ? nav_current_context() : null;
    ?><!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · Adrecon</title>
<link rel="stylesheet" href="assets/app.css">
<script src="assets/app.js" defer></script>
</head>
<body>
<header class="topbar">
  <a class="wordmark" href="index.php">Ad<span>recon</span></a>
  <?php if ($user && $ctx): ?>
  <div class="ctx-wrap" id="ctxWrap">
    <button type="button" class="ctx" id="ctxToggle" aria-haspopup="true" aria-expanded="false" title="Switch dealer">
      <span class="ctx-label" id="ctxLabel"><?= h($ctx['label']) ?></span>
      <span class="caretdown">▾</span>
    </button>
    <div class="ctx-menu" id="ctxMenu" hidden>
      <div class="ctx-pane" id="ctxDealers">
        <a class="ctx-item<?= !empty($ctx['on_dealers']) ? ' on' : '' ?>" href="clients.php">
          <span>All dealers</span>
        </a>
        <?php if (!$ctx['dealers']): ?>
          <div class="ctx-empty">No dealers yet</div>
        <?php else: ?>
          <div class="ctx-sep">Dealers</div>
          <?php foreach ($ctx['dealers'] as $c): ?>
            <a class="ctx-item<?= $ctx['client_id'] === (int)$c['id'] ? ' on' : '' ?>"
               href="client.php?id=<?= (int)$c['id'] ?>">
              <span><?= h($c['name']) ?></span>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php if (is_admin()): ?>
  <nav class="topnav">
    <a class="<?= $here === 'users.php' ? 'on' : '' ?>" href="users.php">Accounts</a>
    <a class="<?= $here === 'admin.php' ? 'on' : '' ?>" href="admin.php">Dealer access</a>
    <a class="<?= $here === 'cron_setup.php' ? 'on' : '' ?>" href="cron_setup.php">Server</a>
  </nav>
  <?php endif; ?>
  <?php if (empty($ctx['on_agencies'])): ?>
  <div class="meter">
    <span class="lbl"><?= h(strtoupper(provider_unit())) ?></span>
    <?php
      $projPct = $ceiling > 0 ? min(100, (int)round($projected / $ceiling * 100)) : 0;
      $overPct = max(0, min(100 - $pct, $projPct - $pct));
    ?>
    <span class="meter-bar meter-track" title="<?= $pct ?>% spent">
      <i style="width: <?= $pct ?>%"></i>
      <?php if ($over || $nearly): ?><u style="left: <?= $pct ?>%; width: <?= $overPct ?>%"></u><?php endif; ?>
    </span>
    <span><b><?= number_format($used) ?></b><?= $ceiling ? ' / ' . number_format($ceiling) : '' ?></span>
    <?php if ($over): ?>
      <a class="meter-alert" href="index.php"><?= number_format($projected - $ceiling) ?> over</a>
    <?php elseif ($nearly): ?>
      <span class="meter-warn"><?= number_format($projected) ?> projected</span>
    <?php else: ?>
      <span class="meter-proj">projected <?= number_format($projected) ?></span>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="right" style="margin-left:auto">
    <span class="who"><?= h($user['username']) ?><?= is_admin() ? ' · admin' : '' ?></span>
    <a href="logout.php">Sign out</a>
  </div>
  <?php endif; ?>
</header>
<div class="wrap">
<?php
    $f = flash();
    if ($f) {
        echo '<div class="flash flash-' . h($f['type']) . '">' . h($f['msg']) . '</div>';
    }
}

function render_foot(): void {
    echo '</div></body></html>';
}

function status_pill(string $status): string {
    $map = [
        'active'  => ['Running', 'run'],
        'paused'  => ['Stopped', 'idle'],
        'done'    => ['Done, ran once', 'idle'],
        'expired' => ['Finished its run', 'idle'],
        'error'   => ['Needs attention', 'err'],
        'success' => ['Ads found', 'run'],
        'empty'   => ['No ads', 'idle'],
        'failed'  => ['Check failed', 'err'],
        'running' => ['Checking now', 'idle'],
        'queued'  => ['Queued', 'idle'],
    ];
    [$label, $cls] = $map[$status] ?? [ucfirst($status), 'idle'];
    return '<span class="pill pill-' . $cls . '">' . h($label) . '</span>';
}

/** Consistency chip for coverage % — matches prototype wording. */
function consistency_chip(float $pct): string {
    if ($pct >= 95) {
        return '<span class="chip green">Always · ' . (int)$pct . '%</span>';
    }
    if ($pct >= 75) {
        return '<span class="chip green">Most checks · ' . (int)$pct . '%</span>';
    }
    if ($pct >= 25) {
        return '<span class="chip amber">Intermittent · ' . (int)$pct . '%</span>';
    }
    return '<span class="chip">Rare · ' . (int)$pct . '%</span>';
}
