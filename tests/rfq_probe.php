<?php
/**
 * The P3-M07 test's eyes, on the test database only: the project's
 * requests for quotation, quotations, orders, approvals and revisions.
 * "expire <quotation id>" dates a quotation yesterday, so the test can show
 * an order is not raised on an expired one.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$job = (int) ($argv[1] ?? 0);
if (($argv[2] ?? '') === 'expire') {
    q('UPDATE purchase_quotations SET valid_until = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = ?', [(int) ($argv[3] ?? 0)]);
    exit(0);
}
$num = static fn(array $rows, array $keys): array => array_map(static function (array $r) use ($keys): array {
    foreach ($keys as $k) {
        if (isset($r[$k])) { $r[$k] = $r[$k] + 0; }
    }
    return $r;
}, $rows);

echo json_encode([
    'rfqs'      => $num(rows('SELECT f.id, f.reference, f.vendor_name, f.status, f.request_id FROM purchase_rfqs f JOIN purchase_requests r ON r.id = f.request_id WHERE r.job_id = ? ORDER BY f.id', [$job]), ['id', 'request_id']),
    'quotes'    => $num(rows('SELECT q.id, q.vendor_name, q.unit_price, q.rfq_id, q.valid_until FROM purchase_quotations q JOIN purchase_requests r ON r.id = q.request_id WHERE r.job_id = ? ORDER BY q.id', [$job]), ['id', 'unit_price', 'rfq_id']),
    'requests'  => $num(rows('SELECT id, title, status FROM purchase_requests WHERE job_id = ? ORDER BY id', [$job]), ['id']),
    'orders'    => $num(rows('SELECT id, vendor_name, quantity, unit_price, total, status, revision, approvers, quotation_id FROM purchase_orders WHERE job_id = ? ORDER BY id', [$job]), ['id', 'quantity', 'unit_price', 'total', 'revision', 'quotation_id']),
    'approvals' => $num(rows('SELECT a.purchase_order_id, a.revision, a.approver, a.user_id FROM purchase_order_approvals a JOIN purchase_orders o ON o.id = a.purchase_order_id WHERE o.job_id = ? ORDER BY a.id', [$job]), ['purchase_order_id', 'revision', 'user_id']),
    'revisions' => $num(rows('SELECT v.purchase_order_id, v.revision, v.before_json, v.after_json, v.reason FROM purchase_order_revisions v JOIN purchase_orders o ON o.id = v.purchase_order_id WHERE o.job_id = ? ORDER BY v.id', [$job]), ['purchase_order_id', 'revision']),
]);
