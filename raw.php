<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/scheduler.php';
require __DIR__ . '/inc/layout.php';

// Raw payloads are full API responses. Administrators only.
$user = require_admin();
$runId = (int)($_GET['run'] ?? 0);

$s = db()->prepare('SELECT r.*, t.keyword, t.location FROM runs r JOIN trackers t ON t.id = r.tracker_id WHERE r.id = ?');
$s->execute([$runId]);
$run = $s->fetch();
if (!$run) {
    http_response_code(404);
    exit('No such run.');
}

$json    = $run['raw_json'] ? json_decode($run['raw_json'], true) : null;
$topKeys = is_array($json) ? array_keys($json) : [];
$adKeys  = [];
if (is_array($json)) {
    foreach ($json as $k => $v) {
        if (is_array($v) && stripos($k, 'ad') !== false && !empty($v) && is_array($v[0] ?? null)) {
            $adKeys[$k] = array_keys($v[0]);
        }
    }
}

render_head('Raw payload', $user);
?>
<p style="margin:0 0 14px"><a href="tracker.php?id=<?= (int)$run['tracker_id'] ?>">&larr; <?= h($run['keyword']) ?></a></p>
<h1>Raw payload</h1>
<p class="sub"><?= h($run['location']) ?> · <?= h($run['started_at']) ?> UTC · block source
  <code><?= h($run['block_source'] ?: 'n/a') ?></code></p>

<div class="panel panel-pad" style="margin:16px 0">
  <h2>What the response contains</h2>
  <p class="mono" style="margin:0 0 10px">top level keys: <?= h(implode(', ', $topKeys)) ?></p>
  <?php if ($adKeys): foreach ($adKeys as $k => $fields): ?>
    <p class="mono" style="margin:0 0 6px"><strong><?= h($k) ?>[0]</strong> fields: <?= h(implode(', ', $fields)) ?></p>
  <?php endforeach; else: ?>
    <p class="empty">No ad-shaped arrays found in this response.</p>
  <?php endif; ?>
  <p class="hint">
    Look for whichever field distinguishes top from bottom placement, then add its name to
    <code>$blockKeys</code> in inc/scrapingdog.php so classification stops guessing.
  </p>
</div>

<div class="panel panel-pad">
  <h2>Full JSON</h2>
  <pre class="mono" style="white-space:pre-wrap;word-break:break-word;margin:0;max-height:520px;overflow:auto"><?=
    h($json ? json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ($run['raw_json'] ?: 'Nothing stored.'))
  ?></pre>
</div>
<?php render_foot(); ?>
