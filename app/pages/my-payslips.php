<?php
/** A worker's own pay statements, for periods already approved. */

require_once __DIR__ . '/../pay-periods.php';

require_login();

if (! is_worker_account()) {
    redirect('/payroll-runs');
}

$candidateId = (int) val('SELECT candidate_id FROM worker_accounts WHERE user_id = ?', [uid()]);

$runs = rows("SELECT r.* FROM payroll_runs r
              WHERE r.status IN ('approved','locked')
                AND (EXISTS (SELECT 1 FROM timesheets t JOIN placements p ON p.id = t.placement_id
                              WHERE p.candidate_id = ? AND t.week_ending = r.week_ending AND t.status IN ('approved','paid'))
                     OR EXISTS (SELECT 1 FROM payroll_adjustments a WHERE a.run_id = r.id AND a.candidate_id = ?))
              ORDER BY r.week_ending DESC LIMIT 104", [$candidateId, $candidateId]);

$slips = [];
foreach ($runs as $r) {
    $data = payslip_data($r, $candidateId);
    if ($data) {
        $slips[] = ['run' => $r, 'payable' => $data['payable']];
    }
}

$pageTitle = t('My pay statements') . ' · ' . $config['app_name'];
render('my-payslips', compact('slips'));
