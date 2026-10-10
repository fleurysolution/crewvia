<?php
/**
 * Receivables and payables (P3-M04): balances and aging by client or
 * vendor, a party's statement, payments recorded once and applied to
 * invoices, credit notes, and what does not reconcile. Payroll and
 * administrators; credit notes and payment terms are administrators'.
 */

require_once __DIR__ . '/../balances.php';

require_role('payroll');

$side = ($_REQUEST['side'] ?? 'ar') === 'ap' ? 'ap' : 'ar';
$party = isset($_REQUEST['party']) && $_REQUEST['party'] !== '' ? ($side === 'ar' ? (int) $_REQUEST['party'] : trim((string) $_REQUEST['party'])) : null;
$back = '/balances?side=' . $side . ($party !== null ? '&party=' . rawurlencode((string) $party) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    if (in_array($do, ['credit', 'reverse_credit', 'terms'], true)) {
        require_role('admin');
    }

    db()->beginTransaction();
    $id = 0;
    switch ($do) {
        case 'record':
            [$id, $why] = bal_record($side, $party, trim((string) ($_POST['received_on'] ?? '')), (string) ($_POST['amount'] ?? ''), (string) ($_POST['method'] ?? ''),
                                     (string) ($_POST['reference'] ?? ''), (string) ($_POST['note'] ?? ''), is_array($_POST['apply'] ?? null) ? $_POST['apply'] : []);
            break;
        case 'apply':
            $why = bal_apply($side, (int) ($_POST['payment_id'] ?? 0), (int) ($_POST['invoice_id'] ?? 0), (string) ($_POST['amount'] ?? ''));
            break;
        case 'unapply':
            $why = bal_unapply($side, (int) ($_POST['allocation_id'] ?? 0), (string) ($_POST['reason'] ?? ''));
            break;
        case 'reverse_payment':
            $why = bal_reverse_payment($side, (int) ($_POST['payment_id'] ?? 0), (string) ($_POST['reason'] ?? ''));
            break;
        case 'credit':
            [$id, $why] = bal_credit($side, (int) ($_POST['invoice_id'] ?? 0), (string) ($_POST['amount'] ?? ''), (string) ($_POST['reason'] ?? ''), trim((string) ($_POST['issued_on'] ?? '')));
            break;
        case 'reverse_credit':
            $why = bal_reverse_credit($side, (int) ($_POST['credit_id'] ?? 0), (string) ($_POST['reason'] ?? ''));
            break;
        case 'terms':
            $days = (int) ($_POST['payment_terms_days'] ?? -1);
            $why = $side !== 'ar' || ! $party || $days < 0 || $days > 365 || (string) $days !== trim((string) ($_POST['payment_terms_days'] ?? ''))
                ? t('Payment terms are 0 to 365 days.') : null;
            if ($why === null) {
                q('UPDATE clients SET payment_terms_days = ? WHERE id = ?', [$days, $party]);
            }
            break;
        default:
            $why = t('Unknown action.');
    }
    if ($why !== null) {
        db()->rollBack();
        refuse(422, $why);
    }
    db()->commit();
    log_activity(($side === 'ar' ? 'receivables ' : 'payables ') . $do, $side === 'ar' ? 'client' : 'vendor', $side === 'ar' ? (int) $party : 0, (string) $party . ($id ? ' #' . $id : ''));
    flash(t('Recorded.'));
    redirect($back);
}

$aging = bal_aging($side);
$exceptions = bal_exceptions($side);
$parties = $side === 'ar' ? rows('SELECT id, name, payment_terms_days FROM clients ORDER BY name')
                          : rows("SELECT DISTINCT vendor_name AS id, vendor_name AS name FROM vendor_invoices ORDER BY vendor_name");
$detail = null;
if ($party !== null) {
    $s = bal_side($side);
    $detail = [
        'label'      => $side === 'ar' ? (string) val('SELECT name FROM clients WHERE id = ?', [$party]) : (string) $party,
        'terms'      => $side === 'ar' ? (int) val('SELECT payment_terms_days FROM clients WHERE id = ?', [$party]) : null,
        'statement'  => bal_statement($side, $party),
        'open'       => bal_invoices($side, $party, true),
        'payments'   => bal_payments($side, $party),
        'applied'    => rows("SELECT a.*, i.reference AS invoice_ref, p.reference AS payment_ref FROM {$s['alloc']} a JOIN {$s['invoices']} i ON i.id = a.invoice_id
                              JOIN {$s['payments']} p ON p.id = a.payment_id WHERE p.{$s['party']} = ? ORDER BY a.id DESC", [$party]),
        'credits'    => rows("SELECT x.*, i.reference AS invoice_ref FROM {$s['credits']} x JOIN {$s['invoices']} i ON i.id = x.invoice_id
                              JOIN jobs j ON j.id = i.job_id WHERE " . ($side === 'ar' ? 'j.client_id' : 'i.vendor_name') . ' = ? ORDER BY x.id DESC', [$party]),
    ];
}

render('balances', compact('side', 'party', 'aging', 'exceptions', 'parties', 'detail'));
