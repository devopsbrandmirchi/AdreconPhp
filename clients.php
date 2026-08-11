<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/scheduler.php';
require __DIR__ . '/inc/layout.php';

$user = require_login();
reap_stale_runs();
expire_finished_trackers();

[$scope, $scopeParams] = client_scope_sql('c.id');

$agencyFilter = isset($_GET['agency']) ? (int)$_GET['agency'] : -1;
$agencyName = '';

// Members never use the "No agency" folder — show all their dealers instead.
if (!is_admin() && $agencyFilter === 0) {
    redirect('clients.php');
}

if ($agencyFilter > 0) {
    $scope = "($scope) AND c.agency_id = ?";
    $scopeParams[] = $agencyFilter;
    $an = db()->prepare('SELECT name FROM agencies WHERE id = ?');
    $an->execute([$agencyFilter]);
    $agencyName = (string)($an->fetchColumn() ?: '');
    if ($agencyName === '' || !can_see_agency($agencyFilter)) {
        http_response_code(404);
        exit('That agency does not exist or you cannot see it.');
    }
} elseif ($agencyFilter === 0 && isset($_GET['agency'])) {
    $scope = "($scope) AND c.agency_id IS NULL";
    $agencyName = 'No agency';
} elseif (is_admin() && $agencyFilter < 0) {
    // Admins land on the agencies board; this page needs an agency.
    redirect('index.php');
}

$sql = "SELECT c.*, a.name AS agency_name,
          (SELECT COUNT(*) FROM trackers t WHERE t.client_id = c.id) AS schedules,
          (SELECT COUNT(*) FROM trackers t WHERE t.client_id = c.id AND t.status = 'active') AS running,
          (SELECT COUNT(DISTINCT t.keyword) FROM trackers t WHERE t.client_id = c.id) AS keywords,
          (SELECT COUNT(DISTINCT t.location) FROM trackers t WHERE t.client_id = c.id) AS locations,
          (SELECT COUNT(*) FROM sites st WHERE st.client_id = c.id) AS sites,
          (SELECT MAX(t.last_run_at) FROM trackers t WHERE t.client_id = c.id) AS last_run
        FROM clients c
        LEFT JOIN agencies a ON a.id = c.agency_id
        WHERE $scope
        ORDER BY c.name";
$stmt = db()->prepare($sql);
$stmt->execute($scopeParams);
$clients = $stmt->fetchAll();

// Admin-only: one dealer under an agency → open it. Members always see the list.
if (is_admin() && count($clients) === 1 && $agencyFilter > 0) {
    redirect('client.php?id=' . (int)$clients[0]['id']);
}

$totalSchedules = array_sum(array_column($clients, 'schedules'));
$totalRunning   = array_sum(array_column($clients, 'running'));

$siteDomainsByClient = [];
if ($clients) {
    $ids = array_map('intval', array_column($clients, 'id'));
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $sq  = db()->prepare(
        "SELECT client_id, domain FROM sites WHERE client_id IN ($in) ORDER BY domain"
    );
    $sq->execute($ids);
    foreach ($sq->fetchAll() as $row) {
        $cid = (int)$row['client_id'];
        if (!isset($siteDomainsByClient[$cid])) {
            $siteDomainsByClient[$cid] = [];
        }
        $siteDomainsByClient[$cid][] = $row['domain'];
    }
}

$firstClientId = $clients ? (int)$clients[0]['id'] : 0;
$addHref = $firstClientId
    ? 'client.php?id=' . $firstClientId . '&tab=add'
    : (is_admin() ? 'admin.php' : 'clients.php');

$title = $agencyName !== '' ? $agencyName : (is_admin() ? 'Clients' : 'Dealers');
render_head($title, $user);

$crumbs = is_admin()
    ? [['Agencies', 'index.php'], [$agencyName !== '' ? $agencyName : 'Dealers', null]]
    : [['My dealers', null]];
?>

<?= crumbs($crumbs) ?>

<div class="pagehead" style="margin-bottom:18px">
  <div>
    <h1 class="page"><?= h($title) ?></h1>
    <div class="sub"><?= count($clients) ?> dealer<?= count($clients) === 1 ? '' : 's' ?> ·
      <?= $totalSchedules ?> keyword<?= $totalSchedules === 1 ? '' : 's' ?> tracked ·
      <?= $totalRunning ?> running now</div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($firstClientId): ?>
      <?php /* <a class="btn primary" href="<?= h($addHref) ?>">🔍 One-Click Spy</a> */ ?>
      <a class="btn primary" href="<?= h($addHref) ?>">+ Add keywords</a>
    <?php endif; ?>
    <?php if (is_admin()): ?>
      <a class="btn dark" href="admin.php">+ Add dealer</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!$clients): ?>
  <div class="card pad">
    <p style="margin:0;color:var(--ink-2)">
      <?= is_admin()
          ? 'No dealers in this agency yet. Add one under Client access.'
          : 'No dealers have been shared with you yet.' ?>
    </p>
  </div>
<?php else: ?>

<div class="stat-row" style="margin-bottom:20px">
  <div class="stat"><div class="k">Dealers</div><div class="v"><?= count($clients) ?></div>
    <div class="foot"><?= $totalRunning ?> running now</div></div>
  <div class="stat"><div class="k">Keywords tracked</div><div class="v blue"><?= (int)$totalSchedules ?></div>
    <div class="foot">across these dealers</div></div>
  <div class="stat"><div class="k">Plan used</div><div class="v"><?= number_format(credits_used_this_month()) ?></div>
    <div class="foot"><?php $c=(int)cfg('monthly_credit_ceiling',0); echo $c ? 'of '.number_format($c).' '.h(provider_unit()) : h(provider_unit()).' this month'; ?></div></div>
  <div class="stat"><div class="k">Projected month</div>
    <div class="v amber"><?= number_format(credits_projected_monthly()) ?></div>
    <div class="foot">at current cadence</div></div>
</div>

<div class="toolbar">
  <div class="search">
    <span class="mag">⌕</span>
    <input type="search" id="clientSearch" placeholder="Search dealers by name or website…"
           oninput="filterClientTable()" aria-label="Search dealers">
  </div>
  <select class="input" id="statusFilter" onchange="filterClientTable()" style="width:auto" aria-label="Filter by status" autocomplete="off">
    <option value="" selected>All statuses</option>
    <option value="running">Running</option>
    <option value="paused">Paused / no setup</option>
  </select>
  <span class="chip ghost" id="clientCount"><?= count($clients) ?> dealer<?= count($clients) === 1 ? '' : 's' ?></span>
</div>

<div class="card" style="overflow:hidden">
  <table class="ctable" id="clientTable">
    <thead>
      <tr>
        <th>Dealer</th>
        <th>Status</th>
        <th>Keywords</th>
        <th style="text-align:center">Locations</th>
        <th>Websites</th>
        <th>Last checked</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($clients as $c):
        $domains = $siteDomainsByClient[(int)$c['id']] ?? [];
        if (!$domains) {
            $domains = array_filter(array_map('trim', explode(',', (string)$c['domains'])));
        }
        $url = $domains[0] ?? '';
        $running = (int)$c['running'] > 0;
        $status = $running ? 'running' : 'paused';
        $searchBlob = strtolower($c['name'] . ' ' . implode(' ', $domains));
    ?>
      <tr class="client-row" data-status="<?= $status ?>" data-search="<?= h($searchBlob) ?>"
          onclick="window.location='client.php?id=<?= (int)$c['id'] ?>'">
        <td>
          <div class="cname"><?= h($c['name']) ?></div>
          <div class="curl"><?= $url !== '' ? h($url) : 'no website yet' ?></div>
        </td>
        <td>
          <?php if ($running): ?>
            <span class="st-dot"><span class="dot" style="background:var(--good)"></span><?= (int)$c['running'] ?> running</span>
          <?php else: ?>
            <span class="st-dot"><span class="dot" style="background:#c7c9cd"></span>paused</span>
          <?php endif; ?>
        </td>
        <td class="tabular"><?= (int)$c['schedules'] ?></td>
        <td class="tabular" style="text-align:center"><?= (int)$c['locations'] ?></td>
        <td class="tabular"><?= (int)$c['sites'] ?></td>
        <td><?= h($c['last_run'] ? relative_time($c['last_run']) : 'never') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<script>
function filterClientTable() {
  var q = (document.getElementById('clientSearch').value || '').toLowerCase().trim();
  var sf = document.getElementById('statusFilter').value;
  var rows = document.querySelectorAll('#clientTable .client-row');
  var n = 0;
  rows.forEach(function (row) {
    var okQ = !q || (row.getAttribute('data-search') || '').indexOf(q) !== -1;
    var st = row.getAttribute('data-status');
    var okS = !sf || (sf === 'running' ? st === 'running' : st !== 'running');
    var show = okQ && okS;
    row.style.display = show ? '' : 'none';
    if (show) n++;
  });
  var cnt = document.getElementById('clientCount');
  if (cnt) cnt.textContent = n + ' dealer' + (n === 1 ? '' : 's');
}
document.addEventListener('DOMContentLoaded', function () {
  var sf = document.getElementById('statusFilter');
  if (sf) sf.value = '';
  filterClientTable();
});
</script>

<?php render_foot(); ?>
