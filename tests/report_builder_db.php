<?php
/**
 * P4-M03 at the database level, on the isolated test database only:
 * rollback, upgrade, silence on a repeat, then users for
 * report_builder_http.py and a person whose name a spreadsheet would run
 * as a formula, placed on the P4-M01 project (reports_db.php's 2017
 * fixture, which runs before this).
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
$all = ['report_definitions', 'report_export_policy', 'report_schedules', 'report_deliveries', 'report_delivery_recipients', 'report_exports'];

check('P4-M03 is in place before the rollback', count(array_filter($all, $table)) === 6);
sql_file($app . '/install/rollback/p4-m03.sql');
check('Rollback removes every P4-M03 table', count(array_filter($all, $table)) === 0);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback succeeds and announces the report builder, nothing emailed',
      $code === 0 && str_contains($first, 'Report builder: saved reports') && str_contains($first, 'nothing emailed'), $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P4-M03', $code === 0 && ! str_contains($second, 'Report builder:'), $second);
[$code, $out] = run_php($app . '/install/run-report-schedules.php');
check('The schedule runner runs from the command line, with nothing due', $code === 0 && str_contains($out, 'nothing due'), $out);

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['admin' => 'admin', 'payroll' => 'payroll', 'payroll2' => 'payroll', 'recruiter' => 'recruiter', 'hotels' => 'hotels', 'worker' => 'worker'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Builder ' . $k, 'rb-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}

$rp = json_decode((string) file_get_contents(__DIR__ . '/reports.json'), true);
q('INSERT INTO candidates(full_name,email,stage) VALUES (?,?,?)', ['=HYPERLINK("http://example.invalid")', 'rb-formula@test.invalid', 'placed']);
$formula = (int) db()->lastInsertId();
q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate,created_by) VALUES (?,?,'on_site','2017-02-05',30,50,?)", [$formula, $rp['jobA'], $ids['admin']]);

file_put_contents(__DIR__ . '/report-builder.json', json_encode(['ids' => $ids, 'jobA' => $rp['jobA'], 'formula' => $formula]));
file_put_contents(__DIR__ . '/rb-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
