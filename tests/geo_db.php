<?php
/**
 * P5-M12 (T8) at the database level, on the isolated test database only:
 * rollback (candidates and projects kept, only their ZIP codes and
 * coordinates go) and upgrade with the gazetteer loaded, silence on a
 * repeat; how places are read (state names, "St." and "Saint", ZIP codes
 * first) and how far apart they are; then the fixture for geo_http.py.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/geo.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: this test only runs against rss_ops_test.\n"); exit(2); }

$results = [];
function check(string $label, bool $condition, string $detail = ''): void
{
    global $results;
    if (! $condition) { fwrite(STDERR, 'FAIL ' . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL); exit(1); }
    $results[] = $label;
    echo 'PASS ' . $label . PHP_EOL;
}
function run_php(string $script): array
{
    $out = []; $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}
$tables = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('geo_places','geo_zips')");
$columns = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = 'candidates' AND column_name IN ('zip','geo_lat','geo_lng','geo_from','geo_state','geo_basis')) OR (table_name = 'jobs' AND column_name = 'site_zip'))");
$app = __DIR__ . '/test-app';

check('P5-M12 is in place before the rollback', $tables() === 2 && $columns() === 7);
$people = (int) val('SELECT COUNT(*) FROM candidates');
$projects = (int) val('SELECT COUNT(*) FROM jobs');
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m12.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes the gazetteer and the new columns, and keeps every candidate and project', $tables() === 0 && $columns() === 0 && (int) val('SELECT COUNT(*) FROM candidates') === $people && (int) val('SELECT COUNT(*) FROM jobs') === $projects);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade loads the Census gazetteer and says how many people and sites it placed', $code === 0 && str_contains($first, 'Locations: 32,114 places and 33,791 ZIP codes (US Census 2023)') && str_contains($first, 'project site(s) placed'), $first);
check('Every row is in', (int) val('SELECT COUNT(*) FROM geo_places') === 32114 && (int) val('SELECT COUNT(*) FROM geo_zips') === 33791 && $columns() === 7);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M12 and loads nothing twice', $code === 0 && ! str_contains($second, 'Locations:') && (int) val('SELECT COUNT(*) FROM geo_places') === 32114, $second);

// ── how places are read ────────────────────────────────────────────────
check('States are read as codes or names', geo_state_code('Texas') === 'TX' && geo_state_code(' tx ') === 'TX' && geo_state_code('TX.') === 'TX' && geo_state_code('new york') === 'NY' && geo_state_code('Tex') === null && geo_state_code('') === null);
check('"St." and "Saint", "Ft." and "Fort" are the same place', geo_place_key('St. Louis') === 'saint louis' && geo_place_key('Saint Louis') === 'saint louis' && geo_place_key('Ft. Worth') === 'fort worth' && geo_place_key('  Winston-Salem ') === 'winston salem');
$stl = geo_point('St. Louis', 'Missouri');
check('A city and state is found', $stl !== null && $stl['from'] === 'city' && abs($stl['lat'] - 38.6357) < 0.01);
$byZip = geo_point('Somewhere else', 'TX', '77494-1234');
check('A ZIP code wins over the city, and ZIP+4 is read', $byZip !== null && $byZip['from'] === 'zip' && abs($byZip['lat'] - 29.74) < 0.1);
check('An unknown place is not invented', geo_point('Atlantis', 'TX') === null && geo_point('Houston', '') === null && geo_point(null, null, '00000') === null);
$houston = geo_point('Houston', 'TX');
[$distance, $args] = geo_distance_sql($houston, 'x');
$dallasMiles = (float) val("SELECT $distance FROM (SELECT ? AS geo_lat, ? AS geo_lng) x", array_merge($args, [geo_point('Dallas', 'TX')['lat'], geo_point('Dallas', 'TX')['lng']]));
check('Houston to Dallas measures about 225 miles', abs($dallasMiles - 225) < 10, (string) $dallasMiles);

// ── the fixture ────────────────────────────────────────────────────────
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['rec' => 'recruiter', 'admin' => 'admin'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Geo ' . $k, 'geo-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO clients(name) VALUES ('Geo client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status,site_city,site_state) VALUES (?,'P5-M12 Katy plant','active','Katy','TX')", [$client]);
$katy = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status) VALUES (?,'P5-M12 unplaced site','active')", [$client]);
$nowhere = (int) db()->lastInsertId();
q("INSERT INTO vacancies(job_id,title,description,is_open,openings,discipline) VALUES (?,'P5-M12 operator','Synthetic.',1,4,'chemical')", [$katy]);
$vKaty = (int) db()->lastInsertId();
q("INSERT INTO vacancies(job_id,title,description,is_open,openings,discipline) VALUES (?,'P5-M12 elsewhere','Synthetic.',1,1,'chemical')", [$nowhere]);
$vNowhere = (int) db()->lastInsertId();
$cands = [];
foreach (['houston' => ['Geo Houston', 'Houston', 'TX', null], 'austin' => ['Geo Austin', 'Austin', 'Texas', null], 'dallas' => ['Geo Dallas', 'Dallas', 'tx', null],
          'zip' => ['Geo Zipcode', 'Somewhere', 'TX', '77494'], 'unknown' => ['Geo Atlantis', 'Atlantis', 'TX', null], 'stlouis' => ['Geo Stlouis', 'St. Louis', 'MO', null]] as $k => [$name, $city, $state, $zip]) {
    q("INSERT INTO candidates(full_name,email,stage,discipline,city,state,zip) VALUES (?,?,'contacted','chemical',?,?,?)", [$name, 'geo-' . $k . '@test.invalid', $city, $state, $zip]);
    $cands[$k] = (int) db()->lastInsertId();
}
$apps = [];
foreach (['houston', 'dallas', 'zip', 'unknown'] as $k) {
    q("INSERT INTO applications(candidate_id,vacancy_id,stage,source) VALUES (?,?,'new','Indeed')", [$cands[$k], $vKaty]);
    $apps[$k] = (int) db()->lastInsertId();
}
file_put_contents(__DIR__ . '/geo.json', json_encode(['ids' => $ids, 'cands' => $cands, 'apps' => $apps, 'katy' => $katy, 'nowhere' => $nowhere, 'v_katy' => $vKaty, 'v_nowhere' => $vNowhere]));
file_put_contents(__DIR__ . '/geo-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
