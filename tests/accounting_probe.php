<?php
/**
 * The accounting test's eyes, on the test database only. Reads batches,
 * lines and sources as JSON. "tamper" and "untamper" alter one stored line
 * and put it back, so the test can show a download refuses a file that no
 * longer matches its fingerprint.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$mode = (string) ($argv[1] ?? 'read');
$batch = (int) ($argv[2] ?? 0);

if ($mode === 'tamper') {
    q("UPDATE accounting_lines SET description = CONCAT(description, ' [tampered]') WHERE batch_id = ? ORDER BY id LIMIT 1", [$batch]);
    exit(0);
}
if ($mode === 'untamper') {
    q("UPDATE accounting_lines SET description = REPLACE(description, ' [tampered]', '') WHERE batch_id = ?", [$batch]);
    exit(0);
}

$numeric = ['id', 'batch_id', 'journal_count', 'total', 'reverses_batch_id', 'reversed_by_batch_id', 'debit', 'credit', 'confirmed', 'has_issued'];
$cast = static fn(array $rows): array => array_map(static function (array $r) use ($numeric): array {
    foreach ($numeric as $k) {
        if (isset($r[$k])) { $r[$k] = $r[$k] + 0; }
    }
    return $r;
}, $rows);

echo json_encode(array_map($cast, [
    'batches'  => rows('SELECT id, kind, status, through_date, journal_count, total, file_sha256, reverses_batch_id, reversed_by_batch_id FROM accounting_batches ORDER BY id'),
    'lines'    => rows('SELECT batch_id, journal_no, txn_date, account_key, qb_account, debit, credit, party, class, source FROM accounting_lines ORDER BY id'),
    'sources'  => rows('SELECT batch_id, source, active_key FROM accounting_sources ORDER BY id'),
    'accounts' => rows('SELECT account_key, qb_account, confirmed FROM accounting_accounts ORDER BY sort_order'),
    'issued'   => rows('SELECT id, status, issued_at IS NOT NULL AS has_issued FROM client_invoices ORDER BY id'),
]));
