<?php
/**
 * P5-M100: the Crewvia subscription as RSS sees it - the same figures Fleury Solutions reads, before the invoice.
 * This month so far, the sealed months with their amount and hash, the chain check, and a month's statement: the
 * people counted, with their projects and days (CSV, or printed to PDF).
 */

declare(strict_types=1);

require_role('admin');
require_once __DIR__ . '/../billing-ledger.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'seal') {
    [$closing, $why] = bl_close((string) ($_POST['month'] ?? ''), uid());
    if ($why !== null) {
        refuse(422, $why, '/crewvia-billing');
    }
    flash(t(':month is sealed: :n people.', ['month' => $closing['period_month'], 'n' => (int) $closing['billable_people']]));
    redirect('/crewvia-billing?month=' . $closing['period_month']);
}

bl_sync();
$current = date('Y-m');
$month = (string) ($_GET['month'] ?? '');
if (! bl_valid_month($month)) {
    $month = '';
}

if ($month !== '' && isset($_GET['csv'])) {
    $statement = bl_statement($month);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="crewvia-usage-' . $month . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Month', 'Person', 'Projects', 'Days validated', 'Pay-cycle lines', 'First day', 'Last day']);
    foreach ($statement as $r) {
        fputcsv($out, [$month, $r['full_name'], $r['projects'], (int) $r['days'], (int) $r['pay_lines'], $r['first_day'], $r['last_day']]);
    }
    fclose($out);
    exit;
}

$now = bl_month($current);
$closings = rows('SELECT * FROM billing_closings ORDER BY period_month DESC LIMIT 36');
$check = bl_verify();
$previous = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
$canSeal = ! bl_closing($previous) && (int) val('SELECT COUNT(*) FROM billing_events WHERE period_month = ?', [$previous]) > 0;
$statement = $month !== '' ? bl_statement($month) : [];
$monthClosing = $month !== '' ? bl_closing($month) : null;
$monthFigures = $month !== '' ? ($monthClosing ? ['people' => (int) $monthClosing['billable_people'], 'events' => (int) $monthClosing['event_count']] : bl_month($month)) : null;
$lateLines = $month !== '' ? bl_after_seal($month) : 0;

$pageTitle = t('Crewvia subscription') . ' · ' . $config['app_name'];
render('crewvia-billing', compact('current', 'now', 'closings', 'check', 'previous', 'canSeal', 'month', 'statement', 'monthClosing', 'monthFigures', 'lateLines'));
