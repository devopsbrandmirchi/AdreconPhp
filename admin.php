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
<p class="hint" style="margin:-8px 0 14px">Open the <b>Clients</b> dropdown and tick dealers. Agency is optional — pick one to select its dealers. Agency choice is kept after Save.</p>

<?php
start_session();
$savedAgencyPick = $_SESSION['access_agency_pick'] ?? [];
?>

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
  <div class="card access-list" style="margin-bottom:26px">
    <div class="access-list-head">
      <span>User</span>
      <span>Clients</span>
      <span>Agency</span>
      <span>Save</span>
      <span>Delete</span>
    </div>
    <?php foreach ($members as $m):
      $uid = (int)$m['id'];
      $agencySelected = array_key_exists($uid, $savedAgencyPick) ? (string)$savedAgencyPick[$uid] : '';
    ?>
      <div class="access-row" data-user-row="<?= $uid ?>">
        <form method="post" action="action.php" class="access-user-form" id="access-save-<?= $uid ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="user_clients">
          <input type="hidden" name="user_id" value="<?= $uid ?>">
          <input type="hidden" name="redirect" value="admin.php">

          <div class="access-col-user">
            <span class="access-user"><?= h($m['username']) ?></span>
            <?php if (!empty($m['email'])): ?>
              <span class="access-meta"><?= h((string)$m['email']) ?></span>
            <?php endif; ?>
          </div>

          <div class="access-clients">
            <?php
              $selectedNames = [];
              foreach ($clients as $c) {
                  if (!empty($grid[$uid][(int)$c['id']])) {
                      $selectedNames[] = (string)$c['name'];
                  }
              }
              $msLabel = $selectedNames
                  ? (count($selectedNames) <= 2
                      ? implode(', ', $selectedNames)
                      : count($selectedNames) . ' clients selected')
                  : 'Select clients…';
            ?>
            <div class="ms-dropdown">
              <button type="button" class="ms-toggle input" aria-haspopup="listbox" aria-expanded="false">
                <span class="ms-label"><?= h($msLabel) ?></span>
                <span class="ms-caret">▾</span>
              </button>
              <div class="ms-panel" hidden role="listbox" aria-multiselectable="true">
                <?php foreach ($clients as $c):
                  $cid = (int)$c['id'];
                  $selected = !empty($grid[$uid][$cid]);
                ?>
                  <label class="ms-option">
                    <input type="checkbox"
                           name="client_ids[]"
                           value="<?= $cid ?>"
                           data-agency="<?= (int)($c['agency_id'] ?? 0) ?>"
                           <?= $selected ? 'checked' : '' ?>>
                    <span><?= h($c['name']) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <div class="access-agency-cell">
            <?php
              $agencyLabel = '— Optional agency › —';
              if ($agencySelected === '0') {
                  $agencyLabel = 'No agency ›';
              } elseif ($agencySelected !== '') {
                  foreach ($agencies as $ag) {
                      if ((string)(int)$ag['id'] === $agencySelected) {
                          $agencyLabel = $ag['name'] . ' ›';
                          break;
                      }
                  }
              }
            ?>
            <div class="ms-dropdown ms-dropdown-single" data-agency-dd>
              <input type="hidden" class="access-agency-pick" name="agency_pick" value="<?= h($agencySelected) ?>">
              <button type="button" class="ms-toggle input" aria-haspopup="listbox" aria-expanded="false">
                <span class="ms-label"><?= h($agencyLabel) ?></span>
                <span class="ms-caret">▾</span>
              </button>
              <div class="ms-panel" hidden role="listbox">
                <button type="button" class="ms-option ms-pick<?= $agencySelected === '' ? ' is-on' : '' ?>" data-value="" data-label="— Optional agency › —">
                  <span>— Optional agency › —</span>
                </button>
                <?php foreach ($agencies as $ag): ?>
                  <button type="button"
                          class="ms-option ms-pick<?= $agencySelected === (string)(int)$ag['id'] ? ' is-on' : '' ?>"
                          data-value="<?= (int)$ag['id'] ?>"
                          data-label="<?= h($ag['name']) ?> ›">
                    <span><?= h($ag['name']) ?> ›</span>
                  </button>
                <?php endforeach; ?>
                <button type="button" class="ms-option ms-pick<?= $agencySelected === '0' ? ' is-on' : '' ?>" data-value="0" data-label="No agency ›">
                  <span>No agency ›</span>
                </button>
              </div>
            </div>
          </div>

          <div class="access-acts">
            <button type="submit" class="btn sm">Save</button>
          </div>
        </form>

        <form method="post" action="action.php" class="access-del-form"
              onsubmit="return confirm('Delete user <?= h(addslashes($m['username'])) ?>? Their keywords stay.')">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="user_delete">
          <input type="hidden" name="user_id" value="<?= $uid ?>">
          <input type="hidden" name="redirect" value="admin.php">
          <button type="submit" class="btn sm btn-danger">Delete</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="hint" style="margin:-10px 0 26px">Each Save updates <b>only that user</b>. Use the clients dropdown to multi-select dealers, optionally pick an agency, then Save.</p>
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
<div class="card client-edit-list" style="margin-bottom:26px">
  <div class="client-edit-head">
    <span>Client</span>
    <span>Agency</span>
    <span>Domain</span>
    <span>Keywords</span>
    <span>Save</span>
    <span>Delete</span>
  </div>
  <?php if (!$clients): ?>
    <div class="card pad" style="border:0;box-shadow:none;border-radius:0">
      <p style="margin:0;color:var(--ink-2)">No clients yet.</p>
    </div>
  <?php else: ?>
    <?php foreach ($clients as $c):
      $cAid = (int)($c['agency_id'] ?? 0);
      $cAgencyLabel = 'No agency ›';
      if ($cAid > 0) {
          foreach ($agencies as $ag) {
              if ((int)$ag['id'] === $cAid) {
                  $cAgencyLabel = $ag['name'] . ' ›';
                  break;
              }
          }
      }
    ?>
    <div class="client-edit-row">
      <form method="post" action="action.php" class="client-edit-form">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="client_update">
        <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
        <input type="hidden" name="redirect" value="admin.php">

        <input class="input" type="text" name="name" value="<?= h($c['name']) ?>" required aria-label="Client name" placeholder="Client name">

        <div class="ms-dropdown ms-dropdown-single" data-agency-dd>
          <input type="hidden" class="access-agency-pick" name="agency_id" value="<?= $cAid ?>">
          <button type="button" class="ms-toggle input" aria-haspopup="listbox" aria-expanded="false">
            <span class="ms-label"><?= h($cAgencyLabel) ?></span>
            <span class="ms-caret">▾</span>
          </button>
          <div class="ms-panel" hidden role="listbox">
            <button type="button" class="ms-option ms-pick<?= $cAid === 0 ? ' is-on' : '' ?>" data-value="0" data-label="No agency ›">
              <span>No agency ›</span>
            </button>
            <?php foreach ($agencies as $ag): ?>
              <button type="button"
                      class="ms-option ms-pick<?= $cAid === (int)$ag['id'] ? ' is-on' : '' ?>"
                      data-value="<?= (int)$ag['id'] ?>"
                      data-label="<?= h($ag['name']) ?> ›">
                <span><?= h($ag['name']) ?> ›</span>
              </button>
            <?php endforeach; ?>
          </div>
        </div>

        <input class="input" type="text" name="domains" value="<?= h((string)$c['domains']) ?>"
               placeholder="theirdomain.com" aria-label="Domains">

        <span class="client-edit-kw"><?= (int)$c['schedules'] ?> kw</span>

        <div class="access-acts">
          <button type="submit" class="btn sm">Edit / Save</button>
        </div>
      </form>

      <form method="post" action="action.php" class="access-del-form"
            onsubmit="return confirm('Delete <?= h(addslashes($c['name'])) ?> and all its keywords? This cannot be undone.')">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="client_delete">
        <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
        <input type="hidden" name="redirect" value="admin.php">
        <button type="submit" class="btn sm btn-danger">Delete</button>
      </form>
    </div>
    <?php endforeach; ?>
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
  function refreshClientLabel(dd) {
    var label = dd.querySelector('.ms-label');
    if (!label) return;
    var checked = dd.querySelectorAll('.ms-option input[type="checkbox"]:checked');
    if (!checked.length) {
      label.textContent = 'Select clients…';
      return;
    }
    var names = [];
    checked.forEach(function (cb) {
      var span = cb.parentNode.querySelector('span');
      if (span) names.push(span.textContent.trim());
    });
    label.textContent = names.length <= 2
      ? names.join(', ')
      : names.length + ' clients selected';
  }

  function closeAll(except) {
    document.querySelectorAll('.ms-dropdown.is-open').forEach(function (dd) {
      if (except && dd === except) return;
      dd.classList.remove('is-open');
      var panel = dd.querySelector('.ms-panel');
      var btn = dd.querySelector('.ms-toggle');
      if (panel) panel.hidden = true;
      if (btn) btn.setAttribute('aria-expanded', 'false');
    });
    document.querySelectorAll('.access-row.is-ms-open, .client-edit-row.is-ms-open').forEach(function (row) {
      if (!row.querySelector('.ms-dropdown.is-open')) row.classList.remove('is-ms-open');
    });
  }

  function openDd(dd) {
    dd.classList.add('is-open');
    var row = dd.closest('.access-row, .client-edit-row');
    if (row) row.classList.add('is-ms-open');
    var panel = dd.querySelector('.ms-panel');
    var btn = dd.querySelector('.ms-toggle');
    if (panel) panel.hidden = false;
    if (btn) btn.setAttribute('aria-expanded', 'true');
  }

  document.querySelectorAll('.ms-dropdown').forEach(function (dd) {
    var btn = dd.querySelector('.ms-toggle');
    var panel = dd.querySelector('.ms-panel');
    if (!btn || !panel) return;

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var open = dd.classList.contains('is-open');
      closeAll();
      if (!open) openDd(dd);
    });

    panel.addEventListener('click', function (e) { e.stopPropagation(); });
    panel.addEventListener('change', function () {
      if (!dd.classList.contains('ms-dropdown-single')) refreshClientLabel(dd);
    });
  });

  // Agency single-select: same dropdown UI, picks value + selects that agency's clients.
  document.querySelectorAll('[data-agency-dd]').forEach(function (dd) {
    var hidden = dd.querySelector('.access-agency-pick');
    var label = dd.querySelector('.ms-label');
    var form = dd.closest('.access-user-form');
    dd.querySelectorAll('.ms-pick').forEach(function (opt) {
      opt.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var val = opt.getAttribute('data-value');
        var text = opt.getAttribute('data-label') || opt.textContent.trim();
        if (hidden) hidden.value = val;
        if (label) label.textContent = text;
        dd.querySelectorAll('.ms-pick').forEach(function (p) { p.classList.remove('is-on'); });
        opt.classList.add('is-on');

        if (form && val !== '') {
          var clientDd = form.querySelector('.access-clients .ms-dropdown');
          if (clientDd) {
            clientDd.querySelectorAll('.ms-option input[type="checkbox"]').forEach(function (cb) {
              cb.checked = String(cb.getAttribute('data-agency') || '0') === String(val);
            });
            refreshClientLabel(clientDd);
          }
        }
        closeAll();
      });
    });
  });

  document.addEventListener('click', function () { closeAll(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAll();
  });
})();
</script>
<?php render_foot(); ?>
