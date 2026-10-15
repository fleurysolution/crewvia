<?php
/**
 * The P5-M52 test's eyes, on the test database only.
 *   mail <email>    the candidate notices queued for this address: subject and body, opened
 *   notes <email>   the in-app notifications of the portal account linked to this candidate
 *   portal <email>      whether a record with this email has a portal
 *   portal-app <email>  the first application of the record that has the portal
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/notices.php';
require_once __DIR__ . '/test-app/app/crypto.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
[$cmd, $arg] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($cmd === 'mail') {
    $out = [];
    foreach (rows("SELECT encrypted_message FROM mail_outbox WHERE recipient = ? AND purpose = 'candidate notice' ORDER BY id", [$arg]) as $m) {
        $out[] = json_decode((string) crypto_open((string) base64_decode((string) $m['encrypted_message'], true)), true);
    }
    echo json_encode($out);
} elseif ($cmd === 'notes') {
    echo json_encode(array_column(rows('SELECT n.message FROM notifications n JOIN worker_accounts w ON w.user_id = n.user_id JOIN candidates c ON c.id = w.candidate_id WHERE c.email = ? ORDER BY n.id', [$arg]), 'message'));
} elseif ($cmd === 'portal-app') {
    echo json_encode(row('SELECT a.id, a.stage, a.vacancy_id FROM applications a JOIN worker_accounts w ON w.candidate_id = a.candidate_id JOIN candidates c ON c.id = a.candidate_id WHERE c.email = ? ORDER BY a.id LIMIT 1', [$arg]) ?: null);
} elseif ($cmd === 'portal') {
    echo json_encode((bool) val('SELECT w.user_id FROM worker_accounts w JOIN candidates c ON c.id = w.candidate_id WHERE c.email = ? LIMIT 1', [$arg]));
}
