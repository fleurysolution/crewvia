<?php
/**
 * Financial statements (P3-M05): income statement, balance sheet, cash
 * flow and budgets, read from Crewvia's ledger. Payroll and administrators
 * read and print them, and set budgets.
 */

require_once __DIR__ . '/../statements.php';

require_role('payroll');

// Everything recorded reaches the ledger before a figure is shown.
ledger_sync();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['do'] ?? '') !== 'budget') {
        refuse(400, t('Unknown action.'));
    }
    $account = (string) ($_POST['account'] ?? '');
    $from = (string) ($_POST['from'] ?? '');
    $to = (string) ($_POST['to'] ?? '');
    db()->beginTransaction();
    $why = budget_set($account, $from, $to, trim((string) ($_POST['amount'] ?? '')), (string) ($_POST['note'] ?? ''));
    if ($why !== null) {
        db()->rollBack();
        refuse(422, $why);
    }
    db()->commit();
    log_activity('set a budget', 'accounting_account', 0, $account . ' ' . $from . '..' . $to . ' ' . trim((string) $_POST['amount']));
    flash(t('Budget saved.'));
    redirect('/statements?view=budget&from=' . rawurlencode(substr($from, 0, 4) . '-01') . '&to=' . rawurlencode(substr($from, 0, 4) . '-12'));
}

$statement = in_array($_GET['view'] ?? '', ['income', 'balance', 'cash', 'budget'], true) ? (string) $_GET['view'] : 'income';
$today = date('Y-m-d');
$from = valid_date((string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : date('Y-01-01');
$to = valid_date((string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : $today;
if ($to < $from) {
    [$from, $to] = [$to, $from];
}
$asof = valid_date((string) ($_GET['asof'] ?? '')) ? (string) $_GET['asof'] : $today;
$class = trim((string) ($_GET['class'] ?? ''));
$classes = array_column(rows("SELECT DISTINCT l.class FROM gl_lines l JOIN gl_journals j ON j.id = l.journal_id WHERE j.status = 'posted' AND l.class IS NOT NULL ORDER BY l.class"), 'class');
if (! in_array($class, $classes, true)) {
    $class = '';
}

$figures = match ($statement) {
    'income'  => stmt_income($from, $to, $class),
    'balance' => stmt_balance($asof),
    'cash'    => stmt_cash($from, $to),
    'budget'  => null,
};
$bfrom = period_valid((string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : date('Y') . '-01';
$bto = period_valid((string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : date('Y') . '-12';
if ($statement === 'budget') {
    if ($bto < $bfrom) {
        [$bfrom, $bto] = [$bto, $bfrom];
    }
    $figures = budget_variance($bfrom, $bto);
}
$integrity = ledger_integrity();
$accounts = accounting_accounts();
$budgetAccounts = array_filter($accounts, fn($a) => in_array($a['side'], ['income', 'expense'], true) && (int) $a['is_active'] === 1);
$history = $statement === 'budget' ? rows('SELECT e.*, u.name AS by_name FROM gl_budget_events e LEFT JOIN users u ON u.id = e.user_id ORDER BY e.id DESC LIMIT 30') : [];

if ($statement !== 'budget' && isset($_GET['print'])) {
    $brand = (string) ($config['app_name'] ?? 'Crewvia');
    try { $brand = (string) (val("SELECT setting_value FROM platform_settings WHERE setting_key = 'brand_name'") ?: $brand); } catch (Throwable $e) {}
    log_activity('printed a financial statement', 'statement', 0, $statement);
    require __DIR__ . '/../views/statements-print.php';
    exit;
}

render('statements', compact('statement', 'from', 'to', 'asof', 'class', 'classes', 'figures', 'bfrom', 'bto', 'integrity', 'accounts', 'budgetAccounts', 'history'));
