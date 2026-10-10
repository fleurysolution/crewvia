<?php
/**
 * Assets at the database level, on the isolated test database only:
 * rollback, upgrade, silence on a repeat, then the project, people, items
 * and order assets_http.py is tested on.
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

check('Assets are in place before the rollback', $table('asset_categories') && $table('asset_events') && $col('equipment', 'status') && $col('equipment_issues', 'return_condition'));
$items = (int) val('SELECT COUNT(*) FROM equipment');
$issued = (int) val('SELECT COUNT(*) FROM equipment_issues');
sql_file($app . '/install/rollback/assets.sql');
check('Rollback removes every assets object', ! $table('asset_categories') && ! $table('asset_events') && ! $col('equipment', 'status') && ! $col('equipment', 'inspection_due') && ! $col('equipment_issues', 'issue_condition'));
check('Rollback keeps every item and every issue', (int) val('SELECT COUNT(*) FROM equipment') === $items && (int) val('SELECT COUNT(*) FROM equipment_issues') === $issued);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback succeeds and announces assets', $code === 0 && str_contains($first, 'Assets: categories, status, condition') && str_contains($first, $items . ' existing item(s)'), $first);
check('Existing items come back available', (int) val("SELECT COUNT(*) FROM equipment WHERE status = 'available'") === $items);
check('Six categories are seeded, gas detection every 30 days', (int) val('SELECT COUNT(*) FROM asset_categories') === 6 && (int) val("SELECT inspection_days FROM asset_categories WHERE code = 'gas_detection'") === 30);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for assets', $code === 0 && ! str_contains($second, 'Assets:'), $second);

$fixture  = json_decode((string) file_get_contents(__DIR__ . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
foreach (['admin', 'hotels', 'recruiter', 'payroll'] as $role) {
    q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)", ['Assets ' . $role, 'asset-' . $role . '@test.invalid', $password, $role]);
}
$admin = (int) val("SELECT id FROM users WHERE email = 'asset-admin@test.invalid'");

q("INSERT INTO clients(name) VALUES ('Assets other client')");
$other = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status)
   VALUES (?,'Assets project','Synthetic project for the asset register.',20,35,0,0,0,'active')", [(int) $fixture['client']]);
$job = (int) db()->lastInsertId();

$placements = [];
foreach (['one', 'two'] as $label) {
    q('INSERT INTO candidates(full_name,email) VALUES (?,?)', ['Asset worker ' . $label, 'asset-w-' . $label . '@test.invalid']);
    q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site',CURDATE(),20,35)", [(int) db()->lastInsertId(), $job]);
    $placements[] = (int) db()->lastInsertId();
}

// An item the other client owns, and an item whose inspection has passed.
$tag = 'AST-' . strtoupper(bin2hex(random_bytes(3)));
q("INSERT INTO equipment(name,asset_tag) VALUES ('Client radio',?)", [$tag . '-C']);
$clientItem = (int) db()->lastInsertId();
q("INSERT INTO equipment_ownership(equipment_id,owner_type,client_id) VALUES (?,'client',?)", [$clientItem, $other]);
q("INSERT INTO equipment(name,asset_tag,category_id,inspection_due) VALUES ('Old gas detector',?,(SELECT id FROM asset_categories WHERE code='gas_detection'),DATE_SUB(CURDATE(), INTERVAL 2 DAY))", [$tag . '-G']);
$overdueItem = (int) db()->lastInsertId();
q("INSERT INTO equipment_ownership(equipment_id,owner_type) VALUES (?,'agency')", [$overdueItem]);

// An approved order for 3 harnesses, 2 received so far, and an approved lodging order.
$unit = (int) val('SELECT id FROM procurement_units ORDER BY id LIMIT 1');
$order = static function (string $category, string $title, int $qty, float $price, int $received) use ($job, $unit, $admin, $tag): int {
    q("INSERT INTO purchase_requests(job_id,category,title,quantity,unit_id,status,requested_by) VALUES (?,?,?,?,?,'ordered',?)", [$job, $category, $title, $qty, $unit, $admin]);
    $request = (int) db()->lastInsertId();
    q("INSERT INTO purchase_orders(reference,job_id,request_id,vendor_name,quantity,unit_id,unit_price,total,status,created_by,decided_by,decided_at)
       VALUES (?,?,?,'Synthetic supplier',?,?,?,?,'approved',?,?,NOW())", [$tag . '-' . $category, $job, $request, $qty, $unit, $price, $qty * $price, $admin, $admin]);
    $id = (int) db()->lastInsertId();
    if ($received) {
        q('INSERT INTO purchase_receipts(purchase_order_id,quantity,received_on,received_by) VALUES (?,?,CURDATE(),?)', [$id, $received, $admin]);
    }
    return $id;
};
$harnessOrder = $order('safety_equipment', 'Full-body harnesses', 3, 245.5, 2);
$roomOrder = $order('lodging', 'Rooms', 4, 90, 4);

file_put_contents(__DIR__ . '/assets.json', json_encode(['job' => $job, 'tag' => $tag, 'placements' => $placements, 'client_item' => $clientItem,
    'overdue_item' => $overdueItem, 'harness_order' => $harnessOrder, 'room_order' => $roomOrder,
    'fall' => (int) val("SELECT id FROM asset_categories WHERE code = 'fall_protection'"), 'gas' => (int) val("SELECT id FROM asset_categories WHERE code = 'gas_detection'"),
    'today' => date('Y-m-d'), 'in_180' => date('Y-m-d', strtotime('+180 days')), 'in_30' => date('Y-m-d', strtotime('+30 days'))]));
file_put_contents(__DIR__ . '/assets-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
