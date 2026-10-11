<?php
/**
 * P5-M03 at the database level, on the isolated test database only:
 * rollback (every item kept, a disposed item retired again) and upgrade,
 * a past repair cost carried into the service history once, silence on a
 * repeat, then the fixture for asset_lifecycle_http.py.
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
function run_php(string $script): array
{
    $out = []; $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}
$tables = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('asset_inspections','asset_maintenance_plans','asset_service_records')");
$cols = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = 'equipment' AND column_name IN ('in_service_on','useful_life_months','residual_value','retired_on','disposed_on','disposal_method','disposal_proceeds')) OR (table_name = 'asset_categories' AND column_name IN ('asset_class','useful_life_months','residual_percent')))");
$app = __DIR__ . '/test-app';

check('P5-M03 is in place before the rollback', $tables() === 3 && $cols() === 10);
$items = (int) val('SELECT COUNT(*) FROM equipment');
q("INSERT INTO equipment(name, asset_tag, status) VALUES ('Rollback probe', 'P5M03-ROLLBACK', 'disposed')");
$probeId = (int) db()->lastInsertId();
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m03.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes the three tables and ten columns, and keeps every item', $tables() === 0 && $cols() === 0 && (int) val('SELECT COUNT(*) FROM equipment') === $items + 1);
check('A disposed item is retired again: the earlier status list has no disposed', val('SELECT status FROM equipment WHERE id = ?', [$probeId]) === 'retired');
// A repair costed before this module, in the history only.
q("INSERT INTO asset_events (equipment_id, event, detail, cost) VALUES (?, 'repaired', 'Replaced the strap', 55.00)", [$probeId]);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade announces the asset lifecycle, with the past repairs carried over', $code === 0 && str_contains($first, 'Asset lifecycle: inspections with results') && preg_match('/(\d+) past repair\(s\) carried/', $first, $m) && (int) $m[1] >= 1, $first);
check('The past repair is in the service history, with its cost', (int) val("SELECT COUNT(*) FROM asset_service_records WHERE equipment_id = ? AND kind = 'repair' AND cost = 55.00", [$probeId]) === 1);
check('The tables, columns and the disposed status are back', $tables() === 3 && $cols() === 10 && str_contains((string) val("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'equipment' AND column_name = 'status'"), 'disposed'));
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent and carries nothing twice', $code === 0 && ! str_contains($second, 'Asset lifecycle:') && (int) val("SELECT COUNT(*) FROM asset_service_records WHERE equipment_id = ?", [$probeId]) === 1, $second);

// ── fixture ──
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['hotels' => 'hotels', 'rec' => 'recruiter', 'pay' => 'payroll'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Lifecycle ' . $k, 'al-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO clients(name) VALUES ('Lifecycle client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status) VALUES (?,'P5-M03 refinery','active')", [$client]);
$job = (int) db()->lastInsertId();
q("INSERT INTO candidates(full_name,email,stage) VALUES ('Lifecycle Crew','lifecycle.crew@test.invalid','placed')");
$cid = (int) db()->lastInsertId();
q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site',CURDATE(),30,50)", [$cid, $job]);
$placement = (int) db()->lastInsertId();
q("INSERT INTO vendors(name,status,categories) VALUES ('Calibration Co','approved','equipment')");
$vendor = (int) db()->lastInsertId();
q("INSERT INTO asset_categories(code,label,asset_class,inspection_days,useful_life_months,residual_percent) VALUES ('p5_gas','P5 gas detectors','safety',30,60,10)");
$gas = (int) db()->lastInsertId();

$monthsAgo = static fn(int $n): string => date('Y-m-d', strtotime("-$n months"));
$item = static function (string $tag, string $name, ?int $category, ?float $cost, ?string $since, array $extra = []): int {
    q('INSERT INTO equipment(name, asset_tag, category_id, status, purchase_date, purchase_cost, in_service_on, useful_life_months, residual_value) VALUES (?,?,?,?,?,?,?,?,?)',
      [$name, $tag, $category, 'available', $since, $cost, $extra['in_service'] ?? $since, $extra['life'] ?? null, $extra['residual'] ?? null]);
    $id = (int) db()->lastInsertId();
    q('INSERT INTO equipment_ownership(equipment_id, owner_type, client_id) VALUES (?,?,?)', [$id, $extra['owner'] ?? 'agency', $extra['client'] ?? null]);
    return $id;
};
$client2 = $client;
$fx = [
    'ids' => $ids, 'job' => $job, 'placement' => $placement, 'vendor' => $vendor, 'gas' => $gas,
    'detector' => $item('P5-GD1', 'Gas detector', $gas, 1200.00, $monthsAgo(24)),                       // 1,080 over 60 months, 24 used: 432 / 768
    'radio' => $item('P5-RD1', 'Radio', null, 300.00, $monthsAgo(18), ['life' => 12]),                    // fully depreciated: 0
    'lift' => $item('P5-LF1', 'Client lift', null, 9000.00, $monthsAgo(6), ['owner' => 'client', 'client' => $client2]),
    'nocost' => $item('P5-NC1', 'Harness, no cost', null, null, null, ['in_service' => null]),
    'generator' => $item('P5-GN1', 'Old generator', null, 5000.00, $monthsAgo(30), ['life' => 60, 'residual' => 500]), // 4,500 over 60, 30 used: 2,250 / 2,750
    'formula' => $item('P5-FX1', '=SUM(1,1)', null, null, null),
    'months_detector' => (new DateTime($monthsAgo(24)))->format('Y-m-d'),
];
file_put_contents(__DIR__ . '/asset-lifecycle.json', json_encode($fx));
file_put_contents(__DIR__ . '/al-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
