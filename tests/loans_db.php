<?php
/**
 * P2-M05 at the database level, on the isolated test database only:
 * rollback (a written-off advance becomes cleared), upgrade, silence on a
 * repeat, the default limit, then the people loans_http.py is tested on.
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

check('P2-M05 is in place before the rollback', $table('advance_pauses') && $table('advance_events') && $col('wage_advances', 'first_week')
      && str_contains((string) val("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'wage_advances' AND column_name = 'status'"), 'written_off'));
$fixture = json_decode((string) file_get_contents(__DIR__ . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
q("INSERT INTO candidates(full_name,email) VALUES ('Loan rollback','loan-rb@test.invalid')");
$rb = (int) db()->lastInsertId();
q("INSERT INTO wage_advances(candidate_id,amount,weekly_repayment,status,kind,written_off_amount,written_off_reason) VALUES (?,300,100,'written_off','loan',300,'Left owing')", [$rb]);
$wo = (int) db()->lastInsertId();
$advances = (int) val('SELECT COUNT(*) FROM wage_advances');
sql_file($app . '/install/rollback/p2-m05.sql');
check('Rollback removes every P2-M05 object and the limit', ! $table('advance_pauses') && ! $table('advance_events') && ! $col('wage_advances', 'kind') && ! $col('wage_advances', 'first_week')
      && ! val("SELECT COUNT(*) FROM platform_settings WHERE setting_key = 'advance_admin_above'"));
check('Rollback keeps every advance, and a written-off one becomes cleared so payroll does not take it again',
      (int) val('SELECT COUNT(*) FROM wage_advances') === $advances && val('SELECT status FROM wage_advances WHERE id = ?', [$wo]) === 'cleared');
$repaying = (int) val("SELECT COUNT(*) FROM wage_advances WHERE status = 'paid_out'");
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback succeeds and keeps the advances being repaid as they are', $code === 0
      && str_contains($first, 'Loans and advances:') && str_contains($first, $repaying . ' advance(s) being repaid keep their weekly amount; above $1,000 an administrator approves'), $first);
check('Existing advances become advances, starting the week they were paid out', ! val("SELECT COUNT(*) FROM wage_advances WHERE kind <> 'advance' OR first_week IS NOT NULL"));
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P2-M05', $code === 0 && ! str_contains($second, 'Loans and advances:'), $second);

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['pay1' => 'payroll', 'pay2' => 'payroll', 'admin' => 'admin', 'rec' => 'recruiter'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Loans ' . $k, 'loan-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status) VALUES (?,'P2-M05 project','Synthetic project for loans.',25,40,0,0,0,'active')", [(int) $fixture['client']]);
$job = (int) db()->lastInsertId();
q("INSERT INTO candidates(full_name,email) VALUES ('Loan alpha','loan-alpha@test.invalid')");
$cid = (int) db()->lastInsertId();
q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site',DATE_SUB(CURDATE(), INTERVAL 30 DAY),25,40)", [$cid, $job]);
$pid = (int) db()->lastInsertId();

$week = static fn(int $days): string => week_ending(date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' days')));
file_put_contents(__DIR__ . '/loan.json', json_encode(['ids' => $ids, 'job' => $job, 'cid' => $cid, 'pid' => $pid,
    'this_week' => $week(0), 'next_week' => $week(7), 'in2' => $week(14), 'in3' => $week(21), 'ago1' => $week(-7), 'ago2' => $week(-14), 'today' => date('Y-m-d')]));
file_put_contents(__DIR__ . '/loan-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
