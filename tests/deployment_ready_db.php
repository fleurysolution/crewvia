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
require_once __DIR__ . '/test-app/app/crypto.php';

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

$check('A task still waiting: the person is not announced', deployment_ready_notice($cid) === 0 && $count('Ready Tester is ready to travel%') === 0);
q("UPDATE onboarding_tasks SET status = 'approved' WHERE placement_id = ?", [$pid]);
$told = deployment_ready_notice($cid);
$office = (int) val("SELECT COUNT(*) FROM users WHERE is_active = 1 AND role IN ('recruiter','admin')");
$check('Everything done from home cleared: the office is told by name to confirm them, so hotel and travel book', $told === 1 && $count('Ready Tester is ready to travel for Ready test plant%') === $office
       && val('SELECT ready_notified_at FROM placements WHERE id = ?', [$pid]) !== null, [$told, $count('Ready Tester is ready to travel%'), $office]);
$check('A second pass tells nobody again', deployment_ready_notice($cid) === 0 && deployment_ready_notice(null, $job) === 0 && $count('Ready Tester is ready to travel%') === $office);
q("UPDATE placements SET status = 'confirmed' WHERE id = ?", [$pid]);
deployment_confirmed_notice($pid);
$hotels = (int) val("SELECT COUNT(*) FROM users WHERE is_active = 1 AND role IN ('hotels','admin')");
$check('Confirmed: hotel and travel are told who, where and when', $count('Ready Tester is confirmed for Ready test plant, starting%') === $hotels && $hotels > 0, [$hotels]);
$check('and that the travel details are not in yet', $count('Ready Tester is confirmed%travel details are not in yet%') === $hotels);

// P5-M66b: two stages. Without the paperwork recorded, travelling needs the W-4 and the I-9 filled in, starting
// needs them signed in person.
q("INSERT INTO candidates (full_name, email, stage) VALUES ('Stage Tester', 'stage-tester@test.invalid', 'new')");
$c2 = (int) db()->lastInsertId();
q("INSERT INTO placements (candidate_id, job_id, status, start_date) VALUES (?, ?, 'offered', DATE_ADD(CURDATE(), INTERVAL 5 DAY))", [$c2, $job]);
$p2 = (int) db()->lastInsertId();
q("INSERT INTO onboarding_tasks (placement_id, requirement_id, status) VALUES (?, ?, 'approved')", [$p2, $req]);
$labels = static fn(string $stage): array => array_column(deployment_blockers($p2, $c2, $job, $stage), 'label');
$check('Forms not filled in: they cannot travel yet', in_array('The W-4 and the I-9 are filled in before travelling (signed in person on arrival).', $labels('travel'), true), $labels('travel'));
foreach (['w4', 'i9'] as $f) {
    // A real sealed payload: the key rotation drill later checks every encrypted value opens.
    q("INSERT INTO worker_tax_forms (candidate_id, form, encrypted_payload, payload_hash, status) VALUES (?, ?, ?, ?, 'draft')", [$c2, $f, base64_encode(crypto_seal('{}')), hash('sha256', '{}')]);
}
$check('Filled in from home: ready to travel, hotel and travel can book', $labels('travel') === [], $labels('travel'));
$check('but not to start: the W-4 and the I-9 are signed in person on arrival', $labels('on_site') === ['The W-4 and Section 1 of the I-9 are signed in person at the office before starting.'], $labels('on_site'));
q("UPDATE worker_tax_forms SET status = 'submitted' WHERE candidate_id = ?", [$c2]);
$check('Signed in person: ready to start (Section 2 follows within three business days)', $labels('on_site') === [], $labels('on_site'));

file_put_contents(__DIR__ . '/deployment-ready-results.json', json_encode(['pass' => $pass]));
