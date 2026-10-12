<?php
/**
 * The P5-M12 test's eyes, on the test database only: the fixture's
 * candidates with their coordinates, and the projects' sites.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$fx = json_decode((string) file_get_contents(__DIR__ . '/geo.json'), true);
$cands = implode(',', array_map('intval', $fx['cands']));
echo json_encode([
    'candidates' => rows("SELECT id, city, state, zip, geo_lat, geo_lng, geo_from, geo_state FROM candidates WHERE id IN ($cands) ORDER BY id"),
    'jobs' => rows("SELECT id, title, site_city, site_state, site_zip FROM jobs WHERE title LIKE 'P5-M12%' ORDER BY id"),
]);
