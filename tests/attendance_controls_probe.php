<?php
/**
 * Read-only: one placement's attendance days and their corrections, as
 * JSON, for attendance_controls_http.py.
 *
 *   php attendance_controls_probe.php <placement_id>
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') {
    fwrite(STDERR, "Refusing: test database only.\n");
    exit(2);
}

$pid = (int) ($argv[1] ?? 0);

echo json_encode([
    'days' => rows('SELECT id, work_date, hours, status, source, note, submitted_by, reviewed_by
                    FROM attendance_records WHERE placement_id = ? ORDER BY work_date', [$pid]),
    'corrections' => rows('SELECT c.attendance_id, c.old_hours, c.new_hours, c.reason, c.corrected_by
                           FROM attendance_corrections c JOIN attendance_records a ON a.id = c.attendance_id
                           WHERE a.placement_id = ? ORDER BY c.id', [$pid]),
    'sheet' => row('SELECT hours_worked, status FROM timesheets WHERE placement_id = ? ORDER BY week_ending DESC LIMIT 1', [$pid]),
]);
