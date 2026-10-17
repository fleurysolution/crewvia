<?php
/**
 * P5-M66: the flow carries on by itself. On the test database only:
 *  - a person with a condition still open is not announced;
 *  - the moment the last condition clears, the office (recruiting, administrators) is told once, by name,
 *    to confirm them, and the person is told their onboarding is done;
 *  - a second pass tells nobody again;
 *  - confirmed on the roster, hotel and travel are told who, where and when.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/deployment.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$pass = 0;
$check = static function (string $label, bool $ok, $detail = '') use (&$pass): void {
    if (! $ok) { fwrite(STDERR, "FAIL $label " . json_encode($detail) . "\n"); exit(1); }
    $pass++;
    echo "PASS $label\n";
};

q("INSERT INTO clients (name) VALUES ('Ready test client')");
q("INSERT INTO jobs (client_id, title, status) VALUES (?, 'Ready test plant', 'active')", [(int) db()->lastInsertId()]);
$job = (int) db()->lastInsertId();
q("INSERT INTO onboarding_requirements (job_id, title, instructions, required) VALUES (?, 'Ready test orientation', 'Watch the video.', 1)", [$job]);
$req = (int) db()->lastInsertId();
q("INSERT INTO candidates (full_name, email, stage) VALUES ('Ready Tester', 'ready-tester@test.invalid', 'new')");
$cid = (int) db()->lastInsertId();
q("INSERT INTO placements (candidate_id, job_id, status, start_date) VALUES (?, ?, 'offered', DATE_ADD(CURDATE(), INTERVAL 5 DAY))", [$cid, $job]);
$pid = (int) db()->lastInsertId();
q("INSERT INTO onboarding_tasks (placement_id, requirement_id, status) VALUES (?, ?, 'submitted')", [$pid, $req]);
q("INSERT INTO employment_clearance (candidate_id, i9_status, w4_status) VALUES (?, 'employer_completed', 'reviewed')", [$cid]);
$count = static fn(string $like): int => (int) val('SELECT COUNT(*) FROM notifications WHERE message LIKE ?', [$like]);

$check('A task still waiting: the person is not announced', deployment_ready_notice($cid) === 0 && $count('Ready Tester is cleared%') === 0);
q("UPDATE onboarding_tasks SET status = 'approved' WHERE placement_id = ?", [$pid]);
$told = deployment_ready_notice($cid);
$office = (int) val("SELECT COUNT(*) FROM users WHERE is_active = 1 AND role IN ('recruiter','admin')");
$check('The last condition cleared: the office is told by name to confirm them', $told === 1 && $count('Ready Tester is cleared for Ready test plant%') === $office
       && val('SELECT ready_notified_at FROM placements WHERE id = ?', [$pid]) !== null, [$told, $count('Ready Tester is cleared%'), $office]);
$check('A second pass tells nobody again', deployment_ready_notice($cid) === 0 && deployment_ready_notice(null, $job) === 0 && $count('Ready Tester is cleared%') === $office);
q("UPDATE placements SET status = 'confirmed' WHERE id = ?", [$pid]);
deployment_confirmed_notice($pid);
$hotels = (int) val("SELECT COUNT(*) FROM users WHERE is_active = 1 AND role IN ('hotels','admin')");
$check('Confirmed: hotel and travel are told who, where and when', $count('Ready Tester is confirmed for Ready test plant, starting%') === $hotels && $hotels > 0, [$hotels]);
$check('and that the travel details are not in yet', $count('Ready Tester is confirmed%travel details are not in yet%') === $hotels);

file_put_contents(__DIR__ . '/deployment-ready-results.json', json_encode(['pass' => $pass]));
