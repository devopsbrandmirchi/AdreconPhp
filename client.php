<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/scheduler.php';
require __DIR__ . '/inc/layout.php';

$user = require_login();
reap_stale_runs();
expire_finished_trackers();

$id = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT c.*, a.name AS agency_name FROM clients c
     LEFT JOIN agencies a ON a.id = c.agency_id WHERE c.id = ?'
);
$stmt->execute([$id]);
$client = $stmt->fetch();

if (!$client) {
    http_response_code(404);
    exit('That client does not exist.');
}
if (!can_see_client($id)) {
    http_response_code(403);
    exit('You do not have access to this client.');
}

$siteStmt = db()->prepare(
    "SELECT s.*,
       (SELECT COUNT(*) FROM trackers t WHERE t.site_id = s.id) AS schedules,
       (SELECT COUNT(*) FROM trackers t WHERE t.site_id = s.id AND t.status = 'active') AS running
     FROM sites s WHERE s.client_id = ? ORDER BY s.domain"
);
$siteStmt->execute([$id]);
$sites = $siteStmt->fetchAll();

$unassigned = (int)db()->query(
    'SELECT COUNT(*) FROM trackers WHERE client_id = ' . $id . ' AND site_id IS NULL'
)->fetchColumn();

// site=0 means the ones not attached to any site yet.
$siteFilter = isset($_GET['site']) ? (int)$_GET['site'] : -1;
$validSite  = $siteFilter > 0 && in_array($siteFilter, array_map('intval', array_column($sites, 'id')), true);
if ($siteFilter > 0 && !$validSite) {
    $siteFilter = -1;
}

$t = db()->prepare(
    "SELECT t.*,
        (SELECT top_count FROM runs r WHERE r.tracker_id = t.id
          AND r.status IN ('success','empty') ORDER BY r.started_at DESC LIMIT 1) AS last_top,
        (SELECT bottom_count FROM runs r WHERE r.tracker_id = t.id
          AND r.status IN ('success','empty') ORDER BY r.started_at DESC LIMIT 1) AS last_bottom
     FROM trackers t
     WHERE t.client_id = ?" .
     ($siteFilter > 0 ? ' AND t.site_id = ' . $siteFilter
      : ($siteFilter === 0 ? ' AND t.site_id IS NULL' : '')) .
     " ORDER BY t.cluster, t.keyword, t.location, t.device"
);
$t->execute([$id]);
$trackers = $t->fetchAll();

$siteName = [];
foreach ($sites as $sx) {
    $siteName[(int)$sx['id']] = $sx['domain'];
}

$active  = count(array_filter($trackers, fn($x) => $x['status'] === 'active'));
$withAds = $noAds = 0;
foreach ($trackers as $x) {
    if (!$x['last_run_at']) { continue; }
    ((int)$x['last_top'] + (int)$x['last_bottom'] > 0) ? $withAds++ : $noAds++;
}

// Who this client is up against, across every keyword.
$compStmt = db()->prepare(
    "SELECT p.domain,
            COUNT(DISTINCT p.tracker_id) AS on_keywords,
            SUM(p.block = 'top')    AS tops,
            SUM(p.block = 'bottom') AS bottoms
     FROM ad_placements p
     JOIN trackers t ON t.id = p.tracker_id
     WHERE t.client_id = ? AND p.block IN ('top','bottom')
       AND p.captured_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
     GROUP BY p.domain
     ORDER BY on_keywords DESC, tops DESC
     LIMIT 12"
);
$compStmt->execute([$id]);
$competitors = $compStmt->fetchAll();
$ownDomains  = array_map('strtolower', array_column($sites, 'domain'));

// Keyword groups, then cluster groups for the Clusters & terms tab.
$groups = [];
foreach ($trackers as $x) {
    $groups[$x['keyword']][] = $x;
}

$byCluster = [];
foreach ($groups as $keyword => $rows) {
    $label = cluster_label($rows[0]['cluster'] ?? '');
    $byCluster[$label][$keyword] = $rows;
}
if (!array_key_exists('No cluster', $byCluster)) {
    $byCluster['No cluster'] = [];
}
// Keep "No cluster" last.
uksort($byCluster, static function ($a, $b) {
    if ($a === 'No cluster') return 1;
    if ($b === 'No cluster') return -1;
    return strcasecmp($a, $b);
});

// Show-rate, open gaps, and always-on rivals (light compute from existing tables).
$checkedCount  = count(array_filter($trackers, fn($x) => !empty($x['last_run_at'])));
$ownShowCount  = 0;
$openGaps      = [];
$showRatePct   = null;
$trackerTotal  = max(1, count($trackers));

if ($checkedCount > 0 && $ownDomains) {
    $ownLower = array_map('strtolower', $ownDomains);
    $inOwn    = implode(',', array_fill(0, count($ownLower), '?'));
    $presenceSql = "SELECT t.id, t.keyword, t.location,
        (SELECT COUNT(*) FROM ad_placements p WHERE p.run_id = lr.id
         AND p.block IN ('top','bottom')) AS total_ads,
        (SELECT COUNT(*) FROM ad_placements p WHERE p.run_id = lr.id
         AND p.block IN ('top','bottom') AND LOWER(p.domain) IN ($inOwn)) AS own_ads,
        (SELECT COUNT(DISTINCT p.domain) FROM ad_placements p WHERE p.run_id = lr.id
         AND p.block IN ('top','bottom') AND LOWER(p.domain) NOT IN ($inOwn)) AS rival_count,
        (SELECT p.domain FROM ad_placements p WHERE p.run_id = lr.id
         AND p.block = 'top' AND LOWER(p.domain) NOT IN ($inOwn)
         ORDER BY p.position LIMIT 1) AS top_rival
    FROM trackers t
    INNER JOIN runs lr ON lr.id = (
        SELECT r.id FROM runs r WHERE r.tracker_id = t.id
        AND r.status IN ('success','empty') ORDER BY r.started_at DESC LIMIT 1
    )
    WHERE t.client_id = ? AND t.last_run_at IS NOT NULL";
    $presenceStmt = db()->prepare($presenceSql);
    $presenceStmt->execute(array_merge($ownLower, $ownLower, $ownLower, [$id]));
    foreach ($presenceStmt->fetchAll() as $row) {
        if ((int)$row['own_ads'] > 0) {
            $ownShowCount++;
        } elseif ((int)$row['rival_count'] > 0) {
            $openGaps[] = $row;
        }
    }
    usort($openGaps, fn($a, $b) => (int)$b['rival_count'] <=> (int)$a['rival_count']);
    $showRatePct = (int)round($ownShowCount / $checkedCount * 100);
}

$alwaysOnRivals = 0;
$rivalDenom     = $checkedCount > 0 ? $checkedCount : count($trackers);
foreach ($competitors as $cp) {
    if (in_array(strtolower($cp['domain']), $ownDomains, true)) {
        continue;
    }
    if ((int)$cp['on_keywords'] / max(1, $rivalDenom) * 100 > 75) {
        $alwaysOnRivals++;
    }
}
$openGapCount = count($openGaps);

$zoneList = ['America/New_York','America/Chicago','America/Denver','America/Los_Angeles',
             'America/Phoenix','America/Anchorage','Pacific/Honolulu','Europe/London',
             'Asia/Karachi','UTC'];

$lib = keyword_library();
$clusterNames = array_keys($lib);
$clusterNames[] = 'No cluster';

$tabRaw = $_GET['tab'] ?? '';
$tab = in_array($tabRaw, ['overview','keywords','clusters','competitors','websites','add'], true)
    ? $tabRaw : 'overview';
if ($tab === 'keywords') {
    $tab = 'clusters';
}

$showBuilder = $tab === 'add' || !$trackers;

render_head($client['name'], $user);

$crumbTrail = [
    ['Dealers', 'clients.php'],
    [$client['name'], $tab === 'add' ? 'client.php?id=' . $id : null],
];
if ($tab === 'add') {
    $crumbTrail[] = ['Add keywords', null];
}
echo crumbs($crumbTrail);
?>

<div class="pagehead">
  <div>
    <h1 class="page"><?= h($client['name']) ?></h1>
    <p class="sub">
      <?= count($trackers) ?> schedule<?= count($trackers) === 1 ? '' : 's' ?>
      across <?= count($groups) ?> search term<?= count($groups) === 1 ? '' : 's' ?><?php
      if ($active): ?>, <?= $active ?> running<?php endif; ?>.
    </p>
    <div class="chips" style="margin-top:12px">
      <?php if ($active): ?>
        <span class="chip green"><span class="dot" style="background:var(--good)"></span><?= $active ?> running</span>
      <?php endif; ?>
      <span class="chip"><?= count($byCluster) ?> cluster<?= count($byCluster) === 1 ? '' : 's' ?></span>
      <?php if ($competitors): ?>
        <span class="chip"><?= count($competitors) ?> competitors seen</span>
      <?php endif; ?>
      <?php foreach ($sites as $sx): ?>
        <span class="chip blue"><?= h($sx['domain']) ?></span>
      <?php endforeach; ?>
      <?php if (!$sites): ?><span class="chip amber">no website yet</span><?php endif; ?>
    </div>
  </div>
  <div class="acts">
    <form method="post" action="action.php">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="bulk"><input type="hidden" name="op" value="start">
      <input type="hidden" name="client_id" value="<?= $id ?>"><input type="hidden" name="scope" value="client">
      <button type="submit" class="btn btn-go">Start all</button>
    </form>
    <form method="post" action="action.php">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="bulk"><input type="hidden" name="op" value="stop">
      <input type="hidden" name="client_id" value="<?= $id ?>"><input type="hidden" name="scope" value="client">
      <button type="submit" class="btn btn-stop">Stop all</button>
    </form>
    <form method="post" action="action.php"
          onsubmit="return confirm('Check every keyword for this client right now? Each one uses a search from your plan.')">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="bulk"><input type="hidden" name="op" value="check">
      <input type="hidden" name="client_id" value="<?= $id ?>"><input type="hidden" name="scope" value="client">
      <button type="submit" class="btn">Check all</button>
    </form>
    <a class="btn dark" href="client.php?id=<?= $id ?>&amp;tab=add">+ Add keywords</a>
  </div>
</div>

<nav class="tabs">
  <a class="tab<?= $tab === 'overview' ? ' active on' : '' ?>" href="client.php?id=<?= $id ?>">Overview</a>
  <a class="tab<?= $tab === 'clusters' ? ' active on' : '' ?>" href="client.php?id=<?= $id ?>&amp;tab=clusters">
    Clusters &amp; terms <span class="badge"><?= count($groups) ?></span></a>
  <a class="tab<?= $tab === 'competitors' ? ' active on' : '' ?>" href="client.php?id=<?= $id ?>&amp;tab=competitors">
    Competitors<?php if ($competitors): ?> <span class="badge"><?= count($competitors) ?></span><?php endif; ?></a>
  <a class="tab<?= $tab === 'websites' ? ' active on' : '' ?>" href="client.php?id=<?= $id ?>&amp;tab=websites">
    Websites<?php if ($sites): ?> <span class="badge"><?= count($sites) ?></span><?php endif; ?></a>
  <a class="tab<?= $tab === 'add' ? ' active on' : '' ?>" href="client.php?id=<?= $id ?>&amp;tab=add">Add keywords</a>
</nav>

<?php if (!$sites && $trackers && $tab !== 'add'): ?>
  <div class="notice">
    <strong>No website added for this client.</strong>
    Every keyword here is unfiled, so none of them highlight the client's own ads.
    <a href="client.php?id=<?= $id ?>&amp;tab=websites">Add their website</a> and the keywords can be filed under it.
  </div>
<?php endif; ?>


<?php /* ---------------- OVERVIEW ---------------- */ ?>
<?php if ($tab === 'overview'): ?>

  <?php if (!$trackers): ?>
    <div class="card pad">
      <p style="margin:0;color:var(--ink-2)">
        No keywords tracked yet.
        <a href="client.php?id=<?= $id ?>&amp;tab=add">Add the first ones with One-Click Spy</a><?php
        if (!$sites): ?>, and add this client's website under the Websites tab so their own ads get highlighted<?php endif; ?>.
      </p>
    </div>
  <?php else: ?>

    <div class="stat-row" style="margin-bottom:20px">
      <div class="stat">
        <div class="k">Your show-rate</div>
        <div class="v blue"><?php
          if ($showRatePct !== null): ?><?= $showRatePct ?>%<?php
          elseif (!$ownDomains): ?>—<?php
          else: ?>0%<?php endif; ?></div>
        <div class="foot"><?php if ($showRatePct !== null): ?>
          you appear in <?= $ownShowCount ?> of <?= $checkedCount ?> tracked checks<?php
          elseif (!$ownDomains): ?>add a website to measure your presence<?php
          else: ?>no checks with your domain yet<?php endif; ?></div>
      </div>
      <div class="stat">
        <div class="k">Competitors seen</div>
        <div class="v"><?= count($competitors) ?></div>
        <div class="foot">unique advertisers across all terms</div>
      </div>
      <div class="stat">
        <div class="k">Always-on rivals</div>
        <div class="v amber"><?= $alwaysOnRivals ?></div>
        <div class="foot">present in &gt;75% of checks</div>
      </div>
      <div class="stat">
        <div class="k">Open gaps</div>
        <div class="v" style="color:var(--critical)"><?= $openGapCount ?></div>
        <div class="foot">term × area where rivals show, you don't</div>
      </div>
    </div>

    <div class="grid" style="grid-template-columns:1.4fr 1fr;align-items:start">
      <div class="card pad">
        <div class="section-t">Top competitors by coverage</div>
        <div class="section-d">Share of this client's keywords each advertiser turned up on, last 30 days. Blue = top of page, amber = bottom.</div>
        <?php if (!$competitors): ?>
          <p class="empty">No ads recorded yet. Start some keywords and check back.</p>
        <?php else: ?>
        <table class="lb">
          <thead>
            <tr>
              <th>Advertiser</th>
              <th style="width:180px">Coverage</th>
              <th>Consistency</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_slice($competitors, 0, 6) as $cp):
              $pct   = min(100, (int)round((int)$cp['on_keywords'] / $trackerTotal * 100));
              $tops  = (int)$cp['tops'];
              $bots  = (int)$cp['bottoms'];
              $sum   = max(1, $tops + $bots);
              $topW  = (int)round($tops / $sum * $pct);
              $botW  = (int)round($bots / $sum * $pct);
              $isOwn = in_array(strtolower($cp['domain']), $ownDomains, true);
            ?>
            <tr>
              <td class="domain"><?= h($cp['domain']) ?><?php if ($isOwn): ?> <span class="you">YOU</span><?php endif; ?></td>
              <td>
                <div class="bar">
                  <?php if ($topW > 0): ?><i class="top" style="width:<?= $topW ?>%"></i><?php endif; ?>
                  <?php if ($botW > 0): ?><i class="bot" style="left:<?= $topW ?>%;width:<?= $botW ?>%"></i><?php endif; ?>
                </div>
              </td>
              <td><?= consistency_chip((float)$pct) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (count($competitors) > 6): ?>
          <div style="margin-top:12px">
            <a href="client.php?id=<?= $id ?>&amp;tab=competitors">See all <?= count($competitors) ?> competitors →</a>
          </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>

      <div class="card pad">
        <div class="section-t">Biggest openings</div>
        <div class="section-d">Where rivals advertise and you're not observed — your best places to win.</div>
        <?php if (!$openGaps): ?>
          <p class="empty" style="padding:8px 0"><?php
            if (!$ownDomains): ?>Add a website under Websites to spot gaps against your domain.<?php
            elseif ($checkedCount === 0): ?>Run a check on your keywords to see where rivals show without you.<?php
            else: ?>No clear gaps at the last check — rivals aren't winning spots you're missing.<?php endif; ?></p>
        <?php else: ?>
          <?php foreach (array_slice($openGaps, 0, 4) as $g):
            $rivals = (int)$g['rival_count'];
            $icoCls = $rivals >= 3 ? 'red' : 'amber';
          ?>
          <div class="gap-item">
            <div class="gap-ico <?= $icoCls ?>">◎</div>
            <div>
              <div style="font-weight:600">&ldquo;<?= h($g['keyword']) ?>&rdquo; · <?= h($g['location']) ?></div>
              <div style="font-size:12px;color:var(--muted)"><?= $rivals ?> rival<?= $rivals === 1 ? '' : 's' ?> show here — you're absent at last check<?php
                if (!empty($g['top_rival'])): ?> · <?= h($g['top_rival']) ?> owns top<?php endif; ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

  <?php endif; ?>

<?php endif; ?>


<?php /* ---------------- CLUSTERS & TERMS ---------------- */ ?>
<?php if ($tab === 'clusters'): ?>

  <?php if ($sites || $unassigned): ?>
  <div class="sitebar">
    <a class="site-tab<?= $siteFilter === -1 ? ' on' : '' ?>" href="client.php?id=<?= $id ?>&amp;tab=clusters">
      All websites <span class="site-n"><?= array_sum(array_column($sites, 'schedules')) + $unassigned ?></span>
    </a>
    <?php foreach ($sites as $sx): ?>
      <a class="site-tab<?= $siteFilter === (int)$sx['id'] ? ' on' : '' ?>"
         href="client.php?id=<?= $id ?>&amp;tab=clusters&amp;site=<?= (int)$sx['id'] ?>">
        <?= h($sx['domain']) ?> <span class="site-n"><?= (int)$sx['schedules'] ?></span>
      </a>
    <?php endforeach; ?>
    <?php if ($unassigned > 0): ?>
      <a class="site-tab<?= $siteFilter === 0 ? ' on' : '' ?>"
         href="client.php?id=<?= $id ?>&amp;tab=clusters&amp;site=0">
        No website <span class="site-n"><?= $unassigned ?></span>
      </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
    <div>
      <div class="section-t">Search terms, grouped by intent cluster</div>
      <div class="section-d" style="margin:0">Terms with the same buyer intent sit together. Terms without a cluster land in No cluster.</div>
    </div>
    <a class="btn dark" href="client.php?id=<?= $id ?>&amp;tab=add">+ Add keywords</a>
  </div>

  <?php if (!$trackers && empty(array_filter($byCluster))): ?>
    <div class="card pad">
      <p style="margin:0;color:var(--ink-2)">Nothing here yet. <a href="client.php?id=<?= $id ?>&amp;tab=add">Add keywords</a> to start tracking.</p>
    </div>
  <?php else: ?>
  <form method="post" action="action.php" id="bulkform">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="bulk">
    <input type="hidden" name="op" value="" id="bulkop">
    <input type="hidden" name="client_id" value="<?= $id ?>">

    <div class="bulkbar" id="bulkbar" hidden>
      <span class="bulkcount"><span id="bulkn">0</span> selected</span>
      <button type="button" onclick="runBulk('start')" class="btn-go">Start</button>
      <button type="button" onclick="runBulk('stop')" class="btn-stop">Stop</button>
      <button type="button" onclick="runBulk('check')">Check now</button>
      <button type="button" onclick="runBulk('delete')" class="btn-danger">Delete</button>
      <select id="newinterval" onchange="changeInterval(this)" aria-label="Change how often the selected keywords are checked">
        <option value="">Check every&hellip;</option>
        <?php foreach (ALLOWED_INTERVALS as $ivv): ?>
          <option value="<?= $ivv ?>"><?= h(interval_label($ivv)) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="interval_hours" id="bulkinterval" value="">
      <select id="newduration" onchange="changeDuration(this)" aria-label="Change how long the selected keywords keep running">
        <option value="">Stop after&hellip;</option>
        <option value="1">1 day</option><option value="3">3 days</option>
        <option value="7">1 week</option><option value="14">2 weeks</option>
        <option value="30">30 days</option><option value="60">60 days (max)</option>
      </select>
      <input type="hidden" name="duration_days" id="bulkduration" value="">
      <?php if ($sites): ?>
        <select id="movesite" onchange="moveToSite(this)" aria-label="Move the selected keywords to a website">
          <option value="">Move to website&hellip;</option>
          <?php foreach ($sites as $sx): ?>
            <option value="<?= (int)$sx['id'] ?>"><?= h($sx['domain']) ?></option>
          <?php endforeach; ?>
          <option value="0">No website</option>
        </select>
        <input type="hidden" name="site_id" id="bulksite" value="">
      <?php endif; ?>
      <button type="button" onclick="clearSel()" style="margin-left:auto">Clear</button>
    </div>

    <?php $ci = 0; foreach ($byCluster as $clusterName => $kwGroups): $ci++;
      $schedCount = array_sum(array_map('count', $kwGroups));
      $termCount  = count($kwGroups);
    ?>
    <div class="card cluster<?= $ci === 1 ? ' open' : '' ?>">
      <div class="ch" onclick="toggleCluster(this.parentElement)">
        <span class="caret">▶</span>
        <div>
          <span class="cname"<?= $clusterName === 'No cluster' ? ' style="color:var(--muted)"' : '' ?>><?= h($clusterName) ?></span>
          &nbsp; <span class="chip blue" style="vertical-align:middle"><?= $termCount ?> term<?= $termCount === 1 ? '' : 's' ?> · <?= $schedCount ?> check<?= $schedCount === 1 ? '' : 's' ?></span>
        </div>
        <?php if ($clusterName === 'No cluster' && !$termCount): ?>
          <div class="cmeta spacer" style="margin-left:auto">Ungrouped terms land here</div>
        <?php endif; ?>
      </div>
      <div class="cbody">
        <?php if (!$termCount): ?>
          <div class="kw" style="color:var(--muted)">
            <span>No ungrouped terms. Terms added without a cluster show up here and can be filed into one later.</span>
          </div>
        <?php else: ?>
          <?php foreach ($kwGroups as $keyword => $rows):
            $nLoc     = count($rows);
            $devices  = array_unique(array_column($rows, 'device'));
            $device   = count($devices) === 1 ? (string)$devices[0] : 'Mixed';
            $intervals = array_unique(array_map(fn($r) => (int)$r['interval_hours'], $rows));
            if (count($intervals) === 1) {
                $ivLabel = (int)$intervals[0] === ONCE ? 'once' : 'every ' . interval_phrase((int)$intervals[0]);
            } else {
                $ivLabel = 'mixed cadence';
            }
            $sumTop   = array_sum(array_map(fn($r) => (int)$r['last_top'], $rows));
            $sumBot   = array_sum(array_map(fn($r) => (int)$r['last_bottom'], $rows));
            $checked  = count(array_filter($rows, fn($r) => !empty($r['last_run_at'])));
            if ($checked === 0) {
                $adsCls = 'chip'; $adsTxt = 'Not checked yet';
            } elseif ($sumTop + $sumBot === 0) {
                $adsCls = 'chip'; $adsTxt = 'No ads';
            } else {
                $adsCls = 'chip green';
                $adsTxt = $sumTop . ' top, ' . $sumBot . ' bottom';
            }
            $firstId = (int)$rows[0]['id'];
          ?>
          <div class="kw">
            <span class="kwn"><?= h($keyword) ?></span>
            <span class="locs"><?= $nLoc ?> location<?= $nLoc === 1 ? '' : 's' ?> · <?= h($device) ?> · <?= h($ivLabel) ?></span>
            <span class="<?= $adsCls ?> spacer"><?= h($adsTxt) ?></span>
            <a href="tracker.php?id=<?= $firstId ?>">See results →</a>
          </div>
          <details class="kw-manage" style="border-bottom:1px solid var(--line);padding:0 18px 10px 40px">
            <summary style="cursor:pointer;font-size:12px;color:var(--blue);font-weight:600;padding:4px 0">Manage schedules (<?= $nLoc ?> location<?= $nLoc === 1 ? '' : 's' ?>)</summary>
            <div class="group" style="border:0;border-radius:0;box-shadow:none;margin:8px 0 0">
              <div class="group-head">
                <input type="checkbox" class="selgroup" onchange="toggleGroup(this)"
                       aria-label="Select every location for <?= h($keyword) ?>">
                <span class="group-title"><?= h($keyword) ?></span>
                <span class="group-count">select all <?= $nLoc ?> location<?= $nLoc === 1 ? '' : 's' ?></span>
              </div>
              <?php foreach ($rows as $x): ?>
              <div class="row-sel">
                <input type="checkbox" class="selrow" name="ids[]" value="<?= (int)$x['id'] ?>"
                       onchange="syncSel()" aria-label="Select <?= h($x['location']) ?>">
                <span class="row-loc">
                  <a href="tracker.php?id=<?= (int)$x['id'] ?>" class="loc"><?= h($x['location']) ?></a>
                  <span class="loc-meta"><?= h($x['device']) ?><?php
                    if ($siteFilter === -1 && !empty($x['site_id']) && isset($siteName[(int)$x['site_id']])) {
                        echo ' · <span class="site-chip">' . h($siteName[(int)$x['site_id']]) . '</span>';
                    } elseif ($siteFilter === -1 && empty($x['site_id']) && $sites) {
                        echo ' · <span class="site-chip site-chip-none">no website</span>';
                    }
                  ?></span>
                </span>
                <span class="cell-num"><?= (int)$x['interval_hours'] === ONCE ? 'once' : h(interval_label((int)$x['interval_hours'])) ?></span>
                <span style="text-align:right">
                  <?php
                    $tp = (int)$x['last_top']; $bt = (int)$x['last_bottom'];
                    if (!$x['last_run_at'])  { $cls = 'chip'; $txt = 'Not checked yet'; }
                    elseif ($tp + $bt === 0) { $cls = 'chip'; $txt = 'No ads'; }
                    else { $cls = 'chip green'; $txt = $tp . ' top, ' . $bt . ' bottom'; }
                  ?>
                  <span class="<?= $cls ?>" data-live-tracker="<?= (int)$x['id'] ?>"><?= h($txt) ?></span>
                </span>
                <span class="cell-num">
                  <?= $x['status'] === 'active' ? h(relative_time($x['next_run_at'])) : '' ?>
                  <?php if ($x['status'] !== 'active'): ?><?= status_pill($x['status']) ?><?php endif; ?>
                </span>
                <span class="cell-num">
                  <?php if ($x['status'] === 'active' && $x['runs_until']):
                      $left = strtotime($x['runs_until']) - time();
                      $d    = (int)floor($left / 86400); ?>
                    <span class="<?= $left < 2 * 86400 ? 'ends-soon' : 'ends' ?>"><?= $d >= 1 ? 'in ' . $d . 'd' : 'today' ?></span>
                  <?php elseif ($x['status'] !== 'expired'): ?>
                    <span class="ends"><?= (int)$x['duration_days'] ?>d</span>
                  <?php endif; ?>
                </span>
                <a class="row-go" href="tracker.php?id=<?= (int)$x['id'] ?>"
                   aria-label="See results for <?= h($x['location']) ?>">&rsaquo;</a>
              </div>
              <?php endforeach; ?>
            </div>
          </details>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </form>
  <?php endif; ?>

<?php endif; ?>


<?php /* ---------------- COMPETITORS ---------------- */ ?>
<?php if ($tab === 'competitors'): ?>

  <div class="card pad" style="margin-bottom:16px">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
      <div>
        <div class="section-t">Your domain <span class="chip red" style="vertical-align:middle">Required</span></div>
        <div class="section-d" style="margin:0">This is the &ldquo;you&rdquo; every metric is measured against. File the client's websites so their ads get highlighted.</div>
      </div>
      <div style="text-align:right">
        <?php if ($sites): ?>
          <div style="font-weight:700;font-size:15px;color:var(--blue)">
            <?= h($sites[0]['domain']) ?>
            <span class="chip green" style="vertical-align:middle">✓ set</span>
          </div>
          <?php if (count($sites) > 1): ?>
            <div style="font-size:12px;color:var(--muted);margin-top:3px">also: <?= h(implode(' · ', array_slice(array_column($sites, 'domain'), 1))) ?></div>
          <?php endif; ?>
        <?php else: ?>
          <div style="font-weight:600;color:var(--muted)">Not set yet</div>
          <div style="font-size:12px;margin-top:4px"><a href="client.php?id=<?= $id ?>&amp;tab=websites">Add a website →</a></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="section-t">Full advertiser leaderboard<?php if ($competitors): ?> <span class="chip" style="vertical-align:middle"><?= count($competitors) ?> seen</span><?php endif; ?></div>
  <div class="section-d">Everyone observed across all clusters and locations in the last 30 days. Blue = top of page, amber = bottom.</div>

  <div class="card pad">
    <?php if (!$competitors): ?>
      <p class="empty">No ads recorded yet. Start some keywords and check back.</p>
    <?php else: ?>
    <table class="lb">
      <thead>
        <tr>
          <th>Advertiser</th>
          <th style="width:200px">Coverage (30d)</th>
          <th>Consistency</th>
          <th style="text-align:center">Top</th>
          <th style="text-align:center">Bottom</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($competitors as $cp):
          $pct   = min(100, (int)round((int)$cp['on_keywords'] / $trackerTotal * 100));
          $tops  = (int)$cp['tops'];
          $bots  = (int)$cp['bottoms'];
          $sum   = max(1, $tops + $bots);
          $topW  = (int)round($tops / $sum * $pct);
          $botW  = (int)round($bots / $sum * $pct);
          $isOwn = in_array(strtolower($cp['domain']), $ownDomains, true);
        ?>
        <tr>
          <td class="domain"><?= h($cp['domain']) ?><?php if ($isOwn): ?> <span class="you">YOU</span><?php endif; ?></td>
          <td>
            <div class="bar">
              <?php if ($topW > 0): ?><i class="top" style="width:<?= $topW ?>%"></i><?php endif; ?>
              <?php if ($botW > 0): ?><i class="bot" style="left:<?= $topW ?>%;width:<?= $botW ?>%"></i><?php endif; ?>
            </div>
          </td>
          <td><?= consistency_chip((float)$pct) ?></td>
          <td style="text-align:center" class="tabular"><?= $tops ?></td>
          <td style="text-align:center" class="tabular"><?= $bots ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php if (!$sites): ?>
    <p style="font-size:13px;color:var(--ink-2);margin-top:14px">
      <a href="client.php?id=<?= $id ?>&amp;tab=websites">Add a website</a> so your own domains are marked YOU in this table.
    </p>
  <?php endif; ?>

<?php endif; ?>


<?php /* ---------------- WEBSITES ---------------- */ ?>
<?php if ($tab === 'websites'): ?>

  <div class="section-t">Websites</div>
  <div class="section-d">Every domain you add is used to highlight this client's own ads in reports.</div>

  <?php if ($sites): ?>
  <div class="card pad" style="margin-bottom:16px">
    <?php foreach ($sites as $sx): ?>
    <div class="row" style="display:grid;grid-template-columns:1fr 1fr 170px auto;gap:16px;align-items:center;padding:12px 0;border-bottom:1px solid var(--line)">
      <div style="font-size:13.5px;font-weight:600"><?= h($sx['domain']) ?></div>
      <div style="font-size:13px;color:var(--ink-2)"><?= h((string)$sx['label']) ?></div>
      <div style="font-size:13px;color:var(--muted)">
        <?= (int)$sx['schedules'] ?> keyword<?= (int)$sx['schedules'] === 1 ? '' : 's' ?>,
        <?= (int)$sx['running'] ?> running
      </div>
      <div class="acts">
        <a class="btn" href="client.php?id=<?= $id ?>&amp;tab=clusters&amp;site=<?= (int)$sx['id'] ?>">View</a>
        <form method="post" action="action.php"
              onsubmit="return confirm('Remove this website? Its <?= (int)$sx['schedules'] ?> keyword(s) stay with the client, they just stop being filed under it.')">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="site_delete">
          <input type="hidden" name="site_id" value="<?= (int)$sx['id'] ?>">
          <input type="hidden" name="client_id" value="<?= $id ?>">
          <button type="submit" class="btn btn-danger">Remove</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="card pad">
    <div class="section-t">Add a website</div>
    <form method="post" action="action.php" class="formrow" style="margin-top:12px">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="site_create">
      <input type="hidden" name="client_id" value="<?= $id ?>">
      <div>
        <label for="sd">Domain</label>
        <input type="text" id="sd" name="domain" class="input" placeholder="theirdomain.com" required>
      </div>
      <div>
        <label for="sl">Label (optional)</label>
        <input type="text" id="sl" name="label" class="input" placeholder="Powersports store">
      </div>
      <div><button type="submit" class="btn primary">Add website</button></div>
    </form>
    <?php if (!$sites): ?>
      <p class="hint" style="margin-top:12px;font-size:12px;color:var(--muted)">
        A client can have as many websites as it needs. Add them here, then file each keyword under the one it belongs to.
      </p>
    <?php endif; ?>
  </div>

<?php endif; ?>


<?php /* ---------------- ADD KEYWORDS BUILDER ---------------- */ ?>
<?php if ($showBuilder && $tab === 'add'): ?>

<h1 class="page">Add keywords</h1>
<div class="sub">Pick from your library or add your own, choose where to track them — Adrecon checks every keyword in every location for you.</div>

<div class="spy-banner">
  <span class="spy-ico">🔍</span>
  <b>One-Click Spy</b>
  <input type="text" class="input" id="spyInput" style="flex:1;min-width:220px"
         placeholder="…or paste a business name / website and we auto-build all of this for you"
         aria-label="Business name or website">
  <button type="button" class="btn primary" id="spyBtn" onclick="revealSpyManual()">Spy &amp; auto-fill</button>
</div>
<p class="hint" id="spyNote" style="margin:-12px 0 18px;display:none">
  Auto-fill needs an LLM key (coming soon). The manual builder below is ready — pick keywords and locations now.
</p>

<form method="post" action="action.php" id="builderForm" data-builder="1">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="bulk_create">
  <input type="hidden" name="client_id" value="<?= $id ?>">
  <textarea name="keywords" id="kws" class="hidden" aria-hidden="true"></textarea>
  <textarea name="clusters" id="kwClusters" class="hidden" aria-hidden="true"></textarea>
  <textarea name="locations" id="locs" class="hidden" aria-hidden="true"></textarea>

  <div class="builder-grid">
    <div class="builder-stack">

      <div class="card pad">
        <div class="step-h">
          <span class="step-n">1</span>
          <div>
            <div class="section-t">Choose keywords</div>
            <div class="section-d" style="margin:0">From your saved library, or type your own.</div>
          </div>
        </div>
        <div class="seg">
          <button type="button" class="seg-btn active" id="segLib" onclick="kwMode('lib')">From library</button>
          <button type="button" class="seg-btn" id="segOwn" onclick="kwMode('own')">Add your own</button>
        </div>
        <div id="kwLib">
          <div class="lib-clusters" id="libClusters">
            <button type="button" class="lib-cl active" data-cluster="__all" onclick="filterLib(this)">All</button>
            <?php foreach ($lib as $cname => $_terms): ?>
              <button type="button" class="lib-cl" data-cluster="<?= h($cname) ?>" onclick="filterLib(this)"><?= h($cname) ?></button>
            <?php endforeach; ?>
          </div>
          <div id="libList">
            <?php foreach ($lib as $cname => $terms):
              foreach ($terms as $term): ?>
              <label class="libitem" data-cluster="<?= h($cname) ?>">
                <input type="checkbox" data-term="<?= h($term) ?>" data-cluster="<?= h($cname) ?>" onchange="toggleLib(this)">
                <span><?= h($term) ?></span>
                <span class="added" hidden>✓ added</span>
              </label>
            <?php endforeach; endforeach; ?>
          </div>
        </div>
        <div id="kwOwn" class="hidden">
          <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <div class="field" style="flex:1;min-width:200px;margin:0">
              <label for="ownTerm">Your keyword</label>
              <input type="text" class="input" id="ownTerm" placeholder="e.g. polaris snowmobile dealer"
                     onkeydown="if(event.key==='Enter'){event.preventDefault();addOwn();}">
            </div>
            <div class="field" style="min-width:160px;margin:0">
              <label for="ownCluster">Put it in a cluster</label>
              <select class="input" id="ownCluster">
                <?php foreach ($clusterNames as $cn): ?>
                  <option value="<?= h($cn === 'No cluster' ? '' : $cn) ?>"><?= h($cn) ?></option>
                <?php endforeach; ?>
                <option value="__new">+ New cluster…</option>
              </select>
            </div>
            <button type="button" class="btn dark" onclick="addOwn()">Add</button>
          </div>
        </div>
      </div>

      <div class="card pad">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <div class="section-t">Selected keywords <span class="badge" id="kwCount">0</span></div>
          <a href="#" onclick="clearKw();return false;" style="font-size:12px">Clear all</a>
        </div>
        <div id="selKw" class="chipwrap" style="margin-top:12px"></div>
      </div>

      <div class="card pad">
        <div class="step-h">
          <span class="step-n">2</span>
          <div>
            <div class="section-t">Where to track</div>
            <div class="section-d" style="margin:0">The same keywords are checked in each location you add.</div>
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
          <div class="field" style="flex:1;min-width:220px;margin:0;position:relative">
            <label for="loclookup">Add a location</label>
            <input type="text" class="input" id="loclookup" data-autocomplete="location"
                   placeholder="City, county or ZIP — e.g. Bartow, FL"
                   aria-label="Search for a location">
          </div>
          <button type="button" class="btn" onclick="addLocFromLookup()">Add</button>
          <button type="button" class="btn" onclick="addNearby()">Add 4 nearby towns</button>
        </div>
        <div id="selLoc" class="chipwrap" style="margin-top:14px"></div>
        <p class="hint" style="font-size:11.5px;color:var(--muted);margin-top:10px">Cities, counties, ZIP codes and TV regions. Pick from the search box to add one.</p>
      </div>

      <div class="card pad">
        <div class="step-h">
          <span class="step-n">3</span>
          <div>
            <div class="section-t">How often</div>
            <div class="section-d" style="margin:0">Each schedule starts stopped until you press Start.</div>
          </div>
        </div>
        <div class="row3">
          <div class="field" style="margin:0">
            <label for="iv">Check every</label>
            <select class="input" id="iv" name="interval_hours" onchange="renderPreview()">
              <?php foreach (ALLOWED_INTERVALS as $ivv): if ($ivv === ONCE) continue; ?>
                <option value="<?= $ivv ?>" <?= $ivv === 6 ? 'selected' : '' ?>><?= h(interval_label($ivv)) ?></option>
              <?php endforeach; ?>
              <option value="0">Once</option>
            </select>
          </div>
          <div class="field" style="margin:0">
            <label for="dev">Device</label>
            <select class="input" id="dev" name="device" onchange="renderPreview()">
              <option value="desktop">Desktop</option>
              <option value="mobile">Mobile</option>
              <option value="both">Desktop and mobile</option>
            </select>
          </div>
          <div class="field" style="margin:0">
            <label for="dur">Run for</label>
            <div class="withsuffix">
              <input type="number" class="input" id="dur" name="duration_days" min="1" max="<?= MAX_DURATION_DAYS ?>"
                     value="<?= DEFAULT_DURATION_DAYS ?>" required onchange="renderPreview()">
              <span>days</span>
            </div>
          </div>
        </div>
        <div class="formrow" style="margin-top:14px">
          <?php if ($sites): ?>
          <div>
            <label for="sid">File under</label>
            <select class="input" id="sid" name="site_id">
              <?php foreach ($sites as $sx): ?>
                <option value="<?= (int)$sx['id'] ?>" <?= $siteFilter === (int)$sx['id'] ? 'selected' : '' ?>>
                  <?= h($sx['domain']) ?></option>
              <?php endforeach; ?>
              <option value="0">No website</option>
            </select>
          </div>
          <?php endif; ?>
          <div>
            <label for="tzz">Timezone</label>
            <select class="input" id="tzz" name="timezone">
              <?php foreach ($zoneList as $z): ?>
                <option value="<?= h($z) ?>" <?= $z === cfg('default_timezone') ? 'selected' : '' ?>><?= h($z) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <details class="advanced">
          <summary>Mark different domains as theirs</summary>
          <div class="advanced-body">
            <input type="text" class="input" name="watch_domains"
                   value="<?= h($sites ? implode(', ', array_column($sites, 'domain')) : (string)$client['domains']) ?>"
                   placeholder="theirdomain.com, anotherdomain.com" aria-label="Domains to mark as theirs">
            <p class="hint">Highlighted as theirs in every report.</p>
          </div>
        </details>
      </div>
    </div>

    <div class="card pad builder-summary" style="position:sticky;top:76px">
      <div class="section-t">You're about to add</div>
      <div class="sum-big" id="sumChecks">0</div>
      <div class="sum-eq" id="sumEq">checks per run — pick keywords &amp; locations</div>
      <div class="sum-rows">
        <div><span>Keywords</span><b id="sumKw">0</b></div>
        <div><span>Locations</span><b id="sumLoc">0</b></div>
        <div><span>Frequency</span><b id="sumFreq">6 hours</b></div>
        <div><span>Searches / month</span><b id="sumMo" class="tabular">0</b></div>
      </div>
      <div class="usage-note" id="sumFit">Pick some keywords and locations to see the plan.</div>
      <button class="btn primary" type="submit" style="width:100%;justify-content:center;margin-top:14px;padding:11px"
              onclick="return prepareBuilderSubmit()">Add to tracking</button>
      <div style="font-size:11px;color:var(--muted);margin-top:10px;line-height:1.5">
        Every keyword × every location becomes its own schedule. They start stopped.
      </div>
    </div>
  </div>
</form>

<?php endif; ?>

<script>
window.TOGGLE_CLUSTER = true;
function toggleCluster(el) {
  if (!el) return;
  el.classList.toggle('open');
}
</script>
<?php render_foot(); ?>
