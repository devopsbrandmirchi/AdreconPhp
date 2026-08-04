<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/scheduler.php';

require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

reap_stale_runs();

$idsRaw = (string)($_GET['ids'] ?? '');
$ids    = array_values(array_filter(array_map('intval', explode(',', $idsRaw)), fn($i) => $i > 0));
$ids    = array_slice($ids, 0, 100);

$out = [];
foreach ($ids as $id) {
    $s = tracker_live_status($id);
    if (!empty($s['exists'])) {
        $out[] = $s;
    }
}

echo json_encode(['trackers' => $out, 'now' => gmdate('c')]);
