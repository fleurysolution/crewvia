<?php
/**
 * Financial statements and budgets (P3-M05), read from Crewvia's ledger
 * (P3-M03). Nothing here is entered: every figure is the sum of posted
 * journals, on their posting dates.
 *
 *   income statement   income and expense accounts between two dates;
 *                      optionally one project (the ledger's Class)
 *   balance sheet      assets, liabilities and equity at a date. Earnings
 *                      are not closed into an account: earlier years'
 *                      appear as retained earnings, this year's as current
 *                      earnings, so assets equal liabilities and equity
 *                      whenever the ledger balances. The year is the
 *                      calendar year.
 *   cash flow          direct: every journal that moves the bank account,
 *                      by what is on its other side. Operating, investing
 *                      (assets other than bank and receivables), financing
 *                      (equity, and liabilities an administrator added)
 *   budgets            an amount per income or expense account and month;
 *                      every change is kept. Variance is favourable when
 *                      income beats its budget or a cost stays under it.
 *
 * The statements are as complete as the ledger: what Crewvia records,
 * plus the journals typed for the rest. They are not audited.
 */

declare(strict_types=1);

require_once __DIR__ . '/ledger.php';

/** Net movement per account between two dates (debit minus credit), posted journals only. */
function stmt_movements(?string $from, string $to, ?string $class = null): array
{
    $sql = "SELECT l.account_key, SUM(l.debit - l.credit) AS net FROM gl_lines l JOIN gl_journals j ON j.id = l.journal_id
            WHERE j.status = 'posted' AND j.posted_on <= ?";
    $args = [$to];
    if ($from !== null) {
        $sql .= ' AND j.posted_on >= ?';
        $args[] = $from;
    }
    if ($class !== null && $class !== '') {
        $sql .= ' AND l.class = ?';
        $args[] = $class;
    }
    $out = [];
    foreach (rows($sql . ' GROUP BY l.account_key', $args) as $r) {
        $out[$r['account_key']] = round((float) $r['net'], 2);
    }

    return $out;
}

/** Accounts of one side with their amount, in the side's natural sign. */
function stmt_section(array $net, string $side): array
{
    $sign = in_array($side, ['asset', 'expense'], true) ? 1 : -1;
    $rows = [];
    foreach (accounting_accounts() as $key => $a) {
        if ($a['side'] !== $side || ! isset($net[$key]) || abs($net[$key]) < 0.005) {
            continue;
        }
        $rows[] = ['key' => $key, 'number' => (string) $a['number'], 'label' => $a['label'], 'amount' => round($sign * $net[$key], 2)];
    }
    usort($rows, fn($x, $y) => [$x['number'], $x['key']] <=> [$y['number'], $y['key']]);

    return ['rows' => $rows, 'total' => round(array_sum(array_column($rows, 'amount')), 2)];
}

function stmt_income(string $from, string $to, ?string $class = null): array
{
    $net = stmt_movements($from, $to, $class);
    $income = stmt_section($net, 'income');
    $expense = stmt_section($net, 'expense');

    return ['from' => $from, 'to' => $to, 'class' => $class, 'income' => $income, 'expense' => $expense,
            'net' => round($income['total'] - $expense['total'], 2)];
}

function stmt_balance(string $asof): array
{
    $yearStart = substr($asof, 0, 4) . '-01-01';
    $net = stmt_movements(null, $asof);
    $earnings = static function (array $m): float {
        return round(stmt_section($m, 'income')['total'] - stmt_section($m, 'expense')['total'], 2);
    };
    $prior = $earnings(stmt_movements(null, date('Y-m-d', strtotime($yearStart . ' -1 day'))));
    $current = $earnings(stmt_movements($yearStart, $asof));
    $assets = stmt_section($net, 'asset');
    $liabilities = stmt_section($net, 'liability');
    $equity = stmt_section($net, 'equity');
    $totalLe = round($liabilities['total'] + $equity['total'] + $prior + $current, 2);

    return ['asof' => $asof, 'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity,
            'prior_earnings' => $prior, 'current_earnings' => $current, 'total_le' => $totalLe,
            'balanced' => abs($assets['total'] - $totalLe) < 0.005];
}

/** Where a cash movement belongs, by the account on its other side. */
function stmt_cash_line(array $a): array
{
    $key = $a['account_key'];
    if ($key === 'accounts_receivable') {
        return ['operating', 'Received from clients'];
    }
    if ($key === 'accounts_payable') {
        return ['operating', 'Paid to vendors'];
    }
    if (in_array($key, ['net_pay_payable', 'deductions_payable', 'employer_payable'], true)) {
        return ['operating', 'Payroll and payroll liabilities paid'];
    }

    return match ($a['side']) {
        'expense'   => ['operating', 'Costs paid directly'],
        'income'    => ['operating', 'Other income received'],
        'equity'    => ['financing', 'Owner contributions and draws'],
        'liability' => ['financing', 'Loans and other liabilities'],
        default     => ['investing', 'Other assets bought or sold'],
    };
}

function stmt_cash(string $from, string $to): array
{
    $accounts = accounting_accounts();
    $opening = round((float) val("SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM gl_lines l JOIN gl_journals j ON j.id = l.journal_id
                                  WHERE j.status = 'posted' AND l.account_key = 'bank' AND j.posted_on < ?", [$from]), 2);
    // Every journal that touches the bank: its other lines say where the money went.
    // In a balanced journal their credits less debits equal the bank's movement.
    $groups = [];
    foreach (rows("SELECT l.account_key, SUM(l.credit - l.debit) AS cash FROM gl_lines l JOIN gl_journals j ON j.id = l.journal_id
                   WHERE j.status = 'posted' AND j.posted_on BETWEEN ? AND ? AND l.account_key <> 'bank'
                     AND EXISTS (SELECT 1 FROM gl_lines b WHERE b.journal_id = j.id AND b.account_key = 'bank')
                   GROUP BY l.account_key", [$from, $to]) as $r) {
        [$section, $label] = stmt_cash_line(['account_key' => $r['account_key'], 'side' => $accounts[$r['account_key']]['side'] ?? 'asset']);
        $groups[$section][$label] = round(($groups[$section][$label] ?? 0) + (float) $r['cash'], 2);
    }
    $sections = [];
    foreach (['operating', 'investing', 'financing'] as $s) {
        $lines = array_filter($groups[$s] ?? [], fn($v) => abs($v) >= 0.005);
        $sections[$s] = ['lines' => $lines, 'total' => round(array_sum($lines), 2)];
    }
    $change = round(array_sum(array_column($sections, 'total')), 2);
    $closing = round((float) val("SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM gl_lines l JOIN gl_journals j ON j.id = l.journal_id
                                  WHERE j.status = 'posted' AND l.account_key = 'bank' AND j.posted_on <= ?", [$to]), 2);

    return ['from' => $from, 'to' => $to, 'opening' => $opening, 'sections' => $sections, 'change' => $change, 'closing' => $closing,
            'reconciled' => abs($opening + $change - $closing) < 0.005];
}

// ── budgets ─────────────────────────────────────────────────────────────

/** Months from one to another, both included. */
function stmt_months(string $from, string $to): array
{
    $out = [];
    for ($m = $from; $m <= $to && count($out) < 60; $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
        $out[] = $m;
    }

    return $out;
}

/** Set the same budget on an account for each month of a range. */
function budget_set(string $account, string $from, string $to, string $amount, string $note): ?string
{
    $a = accounting_accounts()[$account] ?? null;
    if (! $a || ! in_array($a['side'], ['income', 'expense'], true) || (int) $a['is_active'] !== 1) {
        return t('Budgets are set on active income and expense accounts.');
    }
    if (! period_valid($from) || ! period_valid($to) || $to < $from) {
        return t('Choose the first and last month.');
    }
    $months = stmt_months($from, $to);
    if (count($months) > 24) {
        return t('Set at most 24 months at once.');
    }
    if (! preg_match('/^\d{1,11}(\.\d{1,2})?$/', $amount)) {
        return t('A budget is a positive amount, with at most two decimals.');
    }
    if (mb_strlen($note) > 500) {
        return t('A note is at most 500 characters.');
    }
    $value = round((float) $amount, 2);
    foreach ($months as $m) {
        $old = val('SELECT amount FROM gl_budgets WHERE account_key = ? AND period = ? FOR UPDATE', [$account, $m]);
        if ($old !== null && abs((float) $old - $value) < 0.005) {
            continue;
        }
        q('INSERT INTO gl_budgets (account_key, period, amount, updated_by, updated_at) VALUES (?,?,?,?,NOW())
           ON DUPLICATE KEY UPDATE amount = VALUES(amount), updated_by = VALUES(updated_by), updated_at = NOW()', [$account, $m, $value, uid() ?: null]);
        q('INSERT INTO gl_budget_events (account_key, period, old_amount, new_amount, note, user_id) VALUES (?,?,?,?,?,?)',
          [$account, $m, $old, $value, trim($note) === '' ? null : trim($note), uid() ?: null]);
    }

    return null;
}

/**
 * Budget beside actual, per income and expense account, for a range of
 * months: each month and the total. Actual is what the ledger posted.
 */
function budget_variance(string $from, string $to): array
{
    $months = stmt_months($from, $to);
    [$start] = period_bounds($months[0]);
    [, $end] = period_bounds(end($months));
    $budget = [];
    foreach (rows('SELECT account_key, period, amount FROM gl_budgets WHERE period BETWEEN ? AND ?', [$months[0], end($months)]) as $r) {
        $budget[$r['account_key']][$r['period']] = round((float) $r['amount'], 2);
    }
    $actual = [];
    foreach (rows("SELECT l.account_key, DATE_FORMAT(j.posted_on, '%Y-%m') AS period, SUM(l.debit - l.credit) AS net FROM gl_lines l JOIN gl_journals j ON j.id = l.journal_id
                   WHERE j.status = 'posted' AND j.posted_on BETWEEN ? AND ? GROUP BY l.account_key, period", [$start, $end]) as $r) {
        $actual[$r['account_key']][$r['period']] = round((float) $r['net'], 2);
    }
    $rows = [];
    foreach (accounting_accounts() as $key => $a) {
        if (! in_array($a['side'], ['income', 'expense'], true) || (! isset($budget[$key]) && ! isset($actual[$key]))) {
            continue;
        }
        $sign = $a['side'] === 'income' ? -1 : 1;
        $cells = [];
        foreach ($months as $m) {
            $cells[$m] = ['budget' => $budget[$key][$m] ?? null, 'actual' => round($sign * ($actual[$key][$m] ?? 0), 2)];
        }
        $b = round(array_sum(array_map(fn($c) => (float) $c['budget'], $cells)), 2);
        $act = round(array_sum(array_column($cells, 'actual')), 2);
        // Favourable when positive: more income than budgeted, or less cost.
        $variance = $a['side'] === 'income' ? round($act - $b, 2) : round($b - $act, 2);
        $rows[] = ['key' => $key, 'number' => (string) $a['number'], 'label' => $a['label'], 'side' => $a['side'], 'cells' => $cells,
                   'budget' => $b, 'actual' => $act, 'variance' => $variance, 'percent' => $b > 0 ? round(100 * $variance / $b, 1) : null,
                   'budgeted' => isset($budget[$key])];
    }
    usort($rows, fn($x, $y) => [$x['side'] === 'income' ? 0 : 1, $x['number']] <=> [$y['side'] === 'income' ? 0 : 1, $y['number']]);

    return ['months' => $months, 'rows' => $rows];
}
