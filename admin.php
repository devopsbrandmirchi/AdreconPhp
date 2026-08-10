<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/scheduler.php';
require __DIR__ . '/inc/layout.php';

$user = require_admin();

$agencies = db()->query('SELECT id, name FROM agencies ORDER BY name')->fetchAll();

$clients = db()->query(
    "SELECT c.*, a.name AS agency_name,
       (SELECT COUNT(*) FROM trackers t WHERE t.client_id = c.id) AS schedules,
       (SELECT COUNT(*) FROM trackers t WHERE t.client_id = c.id AND t.status = 'active') AS running
     FROM clients c LEFT JOIN agencies a ON a.id = c.agency_id
     ORDER BY COALESCE(a.name,'zzzz'), c.name"
)->fetchAll();

$people = db()->query('SELECT id, username, email, role FROM users ORDER BY role DESC, username')->fetchAll();

$grid = [];
foreach (db()->query('SELECT user_id, client_id FROM user_clients')->fetchAll() as $r) {
    $grid[(int)$r['user_id']][(int)$r['client_id']] = true;
}

$members = array_values(array_filter($people, fn($p) => $p['role'] !== 'admin'));
$admins  = array_values(array_filter($people, fn($p) => $p['role'] === 'admin'));

// agency_id => [client ids]
$clientsByAgency = [];
foreach ($clients as $c) {
    $aid = (int)($c['agency_id'] ?? 0);
    $clientsByAgency[$aid][] = (int)$c['id'];
}

render_head('Client access', $user);
?>
<?= crumbs([['Agencies', 'index.php'], ['Client access', null]]) ?>
<div class="pagehead" style="margin-bottom:22px">
  <div>
    <h1 class="page">Client access</h1>
    <p class="sub">Admin only — you can see every agency and client. Assign users below, or add / edit / delete agencies and clients.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn dark" href="#agencies">+ Add agency</a>
    <a class="btn primary" href="#add-client">+ Add client</a>
  </div>
</div>

<div class="stats">
  <div class="stat"><p class="stat-label">Clients</p><p class="stat-value"><?= count($clients) ?></p></div>
  <div class="stat"><p class="stat-label">Agencies</p><p class="stat-value"><?= count($agencies) ?></p></div>
  <div class="stat"><p class="stat-label">Administrators</p><p class="stat-value stat-ok"><?= count($admins) ?></p></div>
  <div class="stat"><p class="stat-label">Standard users</p><p class="stat-value"><?= count($members) ?></p></div>
</div>

<div class="section-head" id="access"><h2>User access</h2></div>
<p class="hint" style="margin:-8px 0 14px">Layout: <b>User</b> → <b>Clients</b> → <b>Agency</b>. Admins always have access to all agencies and clients (not listed here).</p>

<?php if (!$members): ?>
  <div class="card pad" style="margin-bottom:26px">
    <p style="margin:0;color:var(--ink-2)">
      No standard users yet. People who sign up appear here so you can grant clients.
    </p>
  </div>
<?php elseif (!$clients): ?>
  <div class="card pad" style="margin-bottom:26px">
    <p style="margin:0;color:var(--ink-2)">No clients yet. <a href="#add-client">Add a client</a> first.</p>
  </div>
<?php else: ?>
  <div class="card" style="margin-bottom:26px;overflow:hidden">
    <table class="access-table">
      <thead>
        <tr>
          <th>User</th>
          <th>Clients</th>
          <th>Agency</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($members as $m):
        $uid = (int)$m['id'];
        $assignedAgencyIds = [];
        foreach ($clients as $c) {
            if (!empty($grid[$uid][(int)$c['id']]) && !empty($c['agency_id'])) {
                $assignedAgencyIds[(int)$c['agency_id']] = true;
            }
        }
      ?>
        <tr data-user-row="<?= $uid ?>">
          <td>
            <span class="access-user"><?= h($m['username']) ?></span><?php if (!empty($m['email'])): ?>
            <span class="access-meta"><?= h((string)$m['email']) ?></span><?php endif; ?>
          </td>
          <td>
            <form method="post" action="action.php" id="access-user-<?= $uid ?>" class="access-user-form">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="user_clients">
              <input type="hidden" name="user_id" value="<?= $uid ?>">
              <input type="hidden" name="redirect" value="admin.php">
              <div class="access-clients" data-user-clients="<?= $uid ?>">
                <?php foreach ($clients as $c):
                  $cid = (int)$c['id'];
                  $checked = !empty($grid[$uid][$cid]);
                ?>
                  <label class="access-chip">
                    <input type="checkbox"
                           name="client_ids[]"
                           value="<?= $cid ?>"
                           data-agency="<?= (int)($c['agency_id'] ?? 0) ?>"
                           <?= $checked ? 'checked' : '' ?>>
                    <span><?= h($c['name']) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </form>
          </td>
          <td>
            <div class="access-agency-cell">
              <select class="input access-agency-pick" data-for-user="<?= $uid ?>" aria-label="Grant agency clients">
                <option value="">— Agency —</option>
                <?php foreach ($agencies as $ag): ?>
                  <option value="<?= (int)$ag['id'] ?>"><?= h($ag['name']) ?></option>
                <?php endforeach; ?>
                <option value="0">No agency</option>
              </select>
              <?php if ($assignedAgencyIds):
                $names = [];
                foreach ($agencies as $ag) {
                    if (!empty($assignedAgencyIds[(int)$ag['id']])) {
                        $names[] = $ag['name'];
                    }
                }
              ?>
                <span class="access-agency-linked" title="<?= h(implode(', ', $names)) ?>"><?= h(implode(', ', $names)) ?></span>
              <?php endif; ?>
            </div>
          </td>
          <td class="access-acts">
            <button type="submit" form="access-user-<?= $uid ?>" class="btn sm">Save</button>
            <form method="post" action="action.php" class="access-del-form"
                  onsubmit="return confirm('Delete user <?= h(addslashes($m['username'])) ?>? Their keywords stay.')">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="user_delete">
              <input type="hidden" name="user_id" value="<?= $uid ?>">
              <input type="hidden" name="redirect" value="admin.php">
              <button type="submit" class="btn sm btn-danger">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="hint" style="margin:-10px 0 26px">Admins always see every agency and client. Use Agency to tick all clients in that agency, then Save.</p>
<?php endif; ?>

<div class="section-head" id="agencies"><h2>Agencies</h2></div>
<div class="card" style="margin-bottom:26px;overflow:hidden">
  <?php foreach ($agencies as $ag):
    $n = 0;
    foreach ($clients as $cc) {
        if ((int)$cc['agency_id'] === (int)$ag['id']) {
            $n++;
        }
    }
  ?>
  <div class="row access-edit-row">
    <form method="post" action="action.php" class="access-edit-form">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="agency_update">
      <input type="hidden" name="agency_id" value="<?= (int)$ag['id'] ?>">
      <input class="input" type="text" name="name" value="<?= h($ag['name']) ?>" required aria-label="Agency name">
      <span class="access-meta"><?= $n ?> client<?= $n === 1 ? '' : 's' ?></span>
      <button type="submit" class="btn sm">Edit / Save</button>
    </form>
    <form method="post" action="action.php"
          onsubmit="return confirm('Delete agency <?= h(addslashes($ag['name'])) ?>? Clients stay, they just lose this agency.')">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="agency_delete">
      <input type="hidden" name="agency_id" value="<?= (int)$ag['id'] ?>">
      <button type="submit" class="btn sm btn-danger">Delete</button>
    </form>
  </div>
  <?php endforeach; ?>
  <div class="card pad" style="border:0;border-radius:0;box-shadow:none;border-top:1px solid var(--line)">
    <form method="post" action="action.php" class="formrow">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="agency_create">
      <div>
        <label for="agn">Add an agency</label>
        <input class="input" type="text" id="agn" name="name" placeholder="Wheeler Advertising" required>
      </div>
      <div style="align-self:end"><button type="submit" class="btn primary">Add agency</button></div>
    </form>
  </div>
</div>

<div class="section-head"><h2>Clients</h2></div>
<div class="card" style="margin-bottom:26px;overflow:hidden">
  <?php foreach ($clients as $c): ?>
  <div class="row access-edit-row access-client-row">
    <form method="post" action="action.php" class="access-edit-form access-client-form">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="client_update">
      <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
      <input type="hidden" name="redirect" value="admin.php">
      <input class="input" type="text" name="name" value="<?= h($c['name']) ?>" required aria-label="Client name">
      <select class="input" name="agency_id" aria-label="Agency">
        <option value="0">No agency</option>
        <?php foreach ($agencies as $ag): ?>
          <option value="<?= (int)$ag['id'] ?>" <?= (int)$c['agency_id'] === (int)$ag['id'] ? 'selected' : '' ?>>
            <?= h($ag['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <input class="input" type="text" name="domains" value="<?= h((string)$c['domains']) ?>"
             placeholder="theirdomain.com">
      <span class="access-meta"><?= (int)$c['schedules'] ?> kw</span>
      <button type="submit" class="btn sm">Edit / Save</button>
    </form>
    <form method="post" action="action.php"
          onsubmit="return confirm('Delete <?= h(addslashes($c['name'])) ?> and all its keywords? This cannot be undone.')">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="client_delete">
      <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
      <input type="hidden" name="redirect" value="admin.php">
      <button type="submit" class="btn sm btn-danger">Delete</button>
    </form>
  </div>
  <?php endforeach; ?>
  <?php if (!$clients): ?>
    <div class="card pad"><p style="margin:0;color:var(--ink-2)">No clients yet.</p></div>
  <?php endif; ?>
</div>

<div class="section-head" id="add-client"><h2>Add a client</h2></div>
<div class="card pad">
<form method="post" action="action.php" class="grid">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="client_create">
  <input type="hidden" name="redirect" value="admin.php">
  <div class="grid g2">
    <div class="field"><label for="cn">Client name</label>
      <input class="input" type="text" id="cn" name="name" placeholder="Client or business name" required></div>
    <div class="field"><label for="cd">Domains, comma separated</label>
      <input class="input" type="text" id="cd" name="domains" placeholder="theirdomain.com, another.com"></div>
    <div class="field"><label for="cag">Agency</label>
      <select class="input" id="cag" name="agency_id">
        <option value="0">No agency</option>
        <?php foreach ($agencies as $ag): ?>
          <option value="<?= (int)$ag['id'] ?>"><?= h($ag['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
  </div>
  <div style="margin-top:4px">
    <button class="btn primary" type="submit">Add client</button>
  </div>
</form>
</div>

<script>
(function () {
  document.querySelectorAll('.access-agency-pick').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var uid = sel.getAttribute('data-for-user');
      var aid = sel.value;
      if (aid === '') return;
      var box = document.querySelector('[data-user-clients="' + uid + '"]');
      if (!box) return;
      box.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
        if (String(cb.getAttribute('data-agency')) === String(aid)) {
          cb.checked = true;
        }
      });
      sel.value = '';
    });
  });
})();
</script>
<?php render_foot(); ?>
