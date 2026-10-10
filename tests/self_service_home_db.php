<?php
/**
 * P2-M07 at the database level, on the isolated test database only:
 * rollback, upgrade, silence on a repeat, then two workers - one with a
 * review waiting for their view and an HR decision to acknowledge - and
 * the desks for self_service_home_http.py.
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

check('P2-M07 is in place before the rollback', $table('hr_requests') && $table('hr_request_replies'));
sql_file($app . '/install/rollback/p2-m07.sql');
check('Rollback removes every P2-M07 table', ! $table('hr_requests') && ! $table('hr_request_replies'));
$workers = (int) val('SELECT COUNT(*) FROM worker_accounts');
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback says how many workers see self-service', $code === 0 && str_contains($first, 'Self-service: workers have one home') && str_contains($first, $workers . ' worker account(s) see it'), $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P2-M07', $code === 0 && ! str_contains($second, 'Self-service:'), $second);

$fixture  = json_decode((string) file_get_contents(__DIR__ . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['worker' => 'worker', 'worker2' => 'worker', 'rec' => 'recruiter', 'payroll' => 'payroll', 'hotels' => 'hotels'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['SS7 ' . $k, 'ss7-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status) VALUES (?,'P2-M07 project','Synthetic project for self-service.',32,50,0,0,0,'active')", [(int) $fixture['client']]);
$job = (int) db()->lastInsertId();
$person = static function (string $label, int $user) use ($job): array {
    q('INSERT INTO candidates(full_name,email) VALUES (?,?)', ['Self ' . $label, 'ss7-c-' . $label . '@test.invalid']);
    $cid = (int) db()->lastInsertId();
    q("INSERT INTO employee_profiles(candidate_id,employment_type,flsa_status,payment_method) VALUES (?, 'hourly', 'non_exempt', 'direct_deposit')", [$cid]);
    q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site',DATE_SUB(CURDATE(), INTERVAL 60 DAY),32,50)", [$cid, $job]);
    $pid = (int) db()->lastInsertId();
    q("INSERT INTO assignment_details(placement_id,trade) VALUES (?,'Pipefitter')", [$pid]);
    q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$user, $cid]);
    return ['cid' => $cid, 'pid' => $pid];
};
$a = $person('alpha', $ids['worker']);
$b = $person('bravo', $ids['worker2']);
// Waiting on Alpha: a review asking for their view, and an HR decision to read.
$template = (int) val("SELECT id FROM appraisal_templates WHERE code = 'end_of_assignment'");
q("INSERT INTO appraisals(template_id,placement_id,candidate_id,status,reviewer_id) VALUES (?,?,?,'self_review',?)", [$template, $a['pid'], $a['cid'], $ids['rec']]);
q("INSERT INTO disciplinary_cases(reference,candidate_id,category,incident_on,facts,status,outcome,outcome_note) VALUES (?,?,'attendance',CURDATE(),'Late three days running in the first week.','decided','verbal_warning','Spoken to about the start time.')",
  ['HR-SS7-' . bin2hex(random_bytes(2)), $a['cid']]);

file_put_contents(__DIR__ . '/ss7.json', json_encode(['ids' => $ids, 'job' => $job, 'a' => $a, 'b' => $b]));
file_put_contents(__DIR__ . '/ss7-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
