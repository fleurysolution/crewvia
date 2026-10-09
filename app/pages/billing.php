<?php
/**
 * Billing - what the client owes, and what RSS keeps.
 *
 * One line per engineer per week. The client invoice is built from hours
 * actually worked; the payroll cost carries the guarantee. The difference
 * between those two is the whole business, so it is shown rather than filed.
 */

require_role('payroll');

$job = current_job();
$jobId = (int) ($job['id'] ?? 0);

$from = (string) ($_GET['from'] ?? date('Y-m-d', strtotime('-8 weeks')));
$to   = (string) ($_GET['to']   ?? week_ending());

$sheets = rows(
    "SELECT ts.*, snap.result_json AS snapshot_json, p.pay_rate, p.bill_rate, p.per_diem_rate, c.full_name, c.discipline
     FROM timesheets ts
     JOIN placements p ON p.id = ts.placement_id
     JOIN candidates c ON c.id = p.candidate_id
     LEFT JOIN pay_snapshots snap ON snap.timesheet_id=ts.id
     WHERE p.job_id = ? AND ts.status IN ('approved','paid') AND ts.week_ending BETWEEN ? AND ?
     ORDER BY ts.week_ending DESC, c.full_name", [$jobId, $from, $to]);

$weeks = [];
$grand = ['pay' => 0.0, 'bill' => 0.0, 'hours' => 0.0, 'short' => 0.0, 'perdiem' => 0.0, 'margin' => 0.0];

foreach ($sheets as $s) {
    $m  = week_money($s, $s, $job ?? []);
    $wk = $s['week_ending'];

    if (! isset($weeks[$wk])) {
        $weeks[$wk] = ['lines' => [], 'pay' => 0.0, 'bill' => 0.0, 'hours' => 0.0,
                       'short' => 0.0, 'perdiem' => 0.0, 'approved' => 0, 'count' => 0];
    }

    $weeks[$wk]['lines'][]  = $s + ['m' => $m];
    $weeks[$wk]['pay']     += $m['pay_total'];
    $weeks[$wk]['bill']    += $m['bill_total'];
    $weeks[$wk]['hours']   += $m['worked'];
    $weeks[$wk]['short']   += $m['short_by'];
    $weeks[$wk]['perdiem'] += $m['per_diem'];
    $weeks[$wk]['count']++;

    if (in_array($s['status'], ['approved', 'paid'], true)) {
        $weeks[$wk]['approved']++;
    }

    $grand['pay']     += $m['pay_total'];
    $grand['bill']    += $m['bill_total'];
    $grand['hours']   += $m['worked'];
    $grand['short']   += $m['short_by'];
    $grand['perdiem'] += $m['per_diem'];
}

$grand['margin'] = $grand['bill'] - $grand['pay'];

// Lodging is a real cost of this job and it is not in anybody's timesheet.
$bedNights = (float) (val(
    "SELECT COALESCE(SUM(DATEDIFF(COALESCE(l.check_out, CURDATE()), l.check_in) * COALESCE(l.nightly_rate,0)), 0)
     FROM lodging l JOIN placements p ON p.id = l.placement_id
     WHERE p.job_id = ? AND l.check_in IS NOT NULL AND l.status <> 'cancelled'", [$jobId]) ?? 0);

$travelCost = (float) (val(
    "SELECT COALESCE(SUM(t.cost),0) FROM travel t JOIN placements p ON p.id = t.placement_id
     WHERE p.job_id = ? AND t.status <> 'cancelled'", [$jobId]) ?? 0);

$pageTitle = t('Billing').' · '.$config['app_name'];
render('billing', compact('job','weeks','grand','from','to','bedNights','travelCost'));
