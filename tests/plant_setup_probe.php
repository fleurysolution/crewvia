<?php
/**
 * The P5-M27 test's eyes, on the test database only.
 *   job <title>          the project's id and its shifts
 *   line <role title>    a scope line's terms
 *   assignment <id>      a placement's trade, shift link, label and start
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
[$cmd, $arg] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($cmd === 'job') {
    $id = (int) val('SELECT id FROM jobs WHERE title = ? ORDER BY id DESC LIMIT 1', [$arg]);
    echo json_encode(['id' => $id, 'shifts' => array_map(static fn($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'starts_at' => $s['starts_at'], 'ends_at' => $s['ends_at'], 'bus_departs' => $s['bus_departs']],
        rows('SELECT * FROM project_shifts WHERE job_id = ? ORDER BY sort_order, id', [$id]))]);
} elseif ($cmd === 'line') {
    echo json_encode(row('SELECT id, pay_rate, bill_rate, per_diem_rate FROM job_order_lines WHERE role_title = ? ORDER BY id DESC LIMIT 1', [$arg]));
} elseif ($cmd === 'assignment') {
    $a = row('SELECT trade, shift_id, shift_label, shift_start FROM assignment_details WHERE placement_id = ?', [(int) $arg]);
    if ($a) { $a['shift_id'] = $a['shift_id'] === null ? null : (int) $a['shift_id']; }
    echo json_encode($a);
}
