<?php
/** Read-only: the procurement project's requests, orders, receipts, hotel block and units, as JSON. */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$job = (int) ($argv[1] ?? 0);
$hotel = (int) ($argv[2] ?? 0);

echo json_encode([
    'requests' => rows('SELECT id, category, title, quantity, status, source, placement_id, hotel_id FROM purchase_requests WHERE job_id = ? ORDER BY id', [$job]),
    'orders'   => rows('SELECT id, request_id, vendor_name, unit_price, total, status, created_by, decided_by FROM purchase_orders WHERE job_id = ? ORDER BY id', [$job]),
    'receipts' => rows('SELECT x.purchase_order_id, x.quantity FROM purchase_receipts x JOIN purchase_orders o ON o.id = x.purchase_order_id WHERE o.job_id = ? ORDER BY x.id', [$job]),
    'hotel'    => row('SELECT rooms_held, block_starts, block_ends FROM hotels WHERE id = ?', [$hotel]),
    'job'      => row('SELECT budget_owner_id, auto_lodging FROM jobs WHERE id = ?', [$job]),
    'units'    => array_column(rows('SELECT code FROM procurement_units ORDER BY code'), 'code'),
    'invoices' => rows('SELECT amount, purchase_order_id FROM vendor_invoices WHERE job_id = ? ORDER BY id', [$job]),
    'placements' => rows('SELECT id, candidate_id FROM placements WHERE job_id = ? ORDER BY id', [$job]),
]);
