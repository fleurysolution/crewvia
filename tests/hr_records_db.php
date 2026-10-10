<?php
/**
 * P2-M06 at the database level, on the isolated test database only:
 * rollback, upgrade, silence on a repeat, then the people hr_records_http.py
 * is tested on: one on a live assignment, two whose assignments ended, a
 * safety incident on the project.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: this test only runs against rss_ops_test.\n"); exit(2); }

$results = [];
function check(string $label, bool $condition, string $detail = ''): void
{
    global $results;
    if (! $condition) { fwrite(STDERR, 'FAIL ' . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL); exit(1); }
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
    $out = []; $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}
$table = static fn(string $t): bool => (bool) val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
$app = __DIR__ . '/test-app';
$all = ['recognitions', 'disciplinary_cases', 'disciplinary_notes', 'separations', 'hr_access_grants', 'hr_access_log'];

check('P2-M06 is in place before the rollback', count(array_filter($all, $table)) === 6);
sql_file($app . '/install/rollback/p2-m06.sql');
check('Rollback removes every P2-M06 table', count(array_filter($all, $table)) === 0);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback succeeds and says only administrators see cases', $code === 0 && str_contains($first, 'HR records:') && str_contains($first, 'only administrators see cases and separations'), $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P2-M06', $code === 0 && ! str_contains($second, 'HR records:'), $second);

$fixture  = json_decode((string) file_get_contents(__DIR__ . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['admin' => 'admin', 'rec' => 'recruiter', 'rec2' => 'recruiter', 'payroll' => 'payroll', 'sup' => 'supervisor', 'sup2' => 'supervisor', 'worker' => 'worker'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['HR ' . $k, 'hrr-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status) VALUES (?,'P2-M06 project','Synthetic project for HR records.',25,40,0,0,0,'active')", [(int) $fixture['client']]);
$job = (int) db()->lastInsertId();
$person = static function (string $label, string $status) use ($job, $ids): array {
    q('INSERT INTO candidates(full_name,email) VALUES (?,?)', ['HR ' . $label, 'hrr-c-' . $label . '@test.invalid']);
    $cid = (int) db()->lastInsertId();
    q('INSERT INTO placements(candidate_id,job_id,status,start_date,end_date,pay_rate,bill_rate) VALUES (?,?,?,DATE_SUB(CURDATE(), INTERVAL 30 DAY),?,25,40)',
      [$cid, $job, $status, $status === 'completed' ? date('Y-m-d', strtotime('-2 days')) : null]);
    $pid = (int) db()->lastInsertId();
    q("INSERT INTO assignment_details(placement_id,trade,supervisor_id) VALUES (?,'Welder',?)", [$pid, $ids['sup']]);
    return ['cid' => $cid, 'pid' => $pid];
};
$a = $person('alpha', 'on_site');
$b = $person('bravo', 'completed');
$c = $person('charlie', 'completed');
q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$ids['worker'], $a['cid']]);
q("INSERT INTO safety_incidents(job_id,reported_by,description,severity) VALUES (?,?,'Ladder left unsecured on the pipe rack','medium')", [$job, $ids['sup']]);
$incident = (int) db()->lastInsertId();

file_put_contents(__DIR__ . '/hrr.json', json_encode(['ids' => $ids, 'job' => $job, 'a' => $a, 'b' => $b, 'c' => $c, 'incident' => $incident,
    'today' => date('Y-m-d'), 'yesterday' => date('Y-m-d', strtotime('-1 day')), 'tomorrow' => date('Y-m-d', strtotime('+1 day'))]));
file_put_contents(__DIR__ . '/hrr-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
