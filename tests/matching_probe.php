<?php
/** Read-only: the P3-M08 project's requests, orders, receipts, bills and clearances, as JSON. */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$job = (int) ($argv[1] ?? 0);
$num = static fn(array $rows, array $keys): array => array_map(static function (array $r) use ($keys): array {
    foreach ($keys as $k) {
        if (isset($r[$k])) { $r[$k] = $r[$k] + 0; }
    }
    return $r;
}, $rows);

echo json_encode([
    'requests'   => $num(rows('SELECT id, title, status FROM purchase_requests WHERE job_id = ? ORDER BY id', [$job]), ['id']),
    'orders'     => $num(rows('SELECT o.id, o.status, o.total, r.title FROM purchase_orders o JOIN purchase_requests r ON r.id = o.request_id WHERE o.job_id = ? ORDER BY o.id', [$job]), ['id', 'total']),
    'receipts'   => $num(rows('SELECT x.purchase_order_id, x.quantity, x.rejected_quantity, x.rejection_reason FROM purchase_receipts x JOIN purchase_orders o ON o.id = x.purchase_order_id WHERE o.job_id = ? ORDER BY x.id', [$job]), ['purchase_order_id', 'quantity', 'rejected_quantity']),
    'bills'      => $num(rows('SELECT id, reference, vendor_name, amount, quantity, status, purchase_order_id FROM vendor_invoices WHERE job_id = ? ORDER BY id', [$job]), ['id', 'amount', 'quantity', 'purchase_order_id']),
    'clearances' => $num(rows('SELECT c.invoice_id, c.codes, c.reason FROM bill_match_clearances c JOIN vendor_invoices v ON v.id = c.invoice_id WHERE v.job_id = ? ORDER BY c.id', [$job]), ['invoice_id']),
]);
