<?php
/**
 * The periods test's eyes and hands, on the test database only.
 *   read                    periods, events, March 2023 lines, bill statuses
 *   backdate <payment id>   moves a payment into March 2023, as a record
 *                           slipped in from elsewhere would be
 *   restore  <payment id>   moves it back to today
 *   unbalance / rebalance   adds or removes one lone line in February 2023
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$mode = (string) ($argv[1] ?? 'read');
$arg = (int) ($argv[2] ?? 0);
switch ($mode) {
    case 'backdate':
        q("UPDATE ar_payments SET received_on = '2023-03-28' WHERE id = ?", [$arg]);
        exit(0);
    case 'restore':
        q('UPDATE ar_payments SET received_on = CURDATE() WHERE id = ?', [$arg]);
        exit(0);
    case 'unbalance':
        $batch = (int) val('SELECT MIN(id) FROM accounting_batches');
        q("INSERT INTO accounting_lines (batch_id, journal_no, txn_date, account_key, qb_account, debit, credit, source) VALUES (?, 'TEST-LONE', '2023-02-10', 'bank', 'Checking', 5, 0, 'test:lone')", [$batch]);
        exit(0);
    case 'rebalance':
        q("DELETE FROM accounting_lines WHERE source = 'test:lone'");
        exit(0);
}

echo json_encode([
    'periods' => rows('SELECT period, status FROM financial_periods ORDER BY period'),
    'events'  => rows('SELECT period, action, reason FROM financial_period_events ORDER BY id'),
    'march'   => rows("SELECT batch_id, account_key, debit + 0 AS debit, credit + 0 AS credit, source FROM accounting_lines WHERE txn_date BETWEEN '2023-03-01' AND '2023-03-31' ORDER BY id"),
    'bills'   => rows("SELECT id, status FROM vendor_invoices WHERE vendor_name = 'Periods vendor' ORDER BY id"),
    'ar'      => rows('SELECT id, client_id, reference FROM ar_payments ORDER BY id'),
    'batches' => rows('SELECT id, kind, status FROM accounting_batches ORDER BY id'),
    'gl'      => rows("SELECT source, doc_date, posted_on FROM gl_journals WHERE source LIKE 'ar_payment:%' ORDER BY id"),
]);
