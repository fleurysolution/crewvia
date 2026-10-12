<?php
/**
 * The P5-M11 test's eyes and hands, on the test database only.
 *   read            the switch, the users' login counts, attendance, activity
 *   age <email>     moves an email's failed-attempt window 16 minutes back
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$bucket = static fn(string $email): string => hash('sha256', 'login-email|' . mb_strtolower($email));
if (($argv[1] ?? '') === 'age') {
    q('UPDATE auth_rate_limits SET window_start = window_start - 960 WHERE bucket = ?', [$bucket((string) $argv[2])]);
    exit(0);
}
$fx = json_decode((string) file_get_contents(__DIR__ . '/access_switches.json'), true);
$counts = [];
foreach (['ax-rec@test.invalid', 'ax-admin@test.invalid', 'ax-client@test.invalid', 'nobody-ax@test.invalid'] as $email) {
    $counts[$email] = (int) val('SELECT attempts FROM auth_rate_limits WHERE bucket = ?', [$bucket($email)]);
}
$counts['ip'] = (int) val('SELECT attempts FROM auth_rate_limits WHERE bucket = ?', [hash('sha256', 'login-ip|127.0.0.1')]);
echo json_encode([
    'switch' => val("SELECT setting_value FROM platform_settings WHERE setting_key = 'client_portal_enabled'"),
    'counts' => $counts,
    'attendance' => rows('SELECT work_date, hours, status FROM attendance_records WHERE placement_id = ? ORDER BY work_date', [(int) $fx['placement']]),
    'activity' => rows("SELECT action, entity_id, detail FROM activity WHERE action IN ('unlocked a sign-in','switched the client portal on','switched the client portal off','client sign-in refused') ORDER BY id"),
    'client_users' => rows("SELECT id, email, role FROM users WHERE role = 'client' ORDER BY id"),
    'grants' => rows('SELECT user_id, client_id FROM client_access WHERE client_id = ?', [(int) $fx['client']]),
]);
