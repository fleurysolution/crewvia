<?php
/**
 * The P5-M17 test's eyes and hands, on the test database only.
 *   read        the fixture's assignments
 *   staff <n>   adds n more active staff who could be given a crew
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

if (($argv[1] ?? '') === 'staff') {
    for ($i = 0; $i < (int) $argv[2]; $i++) {
        q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,'x','supervisor',0)", ['Org extra ' . bin2hex(random_bytes(3)), 'org-x-' . bin2hex(random_bytes(4)) . '@test.invalid']);
    }
    exit(0);
}
$fx = json_decode((string) file_get_contents(__DIR__ . '/organization.json'), true);
$places = implode(',', array_map('intval', $fx['places']));
echo json_encode(['assignments' => rows("SELECT placement_id, trade, supervisor_id, shift_label FROM assignment_details WHERE placement_id IN ($places) ORDER BY placement_id")]);
