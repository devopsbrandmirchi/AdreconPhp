<?php
declare(strict_types=1);

/**
 * Adrecon chrome — topbar + .wrap, with Agency → Client (dealer) switcher.
 */
function nav_client_tree(): array {
    static $tree = null;
    if ($tree !== null) {
        return $tree;
    }
    [$scope, $params] = client_scope_sql('c.id');
    $stmt = db()->prepare(
        "SELECT c.id, c.name, c.agency_id, a.name AS agency_name
         FROM clients c
         LEFT JOIN agencies a ON a.id = c.agency_id
         WHERE $scope
         ORDER BY COALESCE(a.name, 'zzz'), c.name"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $tree = [];
    foreach ($rows as $r) {
        $aid = $r['agency_id'] !== null ? (int)$r['agency_id'] : 0;
        $aname = $r['agency_name'] ?: 'No agency';
        if (!isset($tree[$aid])) {
            $tree[$aid] = ['id' => $aid, 'name' => $aname, 'clients' => []];
        }
        $tree[$aid]['clients'][] = [
            'id'   => (int)$r['id'],
            'name' => (string)$r['name'],
        ];
    }
    return $tree;
}

/** Resolve what the topbar context label should show on this request. */
function nav_current_context(): array {
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $tree   = nav_client_tree();

    $clientId = 0;
    $agencyId = null;
    if (isset($_GET['agency'])) {
        $agencyId = (int)$_GET['agency'];
    }

    if ($script === 'client.php') {
        $clientId = (int)($_GET['id'] ?? 0);
    } elseif ($script === 'tracker.php') {
        $tid = (int)($_GET['id'] ?? 0);
        if ($tid > 0) {
            $st = db()->prepare('SELECT client_id FROM trackers WHERE id = ?');
            $st->execute([$tid]);
            $clientId = (int)$st->fetchColumn();
        }
    } elseif ($script === 'clients.php' && isset($_GET['agency'])) {
        $agencyId = (int)$_GET['agency'];
    }

    $clientName = '';
    $agencyName = '';
    if ($clientId > 0) {
        foreach ($tree as $ag) {
            foreach ($ag['clients'] as $c) {
                if ($c['id'] === $clientId) {
                    $clientName = $c['name'];
                    $agencyId = (int)$ag['id'];
                    $agencyName = $ag['name'];
                    break 2;
                }
            }
        }
    } elseif ($agencyId !== null && isset($tree[$agencyId])) {
        $agencyName = $tree[$agencyId]['name'];
    }

    if ($clientName !== '') {
        $label = $clientName;
    } elseif ($agencyName !== '') {
        $label = $agencyName;
    } else {
        $label = is_admin() ? 'All agencies' : 'My accounts';
    }

    return [
        'label'       => $label,
        'client_id'   => $clientId,
        'agency_id'   => $agencyId === null ? -1 : (int)$agencyId,
        'client_name' => $clientName,
        'agency_name' => $agencyName,
        'tree'        => $tree,
        'on_agencies' => $script === 'index.php' && $agencyId === null && $clientId === 0,
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
    <button type="button" class="ctx" id="ctxToggle" aria-haspopup="true" aria-expanded="false" title="Switch agency or account">
      <span class="ctx-label" id="ctxLabel"><?= h($ctx['label']) ?></span>
      <span class="caretdown">▾</span>
    </button>
    <div class="ctx-menu" id="ctxMenu" hidden>
      <div class="ctx-pane" id="ctxAgencies">
        <a class="ctx-item<?= !empty($ctx['on_agencies']) ? ' on' : '' ?>" href="<?= is_admin() ? 'index.php' : 'clients.php' ?>">
          <span><?= is_admin() ? 'All agencies' : 'My accounts' ?></span>
        </a>
        <?php if (!$ctx['tree']): ?>
          <div class="ctx-empty">No accounts yet</div>
        <?php else: ?>
          <?php foreach ($ctx['tree'] as $ag):
            $solo = count($ag['clients']) === 1;
            $agencyHref = $solo
                ? 'client.php?id=' . (int)$ag['clients'][0]['id']
                : 'clients.php?agency=' . (int)$ag['id'];
          ?>
            <?php if ($solo): ?>
              <a class="ctx-item<?= $ctx['agency_id'] === (int)$ag['id'] ? ' on' : '' ?>" href="<?= h($agencyHref) ?>">
                <span><?= h($ag['name']) ?></span>
                <span class="ctx-meta">solo</span>
              </a>
            <?php else: ?>
              <button type="button" class="ctx-item ctx-agency-btn<?= $ctx['agency_id'] === (int)$ag['id'] && $ctx['client_id'] === 0 ? ' on' : '' ?>"
                      data-agency="<?= (int)$ag['id'] ?>">
                <span><?= h($ag['name']) ?></span>
                <span class="ctx-meta"><?= count($ag['clients']) ?> dealers ›</span>
              </button>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <?php foreach ($ctx['tree'] as $ag):
        if (count($ag['clients']) <= 1) continue;
      ?>
      <div class="ctx-pane ctx-clients" id="ctxClients-<?= (int)$ag['id'] ?>" hidden data-agency-pane="<?= (int)$ag['id'] ?>">
        <button type="button" class="ctx-item ctx-back" data-back="1">
          <span>‹ Agencies</span>
        </button>
        <a class="ctx-item ctx-agency-all<?= $ctx['agency_id'] === (int)$ag['id'] && $ctx['client_id'] === 0 ? ' on' : '' ?>"
           href="clients.php?agency=<?= (int)$ag['id'] ?>">
          <span>All in <?= h($ag['name']) ?></span>
        </a>
        <div class="ctx-sep">Dealers</div>
        <?php foreach ($ag['clients'] as $c): ?>
          <a class="ctx-item<?= $ctx['client_id'] === (int)$c['id'] ? ' on' : '' ?>"
             href="client.php?id=<?= (int)$c['id'] ?>">
            <span><?= h($c['name']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if (is_admin()): ?>
  <nav class="topnav">
    <a class="<?= $here === 'users.php' ? 'on' : '' ?>" href="users.php">Accounts</a>
    <a class="<?= $here === 'admin.php' ? 'on' : '' ?>" href="admin.php">Client access</a>
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
