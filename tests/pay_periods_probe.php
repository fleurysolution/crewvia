<?php
/**
 * Read-only: pay periods, their adjustments and audit events, and the
 * M06 projects' sheets, as JSON, for pay_periods_http.py.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

echo json_encode([
    'runs'        => rows('SELECT id, week_ending, status, submitted_by, approved_by, locked_by FROM payroll_runs ORDER BY id'),
    'adjustments' => rows('SELECT run_id, candidate_id, timesheet_id, kind, amount, hours, reason FROM payroll_adjustments ORDER BY id'),
    'events'      => rows('SELECT run_id, event, user_id FROM payroll_run_events ORDER BY id'),
    'sheets'      => rows("SELECT t.id, t.placement_id, t.week_ending, t.hours_worked, t.status FROM timesheets t
                           JOIN placements p ON p.id = t.placement_id JOIN jobs j ON j.id = p.job_id
                           WHERE j.title LIKE 'M06 %' ORDER BY t.id"),
    'days'        => rows("SELECT a.id, a.placement_id, a.work_date, a.hours FROM attendance_records a
                           JOIN placements p ON p.id = a.placement_id JOIN jobs j ON j.id = p.job_id
                           WHERE j.title LIKE 'M06 %' ORDER BY a.id"),
]);
