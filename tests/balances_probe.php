<?php
/**
 * The balances test's eyes, on the test database only: invoices, bills,
 * payments, applications and exported lines as JSON. "mark" sets a client
 * invoice's status directly and "unmark" puts it back, so the test can show
 * the reconciliation catches a status that does not match its balance.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$mode = (string) ($argv[1] ?? 'read');
if ($mode === 'mark' || $mode === 'unmark') {
    q('UPDATE client_invoices SET status = ? WHERE id = ?', [$mode === 'mark' ? 'paid' : 'issued', (int) ($argv[2] ?? 0)]);
    exit(0);
}

$num = static fn(array $rows, array $keys): array => array_map(static function (array $r) use ($keys): array {
    foreach ($keys as $k) {
        if (isset($r[$k])) { $r[$k] = $r[$k] + 0; }
    }
    return $r;
}, $rows);

echo json_encode([
    'invoices' => $num(rows('SELECT id, status, total, due_on, paid_at FROM client_invoices ORDER BY id'), ['id', 'total']),
    'bills'    => $num(rows('SELECT id, status, amount, payment_reference FROM vendor_invoices ORDER BY id'), ['id', 'amount']),
    'ar'       => $num(rows('SELECT id, client_id, amount, reference, reversed_at IS NOT NULL AS reversed FROM ar_payments ORDER BY id'), ['id', 'client_id', 'amount', 'reversed']),
    'ap'       => $num(rows('SELECT id, vendor_name, amount, reference, reversed_at IS NOT NULL AS reversed FROM ap_payments ORDER BY id'), ['id', 'amount', 'reversed']),
    'ar_alloc' => $num(rows('SELECT id, payment_id, invoice_id, amount, reversed_at IS NOT NULL AS reversed FROM ar_allocations ORDER BY id'), ['id', 'payment_id', 'invoice_id', 'amount', 'reversed']),
    'lines'    => $num(rows("SELECT batch_id, journal_no, account_key, debit, credit, source FROM accounting_lines WHERE source LIKE 'ar\\_%' OR source LIKE 'ap\\_%' OR source LIKE 'client_invoice:%' OR source LIKE 'vendor_invoice:%' ORDER BY id"), ['batch_id', 'debit', 'credit']),
]);
