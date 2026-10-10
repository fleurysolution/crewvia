<?php
/**
 * Financial periods and the trial balance of what Crewvia sent to
 * QuickBooks (P3-M05).
 *
 * The books are in QuickBooks Online (P3-M02): its trial balance, income
 * statement, balance sheet and cash flow cover everything RSS records,
 * most of which never passes through Crewvia. Crewvia does not draw those
 * statements from its share of the figures, which would look complete and
 * not be. What it does:
 *
 *   - a month closes. Nothing new is then recorded or exported with a date
 *     in it: payments, credit notes, issued invoices, approved bills, paid
 *     claims, QuickBooks entries and their reversals. A month closes only
 *     when nothing dated in it is still waiting to be exported, and only
 *     once it has ended. An administrator can reopen it, with a reason.
 *     Every close and reopen is kept.
 *   - the trial balance of the entries exported, by account and month, with
 *     opening and closing balances. Debits equal credits, or it says so.
 *     It is what QuickBooks should show for these accounts from Crewvia.
 */

declare(strict_types=1);

require_once __DIR__ . '/accounting.php';

function period_of(string $date): string
{
    return substr($date, 0, 7);
}

function period_valid(string $period): bool
{
    return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period);
}

function period_bounds(string $period): array
{
    $start = $period . '-01';

    return [$start, date('Y-m-t', strtotime($start))];
}

function period_closed(string $date): bool
{
    try {
        return val("SELECT status FROM financial_periods WHERE period = ?", [period_of($date)]) === 'closed';
    } catch (Throwable $e) {
        return false;
    }
}

/** The refusal for a date in a closed month, or null. */
function period_guard(string $date): ?string
{
    if ($date !== '' && period_closed($date)) {
        return t('The month :m is closed. Date it in an open month, or have an administrator reopen :m.', ['m' => period_of($date)]);
    }

    return null;
}

/** What is still waiting to be exported with a date in the month. */
function period_pending(string $period): array
{
    [, $end] = period_bounds($period);

    return array_values(array_filter(accounting_pending(min($end, date('Y-m-d'))), fn($e) => period_of($e['date']) === $period));
}

function period_close(string $period, string $reason): ?string
{
    if (! period_valid($period)) {
        return t('Choose a month.');
    }
    if ($period >= date('Y-m')) {
        return t('A month closes once it has ended.');
    }
    if (val('SELECT status FROM financial_periods WHERE period = ?', [$period]) === 'closed') {
        return t('That month is already closed.');
    }
    if (mb_strlen(trim($reason)) < 3) {
        return t('Say why the month is closed: for example, reconciled with QuickBooks.');
    }
    if ($n = count(period_pending($period))) {
        return t(':n entries dated in :m are still waiting to be exported. Export them first.', ['n' => $n, 'm' => $period]);
    }

    q("INSERT INTO financial_periods (period, status, changed_by, changed_at) VALUES (?, 'closed', ?, NOW())
       ON DUPLICATE KEY UPDATE status = 'closed', changed_by = VALUES(changed_by), changed_at = NOW()", [$period, uid() ?: null]);
    q("INSERT INTO financial_period_events (period, action, reason, user_id) VALUES (?, 'close', ?, ?)", [$period, mb_substr(trim($reason), 0, 500), uid() ?: null]);

    return null;
}

function period_reopen(string $period, string $reason): ?string
{
    if (val('SELECT status FROM financial_periods WHERE period = ?', [$period]) !== 'closed') {
        return t('That month is not closed.');
    }
    if (mb_strlen(trim($reason)) < 3) {
        return t('Say why the month is reopened.');
    }

    q("UPDATE financial_periods SET status = 'open', changed_by = ?, changed_at = NOW() WHERE period = ?", [uid() ?: null, $period]);
    q("INSERT INTO financial_period_events (period, action, reason, user_id) VALUES (?, 'reopen', ?, ?)", [$period, mb_substr(trim($reason), 0, 500), uid() ?: null]);

    return null;
}

/**
 * The exported entries by account for one month: what was there before it,
 * what moved in it, and what is there at its end. Every batch counts,
 * exports and reversals alike: a reversal is entries the other way.
 */
function trial_balance(string $period): array
{
    [$start, $end] = period_bounds($period);
    $accounts = accounting_accounts();
    $rows = [];
    foreach (rows('SELECT account_key,
                          SUM(CASE WHEN txn_date < ? THEN debit - credit ELSE 0 END) AS opening,
                          SUM(CASE WHEN txn_date BETWEEN ? AND ? THEN debit ELSE 0 END) AS debit,
                          SUM(CASE WHEN txn_date BETWEEN ? AND ? THEN credit ELSE 0 END) AS credit
                   FROM accounting_lines WHERE txn_date <= ? GROUP BY account_key', [$start, $start, $end, $start, $end, $end]) as $r) {
        $a = $accounts[$r['account_key']] ?? ['label' => $r['account_key'], 'qb_account' => '', 'sort_order' => 999, 'side' => ''];
        $opening = round((float) $r['opening'], 2);
        $closing = round($opening + (float) $r['debit'] - (float) $r['credit'], 2);
        $rows[] = ['key' => $r['account_key'], 'label' => $a['label'], 'qb_account' => $a['qb_account'], 'side' => $a['side'], 'order' => (int) $a['sort_order'],
                   'opening' => $opening, 'debit' => round((float) $r['debit'], 2), 'credit' => round((float) $r['credit'], 2), 'closing' => $closing];
    }
    usort($rows, fn($x, $y) => $x['order'] <=> $y['order']);

    $sum = fn(string $k) => round(array_sum(array_column($rows, $k)), 2);
    $closingDr = round(array_sum(array_map(fn($r) => max(0, $r['closing']), $rows)), 2);
    $closingCr = round(array_sum(array_map(fn($r) => max(0, -$r['closing']), $rows)), 2);

    return ['rows' => $rows, 'debit' => $sum('debit'), 'credit' => $sum('credit'), 'closing_debit' => $closingDr, 'closing_credit' => $closingCr,
            'balanced' => abs($sum('debit') - $sum('credit')) < 0.005 && abs($closingDr - $closingCr) < 0.005];
}
