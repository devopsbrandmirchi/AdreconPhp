<?php
declare(strict_types=1);

/**
 * Load Google's own geo targets list, which covers small towns, counties,
 * neighborhoods, postal codes and airports. The bundled seed only carries
 * cities over roughly 15,000 people, so towns like Peru, Indiana are missing
 * until you run this.
 *
 * 1. Open https://developers.google.com/google-ads/api/data/geotargets
 * 2. Download the latest zipped CSV and unzip it
 * 3. Upload it to the server, then over SSH:
 *
 *      php seed_locations.php /path/to/geotargets.csv          (US only)
 *      php seed_locations.php /path/to/geotargets.csv CA       (Canada)
 *      php seed_locations.php /path/to/geotargets.csv all      (everything)
 *
 * Safe to re-run. Existing rows are updated rather than duplicated.
 */

// See worker.php for why this checks the request context and not PHP_SAPI.
if (!empty($_SERVER['REQUEST_METHOD']) || !empty($_SERVER['REMOTE_ADDR'])
    || !empty($_SERVER['HTTP_HOST']) || !empty($_SERVER['REQUEST_URI'])) {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/geo.php';

$path    = $argv[1] ?? '';
$country = $argv[2] ?? 'US';

if ($path === '' || !is_readable($path)) {
    fwrite(STDERR, "Usage: php seed_locations.php /path/to/geotargets.csv [US|all]\n");
    exit(1);
}

fwrite(STDOUT, "Importing $path, country filter: $country\n");
$before = locations_count();
$start  = microtime(true);

$res = import_google_geotargets($path, $country);

if ($res['error']) {
    fwrite(STDERR, 'Failed: ' . $res['error'] . "\n");
    exit(1);
}

printf(
    "Processed %s rows, skipped %s. Locations went from %s to %s in %.1fs.\n",
    number_format($res['inserted']),
    number_format($res['skipped']),
    number_format($before),
    number_format(locations_count()),
    microtime(true) - $start
);
