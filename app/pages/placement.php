<?php
/** One deployed engineer: everything RSS owes them, on one page. */

require_role('recruiter','hotels','payroll');

$id = (int) ($_GET['id'] ?? 0);

$p = row(
    "SELECT p.*, c.full_name, c.phone, c.email, c.discipline, c.city, c.state, c.degree,
            j.title AS job_title, j.strike_live
     FROM placements p
     JOIN candidates c ON c.id = p.candidate_id
     JOIN jobs j       ON j.id = p.job_id
     WHERE p.id = ?", [$id]);

if (! $p) {
    http_response_code(404);
    render('404', ['path' => 'that placement']);
    exit;
}

// Where this person's terms come from when their placement did not record
// its own: the line of the scope of work they were recruited against. There
// is no project-wide rate to fall back to any more, because one rate was
// never what the client agreed - a line per trade is.
require_once __DIR__ . '/../scope.php';

$line = scope_line_for_placement((int) $p['candidate_id'], (int) $p['job_id']);

$job = [
    'pay_rate'        => $line['pay_rate'] ?? null,
    'bill_rate'       => $line['bill_rate'] ?? null,
    'per_diem_rate'   => $line['per_diem_rate'] ?? null,
    'guarantee_hours' => $line['guarantee_hours'] ?? null,
    'strike_hours'    => $line['strike_guarantee_hours'] ?? null,
    'strike_live'     => $p['strike_live'],
];

// The views read these the way they always did.
$p['job_pay']        = $job['pay_rate'];
$p['job_bill']       = $job['bill_rate'];
$p['job_per_diem']   = $job['per_diem_rate'];
$p['guarantee_hours'] = $p['guarantee_hours'] ?? $job['guarantee_hours'];
$p['strike_hours']   = $job['strike_hours'];
$p['line_role']      = $line['role_title'] ?? null;

$lodge = row('SELECT l.*, h.name AS hotel, h.phone AS hotel_phone, h.address
              FROM lodging l JOIN hotels h ON h.id = l.hotel_id
              WHERE l.placement_id = ? AND l.status <> ? ORDER BY l.id DESC LIMIT 1',
             [$id, 'cancelled']);

$legs = rows('SELECT * FROM travel WHERE placement_id = ? AND status <> ? ORDER BY direction',
             [$id, 'cancelled']);

if(!can('payroll')) { render('placement-logistics',compact('p','lodge','legs'));exit; }
$sheets = rows('SELECT ts.*,s.result_json AS snapshot_json FROM timesheets ts LEFT JOIN pay_snapshots s ON s.timesheet_id=ts.id WHERE placement_id = ? ORDER BY week_ending DESC LIMIT 12', [$id]);


$totals = ['pay' => 0.0, 'bill' => 0.0, 'hours' => 0.0];

foreach ($sheets as $s) {
    $m = week_money($s, $p, $job);
    $totals['pay']   += $m['pay_total'];
    $totals['bill']  += $m['bill_total'];
    $totals['hours'] += $m['worked'];
}

$pageTitle = $p['full_name'] . ' · '.$config['app_name'];
render('placement', compact('p','lodge','legs','sheets','job','totals'));
