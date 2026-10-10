<?php
/**
 * Bill matching (P3-M08): every open bill against an order, matched to the
 * order and to what was received; the exceptions waiting to be cleared;
 * what is ready to pay. Payroll reads it; administrators clear exceptions
 * and set the tolerance.
 */

require_once __DIR__ . '/../matching.php';

require_role('payroll');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role('admin');
    $do = (string) ($_POST['do'] ?? '');

    db()->beginTransaction();
    if ($do === 'clear') {
        $why = bill_clear((int) ($_POST['invoice_id'] ?? 0), (string) ($_POST['reason'] ?? ''));
    } elseif ($do === 'tolerance') {
        $pct = trim((string) ($_POST['percent'] ?? ''));
        $amt = trim((string) ($_POST['amount'] ?? ''));
        $why = ! is_numeric($pct) || (float) $pct < 0 || (float) $pct > 25 || ! is_numeric($amt) || (float) $amt < 0 || (float) $amt > 10000
            ? t('The tolerance is 0 to 25 %, and 0 to 10,000.') : null;
        if ($why === null) {
            foreach (['match_tolerance_percent' => $pct, 'match_tolerance_amount' => $amt] as $k => $v) {
                q('INSERT INTO platform_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$k, (string) round((float) $v, 2)]);
            }
        }
    } else {
        $why = t('Unknown action.');
    }
    if ($why !== null) {
        db()->rollBack();
        refuse(422, $why);
    }
    db()->commit();
    log_activity('bill matching ' . $do, 'vendor_invoice', (int) ($_POST['invoice_id'] ?? 0), (string) ($_POST['reason'] ?? json_encode([$_POST['percent'] ?? '', $_POST['amount'] ?? ''])));
    flash(t('Recorded.'));
    redirect('/bill-matching');
}

$bills = bills_open();
$exceptions = array_values(array_filter($bills, fn($b) => $b['match']['codes'] && ! $b['match']['clearance']));
$ready = array_values(array_filter($bills, fn($b) => $b['match']['ready']));
$tolerance = match_tolerance();

render('bill-matching', compact('bills', 'exceptions', 'ready', 'tolerance'));
