<?php
/** Read-only: vendors, the P3-M06 projects' orders, approvals, shares and exported bill lines, as JSON. */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$a = (int) ($argv[1] ?? 0);
$b = (int) ($argv[2] ?? 0);
$num = static fn(array $rows, array $keys): array => array_map(static function (array $r) use ($keys): array {
    foreach ($keys as $k) {
        if (isset($r[$k])) { $r[$k] = $r[$k] + 0; }
    }
    return $r;
}, $rows);

echo json_encode([
    'vendors'   => $num(rows('SELECT id, name, status, categories, w9_on_file FROM vendors ORDER BY id'), ['id', 'w9_on_file']),
    'events'    => rows('SELECT v.name, e.event FROM vendor_events e JOIN vendors v ON v.id = e.vendor_id ORDER BY e.id'),
    'orders'    => $num(rows('SELECT o.id, o.job_id, o.vendor_name, o.status, o.total, o.approvers, o.over_budget, r.title FROM purchase_orders o JOIN purchase_requests r ON r.id = o.request_id
                              WHERE o.job_id IN (?, ?) ORDER BY o.id', [$a, $b]), ['id', 'job_id', 'total', 'over_budget']),
    'approvals' => $num(rows('SELECT a.purchase_order_id, a.approver, a.user_id FROM purchase_order_approvals a JOIN purchase_orders o ON o.id = a.purchase_order_id WHERE o.job_id IN (?, ?) ORDER BY a.id', [$a, $b]), ['purchase_order_id', 'user_id']),
    'shares'    => $num(rows('SELECT a.purchase_order_id, a.job_id, a.amount FROM purchase_order_allocations a JOIN purchase_orders o ON o.id = a.purchase_order_id WHERE o.job_id IN (?, ?) ORDER BY a.id', [$a, $b]), ['purchase_order_id', 'job_id', 'amount']),
    'bills'     => $num(rows('SELECT id, purchase_order_id, amount, status FROM vendor_invoices WHERE job_id IN (?, ?) ORDER BY id', [$a, $b]), ['id', 'purchase_order_id', 'amount']),
    'lines'     => $num(rows("SELECT account_key, debit, credit, class, source FROM accounting_lines WHERE source LIKE 'vendor_invoice:%' ORDER BY id"), ['debit', 'credit']),
    'tiers'     => rows('SELECT up_to, approvers FROM procurement_thresholds ORDER BY up_to IS NULL, up_to'),
]);
