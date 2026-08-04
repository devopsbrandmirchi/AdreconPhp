<?php
declare(strict_types=1);

// Upgrade an existing install in place.
//
//   php upgrade.php          check what would change, change nothing
//   php upgrade.php --apply  make the changes
//
// Safe to run repeatedly. It only adds what is missing and never drops or
// rewrites existing data. Your schedules, runs, placements and login all
// survive.
//
// See worker.php for why this checks the request context and not PHP_SAPI.
if (!empty($_SERVER['REQUEST_METHOD']) || !empty($_SERVER['REMOTE_ADDR'])
    || !empty($_SERVER['HTTP_HOST']) || !empty($_SERVER['REQUEST_URI'])) {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

require __DIR__ . '/inc/bootstrap.php';

$apply = in_array('--apply', array_slice($argv, 1), true);
$line  = str_repeat('=', 74);

function col_exists(string $table, string $col): bool {
    $s = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $s->execute([$table, $col]);
    return (int)$s->fetchColumn() > 0;
}

function table_exists(string $table): bool {
    $s = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $s->execute([$table]);
    return (int)$s->fetchColumn() > 0;
}

function count_rows(string $table): int {
    if (!table_exists($table)) {
        return 0;
    }
    return (int)db()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
}

echo "$line\n SERP TRACKER UPGRADE\n$line\n";
echo $apply ? "  mode: APPLYING CHANGES\n\n" : "  mode: dry run, nothing will change. Add --apply to commit.\n\n";

// What is here now.
$before = [
    'users'         => count_rows('users'),
    'trackers'      => count_rows('trackers'),
    'runs'          => count_rows('runs'),
    'ad_placements' => count_rows('ad_placements'),
    'locations'     => count_rows('locations'),
];
echo "  current data\n";
foreach ($before as $t => $n) {
    printf("    %-16s %s rows\n", $t, number_format($n));
}

// Everything this version needs that an older one may lack.
$newTables = [
    'clients' => "CREATE TABLE clients (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        domains VARCHAR(500) DEFAULT NULL,
        notes VARCHAR(500) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_client_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'agencies' => "CREATE TABLE agencies (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        notes VARCHAR(500) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_agency_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'sites' => "CREATE TABLE sites (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        client_id INT UNSIGNED NOT NULL,
        domain VARCHAR(190) NOT NULL,
        label VARCHAR(150) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_client_domain (client_id, domain),
        KEY idx_client (client_id),
        CONSTRAINT fk_site_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'user_clients' => "CREATE TABLE user_clients (
        user_id INT UNSIGNED NOT NULL,
        client_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (user_id, client_id),
        KEY idx_client (client_id),
        CONSTRAINT fk_uc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_uc_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'login_attempts' => "CREATE TABLE login_attempts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        username VARCHAR(60) DEFAULT NULL,
        attempted_at DATETIME NOT NULL,
        KEY idx_ip_time (ip, attempted_at),
        KEY idx_user_time (username, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'locations' => "CREATE TABLE locations (
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

// The complete set of columns this version needs, not merely the recent ones.
// Listing all of them makes the upgrade self healing: whatever version an
// install is on, anything missing gets added.
$newColumns = [
    ['users',         'username',       'VARCHAR(60) DEFAULT NULL'],
    ['users',         'email',          'VARCHAR(190) DEFAULT NULL'],
    ['users',         'role',           "VARCHAR(10) NOT NULL DEFAULT 'member'"],

    ['clients',       'agency_id',      'INT UNSIGNED DEFAULT NULL'],
    ['clients',       'domains',        'VARCHAR(500) DEFAULT NULL'],
    ['clients',       'notes',          'VARCHAR(500) DEFAULT NULL'],

    ['trackers',      'client_id',      'INT UNSIGNED DEFAULT NULL'],
    ['trackers',      'site_id',        'INT UNSIGNED DEFAULT NULL'],
    ['trackers',      'duration_days',  'TINYINT UNSIGNED NOT NULL DEFAULT 7'],
    ['trackers',      'runs_until',     'DATETIME DEFAULT NULL'],
    ['trackers',      'country',        "VARCHAR(4) NOT NULL DEFAULT 'us'"],
    ['trackers',      'device',         "VARCHAR(10) NOT NULL DEFAULT 'desktop'"],
    ['trackers',      'timezone',       "VARCHAR(64) NOT NULL DEFAULT 'America/Chicago'"],
    ['trackers',      'watch_domains',  'VARCHAR(500) DEFAULT NULL'],
    ['trackers',      'fail_streak',    'TINYINT UNSIGNED NOT NULL DEFAULT 0'],
    ['trackers',      'next_run_at',    'DATETIME DEFAULT NULL'],
    ['trackers',      'last_run_at',    'DATETIME DEFAULT NULL'],

    ['runs',          'scheduled_for',  'DATETIME DEFAULT NULL'],
    ['runs',          'finished_at',    'DATETIME DEFAULT NULL'],
    ['runs',          'trigger_source', "VARCHAR(12) NOT NULL DEFAULT 'scheduled'"],
    ['runs',          'http_status',    'SMALLINT DEFAULT NULL'],
    ['runs',          'credits_used',   'SMALLINT NOT NULL DEFAULT 0'],
    ['runs',          'top_count',      'TINYINT UNSIGNED NOT NULL DEFAULT 0'],
    ['runs',          'bottom_count',   'TINYINT UNSIGNED NOT NULL DEFAULT 0'],
    ['runs',          'local_count',    'TINYINT UNSIGNED NOT NULL DEFAULT 0'],
    ['runs',          'task_id',        'VARCHAR(64) DEFAULT NULL'],
    ['runs',          'fingerprint',    'VARCHAR(32) DEFAULT NULL'],
    ['runs',          'block_source',   'VARCHAR(32) DEFAULT NULL'],
    ['runs',          'error_message',  'VARCHAR(500) DEFAULT NULL'],
    ['runs',          'raw_json',       'LONGTEXT DEFAULT NULL'],

    ['ad_placements', 'display_url',    'VARCHAR(500) DEFAULT NULL'],
    ['ad_placements', 'landing_url',    'TEXT DEFAULT NULL'],
    ['ad_placements', 'headline',       'VARCHAR(255) DEFAULT NULL'],
    ['ad_placements', 'description',    'VARCHAR(500) DEFAULT NULL'],
    ['ad_placements', 'sitelinks',      'TEXT DEFAULT NULL'],

    ['sites',         'label',          'VARCHAR(150) DEFAULT NULL'],

    ['locations',     'region',         'VARCHAR(120) DEFAULT NULL'],
    ['locations',     'type',           "VARCHAR(32) NOT NULL DEFAULT 'City'"],
    ['locations',     'population',     'INT UNSIGNED NOT NULL DEFAULT 0'],
];

$todo = [];

foreach ($newTables as $t => $ddl) {
    if (!table_exists($t)) {
        $todo[] = ['table', $t, $ddl];
    }
}
$willExist = array_keys($newTables);
foreach ($newColumns as [$t, $c, $def]) {
    if (table_exists($t) && !col_exists($t, $c)) {
        $todo[] = ['column', "$t.$c", "ALTER TABLE `$t` ADD COLUMN `$c` $def"];
    }
}

echo "\n$line\n WHAT NEEDS DOING\n$line\n";
if (!$todo) {
    echo "  Nothing. The schema is already current.\n";
} else {
    foreach ($todo as [$kind, $name]) {
        printf("    add %-7s %s\n", $kind, $name);
    }
}

// Post-migration housekeeping that only matters on an upgrade.
$needsAdmin  = false;
$orphans     = 0;
$seedNeeded  = false;

if (table_exists('users') && col_exists('users', 'role')) {
    $needsAdmin = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() === 0
                  && count_rows('users') > 0;
} elseif (count_rows('users') > 0) {
    // The column is about to be added and will default everyone to member.
    $needsAdmin = true;
}
if (table_exists('trackers') && col_exists('trackers', 'client_id')) {
    $orphans = (int)db()->query('SELECT COUNT(*) FROM trackers WHERE client_id IS NULL')->fetchColumn();
} else {
    $orphans = count_rows('trackers');
}
$seedNeeded = count_rows('locations') === 0;

// Schedules that predate run windows have no end date. Give the running ones
// the full 60 days: bounded from now on, but nothing stops unexpectedly.
$needWindow = 0;
if (table_exists('trackers') && col_exists('trackers', 'runs_until')) {
    $needWindow = (int)db()->query(
        "SELECT COUNT(*) FROM trackers WHERE status = 'active' AND runs_until IS NULL"
    )->fetchColumn();
} elseif (table_exists('trackers')) {
    $needWindow = (int)db()->query("SELECT COUNT(*) FROM trackers WHERE status = 'active'")->fetchColumn();
}
if ($needWindow > 0) {
    printf("    give %d running schedule%s an end date %d days out, the maximum\n",
        $needWindow, $needWindow === 1 ? '' : 's', 60);
    echo "      (they run unbounded today, so nothing is cut short by this)\n";
}

// Clients used to hold a comma separated domain string. Those become sites.
// Clients used to sit at the top level. Give them an agency to live under.
$needAgency = 0;
if (table_exists('clients')) {
    $needAgency = col_exists('clients', 'agency_id')
        ? (int)db()->query('SELECT COUNT(*) FROM clients WHERE agency_id IS NULL')->fetchColumn()
        : count_rows('clients');
}
// A clients table created during this run starts empty, so nothing to move.
if ($needAgency > 0) {
    printf("    put %d client%s under an agency called \"My agency\", which you can rename\n",
        $needAgency, $needAgency === 1 ? '' : 's');
} elseif (table_exists('clients') && !col_exists('clients', 'agency_id') && count_rows('clients') > 0) {
    printf("    put %d client%s under an agency\n", count_rows('clients'), count_rows('clients') === 1 ? '' : 's');
}

$sitesToMake = 0;
if (table_exists('clients')) {
    $have = table_exists('sites')
        ? (int)db()->query('SELECT COUNT(*) FROM sites')->fetchColumn() : 0;
    if ($have === 0) {
        foreach (db()->query('SELECT domains FROM clients WHERE domains IS NOT NULL')->fetchAll() as $c) {
            $sitesToMake += count(array_filter(array_map('trim', explode(',', (string)$c['domains']))));
        }
    }
}
if ($sitesToMake > 0) {
    printf("    turn %d client domain%s into sites, and attach schedules to them\n",
        $sitesToMake, $sitesToMake === 1 ? '' : 's');
}

if ($needsAdmin) {
    echo "    promote the oldest account to administrator\n";
    echo "      (roles are new, and without this nobody could manage people)\n";
}
if ($orphans > 0) {
    printf("    move %d schedule%s into a client called \"Unassigned\"\n",
        $orphans, $orphans === 1 ? '' : 's');
}
if ($seedNeeded) {
    echo "    load the bundled location list for autocomplete\n";
}

if (!$apply) {
    echo "\n$line\n";
    echo "  Nothing has been changed. Back up first, then run:\n";
    echo "    php upgrade.php --apply\n";
    exit(0);
}

echo "\n$line\n APPLYING\n$line\n";

// Tables first. A table created in this pass will not have been scanned for
// missing columns, so the column check is repeated afterwards.
foreach ($todo as [$kind, $name, $sql]) {
    if ($kind !== 'table') {
        continue;
    }
    try {
        db()->exec($sql);
        printf("  added %-7s %s\n", $kind, $name);
    } catch (Throwable $e) {
        printf("  FAILED %s: %s\n", $name, $e->getMessage());
        exit(1);
    }
}

foreach ($newColumns as [$t, $c, $def]) {
    if (!table_exists($t) || col_exists($t, $c)) {
        continue;
    }
    try {
        db()->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
        printf("  added column  %s.%s\n", $t, $c);
    } catch (Throwable $e) {
        printf("  FAILED %s.%s: %s\n", $t, $c, $e->getMessage());
        exit(1);
    }
}

if ($needsAdmin) {
    $admins = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    if ($admins === 0) {
        $first = (int)db()->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($first) {
            db()->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([$first]);
            $name = db()->query("SELECT username FROM users WHERE id = $first")->fetchColumn();
            echo "  promoted '$name' to administrator\n";
        }
    }
}

if ($orphans > 0) {
    db()->prepare('INSERT IGNORE INTO clients (name, notes) VALUES (?, ?)')
        ->execute(['Unassigned', 'Schedules that existed before clients were introduced.']);
    $cid = (int)db()->query("SELECT id FROM clients WHERE name = 'Unassigned'")->fetchColumn();
    $n = db()->prepare('UPDATE trackers SET client_id = ? WHERE client_id IS NULL');
    $n->execute([$cid]);
    printf("  moved %d schedule%s into \"Unassigned\"\n", $n->rowCount(), $n->rowCount() === 1 ? '' : 's');
}

// Recompute now that the tables and columns definitely exist, so this is
// accurate whether the schema was created in this run or an earlier one.
$needAgency = (table_exists('clients') && col_exists('clients', 'agency_id'))
    ? (int)db()->query('SELECT COUNT(*) FROM clients WHERE agency_id IS NULL')->fetchColumn()
    : 0;

if ($needAgency > 0) {
    db()->prepare('INSERT IGNORE INTO agencies (name, notes) VALUES (?, ?)')
        ->execute(['My agency', 'Created during the upgrade. Rename it under Client access.']);
    $aid = (int)db()->query("SELECT id FROM agencies WHERE name = 'My agency'")->fetchColumn();
    $ua  = db()->prepare('UPDATE clients SET agency_id = ? WHERE agency_id IS NULL');
    $ua->execute([$aid]);
    printf("  put %d client%s under \"My agency\"\n", $ua->rowCount(), $ua->rowCount() === 1 ? '' : 's');
}

if ($needWindow > 0) {
    $u = db()->prepare(
        "UPDATE trackers SET duration_days = 60,
                runs_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 DAY)
         WHERE status = 'active' AND runs_until IS NULL"
    );
    $u->execute();
    printf("  gave %d running schedule%s a 60 day window\n",
        $u->rowCount(), $u->rowCount() === 1 ? '' : 's');
}

if ($sitesToMake > 0) {
    $ins = db()->prepare('INSERT IGNORE INTO sites (client_id, domain) VALUES (?, ?)');
    $made = 0;
    foreach (db()->query('SELECT id, domains FROM clients WHERE domains IS NOT NULL')->fetchAll() as $c) {
        foreach (array_filter(array_map('trim', explode(',', (string)$c['domains']))) as $d) {
            $nd = normalise_domain($d);
            if ($nd !== '') { $ins->execute([(int)$c['id'], mb_substr($nd, 0, 190)]); }
            $made++;
        }
    }
    $attached = 0;
    $upd = db()->prepare(
        'UPDATE trackers SET site_id = ?
         WHERE site_id IS NULL AND client_id = ? AND watch_domains LIKE ?'
    );
    foreach (db()->query('SELECT id, client_id, domain FROM sites')->fetchAll() as $st) {
        $upd->execute([(int)$st['id'], (int)$st['client_id'], '%' . $st['domain'] . '%']);
        $attached += $upd->rowCount();
    }
    printf("  created %d site%s and attached %d schedule%s to them\n",
        $made, $made === 1 ? '' : 's', $attached, $attached === 1 ? '' : 's');
}

// Domains stored before normalisation keep their protocol and www, which means
// a client's own site never matches its own ads. Repair them.
$fixed = 0;
if (table_exists('sites')) {
    foreach (db()->query('SELECT id, domain FROM sites')->fetchAll() as $row) {
        $clean = normalise_domain((string)$row['domain']);
        if ($clean !== '' && $clean !== $row['domain']) {
            db()->prepare('UPDATE sites SET domain = ? WHERE id = ?')->execute([$clean, (int)$row['id']]);
            $fixed++;
        }
    }
}
if (table_exists('clients')) {
    foreach (db()->query('SELECT id, domains FROM clients WHERE domains IS NOT NULL')->fetchAll() as $row) {
        $clean = normalise_domain_list((string)$row['domains']);
        if ($clean !== $row['domains']) {
            db()->prepare('UPDATE clients SET domains = ? WHERE id = ?')->execute([$clean ?: null, (int)$row['id']]);
            $fixed++;
        }
    }
}
if (table_exists('trackers')) {
    foreach (db()->query('SELECT id, watch_domains FROM trackers WHERE watch_domains IS NOT NULL')->fetchAll() as $row) {
        $clean = normalise_domain_list((string)$row['watch_domains']);
        if ($clean !== $row['watch_domains']) {
            db()->prepare('UPDATE trackers SET watch_domains = ? WHERE id = ?')->execute([$clean ?: null, (int)$row['id']]);
            $fixed++;
        }
    }
}
if ($fixed > 0) {
    printf("  tidied %d domain%s so a client's own ads are recognised as theirs\n",
        $fixed, $fixed === 1 ? '' : 's');
}

if ($seedNeeded && is_readable(__DIR__ . '/inc/geo.php')) {
    require_once __DIR__ . '/inc/geo.php';
    $n = seed_locations_from_csv(__DIR__ . '/data/locations-seed.csv');
    printf("  loaded %s locations\n", number_format($n));
}

echo "\n$line\n RESULT\n$line\n";
$after = [
    'users'         => count_rows('users'),
    'trackers'      => count_rows('trackers'),
    'runs'          => count_rows('runs'),
    'ad_placements' => count_rows('ad_placements'),
    'clients'       => count_rows('clients'),
    'locations'     => count_rows('locations'),
];
foreach ($after as $t => $n) {
    $was = $before[$t] ?? null;
    $note = ($was !== null && $was !== $n) ? "  (was " . number_format($was) . ")" : '';
    printf("    %-16s %s rows%s\n", $t, number_format($n), $note);
}

$lost = [];
foreach (['users', 'trackers', 'runs', 'ad_placements'] as $t) {
    if (($after[$t] ?? 0) < ($before[$t] ?? 0)) {
        $lost[] = $t;
    }
}
echo "\n";
if ($lost) {
    echo "  WARNING: row counts fell in: " . implode(', ', $lost) . ". Restore your backup.\n";
    exit(1);
}
echo "  Upgrade complete. Nothing was lost.\n";
echo "  Sign in as usual, then rename \"Unassigned\" or move schedules to real clients.\n";
