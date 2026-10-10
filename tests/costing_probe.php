<?php
/** Read-only: how many financial records exist, and the project's budget changes and rates, as JSON. */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$job = (int) ($argv[1] ?? 0);
$counts = [];
foreach (['timesheets', 'pay_snapshots', 'payroll_adjustments', 'vendor_invoices', 'client_invoices', 'expense_claims', 'lodging', 'travel', 'purchase_orders'] as $t) {
    $counts[$t] = (int) val('SELECT COUNT(*) FROM `' . $t . '`');
}

echo json_encode([
    'counts'  => $counts,
    'changes' => rows('SELECT category, amount, previous_amount, reason, changed_by FROM project_budget_changes WHERE job_id = ? ORDER BY id', [$job]),
    'rates'   => row('SELECT burden_percent, overhead_percent FROM jobs WHERE id = ?', [$job]),
], JSON_NUMERIC_CHECK);
