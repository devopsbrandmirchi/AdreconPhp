<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/scheduler.php';
require __DIR__ . '/inc/layout.php';

// Only administrators manage accounts. Everyone else can do everything else.
$user = require_admin();

$users = db()->query(
    "SELECT u.*,
       (SELECT COUNT(*) FROM user_clients uc WHERE uc.user_id = u.id) AS client_count
     FROM users u ORDER BY u.role DESC, u.username"
)->fetchAll();

$clients = db()->query('SELECT id, name FROM clients ORDER BY name')->fetchAll();

$assigned = [];
foreach (db()->query('SELECT user_id, client_id FROM user_clients')->fetchAll() as $r) {
    $assigned[(int)$r['user_id']][] = (int)$r['client_id'];
}

render_head('Accounts', $user);
?>
<div style="margin-bottom:22px">
  <h1>Accounts</h1>
  <p class="sub">Who can sign in to this tool. These are logins for your team and for
    clients you want to give a view of their own data. They are not the clients
    themselves, which live under All clients.</p>
</div>

<div class="panel" style="margin-bottom:26px">
  <div class="row" style="grid-template-columns:1fr 150px 1fr 110px;background:var(--surface-2);
       font-size:13px;color:var(--ink-2)">
    <span>Username</span><span>Can do</span><span>Can see</span><span></span>
  </div>
  <?php foreach ($users as $u): ?>
  <div class="row" style="grid-template-columns:1fr 150px 1fr 110px">
    <div>
      <div class="loc"><?= h($u['username']) ?><?= (int)$u['id'] === (int)$user['id'] ? ' (you)' : '' ?></div>
      <?php if ($u['email']): ?><div class="loc-meta"><?= h($u['email']) ?></div><?php endif; ?>
    </div>
    <div>
      <?php if ($u['role'] === 'admin'): ?>
        <span class="pill pill-run">Everything</span>
      <?php else: ?>
        <span class="pill pill-idle">Their clients only</span>
      <?php endif; ?>
    </div>
    <div style="font-size:14px;color:var(--ink-2)">
      <?php if ($u['role'] === 'admin'): ?>
        all clients
      <?php else: ?>
        <?php
          $mine = $assigned[(int)$u['id']] ?? [];
          $names = [];
          foreach ($clients as $c) {
              if (in_array((int)$c['id'], $mine, true)) { $names[] = $c['name']; }
          }
          echo $names ? h(implode(', ', $names)) : '<span style="color:var(--ink-3)">none yet</span>';
        ?>
      <?php endif; ?>
    </div>
    <div class="acts">
      <?php if ((int)$u['id'] !== (int)$user['id']): ?>
      <form method="post" action="action.php"
            onsubmit="return confirm('Delete this login? The keywords they set up stay exactly as they are.')">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="user_delete">
        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
        <button class="btn-danger">Delete</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($u['role'] !== 'admin' && $clients): ?>
  <div class="row" style="grid-template-columns:1fr;background:var(--surface-2);padding-top:0">
    <form method="post" action="action.php" class="assign-form">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="user_clients">
      <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
      <div class="assign-list">
        <?php foreach ($clients as $c): ?>
          <label class="assign-item">
            <input type="checkbox" name="client_ids[]" value="<?= (int)$c['id'] ?>"
              <?= in_array((int)$c['id'], $assigned[(int)$u['id']] ?? [], true) ? 'checked' : '' ?>>
            <?= h($c['name']) ?>
          </label>
        <?php endforeach; ?>
      </div>
      <button style="margin-top:10px">Save what <?= h($u['username']) ?> can see</button>
    </form>
  </div>
  <?php endif; ?>
  <?php endforeach; ?>
</div>

<div class="section-head"><h2>Create an account</h2></div>
<div class="panel panel-pad">
<form method="post" action="action.php" class="grid">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="user_create">
  <div class="grid g4">
    <div><label for="un">Username</label>
      <input type="text" id="un" name="username" required></div>
    <div><label for="em">Email (optional)</label>
      <input type="email" id="em" name="email"></div>
    <div><label for="pw">Password</label>
      <input type="text" id="pw" name="password" required
             value="<?= h(bin2hex(random_bytes(6))) ?>"></div>
    <div><label for="rl">Role</label>
      <select id="rl" name="role">
        <option value="member">Standard user, only the clients you tick</option>
        <option value="admin">Administrator, every client plus these settings</option>
      </select></div>
  </div>
  <?php if ($clients): ?>
  <div>
    <label>Clients this person can see</label>
    <div class="assign-list">
      <?php foreach ($clients as $c): ?>
        <label class="assign-item">
          <input type="checkbox" name="client_ids[]" value="<?= (int)$c['id'] ?>"> <?= h($c['name']) ?>
        </label>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
  <div>
    <button class="btn-primary" type="submit">Create account</button>
    <span class="hint" style="margin-left:10px">
      Copy the password before you save. It is stored encrypted and cannot be read
      back afterwards, so send it to them now. A standard user can do everything
      inside the clients you give them, except create accounts or change these settings.
    </span>
  </div>
</form>
</div>
<?php render_foot(); ?>
