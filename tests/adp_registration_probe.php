<?php
/**
 * The P5-M31 test's eyes, on the test database only.
 *   notices <user>       their notification count and the last one
 *   status <candidate>   their ADP employee ID and registration date
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
[$cmd, $arg] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($cmd === 'notices') {
    $last = row('SELECT message, target FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1', [(int) $arg]);
    echo json_encode(['count' => (int) val('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [(int) $arg]), 'last' => $last['message'] ?? '', 'target' => $last['target'] ?? '']);
} elseif ($cmd === 'status') {
    echo json_encode(row('SELECT adp_employee_id, adp_registered_at FROM employee_profiles WHERE candidate_id = ?', [(int) $arg]) ?: null);
}
