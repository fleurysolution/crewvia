<?php
/**
 * Financial periods (P3-M05): which months are closed, what each still has
 * waiting to be exported, the trial balance of what was exported, and the
 * history of every close and reopen. Payroll reads it; administrators close
 * and reopen.
 */

require_once __DIR__ . '/../periods.php';

require_role('payroll');

// A month is judged on a ledger that has everything recorded in it.
ledger_sync();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role('admin');
    $do = (string) ($_POST['do'] ?? '');
    $period = (string) ($_POST['period'] ?? '');
    $reason = (string) ($_POST['reason'] ?? '');

    db()->beginTransaction();
    $why = match ($do) {
        'close'  => period_close($period, $reason),
        'reopen' => period_reopen($period, $reason),
        default  => t('Unknown action.'),
    };
    if ($why !== null) {
        db()->rollBack();
        refuse(422, $why);
    }
    db()->commit();
    log_activity($do === 'close' ? 'closed a financial period' : 'reopened a financial period', 'period', 0, $period . ': ' . trim($reason));
    flash($do === 'close' ? t(':m is closed.', ['m' => $period]) : t(':m is open again.', ['m' => $period]));
    redirect('/periods?period=' . rawurlencode($period));
}

// The last 18 months, newest first.
$months = [];
for ($i = 0; $i < 18; $i++) {
    $months[] = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
}
$statuses = [];
foreach (rows('SELECT period, status, changed_at FROM financial_periods') as $r) {
    $statuses[$r['period']] = $r;
}

$selected = (string) ($_GET['period'] ?? date('Y-m', strtotime(date('Y-m-01') . ' -1 month')));
if (! period_valid($selected)) {
    $selected = date('Y-m');
}
$pending = period_pending($selected);
$trial = trial_balance($selected);
$events = rows('SELECT e.*, u.name AS by_name FROM financial_period_events e LEFT JOIN users u ON u.id = e.user_id ORDER BY e.id DESC LIMIT 50');

render('periods', compact('months', 'statuses', 'selected', 'pending', 'trial', 'events'));
