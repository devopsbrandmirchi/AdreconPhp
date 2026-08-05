<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/scheduler.php';
require __DIR__ . '/inc/layout.php';

$user = require_login();
reap_stale_runs();
expire_finished_trackers();

// Non-admins skip the agencies board and go straight to their accounts.
if (!is_admin()) {
    redirect('clients.php');
}

[$scope, $scopeParams] = client_scope_sql('c.id');

// Aggregate every visible client under its agency (or "No agency").
$sql = "SELECT c.id, c.name, c.agency_id, a.name AS agency_name,
          (SELECT COUNT(*) FROM trackers t WHERE t.client_id = c.id AND t.status = 'active') AS running,
          (SELECT COUNT(*) FROM trackers t WHERE t.client_id = c.id) AS schedules
        FROM clients c
        LEFT JOIN agencies a ON a.id = c.agency_id
        WHERE $scope
        ORDER BY COALESCE(a.name, 'zzzz'), c.name";
$stmt = db()->prepare($sql);
$stmt->execute($scopeParams);
$clientRows = $stmt->fetchAll();

// Also include empty agencies (admin-only) so they show up to be opened/filled.
$allAgencies = db()->query('SELECT id, name FROM agencies ORDER BY name')->fetchAll();

$groups = [];
foreach ($allAgencies as $ag) {
    $groups[(int)$ag['id']] = [
        'id'       => (int)$ag['id'],
        'name'     => (string)$ag['name'],
        'clients'  => [],
        'accounts' => 0,
        'running'  => 0,
        'schedules'=> 0,
    ];
}
$groups[0] = [
    'id' => 0, 'name' => 'No agency', 'clients' => [],
    'accounts' => 0, 'running' => 0, 'schedules' => 0,
];

foreach ($clientRows as $c) {
    $aid = $c['agency_id'] !== null ? (int)$c['agency_id'] : 0;
    if (!isset($groups[$aid])) {
        $groups[$aid] = [
            'id' => $aid,
            'name' => $c['agency_name'] ?: 'No agency',
            'clients' => [],
            'accounts' => 0, 'running' => 0, 'schedules' => 0,
        ];
    }
    $groups[$aid]['clients'][] = $c;
    $groups[$aid]['accounts']++;
    $groups[$aid]['running'] += (int)$c['running'];
    $groups[$aid]['schedules'] += (int)$c['schedules'];
}

// Drop empty "No agency" if unused.
if ($groups[0]['accounts'] === 0) {
    unset($groups[0]);
}

$ceiling = (int)cfg('monthly_credit_ceiling', 0);
$icoColors = ['#2a78d6', '#eb6834', '#1baf7a', '#7c5cbf', '#d03b3b', '#0d9488'];
$totalAccounts = array_sum(array_column($groups, 'accounts'));

/** Searches used this month for trackers under these client ids. */
function agency_credits_used(array $clientIds): int {
    if (!$clientIds) {
        return 0;
    }
    $in = implode(',', array_fill(0, count($clientIds), '?'));
    $st = db()->prepare(
        "SELECT COALESCE(SUM(r.credits_used), 0)
         FROM runs r
         JOIN trackers t ON t.id = r.tracker_id
         WHERE t.client_id IN ($in)
           AND r.started_at >= DATE_FORMAT(UTC_DATE(), '%Y-%m-01')"
    );
    $st->execute($clientIds);
    return (int)$st->fetchColumn();
}

function agency_credits_projected(array $clientIds): int {
    if (!$clientIds) {
        return 0;
    }
    $in = implode(',', array_fill(0, count($clientIds), '?'));
    $st = db()->prepare(
        "SELECT COALESCE(SUM(
            FLOOR((24 / interval_hours) *
                  LEAST(30, GREATEST(0, IFNULL(TIMESTAMPDIFF(DAY, UTC_TIMESTAMP(), runs_until), 30)))
            ) * " . provider_cost() . "
         ), 0)
         FROM trackers
         WHERE status = 'active' AND interval_hours > 0 AND client_id IN ($in)"
    );
    $st->execute($clientIds);
    return (int)$st->fetchColumn();
}

render_head('Agencies & owners', $user);
?>

<div class="pagehead" style="margin-bottom:22px">
  <div>
    <h1 class="page">Agencies &amp; owners</h1>
    <div class="sub">
      <?= count($groups) ?> agenc<?= count($groups) === 1 ? 'y' : 'ies' ?> ·
      <?= (int)$totalAccounts ?> dealer<?= $totalAccounts === 1 ? '' : 's' ?> ·
      each agency has its own dealers
      &nbsp;·&nbsp; <span style="color:var(--blue)">visible to admins only</span>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn" href="admin.php">Users &amp; access</a>
    <a class="btn dark" href="admin.php#agencies">+ Add agency</a>
  </div>
</div>

<?php if (!$groups): ?>
  <div class="card pad">
    <p style="margin:0;color:var(--ink-2)">
      No agencies yet. <a href="admin.php#agencies">Add an agency</a> and attach dealers under Client access.
    </p>
  </div>
<?php else: ?>
<div class="card">
  <?php $i = 0; foreach ($groups as $ag):
    $ids = array_map(fn($c) => (int)$c['id'], $ag['clients']);
    $used = agency_credits_used($ids);
    $proj = agency_credits_projected($ids);
    $solo = $ag['accounts'] === 1;
    $openHref = $solo && $ids
        ? 'client.php?id=' . $ids[0]
        : 'clients.php?agency=' . (int)$ag['id'];
    $pct = $ceiling > 0 ? min(100, (int)round($used / $ceiling * 100)) : 0;
    $projColor = ($ceiling > 0 && $proj > $ceiling)
        ? 'var(--critical)'
        : (($ceiling > 0 && $proj > $ceiling * 0.85) ? '#b97e00' : 'var(--good-ink)');
    $letter = mb_strtoupper(mb_substr($ag['name'], 0, 1));
    $color = $icoColors[$i % count($icoColors)];
    $i++;
  ?>
  <div class="agency-row" onclick="window.location='<?= h($openHref) ?>'" role="link" tabindex="0"
       onkeydown="if(event.key==='Enter'){window.location='<?= h($openHref) ?>'}">
    <div class="agency-ico" style="background:<?= h($color) ?>"><?= h($letter) ?></div>
    <div style="flex:1;min-width:0">
      <div class="agency-name">
        <?= h($ag['name']) ?>
        <?php if ($solo): ?>
          <span class="chip green" style="vertical-align:middle">Solo owner</span>
        <?php else: ?>
          <span class="chip" style="vertical-align:middle">Agency</span>
        <?php endif; ?>
      </div>
      <div class="agency-sub">
        <?= (int)$ag['accounts'] ?> dealer<?= $ag['accounts'] === 1 ? '' : 's' ?> ·
        <?= (int)$ag['running'] ?> running
        <?php if ($ag['schedules']): ?> · <?= (int)$ag['schedules'] ?> keywords<?php endif; ?>
      </div>
    </div>
    <div class="agency-metric">
      <div class="m">Searches this mo</div>
      <div class="n tabular">
        <?= number_format($used) ?>
        <?php if ($ceiling): ?>
          <span style="color:var(--muted);font-weight:500;font-size:12px">/ <?= number_format($ceiling) ?></span>
        <?php endif; ?>
      </div>
      <?php if ($ceiling): ?>
        <div class="qbar"><i style="width:<?= $pct ?>%"></i></div>
      <?php endif; ?>
    </div>
    <div class="agency-metric">
      <div class="m">Projected</div>
      <div class="n tabular" style="color:<?= h($projColor) ?>"><?= number_format($proj) ?></div>
    </div>
    <a href="<?= h($openHref) ?>" onclick="event.stopPropagation()">Open →</a>
  </div>
  <?php endforeach; ?>
</div>

<div class="callout" style="margin-top:20px">
  <b>Hierarchy:</b> Agency → dealers → clusters → terms → locations.
  Open an agency to see its dealers. Use <b>+ Add agency</b> to create a new one.
  This screen is admin-only — see <a href="admin.php">Users &amp; access</a> for how scoped users work.
</div>
<?php endif; ?>

<?php render_foot(); ?>
