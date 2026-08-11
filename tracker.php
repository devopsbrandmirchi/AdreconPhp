<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/scheduler.php';
require __DIR__ . '/inc/layout.php';

$user = require_login();
reap_stale_runs();
expire_finished_trackers();
$id   = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM trackers WHERE id = ?');
$stmt->execute([$id]);
$t = $stmt->fetch();
if (!$t) {
    http_response_code(404);
    exit('That schedule does not exist.');
}
if (!can_see_tracker($t)) {
    http_response_code(403);
    exit('You do not have access to this schedule.');
}

$tz    = $t['timezone'];
$watch = array_filter(array_map('trim', explode(',', (string)$t['watch_domains'])));

// Same keyword and location on the other device, if it exists.
$sibStmt = db()->prepare(
    'SELECT id, device FROM trackers WHERE keyword = ? AND location = ? AND device <> ? LIMIT 1'
);
$sibStmt->execute([$t['keyword'], $t['location'], $t['device']]);
$sibling = $sibStmt->fetch() ?: null;

// Reporting window for the consistency table.
$ranges = ['24h' => 1, '7d' => 7, '30d' => 30, 'all' => 0];
$range  = (string)($_GET['range'] ?? '7d');
if (!isset($ranges[$range])) {
    $range = '7d';
}
$since = $ranges[$range] > 0
    ? gmdate('Y-m-d H:i:s', time() - $ranges[$range] * 86400)
    : '1970-01-01 00:00:00';

$totalStmt = db()->prepare(
    "SELECT COUNT(*) FROM runs
     WHERE tracker_id = ? AND status IN ('success','empty') AND started_at >= ?"
);
$totalStmt->execute([$id, $since]);
$totalChecks = (int)$totalStmt->fetchColumn();

$consStmt = db()->prepare(
    "SELECT domain,
            COUNT(DISTINCT run_id) AS seen,
            SUM(block = 'top')    AS top_hits,
            SUM(block = 'bottom') AS bottom_hits,
            AVG(CASE WHEN block = 'top' THEN position END) AS avg_top_pos,
            MIN(captured_at) AS first_seen,
            MAX(captured_at) AS last_seen
     FROM ad_placements
     WHERE tracker_id = ? AND captured_at >= ? AND block IN ('top','bottom')
     GROUP BY domain
     ORDER BY seen DESC, top_hits DESC, domain ASC"
);
$consStmt->execute([$id, $since]);
$consistency = $consStmt->fetchAll();

// Latest completed run and its placements.
$lastRun = db()->prepare(
    "SELECT * FROM runs WHERE tracker_id = ? AND status IN ('success','empty')
     ORDER BY started_at DESC LIMIT 1"
);
$lastRun->execute([$id]);
$run = $lastRun->fetch();

$top = $bottom = $local = $other = [];
if ($run) {
    $p = db()->prepare('SELECT * FROM ad_placements WHERE run_id = ? ORDER BY block, position');
    $p->execute([$run['id']]);
    foreach ($p->fetchAll() as $a) {
        if ($a['block'] === 'top') {
            $top[] = $a;
        } elseif ($a['block'] === 'bottom') {
            $bottom[] = $a;
        } elseif ($a['block'] === 'local') {
            $local[] = $a;
        } else {
            // middle and right rail: recorded, but not top of page
            $other[] = $a;
        }
    }
}

$diff = diff_last_runs($id);

// Verify the last run actually targeted the location that was asked for.
// Wrong market data looks completely normal, so it has to be surfaced loudly.
$locCheck = null;
if ($run && $run['raw_json']) {
    $decoded = json_decode($run['raw_json'], true);
    if (is_array($decoded)) {
        $locCheck = provider_verify_location($decoded, (string)$t['location']);
    }
}

// Presence grid: last 16 completed runs.
$gridRuns = db()->prepare(
    "SELECT id, started_at FROM runs WHERE tracker_id = ? AND status IN ('success','empty')
     ORDER BY started_at DESC LIMIT 16"
);
$gridRuns->execute([$id]);
$gr = array_reverse($gridRuns->fetchAll());

$matrix = [];
if ($gr) {
    $runIds = array_column($gr, 'id');
    $in     = implode(',', array_fill(0, count($runIds), '?'));
    $q      = db()->prepare("SELECT run_id, domain, block FROM ad_placements
                             WHERE run_id IN ($in) AND block IN ('top','bottom')");
    $q->execute($runIds);
    foreach ($q->fetchAll() as $r) {
        $matrix[$r['domain']][$r['run_id']] = $r['block'];
    }
    uasort($matrix, fn($a, $b) => count($b) <=> count($a));
}

$rtab = in_array($_GET['tab'] ?? '', ['presence','coverage','log'], true) ? $_GET['tab'] : 'snapshot';

render_head($t['keyword'], $user);
?>

<?php
$parent = db()->prepare(
    'SELECT c.id, c.name, a.name AS agency FROM clients c
     LEFT JOIN agencies a ON a.id = c.agency_id WHERE c.id = ?'
);
$parent->execute([$t['client_id']]);
$parentClient = $parent->fetch() ?: null;

$live = tracker_live_status($id);
$noAds = $run && (int)$run['top_count'] + (int)$run['bottom_count'] === 0;

$logCountStmt = db()->prepare('SELECT COUNT(*) FROM runs WHERE tracker_id = ?');
$logCountStmt->execute([$id]);
$logCount = (int)$logCountStmt->fetchColumn();

$always = $cycling = 0;
foreach ($consistency as $c) {
    $p = $totalChecks > 0 ? (int)round((int)$c['seen'] / $totalChecks * 100) : 0;
    if ($p >= 95) { $always++; } else { $cycling++; }
}

$schedChip = match ($t['status']) {
    'active'  => '<span class="chip green"><span class="dot" style="background:var(--good)"></span>Running</span>',
    'expired' => '<span class="chip amber">Finished its run</span>',
    'done'    => '<span class="chip">Done, ran once</span>',
    'error'   => '<span class="chip red">Needs attention</span>',
    default   => '<span class="chip"><span class="dot" style="background:#c7c9cd"></span>Stopped</span>',
};
?>
<?php
$agencyCrumb = $parentClient['agency'] ?? 'No agency';
$agencyIdCrumb = 0;
if ($parentClient && !empty($parentClient['id'])) {
    $agSt = db()->prepare('SELECT agency_id FROM clients WHERE id = ?');
    $agSt->execute([(int)$parentClient['id']]);
    $agencyIdCrumb = (int)$agSt->fetchColumn();
}
$agencyListHref = is_admin()
    ? 'clients.php?agency=' . $agencyIdCrumb
    : 'clients.php';
echo crumbs(is_admin()
    ? [
        ['Agencies', 'index.php'],
        [$agencyCrumb, $agencyListHref],
        [$parentClient['name'] ?? 'Client', $parentClient ? 'client.php?id=' . (int)$parentClient['id'] : null],
        [$t['keyword'], null],
      ]
    : [
        ['My dealers', 'clients.php'],
        [$parentClient['name'] ?? 'Client', $parentClient ? 'client.php?id=' . (int)$parentClient['id'] : null],
        [$t['keyword'], null],
      ]);
?>

<div class="pagehead">
  <div>
    <h1 class="page"><?= h($t['keyword']) ?></h1>
    <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
      <?php if (trim((string)($t['cluster'] ?? '')) !== ''): ?>
        <span class="chip blue"><?= h(cluster_label($t['cluster'])) ?></span>
      <?php endif; ?>
      <span class="chip"><?= h($t['location']) ?></span>
      <span class="chip">every <?= h(interval_phrase((int)$t['interval_hours'])) ?></span>
      <span class="chip"><?= h($tz) ?></span>
      <?= $schedChip ?>
    </div>
    <div class="dev" style="margin-top:12px">
      <?php if ($sibling): ?>
        <?php
          $dtHref = $t['device'] === 'desktop' ? '#' : 'tracker.php?id=' . (int)$sibling['id'];
          $mbHref = $t['device'] === 'mobile'  ? '#' : 'tracker.php?id=' . (int)$sibling['id'];
        ?>
        <a class="<?= $t['device'] === 'desktop' ? 'on' : '' ?>" href="<?= h($dtHref) ?>">Desktop</a>
        <a class="<?= $t['device'] === 'mobile' ? 'on' : '' ?>" href="<?= h($mbHref) ?>">Mobile</a>
      <?php else: ?>
        <a class="on" href="#"><?= $t['device'] === 'mobile' ? 'Mobile' : 'Desktop' ?></a>
        <a class="off" href="#" title="Add the same keyword and location on the other device from the dashboard">
          <?= $t['device'] === 'mobile' ? 'Desktop' : 'Mobile' ?> not tracked</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="acts">
    <form method="post" action="action.php">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
      <?php if ($t['status'] === 'active'): ?>
        <button class="btn" name="do" value="stop" type="submit">Stop</button>
      <?php else: ?>
        <button class="btn dark" name="do" value="start" type="submit">Start</button>
      <?php endif; ?>
    </form>
    <form method="post" action="action.php">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
      <button class="btn dark" name="do" value="check" type="submit">Check now</button>
    </form>
  </div>
</div>

<div class="chips" style="margin:14px 0 0">
  <span class="<?= $live['running'] ? 'chip is-live' : ($live['has_ads'] ? 'chip green' : 'chip') ?>"
        data-live-tracker="<?= $id ?>">
    <?= h($live['running'] ? 'Checking now, ' . $live['running_for'] . 's' : $live['label']) ?>
  </span>
  <span class="chip ghost">last <?= h($t['last_run_at'] ? local_time($t['last_run_at'], $tz) : 'never') ?></span>
  <?php if ($t['status'] === 'active'): ?>
    <span class="chip ghost">next <?= h(relative_time($t['next_run_at'])) ?></span>
    <?php if ($t['runs_until']): ?>
      <?php $secsLeft = strtotime($t['runs_until']) - time(); ?>
      <span class="chip<?= $secsLeft < 2 * 86400 ? ' amber' : ' ghost' ?>">
        stops <?= h(relative_time($t['runs_until'])) ?></span>
    <?php endif; ?>
  <?php elseif ($t['status'] === 'expired'): ?>
    <span class="chip amber">reached its end date, restart to run again</span>
  <?php endif; ?>
  <?php if ($diff['entered']): ?><span class="chip blue"><?= count($diff['entered']) ?> entered</span><?php endif; ?>
  <?php if ($diff['exited']): ?><span class="chip amber"><?= count($diff['exited']) ?> exited</span><?php endif; ?>
  <?php if ($diff['moved']): ?><span class="chip"><?= count($diff['moved']) ?> moved</span><?php endif; ?>
</div>

<?php if ($locCheck && $locCheck['warning']): ?>
  <div class="chip red" style="margin-top:14px;display:inline-flex;max-width:100%;white-space:normal;line-height:1.45">
    <strong>Wrong market.</strong> <?= h($locCheck['warning']) ?>
    <?php if ($locCheck['local_hint']): ?>
      — Google returned businesses in: <?= h($locCheck['local_hint']) ?>
    <?php endif; ?>
    <?php if ($locCheck['uule_wellformed'] === false): ?>
      The uule sent to Google is malformed, so Google ignored it and used the proxy's own location.
      Try setting <span class="mono">'location_mode' =&gt; 'uule'</span> in config.php, then Check now.
    <?php endif; ?>
  </div>
<?php elseif ($locCheck && $locCheck['verifiable']): ?>
  <div class="chip green" style="margin-top:14px">
    Location confirmed — local results came back from
    <?= h(implode(', ', $locCheck['states_found'])) ?>, the right market for this keyword.
  </div>
<?php endif; ?>

<nav class="tabs">
  <a class="tab<?= $rtab === 'snapshot' ? ' active on' : '' ?>" href="tracker.php?id=<?= $id ?>">Snapshot</a>
  <a class="tab<?= $rtab === 'presence' ? ' active on' : '' ?>" href="tracker.php?id=<?= $id ?>&amp;tab=presence">Who appeared, check by check</a>
  <a class="tab<?= $rtab === 'coverage' ? ' active on' : '' ?>" href="tracker.php?id=<?= $id ?>&amp;tab=coverage&amp;range=<?= h($range) ?>">How often each shows</a>
  <a class="tab<?= $rtab === 'log' ? ' active on' : '' ?>" href="tracker.php?id=<?= $id ?>&amp;tab=log">
    Check log<?php if ($logCount): ?> <span class="badge"><?= $logCount ?></span><?php endif; ?>
  </a>
</nav>

<?php if ($rtab === 'snapshot'): ?>

<?php if ($consistency): ?>
<div class="stat-row" style="margin-bottom:20px">
  <div class="stat">
    <div class="k">Advertisers seen</div>
    <div class="v"><?= count($consistency) ?></div>
    <div class="foot">this <?= h($range) ?> window</div>
  </div>
  <div class="stat">
    <div class="k">Always present</div>
    <div class="v green"><?= $always ?></div>
    <div class="foot"><?= $always ? 'show on 95%+ of checks' : 'none show 100% of checks' ?></div>
  </div>
  <div class="stat">
    <div class="k">Cycling in &amp; out</div>
    <div class="v amber"><?= $cycling ?></div>
    <div class="foot">budget-capped or dayparting</div>
  </div>
  <div class="stat">
    <div class="k">Checks in window</div>
    <div class="v"><?= $totalChecks ?></div>
    <div class="foot"><?php if ($t['status'] === 'active'): ?>next <?= h(relative_time($t['next_run_at'])) ?><?php
      if ($t['runs_until']): ?> · stops <?= h(relative_time($t['runs_until'])) ?><?php endif;
    else: ?>last <?= h($t['last_run_at'] ? local_time($t['last_run_at'], $tz) : 'never') ?><?php endif; ?></div>
  </div>
</div>
<?php endif; ?>

<?php if ($live['running']): ?>
  <div class="chip blue" style="margin-bottom:16px;display:inline-flex;white-space:normal;line-height:1.45">
    A check is running right now, started <?= (int)$live['running_for'] ?> seconds ago.
    This page will update itself the moment it finishes.
  </div>
<?php endif; ?>

<?php if ($other): ?>
  <div class="chip amber" style="margin-bottom:16px;display:inline-flex;max-width:100%;white-space:normal;line-height:1.45">
    <?= count($other) ?> ad<?= count($other) === 1 ? '' : 's' ?> showed up elsewhere on the page
    (<?= h(implode(', ', array_unique(array_column($other, 'block')))) ?>), recorded but excluded from counts:
    <?= h(implode(', ', array_slice(array_column($other, 'domain'), 0, 5))) ?>
  </div>
<?php endif; ?>

<?php if ($local): ?>
  <div class="chip amber" style="margin-bottom:16px;display:inline-flex;max-width:100%;white-space:normal;line-height:1.45">
    <?= count($local) ?> local services ad<?= count($local) === 1 ? '' : 's' ?> also appeared — a different
    Google ad product, recorded but excluded from the table below:
    <?= h(implode(', ', array_slice(array_column($local, 'domain'), 0, 5))) ?>
  </div>
<?php endif; ?>

<?php if ($noAds): ?>
  <div class="card pad" style="margin-bottom:16px">
    <strong>Google showed no ads here at the last check.</strong>
    <div class="section-d" style="margin:6px 0 0">
      Nobody bid on this keyword in this location at
      <?= h(local_time($run['started_at'], $tz)) ?>. That is real data, not a failure,
      and it is recorded as a zero in the history below.
    </div>
  </div>
<?php endif; ?>

<?php if ($run && $run['block_source'] === 'assumed_top'): ?>
  <div class="chip amber" style="margin-bottom:16px;display:inline-flex;max-width:100%;white-space:normal;line-height:1.45">
    Every ad in this run was recorded as top of page because the response carried no block field.
    Open the raw payload, find the field that marks bottom ads, and add its name to
    <code>$blockKeys</code> in inc/scrapingdog.php.
    <?php if (is_admin()): ?>
      <a href="raw.php?run=<?= (int)$run['id'] ?>">View raw payload</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="grid" style="grid-template-columns:1fr 1fr">
  <div class="card adcard">
    <div class="h"><b>Top of the page</b><span class="chip blue"><?= count($top) ?> ad<?= count($top) === 1 ? '' : 's' ?> · latest check</span></div>
    <?php if (!$top): ?>
      <div class="adrow"><span class="rank">—</span><div><div class="dom" style="color:var(--muted)">No ads</div><div class="desc">nothing at the top of the page</div></div></div>
    <?php else: foreach ($top as $a): ?>
      <div class="adrow">
        <span class="rank"><?= (int)$a['position'] ?></span>
        <div>
          <div class="dom"><?= h($a['domain'] !== '' ? $a['domain'] : 'unknown') ?>
            <?php if (in_array($a['domain'], $watch, true)): ?><span class="you">YOU</span><?php endif; ?>
            <?php if (in_array($a['domain'], $diff['entered'], true)): ?><span class="chip blue" style="margin-left:4px;font-size:10px;padding:1px 6px">new</span><?php endif; ?>
          </div>
          <?php if ($a['headline']): ?>
            <div class="desc"><?= h($a['headline']) ?></div>
          <?php elseif ($a['display_url']): ?>
            <div class="desc"><?= h($a['display_url']) ?></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="card adcard">
    <div class="h"><b>Bottom of the page</b><span class="chip amber"><?= count($bottom) ?> ad<?= count($bottom) === 1 ? '' : 's' ?> · latest check</span></div>
    <?php if (!$bottom): ?>
      <div class="adrow"><span class="rank">—</span><div><div class="dom" style="color:var(--muted)">No ads</div><div class="desc">nothing at the bottom of the page</div></div></div>
    <?php else: foreach ($bottom as $a): ?>
      <div class="adrow">
        <span class="rank"><?= (int)$a['position'] ?></span>
        <div>
          <div class="dom"><?= h($a['domain'] !== '' ? $a['domain'] : 'unknown') ?>
            <?php if (in_array($a['domain'], $watch, true)): ?><span class="you">YOU</span><?php endif; ?>
          </div>
          <?php if ($a['headline']): ?>
            <div class="desc"><?= h($a['headline']) ?></div>
          <?php elseif ($a['display_url']): ?>
            <div class="desc"><?= h($a['display_url']) ?></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<?php endif; ?>

<?php if ($rtab === 'presence'): ?>
<div class="card pad">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
    <div>
      <div class="section-t">Who appeared, check by check</div>
      <div class="section-d" style="margin:0">Each column is one check over the last <?= count($gr) ?> runs.</div>
    </div>
    <div class="legend">
      <span><span class="cell top" style="display:inline-block;width:16px;height:12px;vertical-align:middle"></span> top</span>
      <span><span class="cell bot" style="display:inline-block;width:16px;height:12px;vertical-align:middle"></span> bottom</span>
      <span><span class="cell" style="display:inline-block;width:16px;height:12px;vertical-align:middle"></span> absent</span>
    </div>
  </div>
  <?php if (!$matrix): ?>
    <p class="section-d" style="margin:0">Nothing recorded yet. Press Check now to pull the first snapshot.</p>
  <?php else: ?>
  <div class="heat">
    <table>
      <tr>
        <td></td>
        <?php foreach ($gr as $r): ?>
          <td class="time"><?= h(local_time($r['started_at'], $tz, 'H:i')) ?></td>
        <?php endforeach; ?>
        <td class="time">cov</td>
      </tr>
      <?php foreach ($matrix as $domain => $cells): ?>
      <tr>
        <td class="adv"><?= h($domain) ?><?php if (in_array($domain, $watch, true)): ?> <span class="you">YOU</span><?php endif; ?></td>
        <?php foreach ($gr as $r):
            $block = $cells[$r['id']] ?? null;
            $cellCls = $block === 'top' ? 'top' : ($block === 'bottom' ? 'bot' : '');
        ?>
          <td><div class="cell <?= $cellCls ?>" title="<?= h(local_time($r['started_at'], $tz)) . ' · ' . h($block ?: 'absent') ?>"></div></td>
        <?php endforeach; ?>
        <td class="cov"><?= (int)round(count($cells) / max(1, count($gr)) * 100) ?>%</td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php endif; ?>

<?php if ($rtab === 'coverage'): ?>
<div class="card pad">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:4px">
    <div>
      <div class="section-t">How often each advertiser shows</div>
      <div class="section-d" style="margin:0">
        <?php if ($consistency && $totalChecks > 0): ?>
          <?= $totalChecks ?> checks in this period. Coverage is the share where the advertiser was present.
          Anything under 75% is coming and going — usually a daily budget running dry.
        <?php else: ?>
          No checks recorded in this window yet.
        <?php endif; ?>
      </div>
    </div>
    <span class="range">
      <?php foreach (array_keys($ranges) as $rk): ?>
        <a class="<?= $rk === $range ? 'on' : '' ?>"
           href="tracker.php?id=<?= $id ?>&amp;tab=coverage&amp;range=<?= h($rk) ?>"><?= h($rk) ?></a>
      <?php endforeach; ?>
    </span>
  </div>
  <?php if ($consistency && $totalChecks > 0): ?>
  <table class="lb">
    <thead>
      <tr>
        <th>Advertiser</th>
        <th style="width:180px">Coverage</th>
        <th>Consistency</th>
        <th style="text-align:center">Top</th>
        <th style="text-align:center">Bottom</th>
        <th style="text-align:right">Avg pos</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($consistency as $c):
      $seen = (int)$c['seen'];
      $pct  = $totalChecks > 0 ? (int)round($seen / $totalChecks * 100) : 0;
      $topH = (int)$c['top_hits'];
      $botH = (int)$c['bottom_hits'];
      $tot  = max(1, $topH + $botH);
      $topW = (int)round($pct * $topH / $tot);
      $botW = (int)round($pct * $botH / $tot);

      if ($pct >= 95) {
          $lbl = 'Always';
          $chipCls = 'green';
      } elseif ($pct >= 75) {
          $lbl = 'Most checks';
          $chipCls = 'green';
      } elseif ($pct >= 25) {
          $lbl = 'Intermittent';
          $chipCls = 'amber';
      } else {
          $lbl = 'Rare';
          $chipCls = '';
      }
    ?>
      <tr>
        <td class="domain"><?= h($c['domain']) ?><?php if (in_array($c['domain'], $watch, true)): ?> <span class="you">YOU</span><?php endif; ?></td>
        <td>
          <div class="bar">
            <?php if ($topW): ?><i class="top" style="width:<?= $topW ?>%"></i><?php endif; ?>
            <?php if ($botW): ?><i class="bot" style="left:<?= $topW ?>%;width:<?= $botW ?>%"></i><?php endif; ?>
          </div>
        </td>
        <td><span class="chip <?= $chipCls ?>"><?= h($lbl) ?> · <?= $pct ?>%</span></td>
        <td style="text-align:center" class="tabular"><?= $topH ?></td>
        <td style="text-align:center" class="tabular"><?= $botH ?></td>
        <td style="text-align:right" class="tabular"><?= $c['avg_top_pos'] !== null ? number_format((float)$c['avg_top_pos'], 1) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($rtab === 'log'): ?>
<div class="card pad">
  <div class="section-t">Every check we ran</div>
  <div class="section-d">Raw record of each scan.<?php if (is_admin()): ?> Click "raw" to see the exact SERP that was captured.<?php endif; ?></div>
  <table class="lb">
    <thead>
      <tr>
        <th>Time</th>
        <th>Result</th>
        <th style="text-align:center">Top</th>
        <th style="text-align:center">Bottom</th>
        <th style="text-align:right"></th>
      </tr>
    </thead>
    <tbody>
    <?php
    $hist = db()->prepare('SELECT * FROM runs WHERE tracker_id = ? ORDER BY started_at DESC LIMIT 20');
    $hist->execute([$id]);
    foreach ($hist->fetchAll() as $r):
      $resultChip = match ($r['status']) {
          'success' => '<span class="chip green">Ads found</span>',
          'empty'   => '<span class="chip">No ads</span>',
          'failed'  => '<span class="chip red">Check failed</span>',
          'running' => '<span class="chip blue">Checking now</span>',
          default   => '<span class="chip">' . h(ucfirst((string)$r['status'])) . '</span>',
      };
    ?>
      <tr>
        <td class="tabular"><?= h(local_time($r['started_at'], $tz)) ?>
          <?php if ($r['trigger_source'] === 'manual'): ?><span class="chip ghost" style="margin-left:6px;font-size:10px;padding:1px 6px">manual</span><?php endif; ?>
        </td>
        <td><?= $resultChip ?></td>
        <td style="text-align:center" class="tabular"><?= (int)$r['top_count'] ?></td>
        <td style="text-align:center" class="tabular"><?= (int)$r['bottom_count'] ?></td>
        <td style="text-align:right"><?php if (is_admin()): ?><a href="raw.php?run=<?= (int)$r['id'] ?>">raw</a><?php endif; ?></td>
      </tr>
      <?php if ($r['error_message']): ?>
      <tr>
        <td colspan="5" style="color:var(--critical);font-size:13px;padding-top:0"><?= h($r['error_message']) ?></td>
      </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php render_foot(); ?>
