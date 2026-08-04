<?php
declare(strict_types=1);
/**
 * Cron setup helper. Detects the absolute path of this install and the PHP
 * command line binary, then prints the exact cron line to paste.
 *
 * Sign in first. Delete this file once the cron job is running.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/scheduler.php';
require __DIR__ . '/inc/layout.php';

$user = require_admin();

$appDir = __DIR__;

// DirectAdmin lays sites out as /home/USER/domains/DOMAIN/public_html
$home = null;
if (preg_match('#^(/home/[^/]+)/#', $appDir, $m)) {
    $home = $m[1];
}
$logPath = ($home ?: $appDir) . '/serp-worker.log';

// The web SAPI binary is usually php-fpm or lsphp, which cron cannot use.
// Look for a real CLI binary in the places shared hosts put them.
$candidates = [
    '/usr/local/bin/php',
    '/usr/bin/php',
    '/usr/local/php84/bin/php', '/usr/local/php83/bin/php',
    '/usr/local/php82/bin/php', '/usr/local/php81/bin/php',
    '/opt/alt/php84/usr/bin/php', '/opt/alt/php83/usr/bin/php',
    '/opt/alt/php82/usr/bin/php', '/opt/alt/php81/usr/bin/php',
    '/opt/cpanel/ea-php83/root/usr/bin/php', '/opt/cpanel/ea-php82/root/usr/bin/php',
];
$found = [];
foreach ($candidates as $c) {
    if (@is_executable($c)) {
        $found[] = $c;
    }
}

// Ask each one for its version, which also proves it can actually execute.
$versions = [];
if (function_exists('shell_exec')) {
    foreach ($found as $bin) {
        $out = @shell_exec(escapeshellarg($bin) . ' -r "echo PHP_VERSION;" 2>/dev/null');
        if ($out) {
            $versions[$bin] = trim((string)$out);
        }
    }
}

$best = null;
foreach ($versions as $bin => $v) {
    if (version_compare($v, '8.0.0', '>=')) {
        $best = $bin;
        break;
    }
}
if (!$best) {
    $best = $found[0] ?? '/usr/local/bin/php';
}

$cronCmd = $best . ' ' . $appDir . '/worker.php >> ' . $logPath . ' 2>&1';
$checkCmd = $best . ' ' . $appDir . '/cron_check.php';

// Is the worker on disk the fixed build? The old one refused to run under a
// CGI binary, which is a silent and very confusing failure.
$workerSrc     = @file_get_contents($appDir . '/worker.php');
$workerOk      = $workerSrc !== false;
$workerParses  = $workerOk && strpos($workerSrc, '$fromWeb') !== false;
$workerOldSapi = $workerOk && strpos($workerSrc, "PHP_SAPI !== 'cli'") !== false;
$workerBroken  = $workerOk && preg_match('#/\*\*.*?\*/5 #s', $workerSrc) === 1;

// Tail the two logs, whichever exist.
function tail_file($path, $lines = 12) {
    if (!@is_readable($path)) {
        return null;
    }
    $all = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$all) {
        return [];
    }
    return array_slice($all, -$lines);
}

$workerLog = tail_file($logPath);
$checkLog  = tail_file($appDir . '/cron_check.log');

// Whether the worker has ever actually run.
$lastCron = db()->query(
    "SELECT MAX(started_at) FROM runs WHERE trigger_source = 'scheduled'"
)->fetchColumn();

$dueNow = (int)db()->query(
    "SELECT COUNT(*) FROM trackers WHERE status = 'active' AND next_run_at <= UTC_TIMESTAMP()"
)->fetchColumn();

$activeCount = (int)db()->query(
    "SELECT COUNT(*) FROM trackers WHERE status = 'active'"
)->fetchColumn();

$dirWritable = @is_writable($appDir);
$logWritable = @is_writable($logPath) || (!file_exists($logPath) && @is_writable(dirname($logPath)));

render_head('Server checks', $user);
?>
<p style="margin:0 0 14px"><a href="index.php">&larr; Dashboard</a></p>
<h1>Server checks</h1>
<p class="sub">Whether the scheduler is running, and the exact cron line for this server.
  Everything below was read from the server itself.</p>

<?php if ($workerBroken): ?>
  <div class="flash flash-err">
    worker.php on this server is the broken early build. The cron example inside its comment
    block ends the comment early and the file will not parse. Replace it with the current
    worker.php before anything else.
  </div>
<?php elseif ($workerOldSapi && !$workerParses): ?>
  <div class="flash flash-err">
    worker.php on this server is the older build that only accepts the CLI binary. If your
    host's cron runs the CGI binary, it refuses every run and writes
    "This script runs from the command line only." to the log. Replace worker.php with the
    current version, which accepts both.
  </div>
<?php elseif ($workerParses): ?>
  <div class="flash flash-ok">worker.php is the current build and accepts both CLI and CGI binaries.</div>
<?php elseif (!$workerOk): ?>
  <div class="flash flash-err">worker.php could not be read at <?= h($appDir) ?>.</div>
<?php endif; ?>

<div class="panel panel-pad" style="margin:16px 0">
  <h2>Step 1, is cron firing at all</h2>
  <p class="sub" style="margin:0 0 10px">
    Temporarily point your cron job at this command instead. It runs on any PHP version, so it
    reports even when the binary is too old for the app. Give it 10 minutes, then reload this page.
  </p>
  <textarea rows="2" readonly onclick="this.select()"><?= h($checkCmd) ?></textarea>

  <?php if ($checkLog === null): ?>
    <div class="warn" style="margin-top:12px">
      cron_check.log does not exist yet. Either the check has not run, or cron is not firing.
    </div>
  <?php elseif (!$checkLog): ?>
    <div class="warn" style="margin-top:12px">cron_check.log exists but is empty.</div>
  <?php else: ?>
    <p class="hint" style="margin-top:12px">Last <?= count($checkLog) ?> entries, newest at the bottom:</p>
    <pre class="mono" style="white-space:pre-wrap;word-break:break-word;background:var(--panel-2);
      border:1px solid var(--line);border-radius:5px;padding:10px;margin:0;max-height:220px;
      overflow:auto;font-size:11.5px"><?= h(implode("\n", $checkLog)) ?></pre>
  <?php endif; ?>
</div>

<div class="panel panel-pad" style="margin:16px 0">
  <h2>Step 2, the real cron command</h2>
  <p class="sub" style="margin:0 0 10px">DirectAdmin, Advanced Features, Cronjobs.</p>
  <table class="cons" style="margin-bottom:14px">
    <tr><th style="width:40%">Field</th><th>Value</th></tr>
    <tr><td>Minute</td><td class="dom">*/5</td></tr>
    <tr><td>Hour</td><td class="dom">*</td></tr>
    <tr><td>Day of month</td><td class="dom">*</td></tr>
    <tr><td>Month</td><td class="dom">*</td></tr>
    <tr><td>Day of week</td><td class="dom">*</td></tr>
  </table>
  <label>Command</label>
  <textarea rows="3" readonly onclick="this.select()"><?= h($cronCmd) ?></textarea>
  <p class="hint">Click the box to select it. Single line format:<br>
    <span class="mono">*/5 * * * * <?= h($cronCmd) ?></span></p>

  <?php if ($workerLog === null): ?>
    <div class="warn" style="margin-top:12px">
      <?= h($logPath) ?> does not exist yet, so the worker has not produced output.
    </div>
  <?php elseif ($workerLog): ?>
    <p class="hint" style="margin-top:12px">Worker log, newest at the bottom:</p>
    <pre class="mono" style="white-space:pre-wrap;word-break:break-word;background:var(--panel-2);
      border:1px solid var(--line);border-radius:5px;padding:10px;margin:0;max-height:220px;
      overflow:auto;font-size:11.5px"><?= h(implode("\n", $workerLog)) ?></pre>
  <?php endif; ?>
</div>

<div class="panel panel-pad" style="margin:16px 0">
  <h2>What was detected</h2>
  <table class="cons">
    <tr><th style="width:34%">App directory</th><td class="dom"><?= h($appDir) ?></td></tr>
    <tr><th>Home directory</th><td class="dom"><?= h($home ?: 'not a standard /home layout') ?></td></tr>
    <tr><th>Log file</th><td class="dom"><?= h($logPath) ?>
      <span class="tag"><?= $logWritable ? 'writable' : 'NOT WRITABLE' ?></span></td></tr>
    <tr><th>App directory writable</th><td class="dom"><?= $dirWritable ? 'yes' : 'no' ?></td></tr>
    <tr><th>Web PHP</th><td class="dom"><?= h(PHP_VERSION) ?> (<?= h(PHP_SAPI) ?>)</td></tr>
    <tr><th>CLI binary chosen</th><td class="dom"><?= h($best) ?>
      <?= isset($versions[$best]) ? '<span class="tag">v' . h($versions[$best]) . '</span>' : '' ?></td></tr>
  </table>

  <?php if (count($found) > 1): ?>
    <p class="hint">Other binaries found. If the chosen one fails, try these in order:<br>
      <?php foreach ($found as $f): if ($f === $best) continue; ?>
        <span class="mono"><?= h($f) ?><?= isset($versions[$f]) ? ' (v' . h($versions[$f]) . ')' : '' ?></span><br>
      <?php endforeach; ?>
    </p>
  <?php elseif (count($found) === 1): ?>
    <p class="hint">Only one PHP binary was found on disk. If cron reports "command not found",
      ask goofyhost support for the correct CLI path for your account.</p>
  <?php endif; ?>

  <?php if (!$versions): ?>
    <div class="warn" style="margin-top:12px">
      shell_exec is disabled here, so the binaries above could not be version checked.
      They exist on disk, which is usually enough. Step 1 will confirm which version cron uses.
    </div>
  <?php endif; ?>
</div>

<div class="panel panel-pad">
  <h2>Has the worker ever run</h2>
  <table class="cons">
    <tr><th style="width:34%">Last scheduled check</th>
      <td class="dom"><?= $lastCron ? h($lastCron) . ' UTC' : 'never' ?></td></tr>
    <tr><th>Schedules started</th><td class="dom"><?= $activeCount ?></td></tr>
    <tr><th>Schedules due right now</th><td class="dom"><?= $dueNow ?></td></tr>
  </table>
  <?php if ($activeCount === 0): ?>
    <div class="warn" style="margin-top:12px">
      Nothing is started. Even a perfect cron job has nothing to do. Go to the dashboard and
      press Start on at least one schedule, then wait 5 minutes.
    </div>
  <?php endif; ?>
  <p class="hint">
    Once "Last scheduled check" shows a recent time, cron is working. Delete cron_setup.php
    and cron_check.php from the server.
  </p>
</div>
<?php render_foot(); ?>

