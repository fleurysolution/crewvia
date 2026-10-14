<?php
/**
 * The P5-M32 test's eyes, on the test database only.
 *   notices <user>       their notification count and the last one
 *   lodging <placement>  their stay: room number, status, when they were told
 *   clock <placement>    their clock-in today, and the roll call mark
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
[$cmd, $arg] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($cmd === 'notices') {
    $last = row('SELECT message, target FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1', [(int) $arg]);
    echo json_encode(['count' => (int) val('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [(int) $arg]), 'last' => $last['message'] ?? '', 'target' => $last['target'] ?? '']);
} elseif ($cmd === 'lodging') {
    echo json_encode(row("SELECT room_number, status, room_told_at FROM lodging WHERE placement_id = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 1", [(int) $arg]) ?: null);
} elseif ($cmd === 'clock') {
    echo json_encode(['clock' => row('SELECT * FROM clock_ins WHERE placement_id = ? ORDER BY id DESC LIMIT 1', [(int) $arg]) ?: null,
                      'present' => val('SELECT present FROM assignment_checkins WHERE placement_id = ? ORDER BY work_date DESC LIMIT 1', [(int) $arg])]);
}
