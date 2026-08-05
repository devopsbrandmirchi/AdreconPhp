<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/scheduler.php';
require __DIR__ . '/inc/layout.php';

$user = require_admin();

$clients = db()->query(
    "SELECT c.*,
       (SELECT COUNT(*) FROM trackers t WHERE t.client_id = c.id) AS schedules,
       (SELECT COUNT(*) FROM trackers t WHERE t.client_id = c.id AND t.status = 'active') AS running
     FROM clients c
     ORDER BY c.name"
)->fetchAll();

$people = db()->query('SELECT id, username, role FROM users ORDER BY role DESC, username')->fetchAll();

$grid = [];
foreach (db()->query('SELECT user_id, client_id FROM user_clients')->fetchAll() as $r) {
    $grid[(int)$r['user_id']][(int)$r['client_id']] = true;
}

$members = array_values(array_filter($people, fn($p) => $p['role'] !== 'admin'));
$admins  = array_values(array_filter($people, fn($p) => $p['role'] === 'admin'));

render_head('Dealer access', $user);
?>
<?= crumbs([['Dealers', 'clients.php'], ['Dealer access', null]]) ?>
<div class="pagehead" style="margin-bottom:22px">
  <div>
    <h1 class="page">Dealer access</h1>
    <p class="sub">Which dealers each person can open. Tick the boxes and save once.
      Administrators always see every dealer, so they are not listed here.</p>
  </div>
</div>

<div class="stats">
  <div class="stat"><p class="stat-label">Dealers</p><p class="stat-value"><?= count($clients) ?></p></div>
  <div class="stat"><p class="stat-label">Accounts</p><p class="stat-value"><?= count($people) ?></p></div>
  <div class="stat"><p class="stat-label">Administrators</p><p class="stat-value stat-ok"><?= count($admins) ?></p></div>
  <div class="stat"><p class="stat-label">Standard users</p><p class="stat-value"><?= count($members) ?></p></div>
</div>

<?php if (!$clients): ?>
  <div class="panel panel-pad" style="margin-bottom:26px">
    <p style="margin:0;color:var(--ink-2)">No dealers yet. Add one below first.</p>
  </div>
<?php elseif (!$members): ?>
  <div class="panel panel-pad" style="margin-bottom:26px">
    <p style="margin:0;color:var(--ink-2)">
      Every account so far is an administrator, and administrators see everything.
      Create a standard user under <a href="users.php">Accounts</a> to start
      granting access to particular dealers.
    </p>
  </div>
<?php else: ?>

<div class="section-head"><h2>Who can see which dealer</h2></div>
<div class="panel panel-pad" style="margin-bottom:26px">
<form method="post" action="action.php">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="access_matrix">
  <div class="matrix-wrap">
    <table class="access-matrix">
      <tr>
        <th class="mx-corner">Dealer</th>
        <?php foreach ($members as $m): ?>
          <th class="mx-person"><span><?= h($m['username']) ?></span></th>
        <?php endforeach; ?>
      </tr>
      <?php foreach ($clients as $c): ?>
      <tr>
        <td class="mx-client">
          <a href="client.php?id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a>
          <span class="mx-meta"><?= (int)$c['schedules'] ?> keyword<?= (int)$c['schedules'] === 1 ? '' : 's' ?></span>
        </td>
        <?php foreach ($members as $m): ?>
          <td class="mx-cell">
            <label>
              <input type="checkbox" name="grant[<?= (int)$m['id'] ?>][]" value="<?= (int)$c['id'] ?>"
                <?= isset($grid[(int)$m['id']][(int)$c['id']]) ? 'checked' : '' ?>>
            </label>
          </td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php foreach ($members as $m): ?>
    <input type="hidden" name="members[]" value="<?= (int)$m['id'] ?>">
  <?php endforeach; ?>
  <div style="margin-top:18px">
    <button class="btn primary" type="submit">Save access</button>
    <span class="hint" style="margin-left:10px">
      Untick everything for someone and they can still sign in but will see nothing.
      That is the tidy way to suspend access without deleting their account.
    </span>
  </div>
</form>
</div>
<?php endif; ?>

<div class="section-head"><h2>Dealers</h2></div>
<div class="card" style="margin-bottom:26px;overflow:hidden">
  <?php foreach ($clients as $c): ?>
  <div class="row" style="display:grid;grid-template-columns:1fr 1.2fr 150px auto;gap:14px;align-items:center">
    <form method="post" action="action.php" style="display:contents">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="client_update">
      <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
      <input type="hidden" name="agency_id" value="<?= (int)($c['agency_id'] ?? 0) ?>">
      <div><input class="input" type="text" name="name" value="<?= h($c['name']) ?>" required
             aria-label="Dealer name"></div>
      <div><input class="input" type="text" name="domains" value="<?= h((string)$c['domains']) ?>"
             placeholder="theirdomain.com, another.com"></div>
      <div style="font-size:14px;color:var(--ink-2)">
        <?= (int)$c['schedules'] ?> keyword<?= (int)$c['schedules'] === 1 ? '' : 's' ?>,
        <?= (int)$c['running'] ?> running
      </div>
      <div class="acts"><button type="submit" class="btn">Save</button></div>
    </form>
    <div class="acts" style="grid-column:1/-1;justify-content:flex-start;padding-top:4px">
      <form method="post" action="action.php"
            onsubmit="return confirm('Delete <?= h(addslashes($c['name'])) ?>, its websites, and all <?= (int)$c['schedules'] ?> of its keywords including everything they recorded? This cannot be undone.')">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="client_delete">
        <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
        <button type="submit" class="btn btn-danger">Delete this dealer</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$clients): ?>
    <div class="card pad"><p style="margin:0;color:var(--ink-2)">No dealers yet.</p></div>
  <?php endif; ?>
</div>

<div class="section-head"><h2>Add a dealer</h2></div>
<div class="card pad">
<form method="post" action="action.php" class="grid">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="client_create">
  <input type="hidden" name="agency_id" value="0">
  <div class="grid g2">
    <div class="field"><label for="cn">Dealer name</label>
      <input class="input" type="text" id="cn" name="name" placeholder="Dealer or business name" required></div>
    <div class="field"><label for="cd">Domains, comma separated</label>
      <input class="input" type="text" id="cd" name="domains" placeholder="theirdomain.com, another.com"></div>
  </div>
  <div style="margin-top:4px">
    <button class="btn primary" type="submit">Add dealer</button>
    <span class="hint" style="margin-left:10px">
      Domains listed here are flagged as theirs in every report.
    </span>
  </div>
</form>
</div>
<?php render_foot(); ?>
