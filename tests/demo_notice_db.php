<?php
/**
 * P5-M62 on the isolated test database only: while a demonstration account works (Owner Number 1,
 * recruiter1), the real candidate whose file it is working on is told in their portal - not only by
 * email. Notices between staff accounts keep the demonstration guard (a demonstration account does
 * not notify a real member of staff).
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/demo.php';
require_once __DIR__ . '/test-app/app/notices.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: this test only runs against rss_ops_test.\n"); exit(2); }

$results = [];
function check(string $label, bool $condition, string $detail = ''): void
{
    global $results;
    if (! $condition) { fwrite(STDERR, 'FAIL ' . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL); exit(1); }
    $results[] = $label;
    echo 'PASS ' . $label . PHP_EOL;
}

demo_registry_ready();
$hash = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
$tag = bin2hex(random_bytes(3));
q("INSERT INTO users (name, email, password_hash, role, must_change_pw) VALUES (?, ?, ?, 'recruiter', 0)", ['Demo recruiter ' . $tag, 'dn-demo-' . $tag . '@test.invalid', $hash]);
$demoUser = (int) db()->lastInsertId();
q("INSERT INTO demo_records (table_name, record_id) VALUES ('users', ?)", [$demoUser]);
q("INSERT INTO users (name, email, password_hash, role, must_change_pw) VALUES (?, ?, ?, 'recruiter', 0)", ['Real recruiter ' . $tag, 'dn-real-' . $tag . '@test.invalid', $hash]);
$realStaff = (int) db()->lastInsertId();
q("INSERT INTO candidates (full_name, email) VALUES (?, ?)", ['Real Candidate ' . $tag, 'dn-cand-' . $tag . '@test.invalid']);
$candidate = (int) db()->lastInsertId();
q("INSERT INTO users (name, email, password_hash, role, must_change_pw) VALUES (?, ?, ?, 'worker', 0)", ['Real Candidate ' . $tag, 'dn-cand-' . $tag . '@test.invalid', $hash]);
$portal = (int) db()->lastInsertId();
q('INSERT INTO worker_accounts (user_id, candidate_id) VALUES (?, ?)', [$portal, $candidate]);

$_SESSION['user'] = row('SELECT * FROM users WHERE id = ?', [$demoUser]);
$count = static fn(int $u): int => (int) val('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$u]);

$before = $count($portal);
notify_candidate($candidate, 'Your interview is booked (rehearsal).', '/my-jobs');
check('A demonstration account working on a real candidate\'s file: the candidate is told in their portal', $count($portal) === $before + 1);

$before = $count($realStaff);
notify_user($realStaff, 'From the demonstration (rehearsal).', '/home');
check('Between staff, the demonstration guard still holds: a demonstration account does not notify a real member of staff', $count($realStaff) === $before);

unset($_SESSION['user']);
q('DELETE FROM notifications WHERE user_id IN (?, ?)', [$portal, $realStaff]);
q('DELETE FROM worker_accounts WHERE user_id = ?', [$portal]);
q("DELETE FROM demo_records WHERE table_name = 'users' AND record_id = ?", [$demoUser]);
q('DELETE FROM users WHERE id IN (?, ?, ?)', [$demoUser, $realStaff, $portal]);
q('DELETE FROM candidates WHERE id = ?', [$candidate]);

file_put_contents(__DIR__ . '/demo-notice-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
