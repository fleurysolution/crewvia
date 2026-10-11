<?php
/**
 * Asset valuation (P5-M03), for logistics, payroll and administrators: what
 * every agency-owned item is worth on a date, straight-line, with the totals
 * by class, the items that cannot be valued and why, and a CSV of it. A
 * management valuation: nothing here is posted to the ledger.
 */

declare(strict_types=1);

require_once __DIR__ . '/../asset-lifecycle.php';

require_role('hotels', 'payroll');

$asOf = valid_date((string) ($_GET['as_of'] ?? '')) && (string) $_GET['as_of'] >= '2000-01-01' ? (string) $_GET['as_of'] : date('Y-m-d');
$class = isset(al_classes()[$_GET['class'] ?? '']) ? (string) $_GET['class'] : '';
$report = al_valuation_report($asOf, $class);

if (isset($_GET['csv'])) {
    log_activity('exported the asset valuation', 'equipment', 0, 'as of ' . $asOf . ($class ? ', ' . $class : ''));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="asset-valuation-' . $asOf . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['tag', 'item', 'category', 'class', 'owner', 'state', 'cost', 'residual', 'life_months', 'in_service_from', 'months_used', 'depreciation', 'book_value', 'service_costs', 'cost_of_ownership', 'disposal_gain_loss', 'not_valued_because']);
    foreach ($report['rows'] as $r) {
        $a = $r['item'];
        $v = $r['value'];
        fputcsv($out, array_map('csv_cell', [$a['asset_tag'], $a['name'], (string) $a['category'], (string) $a['asset_class'], (string) ($a['owner_type'] ?? 'agency'), (string) $a['status'],
            $v['cost'], $v['residual'], $v['life'], $v['start'], $v['months'], $v['accumulated'], $v['book'], round($v['service_cost'], 2), $v['tco'], $v['gain'], $v['valued'] ? '' : $v['reason']]));
    }
    fclose($out);
    exit;
}

render('asset-valuation', compact('asOf', 'class', 'report'));
