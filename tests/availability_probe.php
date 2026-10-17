<?php
/**
 * The P5-M45 test's eyes, on the test database only.
 *   candidate <email>   their latest candidate record: availability, the date, the application
 *   account <email>     their account, if any: role and whether it is linked to a candidate
 *   outbox <email>      how many emails are queued for them
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
[$cmd, $arg] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($cmd === 'candidate') {
    $c = row('SELECT id, availability, available_from, home_airport FROM candidates WHERE email = ? ORDER BY id DESC LIMIT 1', [$arg]);
    if ($c) { $c['application'] = (int) val('SELECT id FROM applications WHERE candidate_id = ? ORDER BY id DESC LIMIT 1', [(int) $c['id']]); }
    echo json_encode($c ?: null);
} elseif ($cmd === 'outbox') {
    echo json_encode((int) val('SELECT COUNT(*) FROM email_outbox WHERE recipient = ?', [$arg]));
} elseif ($cmd === 'account') {
    echo json_encode(row('SELECT u.role, w.candidate_id FROM users u LEFT JOIN worker_accounts w ON w.user_id = u.id WHERE u.email = ?', [$arg]) ?: null);
}
