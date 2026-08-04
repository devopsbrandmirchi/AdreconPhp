<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/geo.php';
require __DIR__ . '/inc/provider.php';

require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=300');

$q = (string)($_GET['q'] ?? '');

try {
    $local = search_locations($q);

    // When the provider publishes its own location list, ask it. That list is
    // authoritative for what the location parameter will actually accept, and
    // it reaches further than the bundled seed: postal codes, counties, DMA
    // regions and small towns. SerpApi's lookup is free and costs no search.
    if (provider_name() === 'serpapi' && cfg('use_provider_locations', true) && mb_strlen(trim($q)) >= 2) {
        $remote = sp_locations_search($q, 10);

        if ($remote) {
            // Cache them so the next lookup is instant and works offline.
            $ins = db()->prepare(
                'INSERT INTO locations (canonical, name, region, type, population)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE type = VALUES(type),
                                         population = GREATEST(population, VALUES(population))'
            );
            $seen = [];
            foreach ($local as $l) {
                $seen[strtolower($l['canonical'])] = true;
            }

            $merged = [];
            foreach ($remote as $r) {
                try {
                    $ins->execute([
                        mb_substr($r['canonical'], 0, 255),
                        mb_substr($r['name'], 0, 150),
                        $r['region'] ? mb_substr($r['region'], 0, 120) : null,
                        mb_substr($r['type'], 0, 32),
                        $r['reach'],
                    ]);
                } catch (Throwable $e) {
                    // Caching is a convenience, never a reason to fail a lookup.
                }
                if (!isset($seen[strtolower($r['canonical'])])) {
                    $merged[] = [
                        'canonical' => $r['canonical'],
                        'name'      => $r['name'],
                        'region'    => $r['region'],
                        'type'      => $r['type'],
                    ];
                }
            }
            // Provider results first: those are guaranteed to be accepted.
            $local = array_merge($merged, $local);
        }
    }

    echo json_encode(array_slice($local, 0, 12), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([]);
}
