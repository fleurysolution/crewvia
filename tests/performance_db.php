<?php
/**
 * P2-M03 at the database level, on the isolated test database only:
 * rollback, upgrade, silence on a repeat, then a project with four
 * assignments (a worker with an account, one without, one with no
 * supervisor, one finished) for performance_http.py.
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
$col = static fn(string $t, string $c): bool => (bool) val('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
$app = __DIR__ . '/test-app';
$all = ['appraisal_cycles', 'performance_goals', 'goal_updates', 'development_actions'];

check('P2-M03 is in place before the rollback', count(array_filter($all, $table)) === 4 && $col('appraisals', 'cycle_id'));
$reviews = (int) val('SELECT COUNT(*) FROM appraisals');
sql_file($app . '/install/rollback/p2-m03.sql');
check('Rollback removes every P2-M03 object', count(array_filter($all, $table)) === 0 && ! $col('appraisals', 'cycle_id'));
check('Rollback keeps every review', (int) val('SELECT COUNT(*) FROM appraisals') === $reviews);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback succeeds and says existing reviews belong to no cycle', $code === 0
      && str_contains($first, 'Performance: evaluation cycles, goals') && str_contains($first, $reviews . ' existing review(s) belong to no cycle'), $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P2-M03', $code === 0 && ! str_contains($second, 'Performance:'), $second);

$fixture  = json_decode((string) file_get_contents(__DIR__ . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['rec' => 'recruiter', 'sup' => 'supervisor', 'sup2' => 'supervisor', 'worker' => 'worker', 'payroll' => 'payroll'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Perf ' . $k, 'perf-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status) VALUES (?,'P2-M03 project','Synthetic project for goals.',20,35,0,0,0,'active')", [(int) $fixture['client']]);
$job = (int) db()->lastInsertId();
$person = static function (string $label, string $status, ?int $supervisor, ?int $worker) use ($job): array {
    q('INSERT INTO candidates(full_name,email) VALUES (?,?)', ['Perf ' . $label, 'perf-c-' . $label . '@test.invalid']);
    $cid = (int) db()->lastInsertId();
    q('INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,?,CURDATE(),20,35)', [$cid, $job, $status]);
    $pid = (int) db()->lastInsertId();
    q("INSERT INTO assignment_details(placement_id,trade,supervisor_id) VALUES (?,'Rigger',?)", [$pid, $supervisor]);
    if ($worker) {
        q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$worker, $cid]);
    }
    return ['cid' => $cid, 'pid' => $pid];
};
$a = $person('alpha', 'on_site', $ids['sup'], $ids['worker']);
$b = $person('bravo', 'on_site', $ids['sup'], null);
$c = $person('charlie', 'on_site', null, null);
$d = $person('delta', 'completed', $ids['sup'], null);

file_put_contents(__DIR__ . '/perf.json', json_encode(['ids' => $ids, 'job' => $job, 'a' => $a, 'b' => $b, 'c' => $c, 'd' => $d,
    'template' => (int) val("SELECT id FROM appraisal_templates WHERE code = 'end_of_assignment'"),
    'today' => date('Y-m-d'), 'in30' => date('Y-m-d', strtotime('+30 days')), 'in60' => date('Y-m-d', strtotime('+60 days')),
    'ago30' => date('Y-m-d', strtotime('-30 days')), 'yesterday' => date('Y-m-d', strtotime('-1 day'))]));
file_put_contents(__DIR__ . '/perf-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
