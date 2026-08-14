<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/scheduler.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}
check_csrf();

$do  = (string)($_POST['do'] ?? '');
$id  = (int)($_POST['id'] ?? 0);
$pdo = db();

function load_tracker(int $id): ?array {
    $s = db()->prepare('SELECT * FROM trackers WHERE id = ?');
    $s->execute([$id]);
    $t = $s->fetch() ?: null;
    // A tracker outside the user's clients may as well not exist.
    return ($t && can_see_tracker($t)) ? $t : null;
}

/**
 * A home for schedules created without naming a client, so nothing is ever
 * orphaned and invisible.
 */
function default_client_id(): int {
    $id = (int)db()->query("SELECT id FROM clients WHERE name = 'Unassigned'")->fetchColumn();
    if ($id) {
        return $id;
    }
    db()->prepare('INSERT IGNORE INTO clients (name, notes) VALUES (?, ?)')
        ->execute(['Unassigned', 'Schedules created without a client.']);
    return (int)db()->query("SELECT id FROM clients WHERE name = 'Unassigned'")->fetchColumn();
}

/** Restrict a set of tracker ids to those the user may act on. */
function permitted_ids(array $ids): array {
    $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    [$scope, $scopeParams] = client_scope_sql('client_id');
    $st = db()->prepare("SELECT id FROM trackers WHERE id IN ($in) AND $scope");
    $st->execute(array_merge($ids, $scopeParams));
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

switch ($do) {

    case 'create':
        $keyword  = trim((string)($_POST['keyword'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $interval = (int)($_POST['interval_hours'] ?? 3);
        $deviceIn = (string)($_POST['device'] ?? 'desktop');
        $tz       = (string)($_POST['timezone'] ?? cfg('default_timezone'));
        $watch    = normalise_domain_list((string)($_POST['watch_domains'] ?? ''));

        if ($keyword === '' || $location === '') {
            flash('Enter both a keyword and a location.', 'err');
            redirect('index.php');
        }
        if (!in_array($interval, ALLOWED_INTERVALS, true)) {
            $interval = 3;
        }
        if (!in_array($tz, timezone_identifiers_list(), true)) {
            $tz = 'UTC';
        }

        // Normalize spacing so "Arlington,Texas,United States" and the spaced
        // form do not become two different schedules.
        $location = implode(', ', array_filter(array_map('trim', explode(',', $location)), fn($p) => $p !== ''));

        $devices = $deviceIn === 'both' ? ['desktop', 'mobile']
                 : [$deviceIn === 'mobile' ? 'mobile' : 'desktop'];

        $clientId = (int)($_POST['client_id'] ?? 0);
        if (!$clientId || !can_see_client($clientId)) {
            $clientId = default_client_id();
        }

        $made = 0;
        $dupe = 0;
        foreach ($devices as $device) {
            try {
                $pdo->prepare(
                    "INSERT INTO trackers
                     (client_id, keyword, location, device, interval_hours, duration_days,
                      timezone, watch_domains, status, next_run_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'paused', ?)"
                )->execute([$clientId, $keyword, $location, $device, $interval,
                            clamp_duration((int)($_POST['duration_days'] ?? DEFAULT_DURATION_DAYS)),
                            $tz, $watch ?: null, next_run_at($interval, $tz)]);
                $made++;
            } catch (PDOException $e) {
                if ((int)$e->getCode() === 23000) {
                    $dupe++;
                } else {
                    flash('Could not save that keyword. Try again, and if it keeps failing check the server error log.', 'err');
                    redirect('index.php');
                }
            }
        }

        if ($made && $dupe) {
            flash("Added $made. $dupe were already tracked.");
        } elseif ($made) {
            flash($made > 1
                ? 'Added on desktop and mobile. Press Start on each.'
                : 'Keyword added. Press Start when you want it checked.');
        } else {
            flash('That keyword is already tracked for that location and device.', 'err');
        }
        redirect('index.php');

    case 'user_create':
        require_admin();
        $un   = trim((string)($_POST['username'] ?? ''));
        $em   = trim((string)($_POST['email'] ?? ''));
        $pw   = (string)($_POST['password'] ?? '');
        $role = ($_POST['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
        $back = (string)($_POST['redirect'] ?? '');
        if ($back !== 'admin.php') {
            $back = 'users.php';
        }

        if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $un)) {
            flash('Usernames are 3 to 60 characters: letters, numbers, dot, dash or underscore.', 'err');
            redirect($back);
        }
        if (strlen($pw) < 8) {
            flash('Use a password of at least 8 characters.', 'err');
            redirect($back);
        }
        if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) {
            flash('That email address is not valid.', 'err');
            redirect($back);
        }
        try {
            $pdo->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)')
                ->execute([$un, $em ?: null, password_hash($pw, PASSWORD_DEFAULT), $role]);
            $newUser = (int)$pdo->lastInsertId();

            $assign = $pdo->prepare('INSERT IGNORE INTO user_clients (user_id, client_id) VALUES (?, ?)');
            foreach ((array)($_POST['client_ids'] ?? []) as $cid) {
                $assign->execute([$newUser, (int)$cid]);
            }
            flash('Account created for ' . $un . '. Send them the password now, it cannot be shown again.');
        } catch (PDOException $e) {
            flash((int)$e->getCode() === 23000 ? 'That username is already taken.'
                                               : 'Could not create that account. Try again.', 'err');
        }
        redirect($back === 'admin.php' ? 'admin.php#access' : $back);

    case 'access_matrix':
        require_admin();
        // Every member listed on the form is rewritten, so unticking a whole
        // row correctly removes access rather than being a silent no-op.
        $members = array_map('intval', (array)($_POST['members'] ?? []));
        $grant   = (array)($_POST['grant'] ?? []);
        $del     = $pdo->prepare('DELETE FROM user_clients WHERE user_id = ?');
        $add     = $pdo->prepare('INSERT IGNORE INTO user_clients (user_id, client_id) VALUES (?, ?)');
        $granted = 0;
        foreach ($members as $uid) {
            if ($uid <= 0) { continue; }
            $del->execute([$uid]);
            foreach ((array)($grant[$uid] ?? []) as $cid) {
                $add->execute([$uid, (int)$cid]);
                $granted++;
            }
        }
        flash('Saved. ' . $granted . ' client assignment' . ($granted === 1 ? '' : 's') . ' in total.');
        redirect('admin.php');

    case 'user_clients':
        require_admin();
        $uid = (int)($_POST['user_id'] ?? 0);
        $back = (string)($_POST['redirect'] ?? '');
        if ($back !== 'admin.php') {
            $back = 'users.php';
        }
        if ($uid > 0) {
            // Only rewrite THIS user — never touch other members.
            $who = $pdo->prepare('SELECT username, role FROM users WHERE id = ?');
            $who->execute([$uid]);
            $row = $who->fetch();
            if (!$row || ($row['role'] ?? '') === 'admin') {
                flash('That user cannot be updated here.', 'err');
                redirect($back);
            }
            $pdo->prepare('DELETE FROM user_clients WHERE user_id = ?')->execute([$uid]);
            $assign = $pdo->prepare('INSERT IGNORE INTO user_clients (user_id, client_id) VALUES (?, ?)');
            $n = 0;
            foreach ((array)($_POST['client_ids'] ?? []) as $cid) {
                $cid = (int)$cid;
                if ($cid <= 0) {
                    continue;
                }
                $assign->execute([$uid, $cid]);
                $n++;
            }
            // Keep the agency dropdown choice after Save (including blank / optional).
            start_session();
            if (!isset($_SESSION['access_agency_pick']) || !is_array($_SESSION['access_agency_pick'])) {
                $_SESSION['access_agency_pick'] = [];
            }
            $agencyPick = (string)($_POST['agency_pick'] ?? '');
            if ($agencyPick === '' || $agencyPick === '0' || ctype_digit($agencyPick)) {
                $_SESSION['access_agency_pick'][$uid] = $agencyPick;
            }
            flash('Saved ' . $n . ' client' . ($n === 1 ? '' : 's') . ' for ' . (string)$row['username'] . '.');
        }
        redirect($back);

    case 'user_delete':
        require_admin();
        $uid = (int)($_POST['user_id'] ?? 0);
        $back = (string)($_POST['redirect'] ?? '');
        if ($back !== 'admin.php') {
            $back = 'users.php';
        }
        if ($uid === (int)current_user()['id']) {
            flash('You cannot delete your own account.', 'err');
            redirect($back);
        }
        // Never leave the system without an administrator.
        $admins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        $target = $pdo->prepare('SELECT role FROM users WHERE id = ?');
        $target->execute([$uid]);
        if ($target->fetchColumn() === 'admin' && $admins <= 1) {
            flash('That is the only administrator. Make someone else an administrator first.', 'err');
            redirect($back);
        }
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
        flash('Account deleted. The keywords they set up are untouched.');
        redirect($back);

    case 'site_create':
        $cid = (int)($_POST['client_id'] ?? 0);
        if (!can_see_client($cid)) {
            flash('You do not have access to that client.', 'err');
            redirect('index.php');
        }
        $dom = normalise_domain((string)($_POST['domain'] ?? ''));

        if ($dom === '' || !str_contains($dom, '.') || preg_match('/\s/', $dom)) {
            flash('Enter a website like theirdomain.com', 'err');
            redirect('client.php?id=' . $cid);
        }
        try {
            $pdo->prepare('INSERT INTO sites (client_id, domain, label) VALUES (?, ?, ?)')
                ->execute([$cid, mb_substr($dom, 0, 190), trim((string)($_POST['label'] ?? '')) ?: null]);
            flash('Website added.');
        } catch (PDOException $e) {
            flash((int)$e->getCode() === 23000 ? 'That website is already on this client.'
                                               : 'Could not add that website. Try again.', 'err');
        }
        redirect('client.php?id=' . $cid);

    case 'site_delete':
        $cid = (int)($_POST['client_id'] ?? 0);
        if (!can_see_client($cid)) {
            flash('You do not have access to that client.', 'err');
            redirect('index.php');
        }
        $sid = (int)($_POST['site_id'] ?? 0);
        // Schedules survive: they simply lose their website tag.
        $pdo->prepare('UPDATE trackers SET site_id = NULL WHERE site_id = ? AND client_id = ?')
            ->execute([$sid, $cid]);
        $pdo->prepare('DELETE FROM sites WHERE id = ? AND client_id = ?')->execute([$sid, $cid]);
        flash('Website removed. Its keywords are still tracked, they are just no longer filed under it.');
        redirect('client.php?id=' . $cid);

    case 'agency_create':
        require_admin();
        $an = trim((string)($_POST['name'] ?? ''));
        if ($an === '') {
            flash('Give the agency a name.', 'err');
            redirect('admin.php');
        }
        try {
            $pdo->prepare('INSERT INTO agencies (name) VALUES (?)')->execute([$an]);
            flash('Agency added.');
        } catch (PDOException $e) {
            flash((int)$e->getCode() === 23000 ? 'An agency with that name already exists.'
                                               : 'Could not save the agency. Try again.', 'err');
        }
        redirect('admin.php');

    case 'agency_update':
        require_admin();
        $aid = (int)($_POST['agency_id'] ?? 0);
        $an  = trim((string)($_POST['name'] ?? ''));
        if ($aid > 0 && $an !== '') {
            try {
                $pdo->prepare('UPDATE agencies SET name = ? WHERE id = ?')->execute([$an, $aid]);
                flash('Agency renamed.');
            } catch (PDOException $e) {
                flash('An agency with that name already exists.', 'err');
            }
        }
        redirect('admin.php');

    case 'agency_delete':
        require_admin();
        $aid = (int)($_POST['agency_id'] ?? 0);
        if ($aid > 0) {
            $pdo->prepare('UPDATE clients SET agency_id = NULL WHERE agency_id = ?')->execute([$aid]);
            $pdo->prepare('DELETE FROM agencies WHERE id = ?')->execute([$aid]);
            flash('Agency deleted. Its clients were kept (unassigned).');
        }
        redirect('admin.php');

    case 'client_create':
        $name    = trim((string)($_POST['name'] ?? ''));
        $domains = trim((string)($_POST['domains'] ?? ''));
        $back    = (string)($_POST['redirect'] ?? '');
        if ($back !== 'admin.php') {
            $back = '';
        }
        if ($name === '') {
            flash('Give the client a name.', 'err');
            redirect($back !== '' ? $back : 'index.php');
        }
        try {
            $agencyId = (int)($_POST['agency_id'] ?? 0) ?: null;
            $pdo->prepare('INSERT INTO clients (name, domains, agency_id) VALUES (?, ?, ?)')
                ->execute([$name, normalise_domain_list($domains) ?: null, $agencyId]);
            $newId = (int)$pdo->lastInsertId();
            // Whoever creates a client can see it, administrators see everything anyway.
            if (!is_admin()) {
                $pdo->prepare('INSERT IGNORE INTO user_clients (user_id, client_id) VALUES (?, ?)')
                    ->execute([current_user()['id'], $newId]);
            }
            flash('Client added.');
            if ($back !== '') {
                redirect($back);
            }
            redirect('client.php?id=' . $newId);
        } catch (PDOException $e) {
            flash((int)$e->getCode() === 23000 ? 'A client with that name already exists.'
                                               : 'Could not save the client. Try again.', 'err');
        }
        redirect($back !== '' ? $back : 'index.php');

    case 'client_update':
        $cid = (int)($_POST['client_id'] ?? 0);
        $back = (string)($_POST['redirect'] ?? '');
        if ($back !== 'admin.php') {
            $back = '';
        }
        if (!can_see_client($cid)) {
            flash('You do not have access to that client.', 'err');
            redirect('index.php');
        }
        $pdo->prepare('UPDATE clients SET name = ?, domains = ?, agency_id = ? WHERE id = ?')
            ->execute([trim((string)$_POST['name']),
                       normalise_domain_list((string)$_POST['domains']) ?: null,
                       (int)($_POST['agency_id'] ?? 0) ?: null, $cid]);
        flash('Client updated.');
        redirect($back !== '' ? $back : ('client.php?id=' . $cid));

    case 'client_delete':
        require_admin();
        $cid = (int)($_POST['client_id'] ?? 0);
        $back = (string)($_POST['redirect'] ?? '');
        if ($back !== 'admin.php') {
            $back = 'index.php';
        }
        $pdo->prepare('DELETE FROM trackers WHERE client_id = ?')->execute([$cid]);
        $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$cid]);
        flash('Client deleted, along with its websites and keywords.');
        redirect($back);

    case 'bulk':
        $op    = (string)($_POST['op'] ?? '');
        $cid   = (int)($_POST['client_id'] ?? 0);
        $scope = (string)($_POST['scope'] ?? '');

        if ($scope === 'client') {
            // Start all / Stop all / Check all for a whole client.
            if (!can_see_client($cid)) {
                flash('You do not have access to that client.', 'err');
                redirect('index.php');
            }
            $st = $pdo->prepare('SELECT id FROM trackers WHERE client_id = ?');
            $st->execute([$cid]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } else {
            $ids = permitted_ids((array)($_POST['ids'] ?? []));
        }

        if (!$ids) {
            flash('Tick at least one keyword first.', 'err');
            redirect($cid ? 'client.php?id=' . $cid : 'index.php');
        }

        $in = implode(',', array_fill(0, count($ids), '?'));

        if ($op === 'start') {
            $pdo->prepare(
                "UPDATE trackers
                 SET status = 'active', fail_streak = 0, next_run_at = UTC_TIMESTAMP(),
                     runs_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL duration_days DAY)
                 WHERE id IN ($in)"
            )->execute($ids);
            flash(count($ids) . ' keyword(s) started. Each runs for its own number of days, then stops.');
        } elseif ($op === 'stop') {
            $pdo->prepare("UPDATE trackers SET status = 'paused' WHERE id IN ($in)")->execute($ids);
            flash(count($ids) . ' keyword(s) stopped.');
        } elseif ($op === 'delete') {
            $pdo->prepare("DELETE FROM trackers WHERE id IN ($in)")->execute($ids);
            flash(count($ids) . ' keyword(s) deleted, along with everything they had recorded.');
        } elseif ($op === 'move') {
            $sid = (int)($_POST['site_id'] ?? 0);
            if ($sid > 0) {
                // Only onto a site belonging to a client the user can reach.
                $chk = $pdo->prepare('SELECT client_id FROM sites WHERE id = ?');
                $chk->execute([$sid]);
                $owner = $chk->fetchColumn();
                if ($owner === false || !can_see_client((int)$owner)) {
                    flash('That website does not belong to a client you can see.', 'err');
                    redirect($cid ? 'client.php?id=' . $cid : 'index.php');
                }
                $pdo->prepare("UPDATE trackers SET site_id = ? WHERE id IN ($in)")
                    ->execute(array_merge([$sid], $ids));
                flash(count($ids) . ' keyword(s) moved to that website.');
            } else {
                $pdo->prepare("UPDATE trackers SET site_id = NULL WHERE id IN ($in)")->execute($ids);
                flash(count($ids) . ' keyword(s) are no longer filed under a website.');
            }
        } elseif ($op === 'interval') {
            $iv = (int)($_POST['interval_hours'] ?? -1);
            if (!in_array($iv, ALLOWED_INTERVALS, true)) {
                flash('Choose one of the intervals in the list.', 'err');
                redirect($cid ? 'client.php?id=' . $cid : 'index.php');
            }

            // Each schedule keeps its own timezone, so the next slot has to be
            // worked out row by row rather than in one statement.
            $sel = $pdo->prepare("SELECT id, timezone, status FROM trackers WHERE id IN ($in)");
            $sel->execute($ids);
            $upd = $pdo->prepare(
                'UPDATE trackers SET interval_hours = ?, next_run_at = ?, status = ? WHERE id = ?'
            );

            $changed = $revived = 0;
            foreach ($sel->fetchAll() as $row) {
                $status = $row['status'];

                // A completed one-off is no longer complete once it is given a
                // repeating interval, so it goes back to stopped and waits to
                // be started rather than firing unexpectedly.
                if ($status === 'done' && $iv !== ONCE) {
                    $status = 'paused';
                    $revived++;
                }

                $next = ($iv === ONCE && $status === 'active')
                    ? gmdate('Y-m-d H:i:s')
                    : next_run_at($iv, (string)$row['timezone']);

                $upd->execute([$iv, $next, $status, (int)$row['id']]);
                $changed++;
            }

            $label = $iv === ONCE ? 'once, then stop' : 'every ' . $iv . ' hours';
            $msg   = $changed . ' keyword(s) will now be checked ' . $label;
            if ($revived) {
                $msg .= '. ' . $revived . ' finished one-off(s) went back to stopped';
            }
            flash($msg . '.');
        } elseif ($op === 'duration') {
            $days = clamp_duration((int)($_POST['duration_days'] ?? DEFAULT_DURATION_DAYS));

            // Anything already running gets its end date recomputed from now,
            // so the change takes effect rather than waiting for a restart.
            $pdo->prepare(
                "UPDATE trackers SET duration_days = ?,
                    runs_until = CASE WHEN status = 'active'
                                 THEN DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY)
                                 ELSE runs_until END
                 WHERE id IN ($in)"
            )->execute(array_merge([$days, $days], $ids));

            // A schedule that had already finished is now running again.
            $revive = $pdo->prepare(
                "UPDATE trackers
                 SET status = 'active', next_run_at = UTC_TIMESTAMP(),
                     runs_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY)
                 WHERE id IN ($in) AND status = 'expired'"
            );
            $revive->execute(array_merge([$days], $ids));

            $msg = count($ids) . ' keyword(s) will now run for ' . duration_label($days);
            if ($revive->rowCount() > 0) {
                $msg .= '. ' . $revive->rowCount() . ' that had finished are running again';
            }
            flash($msg . '.');
        } elseif ($op === 'check') {
            // Runs inline. Capped so a click cannot hang on a hundred API calls.
            $cap = (int)cfg('bulk_check_max', 25);
            @set_time_limit(0);
            $ran = $failed = 0;
            foreach (array_slice($ids, 0, $cap) as $tid) {
                $t = load_tracker($tid);
                if (!$t) { continue; }
                [$ok] = run_tracker($t, 'manual');
                $ok ? $ran++ : $failed++;
            }
            $msg = "Checked $ran keyword(s)" . ($failed ? ", $failed failed" : '');
            if (count($ids) > $cap) {
                $msg .= '. ' . (count($ids) - $cap) . ' were skipped: the limit is ' . $cap . ' per click';
            }
            flash($msg . '.', $failed ? 'err' : 'ok');
        } else {
            flash('That action was not recognised. Reload the page and try again.', 'err');
        }
        redirect($cid ? 'client.php?id=' . $cid : 'index.php');

    case 'bulk_create':
        $kwRaw  = (string)($_POST['keywords'] ?? '');
        $locRaw = (string)($_POST['locations'] ?? '');
        $clRaw  = (string)($_POST['clusters'] ?? '');

        $clean = function (string $raw): array {
            $out = [];
            foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $out[strtolower($line)] = $line;
                }
            }
            return array_values($out);
        };

        // Preserve order for keyword ↔ cluster pairing (do not dedupe by case alone).
        $keywordsOrdered = [];
        foreach (preg_split('/\r\n|\r|\n/', $kwRaw) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $keywordsOrdered[] = $line;
            }
        }
        // Deduplicate keywords but keep first cluster assignment.
        $seenKw = [];
        $keywords = [];
        $clusterFor = [];
        $clusterLines = preg_split('/\r\n|\r|\n/', $clRaw);
        foreach ($keywordsOrdered as $i => $kw) {
            $key = strtolower($kw);
            if (isset($seenKw[$key])) {
                continue;
            }
            $seenKw[$key] = true;
            $keywords[] = $kw;
            $cl = trim((string)($clusterLines[$i] ?? ''));
            if ($cl === '' || strcasecmp($cl, 'No cluster') === 0) {
                $cl = '';
            }
            $clusterFor[$key] = mb_substr($cl, 0, 100);
        }

        $locations = array_map(
            fn($l) => implode(', ', array_filter(array_map('trim', explode(',', $l)), fn($p) => $p !== '')),
            $clean($locRaw)
        );

        if (!$keywords || !$locations) {
            flash('Add at least one keyword and one location.', 'err');
            $back = (int)($_POST['client_id'] ?? 0);
            redirect($back ? 'client.php?id=' . $back . '&tab=add' : 'index.php');
        }

        $interval = (int)($_POST['interval_hours'] ?? 6);
        if (!in_array($interval, ALLOWED_INTERVALS, true)) {
            $interval = 6;
        }
        $clientId = (int)($_POST['client_id'] ?? 0);
        if (!$clientId || !can_see_client($clientId)) {
            $clientId = default_client_id();
        }
        $deviceIn = (string)($_POST['device'] ?? 'desktop');
        $devices  = $deviceIn === 'both' ? ['desktop', 'mobile']
                  : [$deviceIn === 'mobile' ? 'mobile' : 'desktop'];
        $tz    = (string)($_POST['timezone'] ?? cfg('default_timezone'));
        if (!in_array($tz, timezone_identifiers_list(), true)) {
            $tz = 'UTC';
        }
        $watch = normalise_domain_list((string)($_POST['watch_domains'] ?? ''));

        $days = clamp_duration((int)($_POST['duration_days'] ?? DEFAULT_DURATION_DAYS));

        $siteId = (int)($_POST['site_id'] ?? 0);
        if ($siteId > 0) {
            $chk = $pdo->prepare('SELECT domain FROM sites WHERE id = ? AND client_id = ?');
            $chk->execute([$siteId, $clientId]);
            $siteDomain = $chk->fetchColumn();
            if ($siteDomain === false) {
                $siteId = 0;
            } elseif ($watch === '') {
                // No explicit list, so the site's own domain is what is theirs.
                $watch = (string)$siteDomain;
            }
        }

        $planned = count($keywords) * count($locations) * count($devices);
        if ($planned > BULK_MAX) {
            flash('That would create ' . number_format($planned) . ' keywords. The limit is '
                  . BULK_MAX . ' at a time, so add them in smaller batches.', 'err');
            redirect('client.php?id=' . $clientId . '&tab=add');
        }

        $next = next_run_at($interval, $tz);
        $ins  = $pdo->prepare(
            "INSERT INTO trackers
             (client_id, site_id, keyword, cluster, location, device, interval_hours, duration_days,
              timezone, watch_domains, status, next_run_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paused', ?)"
        );

        $made = $dupe = 0;
        foreach ($keywords as $kw) {
            $cluster = $clusterFor[strtolower($kw)] ?? '';
            foreach ($locations as $loc) {
                foreach ($devices as $device) {
                    try {
                        $ins->execute([$clientId, $siteId ?: null, $kw, $cluster, $loc, $device,
                                       $interval, $days, $tz, $watch ?: null, $next]);
                        $made++;
                    } catch (PDOException $e) {
                        if ((int)$e->getCode() === 23000) {
                            $dupe++;
                        } else {
                            throw $e;
                        }
                    }
                }
            }
        }

        $msg = $made . ' keyword' . ($made === 1 ? '' : 's') . ' added';
        if ($dupe) {
            $msg .= ', ' . $dupe . ' were already tracked';
        }
        flash($msg . '. Nothing is checked until you press Start.', $made ? 'ok' : 'err');
        redirect('client.php?id=' . $clientId . '&tab=clusters');

    case 'start':
        $t = load_tracker($id);
        if ($t) {
            // Start means start: the first check fires on the next worker pass,
            // then it settles onto the interval boundaries. The end date is
            // stamped now, so the window begins when it actually runs rather
            // than when it was created.
            $days = clamp_duration((int)$t['duration_days']);
            $pdo->prepare(
                "UPDATE trackers
                 SET status = 'active', fail_streak = 0, next_run_at = UTC_TIMESTAMP(),
                     runs_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY)
                 WHERE id = ?"
            )->execute([$days, $id]);
            flash('Started. It runs for ' . duration_label($days) . ', then stops itself.');
        }
        break;

    case 'stop':
        $pdo->prepare("UPDATE trackers SET status = 'paused' WHERE id = ?")->execute([$id]);
        flash('Stopped. No more ' . provider_unit() . ' will be spent on it.');
        break;

    case 'check':
        $t = load_tracker($id);
        if (!$t) {
            break;
        }
        set_time_limit(120);
        [$ok, $msg] = run_tracker($t, 'manual');
        flash($ok ? 'Checked. ' . $msg . '.' : 'That check failed: ' . $msg, $ok ? 'ok' : 'err');
        redirect('tracker.php?id=' . $id);

    case 'delete':
        $pdo->prepare('DELETE FROM trackers WHERE id = ?')->execute([$id]);
        flash('Keyword deleted, along with everything it had recorded.');
        redirect('index.php');

    default:
        break;
}

redirect('index.php');
