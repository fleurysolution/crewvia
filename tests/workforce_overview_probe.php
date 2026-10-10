<?php
/**
 * Read-only: today's figures for one project, counted with plain SQL that
 * does not go through app/workforce-overview.php, so the screen is checked
 * against something other than itself.
 *
 *   php workforce_overview_probe.php <job_id>
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') {
    fwrite(STDERR, "Refusing: test database only.\n");
    exit(2);
}

$job = (int) ($argv[1] ?? 0);
$today = date('Y-m-d');
$ids = array_map('intval', array_column(rows('SELECT id FROM placements WHERE job_id = ?', [$job]), 'id'));
$in = $ids ? implode(',', $ids) : '0';

echo json_encode([
    'on_assignment' => (int) val("SELECT COUNT(*) FROM placements WHERE job_id = ? AND status IN ('confirmed','travelling','on_site')", [$job]),
    'present'       => (int) val("SELECT COUNT(*) FROM assignment_checkins WHERE placement_id IN ($in) AND work_date = ? AND present = 1", [$today]),
    'absent'        => (int) val("SELECT COUNT(*) FROM assignment_checkins WHERE placement_id IN ($in) AND work_date = ? AND present = 0", [$today]),
    'on_leave'      => (int) val("SELECT COUNT(DISTINCT placement_id) FROM time_off_requests WHERE placement_id IN ($in) AND status = 'approved' AND starts_on <= ? AND ends_on >= ?", [$today, $today]),
]);
