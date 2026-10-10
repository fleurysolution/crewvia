<?php
/**
 * Project costs and profitability (P3-M01), for the selected project,
 * project to date. Payroll and administrators read it: it shows pay and
 * margins. Administrators set the budget and the burden and overhead
 * rates; every budget change is kept with its reason.
 */

require_once __DIR__ . '/../procurement.php';
require_once __DIR__ . '/../project-costing.php';

require_role('payroll');

$job = current_job();
$jobId = (int) ($job['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role('admin');
    $do = (string) ($_POST['do'] ?? '');

    if (! $jobId) {
        refuse(422, t('Select a project first.'));
    }

    if ($do === 'budget') {
        $category = (string) ($_POST['category'] ?? '');
        $why = project_budget_set($jobId, $category, trim((string) ($_POST['amount'] ?? '')), (string) ($_POST['reason'] ?? ''));
        if ($why !== null) {
            refuse(422, $why);
        }
        log_activity('changed a project budget', 'project', $jobId, $category . ' ' . (string) $_POST['amount']);
        flash(t('Budget changed.'));
        redirect('/project-costs');
    }

    if ($do === 'rates') {
        $rates = [];
        foreach (['burden_percent', 'overhead_percent'] as $k) {
            $v = trim((string) ($_POST[$k] ?? ''));
            if ($v !== '' && (! is_numeric($v) || (float) $v < 0 || (float) $v > 100)) {
                refuse(422, t('A rate is a percentage from 0 to 100, or empty.'));
            }
            $rates[$k] = $v === '' ? null : round((float) $v, 2);
        }
        q('UPDATE jobs SET burden_percent = ?, overhead_percent = ? WHERE id = ?', [$rates['burden_percent'], $rates['overhead_percent'], $jobId]);
        log_activity('changed project cost rates', 'project', $jobId, json_encode($rates));
        flash(t('Rates saved. The report uses them for every week, past and future.'));
        redirect('/project-costs');
    }

    refuse(400, t('Unknown action.'));
}

$costs = $jobId ? project_costs($jobId) : null;
$profit = $costs ? project_profit($costs['actual']) : null;
$budget = $jobId ? project_budget($jobId) : [];
$history = $jobId ? rows('SELECT b.*, u.name AS by_name FROM project_budget_changes b LEFT JOIN users u ON u.id = b.changed_by
                          WHERE b.job_id = ? ORDER BY b.id DESC LIMIT 50', [$jobId]) : [];
$categories = project_cost_categories();

render('project-costs', compact('job', 'costs', 'profit', 'budget', 'history', 'categories'));
