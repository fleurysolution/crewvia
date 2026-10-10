<?php
/**
 * The chart-of-accounts mapping and the QuickBooks export (P3-M02).
 *
 * RSS keeps its books in QuickBooks Online and runs payroll through ADP.
 * Crewvia turns what it already records into journal entries: they post
 * themselves to Crewvia's own ledger (P3-M03, app/ledger.php), and the
 * export sends the ledger's journals to QuickBooks, in batches. Nothing is
 * keyed twice. The entries are:
 *
 *   client invoice issued     Dr A/R (client)          Cr revenue
 *   payment received (P3-M04) Dr bank                  Cr A/R (client)
 *   credit note to a client   Dr revenue               Cr A/R (client)
 *   vendor bill approved      Dr cost by category      Cr A/P (vendor)
 *   payment made (P3-M04)     Dr A/P (vendor)          Cr bank
 *   credit note from a vendor Dr A/P (vendor)          Cr cost by category
 *   a reversal of any of these posts the same lines the other way, dated
 *   when it was reversed
 *
 * An invoice marked paid before payments were recorded still sends its
 * one payment entry; an invoice with recorded payments never does, so the
 * money is not counted twice.
 *   agency-paid claim paid    Dr cost by category      Cr bank
 *   payroll period approved   Dr wages, employer contributions, per diem
 *                             Cr deductions, employer contributions owed,
 *                             net pay owed
 *
 * Payroll is off unless an administrator turns it on: ADP can post
 * payroll to QuickBooks itself, and both would count the same pay twice.
 *
 * Rules that keep the books right:
 *   - every entry balances, or the batch is refused
 *   - each journal goes out once: accounting_sources.active_key is unique.
 *     A journal posted from a record keeps the record's key (the keys of
 *     exports made before the ledger), a manual one is gl_journal:<id>:<random>
 *   - nothing goes to an account an administrator has not confirmed
 *   - a batch is never edited or deleted; it is reversed by a correcting
 *     batch with debits and credits swapped, which frees its sources
 *   - the file is rebuilt from the stored lines, so a download is the same
 *     file every time, and its SHA-256 is kept
 */

declare(strict_types=1);

require_once __DIR__ . '/ledger.php';

function accounting_accounts(): array
{
    $out = [];
    foreach (rows('SELECT * FROM accounting_accounts ORDER BY sort_order') as $a) {
        $out[$a['account_key']] = $a;
    }

    return $out;
}

function accounting_payroll_enabled(): bool
{
    return (string) val("SELECT setting_value FROM platform_settings WHERE setting_key = 'accounting_export_payroll'") === '1';
}

/** The account a cost goes to. */
function accounting_cost_account(string $category): string
{
    return ['hotel' => 'hotels_expense', 'hotels' => 'hotels_expense', 'lodging' => 'hotels_expense',
            'flight' => 'transportation_expense', 'transport' => 'transportation_expense', 'vehicle' => 'transportation_expense',
            'safety_equipment' => 'equipment_expense'][$category] ?? 'other_expense';
}

/**
 * Every ledger journal not yet exported, posted on or before $through. Each
 * entry is one journal with balanced lines. The caller syncs the ledger
 * first (ledger_sync), outside its transaction.
 *
 * @return list<array{source:string,date:string,memo:string,lines:list<array>}>
 */
function accounting_pending(string $through): array
{
    return ledger_unexported($through, accounting_payroll_enabled());
}

/**
 * Every entry Crewvia's records make, dated on or before $through. The
 * ledger posts each once (ledger_sync). $payroll: whether the payroll
 * periods are included.
 */
function accounting_entries(string $through, bool $payroll): array
{
    $entries = [];
    $line = static fn(string $account, float $debit, float $credit, ?string $party = null, ?string $class = null): array =>
        ['account' => $account, 'debit' => round($debit, 2), 'credit' => round($credit, 2), 'party' => $party, 'class' => $class];
    $push = static function (string $source, string $date, string $memo, array $lines) use (&$entries, $through): void {
        if ($date <= $through) {
            $entries[] = ['source' => $source, 'date' => $date, 'memo' => $memo, 'lines' => $lines];
        }
    };

    foreach (rows("SELECT i.*, c.name AS client, j.title,
                          (SELECT COUNT(*) FROM ar_allocations a WHERE a.invoice_id = i.id) + (SELECT COUNT(*) FROM ar_credits x WHERE x.invoice_id = i.id) AS recorded
                   FROM client_invoices i JOIN jobs j ON j.id = i.job_id JOIN clients c ON c.id = j.client_id
                   WHERE i.status IN ('issued','paid')") as $i) {
        $total = (float) $i['total'];
        $issued = substr((string) ($i['issued_at'] ?? $i['created_at']), 0, 10);
        $push('client_invoice:' . $i['id'] . ':issue', $issued, 'Invoice ' . $i['reference'] . ' ' . $i['starts_on'] . ' to ' . $i['ends_on'],
              [$line('accounts_receivable', $total, 0, $i['client'], $i['title']), $line('revenue', 0, $total, $i['client'], $i['title'])]);
        if ($i['status'] === 'paid' && (int) $i['recorded'] === 0) {
            $push('client_invoice:' . $i['id'] . ':payment', substr((string) ($i['paid_at'] ?? $i['issued_at'] ?? $i['created_at']), 0, 10), 'Payment of invoice ' . $i['reference'],
                  [$line('bank', $total, 0, $i['client'], $i['title']), $line('accounts_receivable', 0, $total, $i['client'], $i['title'])]);
        }
    }

    foreach (rows("SELECT v.*, j.title, r.category,
                          (SELECT COUNT(*) FROM ap_allocations a WHERE a.invoice_id = v.id) + (SELECT COUNT(*) FROM ap_credits x WHERE x.invoice_id = v.id) AS recorded
                   FROM vendor_invoices v JOIN jobs j ON j.id = v.job_id
                   LEFT JOIN purchase_orders o ON o.id = v.purchase_order_id LEFT JOIN purchase_requests r ON r.id = o.request_id
                   WHERE v.status IN ('approved','paid')") as $v) {
        $amount = (float) $v['amount'];
        $cost = $v['hotel_id'] ? 'hotels_expense' : accounting_cost_account((string) ($v['category'] ?? ''));
        // A bill against an order split across projects (P3-M06) puts its cost
        // on each project's Class by its share; the last share takes the cents.
        $costLines = [];
        $shares = $v['purchase_order_id'] ? rows('SELECT a.amount, j.title FROM purchase_order_allocations a JOIN jobs j ON j.id = a.job_id
                                                  WHERE a.purchase_order_id = ? ORDER BY a.id', [(int) $v['purchase_order_id']]) : [];
        $orderTotal = array_sum(array_map(fn($s) => (float) $s['amount'], $shares));
        if (count($shares) > 1 && $orderTotal > 0) {
            $left = $amount;
            foreach ($shares as $k => $s) {
                $part = $k === count($shares) - 1 ? round($left, 2) : round($amount * (float) $s['amount'] / $orderTotal, 2);
                $left -= $part;
                $costLines[] = $line($cost, $part, 0, $v['vendor_name'], $s['title']);
            }
        } else {
            $costLines[] = $line($cost, $amount, 0, $v['vendor_name'], $shares[0]['title'] ?? $v['title']);
        }
        $push('vendor_invoice:' . $v['id'] . ':bill', (string) $v['due_on'], 'Bill ' . $v['reference'],
              [...$costLines, $line('accounts_payable', 0, $amount, $v['vendor_name'], $v['title'])]);
        if ($v['status'] === 'paid' && (int) $v['recorded'] === 0) {
            $push('vendor_invoice:' . $v['id'] . ':payment', substr((string) ($v['paid_at'] ?? $v['due_on']), 0, 10), 'Payment of bill ' . $v['reference'],
                  [$line('accounts_payable', $amount, 0, $v['vendor_name'], $v['title']), $line('bank', 0, $amount, $v['vendor_name'], $v['title'])]);
        }
    }

    // ── payments and credit notes (P3-M04), and their reversals ──
    $twice = static function (string $key, array $row, string $date, string $memo, array $lines) use ($push): void {
        $push($key . ':post', $date, $memo, $lines);
        if (! empty($row['reversed_at'])) {
            $swapped = array_map(fn($l) => ['debit' => $l['credit'], 'credit' => $l['debit']] + $l, $lines);
            $push($key . ':reversal', substr((string) $row['reversed_at'], 0, 10), 'Reversal: ' . $memo . ' · ' . $row['reversal_reason'], $swapped);
        }
    };
    foreach (rows('SELECT p.*, c.name AS client FROM ar_payments p JOIN clients c ON c.id = p.client_id') as $p) {
        $a = (float) $p['amount'];
        $twice('ar_payment:' . $p['id'], $p, (string) $p['received_on'], 'Payment received ' . $p['reference'],
               [$line('bank', $a, 0, $p['client'], null), $line('accounts_receivable', 0, $a, $p['client'], null)]);
    }
    foreach (rows('SELECT x.*, i.reference AS invoice_ref, c.name AS client, j.title FROM ar_credits x JOIN client_invoices i ON i.id = x.invoice_id
                   JOIN jobs j ON j.id = i.job_id JOIN clients c ON c.id = j.client_id') as $x) {
        $a = (float) $x['amount'];
        $twice('ar_credit:' . $x['id'], $x, (string) $x['issued_on'], 'Credit note on ' . $x['invoice_ref'],
               [$line('revenue', $a, 0, $x['client'], $x['title']), $line('accounts_receivable', 0, $a, $x['client'], $x['title'])]);
    }
    foreach (rows('SELECT * FROM ap_payments') as $p) {
        $a = (float) $p['amount'];
        $twice('ap_payment:' . $p['id'], $p, (string) $p['received_on'], 'Payment made ' . $p['reference'],
               [$line('accounts_payable', $a, 0, $p['vendor_name'], null), $line('bank', 0, $a, $p['vendor_name'], null)]);
    }
    foreach (rows('SELECT x.*, v.reference AS bill_ref, v.vendor_name, v.hotel_id, j.title, r.category FROM ap_credits x JOIN vendor_invoices v ON v.id = x.invoice_id
                   JOIN jobs j ON j.id = v.job_id LEFT JOIN purchase_orders o ON o.id = v.purchase_order_id LEFT JOIN purchase_requests r ON r.id = o.request_id') as $x) {
        $a = (float) $x['amount'];
        $cost = $x['hotel_id'] ? 'hotels_expense' : accounting_cost_account((string) ($x['category'] ?? ''));
        $twice('ap_credit:' . $x['id'], $x, (string) $x['issued_on'], 'Vendor credit on ' . $x['bill_ref'],
               [$line('accounts_payable', $a, 0, $x['vendor_name'], $x['title']), $line($cost, 0, $a, $x['vendor_name'], $x['title'])]);
    }

    foreach (rows("SELECT e.*, j.title FROM expense_claims e JOIN placements p ON p.id = e.placement_id JOIN jobs j ON j.id = p.job_id
                   WHERE e.status = 'paid' AND e.payer = 'agency' AND e.paid_at IS NOT NULL") as $e) {
        $amount = (float) $e['amount'];
        // A worker's name is not sent to QuickBooks: the claim number is enough to find it here.
        $push('expense_claim:' . $e['id'] . ':paid', substr((string) $e['paid_at'], 0, 10), 'Expense claim #' . $e['id'] . ' ' . $e['category'],
              [$line(accounting_cost_account((string) $e['category']), $amount, 0, null, $e['title']), $line('bank', 0, $amount, null, $e['title'])]);
    }

    if ($payroll) {
        foreach (rows("SELECT * FROM payroll_runs WHERE status IN ('approved','locked')") as $run) {
            $lines = accounting_payroll_lines($run, $line);
            if ($lines) {
                $push('payroll_run:' . $run['id'] . ':journal', (string) $run['week_ending'], 'Payroll week ending ' . $run['week_ending'], $lines);
            }
        }
    }

    usort($entries, fn($a, $b) => [$a['date'], $a['source']] <=> [$b['date'], $b['source']]);

    return $entries;
}

/** One payroll period as a balanced journal, a set of lines per project. */
function accounting_payroll_lines(array $run, callable $line): array
{
    require_once __DIR__ . '/pay-periods.php';
    $byProject = [];
    foreach (rows("SELECT j.title, s.result_json FROM timesheets t JOIN placements p ON p.id = t.placement_id JOIN jobs j ON j.id = p.job_id
                   JOIN pay_snapshots s ON s.timesheet_id = t.id WHERE t.week_ending = ? AND t.status IN ('approved','paid')", [(string) $run['week_ending']]) as $r) {
        $f = payroll_sheet_figures(json_decode((string) $r['result_json'], true) ?: []);
        $b = &$byProject[$r['title']];
        $b ??= ['gross' => 0.0, 'deductions' => 0.0, 'employer' => 0.0, 'reimbursed' => 0.0, 'net' => 0.0, 'adjust_wages' => 0.0, 'adjust_reimbursed' => 0.0];
        foreach (['gross', 'deductions', 'employer', 'reimbursed', 'net'] as $k) {
            $b[$k] += $f[$k];
        }
        unset($b);
    }
    foreach (rows("SELECT a.kind, a.amount, j.title FROM payroll_adjustments a LEFT JOIN timesheets t ON t.id = a.timesheet_id
                   LEFT JOIN placements p ON p.id = t.placement_id LEFT JOIN jobs j ON j.id = p.job_id WHERE a.run_id = ?", [(int) $run['id']]) as $a) {
        $b = &$byProject[$a['title'] ?? ''];
        $b ??= ['gross' => 0.0, 'deductions' => 0.0, 'employer' => 0.0, 'reimbursed' => 0.0, 'net' => 0.0, 'adjust_wages' => 0.0, 'adjust_reimbursed' => 0.0];
        $b[$a['kind'] === 'reimbursement' ? 'adjust_reimbursed' : 'adjust_wages'] += (float) $a['amount'];
        unset($b);
    }

    $lines = [];
    foreach ($byProject as $project => $b) {
        $class = $project !== '' ? $project : null;
        // A recovery is a negative adjustment: it reduces the cost and what is owed.
        $wages = $b['gross'] + $b['adjust_wages'];
        $reimbursed = $b['reimbursed'] + $b['adjust_reimbursed'];
        $owed = $b['net'] + $b['adjust_wages'] + $b['adjust_reimbursed'];
        foreach ([['wages_expense', $wages, true], ['employer_expense', $b['employer'], true], ['per_diem_expense', $reimbursed, true],
                  ['deductions_payable', $b['deductions'], false], ['employer_payable', $b['employer'], false], ['net_pay_payable', $owed, false]] as [$acct, $amt, $isDebit]) {
            $amt = round($amt, 2);
            if (abs($amt) < 0.005) {
                continue;
            }
            // A negative debit is a credit, and the other way round.
            $debit = $isDebit === ($amt > 0);
            $lines[] = $line($acct, $debit ? abs($amt) : 0, $debit ? 0 : abs($amt), null, $class);
        }
    }

    return $lines;
}

/** What stops an export: unbalanced entries, unconfirmed or unmapped accounts. */
function accounting_blockers(array $entries): array
{
    require_once __DIR__ . '/periods.php';
    $accounts = accounting_accounts();
    $problems = [];
    foreach ($entries as $e) {
        if (period_closed($e['date'])) {
            $problems['closed:' . period_of($e['date'])] = t(':source is dated in :m, a closed month. Reopen the month, or reverse and record it again in an open one.', ['source' => $e['source'], 'm' => period_of($e['date'])]);
        }
        $dr = round(array_sum(array_column($e['lines'], 'debit')), 2);
        $cr = round(array_sum(array_column($e['lines'], 'credit')), 2);
        if (abs($dr - $cr) >= 0.005) {
            $problems[] = t(':source does not balance: debits :dr, credits :cr.', ['source' => $e['source'], 'dr' => money($dr), 'cr' => money($cr)]);
        }
        foreach ($e['lines'] as $l) {
            $a = $accounts[$l['account']] ?? null;
            if (! $a || trim((string) $a['qb_account']) === '' || (int) $a['confirmed'] !== 1) {
                $problems['acct:' . $l['account']] = t('The account ":label" is not confirmed against the QuickBooks chart.', ['label' => $a ? t($a['label']) : $l['account']]);
            }
        }
    }

    return array_values($problems);
}

/** Export everything pending up to a date. Returns [batch id, refusal]. */
function accounting_export(string $through): array
{
    if (! valid_date($through) || $through > date('Y-m-d')) {
        return [0, t('Export up to a date that has passed, today at the latest.')];
    }

    $entries = accounting_pending($through);
    if (! $entries) {
        return [0, t('Nothing is waiting to be exported up to that date.')];
    }
    if ($why = accounting_blockers($entries)) {
        return [0, implode(' ', $why)];
    }

    $accounts = accounting_accounts();
    q("INSERT INTO accounting_batches (kind, through_date, journal_count, total, file_sha256, created_by) VALUES ('export', ?, ?, 0, '', ?)",
      [$through, count($entries), uid() ?: null]);
    $batch = (int) db()->lastInsertId();

    $total = 0.0;
    foreach ($entries as $n => $e) {
        $journal = sprintf('CV%d-%d', $batch, $n + 1);
        foreach ($e['lines'] as $l) {
            q('INSERT INTO accounting_lines (batch_id, journal_no, txn_date, account_key, qb_account, debit, credit, description, party, class, source) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
              [$batch, $journal, $e['date'], $l['account'], $accounts[$l['account']]['qb_account'], $l['debit'], $l['credit'],
               mb_substr($e['memo'], 0, 500), $l['party'], $l['class'], $e['source']]);
            $total += $l['debit'];
        }
        // The unique key refuses a source a concurrent export already took.
        q('INSERT INTO accounting_sources (batch_id, source, active_key) VALUES (?,?,?)', [$batch, $e['source'], $e['source']]);
    }

    q('UPDATE accounting_batches SET total = ?, file_sha256 = ? WHERE id = ?', [round($total, 2), hash('sha256', accounting_csv($batch)), $batch]);

    return [$batch, null];
}

/** Reverse a batch with a correcting batch. Returns [reversal id, refusal]. */
function accounting_reverse(int $batchId, string $date, string $reason): array
{
    $b = row("SELECT * FROM accounting_batches WHERE id = ? AND kind = 'export' FOR UPDATE", [$batchId]);
    if (! $b) {
        return [0, t('That export does not exist.')];
    }
    if ($b['status'] !== 'exported') {
        return [0, t('That export is already reversed.')];
    }
    if (mb_strlen(trim($reason)) < 3) {
        return [0, t('Say why the export is reversed.')];
    }
    if (! valid_date($date) || $date > date('Y-m-d')) {
        return [0, t('Date the reversal today or earlier.')];
    }
    require_once __DIR__ . '/periods.php';
    if ($why = period_guard($date)) {
        return [0, $why];
    }

    q("INSERT INTO accounting_batches (kind, through_date, journal_count, total, file_sha256, reverses_batch_id, reason, created_by) VALUES ('reversal', ?, ?, ?, '', ?, ?, ?)",
      [$date, (int) $b['journal_count'], (float) $b['total'], $batchId, mb_substr(trim($reason), 0, 500), uid() ?: null]);
    $rev = (int) db()->lastInsertId();

    foreach (rows('SELECT * FROM accounting_lines WHERE batch_id = ? ORDER BY id', [$batchId]) as $l) {
        q('INSERT INTO accounting_lines (batch_id, journal_no, txn_date, account_key, qb_account, debit, credit, description, party, class, source) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
          [$rev, 'R' . $l['journal_no'], $date, $l['account_key'], $l['qb_account'], $l['credit'], $l['debit'],
           mb_substr('Reversal of ' . $l['journal_no'] . ': ' . $l['description'], 0, 500), $l['party'], $l['class'], $l['source']]);
    }

    q('UPDATE accounting_sources SET active_key = NULL, released_at = NOW() WHERE batch_id = ?', [$batchId]);
    q("UPDATE accounting_batches SET status = 'reversed', reversed_by_batch_id = ? WHERE id = ?", [$rev, $batchId]);
    q('UPDATE accounting_batches SET file_sha256 = ? WHERE id = ?', [hash('sha256', accounting_csv($rev)), $rev]);

    return [$rev, null];
}

/**
 * The batch as a QuickBooks Online journal entry import file. Dates are
 * MM/DD/YYYY, a U.S. company's format. Cells that a spreadsheet would read
 * as a formula are neutralised.
 */
function accounting_csv(int $batchId): string
{
    $out = fopen('php://temp', 'r+');
    fputcsv($out, ['Journal No', 'Journal Date', 'Account', 'Debits', 'Credits', 'Description', 'Name', 'Class'], ',', '"', '');
    foreach (rows('SELECT * FROM accounting_lines WHERE batch_id = ? ORDER BY id', [$batchId]) as $l) {
        fputcsv($out, [
            $l['journal_no'], date('m/d/Y', strtotime((string) $l['txn_date'])), csv_cell($l['qb_account']),
            (float) $l['debit'] > 0 ? number_format((float) $l['debit'], 2, '.', '') : '',
            (float) $l['credit'] > 0 ? number_format((float) $l['credit'], 2, '.', '') : '',
            csv_cell((string) $l['description']), csv_cell((string) $l['party']), csv_cell((string) $l['class']),
        ], ',', '"', '');
    }
    rewind($out);
    $csv = (string) stream_get_contents($out);
    fclose($out);

    return $csv;
}
