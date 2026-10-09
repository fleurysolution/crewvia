<?php
/**
 * P1-M01 at the database level, on the isolated test database only.
 *
 *   1. down: install/rollback/p1-m01.sql removes everything P1-M01 added
 *   2. records written the way they were before P1-M01 existed
 *   3. up:   install/upgrade.php adds it back and backfills them
 *   4. up again: silent for P1-M01
 *   5. install/verify-relationships.php: clean, then catches an orphan
 *
 * The scenario rows are removed afterwards. Three staff accounts and two
 * candidates are left for hr_relationships_http.py, described in m01.json.
 *
 * Synthetic data only. Refuses to run unless the database is the test one.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') {
    fwrite(STDERR, "Refusing: this test only runs against rss_ops_test.\n");
    exit(2);
}

$results = [];

function check(string $label, bool $condition, string $detail = ''): void
{
    global $results;

    if (! $condition) {
        fwrite(STDERR, 'FAIL ' . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL);
        exit(1);
    }

    $results[] = $label;
    echo 'PASS ' . $label . PHP_EOL;
}

function sql_file(string $path): void
{
    foreach (preg_split('/;\s*\n/', (string) file_get_contents($path)) as $chunk) {
        $lines = array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'));
        $statement = trim(implode("\n", $lines));

        if ($statement !== '') { db()->exec($statement); }
    }
}

function run_php(string $script): array
{
    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $out, $code);

    return [$code, implode("\n", $out)];
}

$has = static fn(string $table, ?string $column = null): bool => (bool) ($column === null
    ? val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table])
    : val('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $column]));

$app = __DIR__ . '/test-app';

// ── 1. down ────────────────────────────────────────────────────────────
check('P1-M01 is in place before the rollback',
      $has('employee_classifications') && $has('placement_rate_changes')
      && $has('placements', 'vacancy_id') && $has('placements', 'order_line_id')
      && $has('employee_profiles', 'flsa_status'));

$before = [
    'placements' => (int) val('SELECT COUNT(*) FROM placements'),
    'profiles'   => (int) val('SELECT COUNT(*) FROM employee_profiles'),
    'candidates' => (int) val('SELECT COUNT(*) FROM candidates'),
];

sql_file($app . '/install/rollback/p1-m01.sql');

check('Rollback removes every P1-M01 object',
      ! $has('employee_classifications') && ! $has('placement_rate_changes')
      && ! $has('placements', 'vacancy_id') && ! $has('placements', 'order_line_id')
      && ! $has('employee_profiles', 'flsa_status'));

check('Rollback leaves the records that existed before it',
      (int) val('SELECT COUNT(*) FROM placements') === $before['placements']
      && (int) val('SELECT COUNT(*) FROM employee_profiles') === $before['profiles']
      && (int) val('SELECT COUNT(*) FROM candidates') === $before['candidates']);

// ── 2. the world as it was before P1-M01 ───────────────────────────────
q("INSERT INTO clients(name) VALUES ('M01 scenario client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,description,status) VALUES (?,'M01 scenario project','Synthetic.','active')", [$client]);
$job = (int) db()->lastInsertId();
q("INSERT INTO job_order_lines(job_id,role_title,quantity,pay_rate,bill_rate,sort_order) VALUES (?,'M01 fitter',2,40,70,1)", [$job]);
$lineFitter = (int) db()->lastInsertId();
q("INSERT INTO job_order_lines(job_id,role_title,quantity,pay_rate,bill_rate,sort_order) VALUES (?,'M01 rigger',2,45,75,2)", [$job]);
$lineRigger = (int) db()->lastInsertId();
q("INSERT INTO vacancies(job_id,order_line_id,title,description,discipline,openings) VALUES (?,?,'M01 fitter','Synthetic.','other',2)", [$job, $lineFitter]);
$vFitter = (int) db()->lastInsertId();
q("INSERT INTO vacancies(job_id,order_line_id,title,description,discipline,openings) VALUES (?,?,'M01 rigger','Synthetic.','other',2)", [$job, $lineRigger]);
$vRigger = (int) db()->lastInsertId();

$person = static function (string $label, string $type) {
    q('INSERT INTO candidates(full_name,email) VALUES (?,?)', [$label, strtolower(str_replace(' ', '-', $label)) . '@test.invalid']);
    $id = (int) db()->lastInsertId();
    q('INSERT INTO employee_profiles(candidate_id,employment_type) VALUES (?,?)', [$id, $type]);

    return $id;
};

$oneTrade  = $person('M01 one trade', 'hourly');
$twoTrades = $person('M01 two trades', 'salaried');
$noApply   = $person('M01 no application', 'contractor');

q("INSERT INTO applications(candidate_id,vacancy_id,stage) VALUES (?,?,'screening')", [$oneTrade, $vFitter]);
q("INSERT INTO applications(candidate_id,vacancy_id,stage) VALUES (?,?,'screening')", [$oneTrade, $vFitter]);
q("INSERT INTO applications(candidate_id,vacancy_id,stage) VALUES (?,?,'screening')", [$twoTrades, $vFitter]);
q("INSERT INTO applications(candidate_id,vacancy_id,stage) VALUES (?,?,'screening')", [$twoTrades, $vRigger]);

$placement = static function (int $candidate) use ($job) {
    q("INSERT INTO placements(candidate_id,job_id,status,start_date) VALUES (?,?,'offered','2026-10-01')", [$candidate, $job]);

    return (int) db()->lastInsertId();
};

$pOne = $placement($oneTrade);
$pTwo = $placement($twoTrades);
$pNone = $placement($noApply);

// ── 3. up ──────────────────────────────────────────────────────────────
[$code, $first] = run_php($app . '/install/upgrade.php');

check('Upgrade after the rollback succeeds', $code === 0, $first);
check('Upgrade announces the overtime status column', str_contains($first, 'People: overtime status added to employee profiles.'));
check('Upgrade announces the classification backfill', str_contains($first, 'People: classification history started for'));
check('Upgrade announces the assignment backfill', str_contains($first, 'Assignments: '));

$link = static fn(int $id) => row('SELECT vacancy_id, order_line_id FROM placements WHERE id = ?', [$id]);

check('A person who applied to one requisition is linked to it and its line',
      (int) $link($pOne)['vacancy_id'] === $vFitter && (int) $link($pOne)['order_line_id'] === $lineFitter);
check('A person who applied to two requisitions is left unlinked, not guessed',
      $link($pTwo)['vacancy_id'] === null && $link($pTwo)['order_line_id'] === null);
check('A person who applied to none is left unlinked', $link($pNone)['vacancy_id'] === null);

$k = static fn(int $id) => rows('SELECT employment_type, flsa_status, effective_from, recorded_by FROM employee_classifications WHERE candidate_id = ?', [$id]);
$f = static fn(int $id) => (string) val('SELECT flsa_status FROM employee_profiles WHERE candidate_id = ?', [$id]);

check('Every existing profile gets exactly one initial classification row',
      count($k($oneTrade)) === 1 && count($k($twoTrades)) === 1 && count($k($noApply)) === 1);
check('The initial row has no invented date and no invented author',
      $k($oneTrade)[0]['effective_from'] === null && $k($oneTrade)[0]['recorded_by'] === null);
check('Hourly starts non-exempt', $k($oneTrade)[0]['flsa_status'] === 'non_exempt' && $f($oneTrade) === 'non_exempt');
check('Salaried is left for a person to decide', $k($twoTrades)[0]['flsa_status'] === 'not_determined' && $f($twoTrades) === 'not_determined');
check('A contractor is not classified for overtime', $k($noApply)[0]['flsa_status'] === 'not_applicable' && $f($noApply) === 'not_applicable');

// ── 4. up again ────────────────────────────────────────────────────────
[$code, $second] = run_php($app . '/install/upgrade.php');

check('A second upgrade succeeds', $code === 0, $second);
check('A second upgrade is silent for P1-M01',
      ! str_contains($second, 'overtime status added')
      && ! str_contains($second, 'classification history started')
      && ! str_contains($second, 'Assignments: '), $second);
check('A second upgrade writes no second classification row', count($k($oneTrade)) === 1);

// ── 5. the integrity check ─────────────────────────────────────────────
[$code, $report] = run_php($app . '/install/verify-relationships.php');
check('Relationship check is clean on consistent data', $code === 0, $report);

// The point is a row no constraint would allow, so the check is suspended
// for this one insert, exactly as a bad import or a manual fix would.
q('SET FOREIGN_KEY_CHECKS = 0');
q("INSERT INTO placements(candidate_id,job_id,status) VALUES (999999999,?,'offered')", [$job]);
$orphan = (int) db()->lastInsertId();
q('SET FOREIGN_KEY_CHECKS = 1');
[$code, $report] = run_php($app . '/install/verify-relationships.php');
check('Relationship check catches a placement without a person',
      $code === 1 && (bool) preg_match('/placements without a person\s+1/', $report), $report);
q('DELETE FROM placements WHERE id = ?', [$orphan]);

q('UPDATE employee_profiles SET employment_type = ? WHERE candidate_id = ?', ['salaried', $oneTrade]);
[$code, $report] = run_php($app . '/install/verify-relationships.php');
check('Relationship check catches a profile that disagrees with its history', $code === 1, $report);
q('UPDATE employee_profiles SET employment_type = ? WHERE candidate_id = ?', ['hourly', $oneTrade]);

// ── clean the scenario away ────────────────────────────────────────────
$people = [$oneTrade, $twoTrades, $noApply];
$in = implode(',', array_fill(0, count($people), '?'));
q("DELETE FROM employee_classifications WHERE candidate_id IN ($in)", $people);
q("DELETE FROM employee_profiles WHERE candidate_id IN ($in)", $people);
q("DELETE FROM placements WHERE candidate_id IN ($in)", $people);
q("DELETE FROM applications WHERE candidate_id IN ($in)", $people);
q("DELETE FROM candidates WHERE id IN ($in)", $people);
q('DELETE FROM vacancies WHERE job_id = ?', [$job]);
q('DELETE FROM job_order_lines WHERE job_id = ?', [$job]);
q('DELETE FROM jobs WHERE id = ?', [$job]);
q('DELETE FROM clients WHERE id = ?', [$client]);

check('Scenario removed; the records from before it are intact',
      (int) val('SELECT COUNT(*) FROM placements') === $before['placements']
      && (int) val('SELECT COUNT(*) FROM candidates') === $before['candidates']);

// ── what the HTTP test needs ───────────────────────────────────────────
$fixture = json_decode((string) file_get_contents(__DIR__ . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);

foreach (['recruiter', 'payroll', 'hotels'] as $role) {
    q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)",
      ['M01 ' . $role, 'm01-' . $role . '@test.invalid', $password, $role]);
}

// To be placed through the screen, so the link is written by the code path.
q("INSERT INTO candidates(full_name,email) VALUES ('M01 to be placed','m01-place@test.invalid')");
$toPlace = (int) db()->lastInsertId();
q("INSERT INTO applications(candidate_id,vacancy_id,stage) VALUES (?,?,'screening')", [$toPlace, $fixture['vac']]);

// Placed before the link existed: the page must say its line is inferred.
q("INSERT INTO candidates(full_name,email) VALUES ('M01 placed before','m01-before@test.invalid')");
$placedBefore = (int) db()->lastInsertId();
q("INSERT INTO applications(candidate_id,vacancy_id,stage) VALUES (?,?,'screening')", [$placedBefore, $fixture['vac']]);
q("INSERT INTO placements(candidate_id,job_id,status,start_date) VALUES (?,?,'offered','2026-10-01')", [$placedBefore, $fixture['a']]);
$placedBeforeId = (int) db()->lastInsertId();

file_put_contents(__DIR__ . '/m01.json', json_encode([
    'to_place'          => $toPlace,
    'placed_before'     => $placedBefore,
    'placed_before_pid' => $placedBeforeId,
]));

file_put_contents(__DIR__ . '/m01-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
