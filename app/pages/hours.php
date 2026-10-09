<?php
/**
 * The week. Judith and Carole's screen.
 *
 * Hours go in per person per week, because the cheque is weekly and the
 * guarantee is weekly. The guarantee is the point: a man who works 41 hours
 * is still paid 50, and this screen shows what that gap costs rather than
 * quietly absorbing it.
 */

require_role('payroll');

$job = current_job();
$jobId = (int) ($job['id'] ?? 0);
$week  = week_ending($_GET['week'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';

    if($do==='policy') {
        $threshold=trim((string)($_POST['weekly_overtime_after']??''));$multiplier=(float)($_POST['overtime_multiplier']??1.5);
        if(!$jobId||($threshold!==''&&(!is_numeric($threshold)||(float)$threshold<0||(float)$threshold>168))||!is_finite($multiplier)||$multiplier<1||$multiplier>5){refuse(422, t('Invalid weekly payroll policy.'));}
        q('INSERT INTO project_pay_policies(job_id,weekly_overtime_after,overtime_multiplier,reviewed_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE weekly_overtime_after=VALUES(weekly_overtime_after),overtime_multiplier=VALUES(overtime_multiplier),reviewed_by=VALUES(reviewed_by),reviewed_at=NOW()',[$jobId,$threshold!==''?(float)$threshold:null,$multiplier,uid()]);
        log_activity('configured gross payroll policy','project',$jobId);redirect('/hours');
    }
    if($do==='import_attendance') {
        $weekEnding=week_ending((string)($_POST['week_ending']??''));$start=date('Y-m-d',strtotime($weekEnding.' -6 days'));
        db()->beginTransaction();q('SELECT id FROM jobs WHERE id=? FOR UPDATE',[$jobId]);
        $records=rows("SELECT a.* FROM attendance_records a JOIN placements p ON p.id=a.placement_id WHERE p.job_id=? AND a.work_date BETWEEN ? AND ? AND a.status='approved' ORDER BY a.placement_id,a.work_date FOR UPDATE",[$jobId,$start,$weekEnding]);
        $grouped=[];foreach($records as $record)$grouped[$record['placement_id']][]=$record;
        foreach($grouped as $pid=>$source) {
            if(row("SELECT id FROM timesheets WHERE placement_id=? AND week_ending=? AND status IN ('approved','paid')",[$pid,$weekEnding]))continue;
            $hours=array_sum(array_column($source,'hours'));
            q('INSERT INTO timesheets(placement_id,week_ending,hours_worked) VALUES (?,?,?) ON DUPLICATE KEY UPDATE hours_worked=VALUES(hours_worked)',[$pid,$weekEnding,$hours]);
            $sheet=(int)val('SELECT id FROM timesheets WHERE placement_id=? AND week_ending=?',[$pid,$weekEnding]);
            q('INSERT INTO attendance_payroll_sources(timesheet_id,attendance_json,imported_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE attendance_json=VALUES(attendance_json),imported_by=VALUES(imported_by),imported_at=NOW()',[$sheet,json_encode($source,JSON_THROW_ON_ERROR),uid()]);
        }
        db()->commit();log_activity('imported approved attendance','project',$jobId,$weekEnding);flash(t('Approved attendance imported. Review per diem and expenses before approving payroll.'));redirect('/hours?week='.$weekEnding);
    }
    if ($do === 'save') {
        $weekEnding = week_ending((string) ($_POST['week_ending'] ?? ''));
        $saved = 0;
        db()->beginTransaction();
        q('SELECT id FROM jobs WHERE id=? FOR UPDATE',[$jobId]);

        foreach ((array) ($_POST['hours'] ?? []) as $pid => $h) {
            $pid = (int) $pid;
            $hrs = (float) $h;
            $pd  = (int)   ($_POST['per_diem'][$pid] ?? 0);
            $exp = (float) ($_POST['expenses'][$pid] ?? 0);

            // An untouched row is not a zero week. Skip it rather than write
            // a zero that somebody later has to explain.
            if (trim((string)$h) === '' && trim((string)($_POST['per_diem'][$pid] ?? '')) === '' && trim((string)($_POST['expenses'][$pid] ?? '')) === '') {
                continue;
            }

            if ($hrs < 0 || $hrs > 168 || $pd < 0 || $pd > 7 || $exp < 0 || !is_finite($hrs) || !is_finite($exp)) {
                continue;
            }

            $placement = row('SELECT id FROM placements WHERE id=? AND job_id=?', [$pid, $jobId]);
            if (!$placement) continue;
            $locked = row("SELECT id FROM timesheets WHERE placement_id=? AND week_ending=? AND status IN ('approved','paid')", [$pid,$weekEnding]);
            if ($locked) continue;
            q('INSERT INTO timesheets (placement_id, week_ending, hours_worked, per_diem_days, expenses)
               VALUES (?,?,?,?,?)
               ON DUPLICATE KEY UPDATE hours_worked=VALUES(hours_worked),
                 per_diem_days=VALUES(per_diem_days), expenses=VALUES(expenses)',
              [$pid, $weekEnding, $hrs, $pd, $exp]);

            $saved++;
        }

        db()->commit();
        log_activity('saved hours', 'week', 0, $weekEnding . ' - ' . $saved . ' sheets');
        flash(t(':count timesheets saved for week ending :date.', ['count'=>$saved,'date'=>d($weekEnding,'j M')]));
        redirect('/hours?week=' . $weekEnding);
    }

    if ($do === 'approve_week') {
        $weekEnding = week_ending((string) ($_POST['week_ending'] ?? ''));

        db()->beginTransaction();
        q('SELECT id FROM jobs WHERE id=? FOR UPDATE',[$jobId]);
        $job=current_job();
        $pending=rows("SELECT ts.*,p.candidate_id,p.pay_rate,p.bill_rate,p.per_diem_rate,e.employment_type,e.salary_per_period FROM timesheets ts JOIN placements p ON p.id=ts.placement_id LEFT JOIN employee_profiles e ON e.candidate_id=p.candidate_id WHERE p.job_id=? AND ts.week_ending=? AND ts.status IN ('draft','submitted') FOR UPDATE",[$jobId,$weekEnding]);
        foreach($pending as $sheet) {
            if(($sheet['employment_type']??'')==='salaried'&&$sheet['salary_per_period']===null){db()->rollBack();flash(t('Set the employee salary basis before approving payroll.'),'err');redirect('/hours?week='.$weekEnding);}
            if(isset($job['weekly_overtime_after']) && (int)val("SELECT COUNT(*) FROM timesheets ts JOIN placements p ON p.id=ts.placement_id WHERE p.candidate_id=? AND p.job_id<>? AND ts.week_ending=? AND ts.hours_worked>0",[$sheet['candidate_id'],$jobId,$weekEnding])){db()->rollBack();flash(t('Cross-project workweek requires payroll provider reconciliation before approval.'),'err');redirect('/hours?week='.$weekEnding);}
            if(($sheet['employment_type']??'')==='salaried'&&isset($job['weekly_overtime_after'])&&(float)$sheet['hours_worked']>(float)$job['weekly_overtime_after']){db()->rollBack();flash(t('Salaried overtime requires payroll provider reconciliation.'),'err');redirect('/hours?week='.$weekEnding);}
            $result=week_money($sheet,$sheet,$job);
            q('INSERT INTO pay_snapshots(timesheet_id,result_json) VALUES (?,?)',[$sheet['id'],json_encode($result,JSON_THROW_ON_ERROR)]);
            q("UPDATE timesheets SET status='approved',approved_by=?,approved_at=NOW() WHERE id=?",[uid(),$sheet['id']]);
        }
        db()->commit();

        log_activity('approved the week', 'week', 0, $weekEnding);
        flash(t('Week ending :date approved.', ['date'=>d($weekEnding,'j M')]));
        redirect('/hours?week=' . $weekEnding);
    }
}

$crew = rows(
    "SELECT p.id, p.pay_rate, p.bill_rate, p.per_diem_rate, p.status,
            c.full_name, c.discipline,
            e.employment_type,e.salary_per_period,ts.hours_worked, ts.per_diem_days, ts.expenses, ts.status AS sheet_status,snap.result_json snapshot_json
     FROM placements p
     JOIN candidates c ON c.id = p.candidate_id
     LEFT JOIN employee_profiles e ON e.candidate_id=p.candidate_id
     LEFT JOIN timesheets ts ON ts.placement_id = p.id AND ts.week_ending = ?
     LEFT JOIN pay_snapshots snap ON snap.timesheet_id=ts.id
     WHERE p.job_id = ? AND p.status IN ('on_site','travelling','completed')
     ORDER BY c.full_name", [$week, $jobId]);

$totals = ['pay' => 0.0, 'bill' => 0.0, 'hours' => 0.0, 'short' => 0.0, 'perdiem' => 0.0];
$lines  = [];

foreach ($crew as $p) {
    $sheet = [
        'snapshot_json' => $p['snapshot_json'] ?? null,
        'hours_worked' => $p['hours_worked']  ?? 0,
        'per_diem_days' => $p['per_diem_days'] ?? 0,
        'expenses'      => $p['expenses']      ?? 0,
    ];

    $m = week_money($sheet, $p, $job ?? []);
    if ($p['hours_worked'] === null) foreach (['paid_hours','short_by','pay_total','bill_total','labour_cost','per_diem','margin'] as $key) $m[$key]=0;
    $lines[] = $p + ['m' => $m];

    if (($p['hours_worked'] ?? null) !== null) {
        $totals['pay']     += $m['pay_total'];
        $totals['bill']    += $m['bill_total'];
        $totals['hours']   += $m['worked'];
        $totals['short']   += $m['short_by'];
        $totals['perdiem'] += $m['per_diem'];
    }
}

$pageTitle = t('Hours').' · '.$config['app_name'];
render('hours', compact('job','week','lines','totals'));
