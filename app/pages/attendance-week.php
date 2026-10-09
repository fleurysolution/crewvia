<?php
/**
 * The week, checked before it is paid.
 *
 * Read-only. It answers three questions on the current project:
 *   - does each weekly sheet match the approved days behind it?
 *   - which days do not add up (roll call against hours, long days,
 *     two jobs on one day, decisions left waiting)?
 *   - who worked on more than one assignment this week, and for how long?
 *
 * Nothing is changed from here. Days are corrected on /attendance, sheets
 * are imported and approved on /hours.
 */

require_once __DIR__ . '/../attendance-controls.php';

require_role('payroll');

$job   = current_job();
$jobId = (int) ($job['id'] ?? 0);
$week  = week_ending($_GET['week'] ?? null);
$start = date('Y-m-d', strtotime($week . ' -6 days'));

$reconciliation = $jobId ? attendance_week_reconciliation($jobId, $week) : [];
$exceptions     = $jobId ? attendance_exceptions($jobId, $start, $week) : [];
$several        = $jobId ? attendance_people_on_several_assignments($jobId, $week) : [];

$states = attendance_reconciliation_states();
$kinds  = attendance_exception_kinds();

$pageTitle = t('Week check') . ' · ' . $config['app_name'];
render('attendance-week', compact('job', 'week', 'start', 'reconciliation', 'exceptions', 'several', 'states', 'kinds'));
