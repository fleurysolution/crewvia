<?php
/**
 * The P5-M09 test's eyes and hands, on the test database only.
 *   read                              checks (licence sealed and opened), qualifications, events, notifications
 *   age <check> <result_on> <due_on>  moves a recorded check back in time
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/driver-records.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

if (($argv[1] ?? '') === 'age') {
    q('UPDATE driver_checks SET result_on = ?, next_due_on = ? WHERE id = ?', [(string) $argv[3], (string) $argv[4], (int) $argv[2]]);
    exit(0);
}
$fx = json_decode((string) file_get_contents(__DIR__ . '/driver.json'), true);
$cands = implode(',', array_map('intval', $fx['cands']));
$checks = rows("SELECT * FROM driver_checks WHERE candidate_id IN ($cands) ORDER BY id");
foreach ($checks as &$c) {
    $c['license_opened'] = dr_license($c);
}
echo json_encode([
    'checks' => $checks,
    'qualifications' => rows("SELECT q.id, q.candidate_id, t.name, q.detail, q.expires_on, q.evidence_reference, q.status FROM candidate_qualifications q JOIN qualification_types t ON t.id = q.type_id WHERE q.candidate_id IN ($cands) ORDER BY q.id"),
    'qualification_events' => rows("SELECT e.qualification_id, e.action, e.note FROM qualification_events e JOIN candidate_qualifications q ON q.id = e.qualification_id WHERE q.candidate_id IN ($cands) ORDER BY e.id"),
    'events' => rows("SELECT candidate_id, event_type, detail FROM candidate_events WHERE candidate_id IN ($cands) AND event_type = 'driver_check' ORDER BY id"),
    'notifications' => rows("SELECT user_id, message, target FROM notifications WHERE target LIKE '/driver-checks%' ORDER BY id"),
    'links' => dr_links(),
]);
