<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/geo.php';

$sql = [

"CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  email VARCHAR(190) DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(10) NOT NULL DEFAULT 'member',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS clients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agency_id INT UNSIGNED DEFAULT NULL,
  name VARCHAR(150) NOT NULL,
  domains VARCHAR(500) DEFAULT NULL,
  notes VARCHAR(500) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_client_name (name),
  KEY idx_agency (agency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS agencies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  notes VARCHAR(500) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_agency_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS sites (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  domain VARCHAR(190) NOT NULL,
  label VARCHAR(150) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_client_domain (client_id, domain),
  KEY idx_client (client_id),
  CONSTRAINT fk_site_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS user_clients (
  user_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, client_id),
  KEY idx_client (client_id),
  CONSTRAINT fk_uc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_uc_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS trackers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED DEFAULT NULL,
  site_id INT UNSIGNED DEFAULT NULL,
  keyword VARCHAR(190) NOT NULL,
  cluster VARCHAR(100) NOT NULL DEFAULT '',
  location VARCHAR(190) NOT NULL,
  country VARCHAR(4) NOT NULL DEFAULT 'us',
  device VARCHAR(10) NOT NULL DEFAULT 'desktop',
  interval_hours TINYINT UNSIGNED NOT NULL DEFAULT 3,
  duration_days TINYINT UNSIGNED NOT NULL DEFAULT 7,
  runs_until DATETIME DEFAULT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/Chicago',
  status VARCHAR(10) NOT NULL DEFAULT 'paused',
  watch_domains VARCHAR(500) DEFAULT NULL,
  fail_streak TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_run_at DATETIME DEFAULT NULL,
  last_run_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_tracker (keyword, location, device),
  KEY idx_client (client_id),
  KEY idx_site (site_id),
  KEY idx_due (status, next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS runs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tracker_id INT UNSIGNED NOT NULL,
  scheduled_for DATETIME DEFAULT NULL,
  started_at DATETIME DEFAULT NULL,
  finished_at DATETIME DEFAULT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'running',
  task_id VARCHAR(64) DEFAULT NULL,
  trigger_source VARCHAR(12) NOT NULL DEFAULT 'scheduled',
  http_status SMALLINT DEFAULT NULL,
  credits_used SMALLINT NOT NULL DEFAULT 0,
  top_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  bottom_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  local_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  fingerprint VARCHAR(32) DEFAULT NULL,
  block_source VARCHAR(32) DEFAULT NULL,
  error_message VARCHAR(500) DEFAULT NULL,
  raw_json LONGTEXT DEFAULT NULL,
  KEY idx_tracker_time (tracker_id, started_at),
  KEY idx_task (task_id),
  CONSTRAINT fk_run_tracker FOREIGN KEY (tracker_id) REFERENCES trackers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS ad_placements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id INT UNSIGNED NOT NULL,
  tracker_id INT UNSIGNED NOT NULL,
  block VARCHAR(6) NOT NULL,
  position TINYINT UNSIGNED NOT NULL,
  domain VARCHAR(190) NOT NULL,
  display_url VARCHAR(500) DEFAULT NULL,
  landing_url TEXT DEFAULT NULL,
  headline VARCHAR(255) DEFAULT NULL,
  description VARCHAR(500) DEFAULT NULL,
  sitelinks TEXT DEFAULT NULL,
  captured_at DATETIME NOT NULL,
  KEY idx_run (run_id),
  KEY idx_tracker_dom (tracker_id, domain, captured_at),
  CONSTRAINT fk_ad_run FOREIGN KEY (run_id) REFERENCES runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  username VARCHAR(60) DEFAULT NULL,
  attempted_at DATETIME NOT NULL,
  KEY idx_ip_time (ip, attempted_at),
  KEY idx_user_time (username, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS locations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  canonical VARCHAR(255) NOT NULL,
  name VARCHAR(150) NOT NULL,
  region VARCHAR(120) DEFAULT NULL,
  type VARCHAR(32) NOT NULL DEFAULT 'City',
  population INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_canonical (canonical),
  KEY idx_name (name),
  KEY idx_pop (population)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

/**
 * Add columns introduced after an install already exists. Re-running
 * install.php on an older database picks these up. Safe to run repeatedly.
 */
function migrate_columns(): void {
    $wanted = [
        'ad_placements' => ['sitelinks' => 'TEXT DEFAULT NULL'],
        'runs'          => ['local_count' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
                            'task_id'     => 'VARCHAR(64) DEFAULT NULL'],
        'users'         => ['username'  => 'VARCHAR(60) DEFAULT NULL',
                            'role'      => "VARCHAR(10) NOT NULL DEFAULT 'member'"],
        'clients'       => ['agency_id' => 'INT UNSIGNED DEFAULT NULL'],
        'trackers'      => ['client_id' => 'INT UNSIGNED DEFAULT NULL',
                            'site_id'   => 'INT UNSIGNED DEFAULT NULL',
                            'duration_days' => 'TINYINT UNSIGNED NOT NULL DEFAULT 7',
                            'runs_until'    => 'DATETIME DEFAULT NULL',
                            'cluster'       => "VARCHAR(100) NOT NULL DEFAULT ''"],
    ];

    foreach ($wanted as $table => $columns) {
        foreach ($columns as $col => $definition) {
            $check = db()->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $check->execute([$table, $col]);
            if ((int)$check->fetchColumn() === 0) {
                db()->exec("ALTER TABLE `$table` ADD COLUMN `$col` $definition");
            }
        }
    }
}

/**
 * Accounts that predate roles are given 'member' by the column default. On an
 * upgrade that would leave nobody able to manage people, and no way to fix it
 * from inside the app. If there is no administrator, promote the oldest
 * account, which is whoever ran the installer originally.
 */
function ensure_admin_exists(): void {
    $admins = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    if ($admins > 0) {
        return;
    }
    $first = (int)db()->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($first) {
        db()->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([$first]);
    }
}

/**
 * Clients used to carry a comma separated list of domains. Turn each of those
 * into a real site, then attach existing schedules to the site whose domain
 * they were already watching.
 */
function split_client_domains_into_sites(): void {
    $clients = db()->query('SELECT id, domains FROM clients WHERE domains IS NOT NULL')->fetchAll();
    $ins = db()->prepare('INSERT IGNORE INTO sites (client_id, domain) VALUES (?, ?)');

    foreach ($clients as $c) {
        foreach (array_filter(array_map('trim', explode(',', (string)$c['domains']))) as $d) {
            $nd = normalise_domain($d);
            if ($nd !== '') { $ins->execute([(int)$c['id'], mb_substr($nd, 0, 190)]); }
        }
    }

    // Attach schedules by the domain they already flag as theirs.
    $sites = db()->query('SELECT id, client_id, domain FROM sites')->fetchAll();
    $upd = db()->prepare(
        'UPDATE trackers SET site_id = ?
         WHERE site_id IS NULL AND client_id = ? AND watch_domains LIKE ?'
    );
    foreach ($sites as $s) {
        $upd->execute([(int)$s['id'], (int)$s['client_id'], '%' . $s['domain'] . '%']);
    }
}

/**
 * Schedules created before clients existed have no home. Give them one rather
 * than leaving them invisible to a client-scoped interface.
 */
function adopt_orphan_trackers(): void {
    $orphans = (int)db()->query('SELECT COUNT(*) FROM trackers WHERE client_id IS NULL')->fetchColumn();
    if ($orphans === 0) {
        return;
    }
    db()->prepare('INSERT IGNORE INTO clients (name, notes) VALUES (?, ?)')
        ->execute(['Unassigned', 'Schedules that existed before clients were introduced.']);
    $id = (int)db()->query("SELECT id FROM clients WHERE name = 'Unassigned'")->fetchColumn();
    if ($id) {
        db()->prepare('UPDATE trackers SET client_id = ? WHERE client_id IS NULL')->execute([$id]);
    }
}

$done  = false;
$error = null;
$seeded = 0;

$hasUsers = false;
try {
    foreach ($sql as $q) {
        db()->exec($q);
    }
    migrate_columns();
    ensure_admin_exists();
    adopt_orphan_trackers();
    split_client_domains_into_sites();
    $seeded   = seed_locations_from_csv(APP_ROOT . '/data/locations-seed.csv');
    $hasUsers = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if (!$error && !$hasUsers && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));
    $pass     = (string)($_POST['password'] ?? '');

    if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
        $error = 'Usernames are 3 to 60 characters, letters, numbers, dot, dash or underscore.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'That email address is not valid. Leave it blank if you prefer.';
    } elseif (strlen($pass) < 10) {
        $error = 'Use a password of at least 10 characters.';
    } else {
        db()->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'admin')")
            ->execute([$username, $email ?: null, password_hash($pass, PASSWORD_DEFAULT)]);
        $done = true;
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install · Ads tracker</title><link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="assets/app.css"></head>
<body><div class="login">
<h1>Set up</h1>
<p class="sub" style="margin-bottom:16px">This sets up the database and creates the first account. That account is the administrator.</p>

<?php if ($seeded): ?>
  <div class="flash flash-ok"><?= number_format($seeded) ?> US locations loaded, so the location box can autocomplete.</div>
<?php endif; ?>

<?php if ($error): ?><div class="flash flash-err"><?= h($error) ?></div><?php endif; ?>

<?php if ($done || $hasUsers): ?>
  <div class="flash flash-ok">All set. Delete install.php from the server, then sign in.</div>
  <a class="btn btn-primary" href="login.php">Go to sign in</a>
<?php else: ?>
  <div class="panel panel-pad">
  <form method="post" class="grid">
    <div><label for="username">Username</label><input type="text" id="username" name="username" required autofocus></div>
    <div><label for="email">Email (optional)</label><input type="email" id="email" name="email"></div>
    <div><label for="password">Password</label><input type="password" id="password" name="password" required>
      <p class="hint">At least 10 characters.</p></div>
    <div><button class="btn-primary" type="submit">Create account</button></div>
  </form>
  </div>
<?php endif; ?>
</div></body></html>
