<?php
declare(strict_types=1);

/**
 * Locations are stored in Google's canonical form, because that is what the
 * search API expects: "Arlington, Texas, United States".
 *
 * Two sources feed this table:
 *   1. data/locations-seed.csv, bundled, US cities over roughly 15,000 people
 *      plus every state and the country itself. Loaded at install.
 *   2. Google's own geo targets CSV, loaded with seed_locations.php. That one
 *      adds small towns, counties, neighborhoods, postal codes and airports.
 */

/** Load the bundled starter CSV. Returns the number of rows inserted. */
function seed_locations_from_csv(string $path): int {
    if (!is_readable($path)) {
        return 0;
    }
    $existing = (int)db()->query('SELECT COUNT(*) FROM locations')->fetchColumn();
    if ($existing > 0) {
        return 0;
    }

    $fh = fopen($path, 'r');
    if (!$fh) {
        return 0;
    }
    fgetcsv($fh); // header

    $pdo  = db();
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO locations (canonical, name, region, type, population)
         VALUES (?, ?, ?, ?, ?)'
    );

    $pdo->beginTransaction();
    $n = 0;
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < 5 || $row[0] === '') {
            continue;
        }
        $stmt->execute([
            trim($row[0]), trim($row[1]), trim($row[2]) ?: null, trim($row[3]) ?: 'City', (int)$row[4],
        ]);
        $n++;
    }
    $pdo->commit();
    fclose($fh);

    return $n;
}

/**
 * Import Google's geo targets CSV.
 * Columns: Criteria ID, Name, Canonical Name, Parent ID, Country Code, Target Type, Status
 * Google writes canonical names without spaces after commas, so they are
 * normalized here to match what the rest of the app stores.
 */
function import_google_geotargets(string $path, string $countryFilter = 'US'): array {
    $fh = fopen($path, 'r');
    if (!$fh) {
        return ['inserted' => 0, 'skipped' => 0, 'error' => 'Could not open ' . $path];
    }

    $header = fgetcsv($fh);
    if (!$header) {
        fclose($fh);
        return ['inserted' => 0, 'skipped' => 0, 'error' => 'Empty file'];
    }
    $idx = array_flip(array_map(fn($h) => strtolower(trim((string)$h)), $header));
    foreach (['name', 'canonical name', 'country code', 'target type', 'status'] as $need) {
        if (!isset($idx[$need])) {
            fclose($fh);
            return ['inserted' => 0, 'skipped' => 0, 'error' => 'Missing column: ' . $need];
        }
    }

    // Rough weights so cities outrank postal codes in autocomplete.
    $weights = [
        'Country' => 1000000, 'State' => 900000, 'County' => 40000, 'City' => 50000,
        'Municipality' => 45000, 'Neighborhood' => 20000, 'City Region' => 20000,
        'Borough' => 25000, 'District' => 20000, 'Postal Code' => 5000,
        'Airport' => 3000, 'University' => 2000, 'TV Region' => 30000,
    ];

    $pdo  = db();
    $stmt = $pdo->prepare(
        'INSERT INTO locations (canonical, name, region, type, population)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE type = VALUES(type), population = GREATEST(population, VALUES(population))'
    );

    $inserted = $skipped = 0;
    $pdo->beginTransaction();

    while (($row = fgetcsv($fh)) !== false) {
        $country = strtoupper(trim((string)($row[$idx['country code']] ?? '')));
        $status  = trim((string)($row[$idx['status']] ?? ''));

        if ($countryFilter !== 'all' && $country !== strtoupper($countryFilter)) {
            $skipped++;
            continue;
        }
        if (strcasecmp($status, 'Active') !== 0) {
            $skipped++;
            continue;
        }

        $canonRaw = trim((string)$row[$idx['canonical name']]);
        if ($canonRaw === '') {
            $skipped++;
            continue;
        }

        $parts  = array_map('trim', explode(',', $canonRaw));
        $canon  = implode(', ', $parts);
        $name   = trim((string)$row[$idx['name']]);
        $region = count($parts) >= 3 ? $parts[count($parts) - 2] : null;
        $type   = trim((string)$row[$idx['target type']]) ?: 'City';

        $stmt->execute([
            mb_substr($canon, 0, 255),
            mb_substr($name, 0, 150),
            $region ? mb_substr($region, 0, 120) : null,
            mb_substr($type, 0, 32),
            $weights[$type] ?? 1000,
        ]);
        $inserted++;

        if ($inserted % 5000 === 0) {
            $pdo->commit();
            $pdo->beginTransaction();
        }
    }

    $pdo->commit();
    fclose($fh);

    return ['inserted' => $inserted, 'skipped' => $skipped, 'error' => null];
}

/** Autocomplete search. Prefix matches on the place name rank first. */
function search_locations(string $q, int $limit = 12): array {
    $q = trim($q);
    if (mb_strlen($q) < 2) {
        return [];
    }
    $prefix = $q . '%';
    $any    = '%' . $q . '%';

    $stmt = db()->prepare(
        'SELECT canonical, name, region, type
         FROM locations
         WHERE name LIKE ? OR canonical LIKE ?
         ORDER BY (name LIKE ?) DESC, (canonical LIKE ?) DESC, population DESC, canonical ASC
         LIMIT ' . (int)$limit
    );
    $stmt->execute([$prefix, $any, $prefix, $prefix]);
    return $stmt->fetchAll();
}

function locations_count(): int {
    try {
        return (int)db()->query('SELECT COUNT(*) FROM locations')->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
