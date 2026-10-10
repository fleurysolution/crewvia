<?php
/**
 * P3-M06 at the database level, on the isolated test database only:
 * rollback, upgrade carrying over the vendors already in use, silence on a
 * repeat, then two projects (one with a budget owner and an equipment
 * budget of 1,000) and the people vendors_http.py is tested on.
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
$all = ['vendors', 'vendor_events', 'procurement_thresholds', 'purchase_order_approvals', 'purchase_order_allocations'];

check('P3-M06 is in place before the rollback', count(array_filter($all, $table)) === 5 && $col('purchase_orders', 'approvers'));
$orders = (int) val('SELECT COUNT(*) FROM purchase_orders');
$names = (int) val("SELECT COUNT(DISTINCT n) FROM (SELECT o.vendor_name AS n FROM purchase_orders o UNION SELECT vendor_name FROM vendor_invoices UNION SELECT name FROM hotels) x WHERE TRIM(n) <> ''");
sql_file($app . '/install/rollback/p3-m06.sql');
check('Rollback removes every P3-M06 object', count(array_filter($all, $table)) === 0 && ! $col('purchase_orders', 'approvers') && ! $col('purchase_orders', 'over_budget'));
check('Rollback keeps every order', (int) val('SELECT COUNT(*) FROM purchase_orders') === $orders);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback carries over every vendor already in use, as approved', $code === 0
      && str_contains($first, 'Approved vendors: ' . $names . ' vendor(s) already in use carried over as approved'), $first);
check('Carried-over vendors are approved and say so', (int) val("SELECT COUNT(*) FROM vendors WHERE status = 'approved' AND note LIKE 'Carried over at upgrade%'") === $names);
check('Three tiers: 5,000 budget owner, 25,000 administrator, above both',
      array_map(fn($r) => [$r['up_to'] === null ? null : (float) $r['up_to'], $r['approvers']], rows('SELECT up_to, approvers FROM procurement_thresholds ORDER BY up_to IS NULL, up_to'))
      === [[5000.0, 'budget_owner'], [25000.0, 'admin'], [null, 'both']]);
check('Every existing order is carried wholly by its own project', ! val('SELECT COUNT(*) FROM purchase_orders o WHERE ABS(o.total - COALESCE((SELECT SUM(a.amount) FROM purchase_order_allocations a WHERE a.purchase_order_id = o.id AND a.job_id = o.job_id), -1)) > 0.004'));
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P3-M06', $code === 0 && ! str_contains($second, 'Approved vendors:'), $second);

$fixture  = json_decode((string) file_get_contents(__DIR__ . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['admin' => 'admin', 'admin2' => 'admin', 'hotels' => 'hotels', 'super' => 'supervisor', 'payroll' => 'payroll', 'recruiter' => 'recruiter'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Vendors ' . $k, 'vend-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
$job = static function (string $title, ?int $owner) use ($fixture): int {
    q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status,budget_owner_id) VALUES (?,?,'Synthetic project for vendors.',20,35,0,0,0,'active',?)",
      [(int) $fixture['client'], $title, $owner]);
    return (int) db()->lastInsertId();
};
// B first: a supervisor cannot switch projects and works on the newest, which is A.
$jobB = $job('P3-M06 project B', null);
$jobA = $job('P3-M06 project A', $ids['super']);
q("INSERT INTO project_budget_changes(job_id,category,amount,reason,changed_by) VALUES (?,'equipment',1000,'Signed scope',?)", [$jobA, $ids['admin']]);
$unit = (int) val('SELECT id FROM procurement_units ORDER BY id LIMIT 1');

file_put_contents(__DIR__ . '/vend.json', json_encode(['ids' => $ids, 'job_a' => $jobA, 'job_b' => $jobB, 'unit' => $unit,
    'in_year' => date('Y-m-d', strtotime('+1 year')), 'ago' => date('Y-m-d', strtotime('-3 days')), 'today' => date('Y-m-d')]));
file_put_contents(__DIR__ . '/vend-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
