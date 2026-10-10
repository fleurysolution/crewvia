<?php
/**
 * The P3-M03 test's eyes and hands, on the test database only.
 *   read                     journals (with lines), counts per source, triggers
 *   try_update <journal id>  tries to change a posted line: prints the refusal
 *   try_delete <journal id>  tries to delete a posted journal: prints the refusal
 *   tamper <journal id>      removes the line trigger and changes a posted line,
 *                            as someone with direct database access could
 *   untamper <journal id>    puts the line back as it was
 *   drift <invoice id>       changes an invoice total after it was posted
 *   undrift <invoice id>     puts it back
 *   claim                    records a new paid agency claim (prints its id)
 *   sync                     posts the records not yet in the ledger (prints how many)
 *   integrity                the chain check, as the ledger page runs it
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$mode = (string) ($argv[1] ?? 'read');
$arg = (int) ($argv[2] ?? 0);
$fx = json_decode((string) file_get_contents(__DIR__ . '/ledger.json'), true);
$attempt = static function (string $sql, array $params): void {
    try {
        q($sql, $params);
        echo json_encode(['refused' => false]);
    } catch (Throwable $e) {
        echo json_encode(['refused' => true, 'message' => $e->getMessage()]);
    }
};

switch ($mode) {
    case 'try_update':
        $attempt('UPDATE gl_lines SET debit = debit + 1 WHERE journal_id = ? AND debit > 0 LIMIT 1', [$arg]);
        exit(0);
    case 'try_delete':
        $attempt('DELETE FROM gl_journals WHERE id = ?', [$arg]);
        exit(0);
    case 'tamper':
        db()->exec('DROP TRIGGER IF EXISTS gl_lines_no_update');
        q('UPDATE gl_lines SET debit = debit + 100 WHERE journal_id = ? AND debit > 0 ORDER BY id LIMIT 1', [$arg]);
        exit(0);
    case 'untamper':
        q('UPDATE gl_lines SET debit = debit - 100 WHERE journal_id = ? AND debit > 0 ORDER BY id LIMIT 1', [$arg]);
        exit(0);
    case 'drift':
        q('UPDATE client_invoices SET total = total + 10 WHERE id = ?', [$arg]);
        exit(0);
    case 'undrift':
        q('UPDATE client_invoices SET total = total - 10 WHERE id = ?', [$arg]);
        exit(0);
    case 'sync':
        // Run by several processes at once: the lock lets one post each record.
        require_once __DIR__ . '/test-app/app/ledger.php';
        usleep(random_int(0, 50000));
        echo json_encode(ledger_sync()[0]);
        exit(0);
    case 'integrity':
        require_once __DIR__ . '/test-app/app/ledger.php';
        echo json_encode(ledger_integrity());
        exit(0);
    case 'claim':
        q("INSERT INTO expense_claims(placement_id,user_id,category,amount,receipt_document_id,status,payer,paid_at) VALUES (?,?,'other',25,?,'paid','agency',NOW())",
          [$fx['placement'], $fx['ids']['admin'], $fx['doc']]);
        echo (int) db()->lastInsertId();
        exit(0);
}

$journals = rows('SELECT id, kind, source, export_key, doc_date, posted_on, memo, status, total + 0 AS total, reverses_id, reversed_by_id, created_by, approved_by, chain_no, prev_hash, hash
                  FROM gl_journals ORDER BY id');
$lines = [];
foreach (rows('SELECT journal_id, account_key, debit + 0 AS debit, credit + 0 AS credit, party, class FROM gl_lines ORDER BY id') as $l) {
    $lines[(int) $l['journal_id']][] = ['account' => $l['account_key'], 'debit' => (float) $l['debit'], 'credit' => (float) $l['credit'], 'party' => $l['party'], 'class' => $l['class']];
}
foreach ($journals as &$j) {
    foreach (['id', 'reverses_id', 'reversed_by_id', 'created_by', 'approved_by', 'chain_no'] as $k) {
        $j[$k] = $j[$k] === null ? null : (int) $j[$k];
    }
    $j['total'] = (float) $j['total'];
    $j['lines'] = $lines[$j['id']] ?? [];
}
unset($j);

echo json_encode([
    'journals' => $journals,
    'per_source' => array_map('intval', array_column(rows("SELECT source, COUNT(*) AS n FROM gl_journals WHERE source IS NOT NULL GROUP BY source"), 'n', 'source')),
    'triggers' => (int) val("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND trigger_name LIKE 'gl\\_%'"),
    'accounts' => rows('SELECT account_key, number, side, is_system + 0 AS is_system, is_active + 0 AS is_active, confirmed + 0 AS confirmed FROM accounting_accounts ORDER BY number'),
    'sources' => rows('SELECT batch_id + 0 AS batch_id, source, active_key FROM accounting_sources ORDER BY id'),
]);
