<?php
/**
 * P5-M19 fixture for review_fixes_http.py, on the isolated test database
 * only. No table changes, so no rollback here.
 *   - termination: a worker on site with an open case (opened by another
 *     administrator) and a trusted phone; a worker whose assignment ended
 *     with a resignation; a worker terminated by a decided case, assignment
 *     closed, separation still to record;
 *   - grading: a supervisor's crew member finished 10 days ago, one
 *     finished 90 days ago, one still on site, and another supervisor's
 *     finished one;
 *   - a recruiter and the administrators.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: this test only runs against rss_ops_test.\n"); exit(2); }

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['admin' => 'admin', 'admin2' => 'admin', 'rec' => 'recruiter', 'sup' => 'supervisor', 'sup2' => 'supervisor', 'w' => 'worker', 'w2' => 'worker', 'w3' => 'worker'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Fix ' . $k, 'rf-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO clients(name) VALUES ('Fixes client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status) VALUES (?,'P5-M19 site','active')", [$client]);
$job = (int) db()->lastInsertId();
$cands = $places = [];
$place = static function (string $k, ?string $account, string $status, ?int $supervisor, int $endedDaysAgo = 0) use (&$cands, &$places, $ids, $job): void {
    q("INSERT INTO candidates(full_name,email,stage) VALUES (?,?,'placed')", ['Fix person ' . $k, 'rf-cand-' . $k . '@test.invalid']);
    $cands[$k] = (int) db()->lastInsertId();
    if ($account) { q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$ids[$account], $cands[$k]]); }
    q('INSERT INTO placements(candidate_id,job_id,status,start_date,end_date) VALUES (?,?,?,?,?)',
      [$cands[$k], $job, $status, date('Y-m-d', strtotime('-120 days')), $status === 'completed' ? date('Y-m-d', strtotime('-' . $endedDaysAgo . ' days')) : null]);
    $places[$k] = (int) db()->lastInsertId();
    q('INSERT INTO assignment_details(placement_id,trade,supervisor_id) VALUES (?,?,?)', [$places[$k], 'Fitter', $supervisor]);
};
$place('w', 'w', 'on_site', $ids['sup']);
$place('w2', 'w2', 'completed', null, 3);
$place('w3', 'w3', 'completed', null, 3);
$place('g1', null, 'completed', $ids['sup'], 10);
$place('g90', null, 'completed', $ids['sup'], 90);
$place('gon', null, 'on_site', $ids['sup']);
$place('gother', null, 'completed', $ids['sup2'], 10);
q("INSERT INTO disciplinary_cases(reference,candidate_id,placement_id,category,incident_on,facts,status,opened_by) VALUES ('P5M19-1',?,?,'conduct',CURDATE(),'Left the unit unattended during a permit job.','open',?)",
  [$cands['w'], $places['w'], $ids['admin2']]);
$case = (int) db()->lastInsertId();
q("INSERT INTO disciplinary_cases(reference,candidate_id,placement_id,category,incident_on,facts,status,outcome,outcome_note,opened_by,decided_by,decided_at) VALUES ('P5M19-2',?,?,'safety',CURDATE(),'Repeated permit violations on the unit.','decided','termination','Decided before this test.',?,?,NOW())",
  [$cands['w3'], $places['w3'], $ids['admin2'], $ids['admin2']]);
$case3 = (int) db()->lastInsertId();
q("INSERT INTO device_grants(user_id,token_hash,label,session_version,expires_at) VALUES (?,?,'Fix phone',0,DATE_ADD(NOW(), INTERVAL 14 DAY))", [$ids['w'], hash('sha256', bin2hex(random_bytes(16)))]);

// The one-time sweep of people terminated before the lock: a fourth worker
// terminated by a decided case, with an active account, and the sweep not yet run.
$results = ['Fixture for the review fixes'];
function check(string $label, bool $condition, string $detail = ''): void
{
    global $results;
    if (! $condition) { fwrite(STDERR, 'FAIL ' . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL); exit(1); }
    $results[] = $label;
    echo 'PASS ' . $label . PHP_EOL;
}
q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Fix w4', 'rf-w4@test.invalid', $password, 'worker']);
$ids['w4'] = (int) db()->lastInsertId();
q("INSERT INTO candidates(full_name,email,stage) VALUES ('Fix person w4','rf-cand-w4@test.invalid','placed')");
$cands['w4'] = (int) db()->lastInsertId();
q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$ids['w4'], $cands['w4']]);
q("INSERT INTO disciplinary_cases(reference,candidate_id,category,incident_on,facts,status,outcome,outcome_note,opened_by,decided_by,decided_at) VALUES ('P5M19-4',?,'conduct',CURDATE(),'Old termination, before the lock.','closed','termination','Long ago.',?,?,NOW())",
  [$cands['w4'], $ids['admin2'], $ids['admin2']]);
// Other suites' accounts are put back after the sweep: only this fixture's are the test's business.
$othersOn = array_map('intval', array_column(rows("SELECT id FROM users WHERE is_active = 1 AND id NOT IN (" . implode(',', array_map('intval', $ids)) . ")"), 'id'));
q("DELETE FROM platform_settings WHERE setting_key = 'termination_lock_swept'");
$isActive = static fn(int $u): int => (int) val('SELECT is_active FROM users WHERE id = ?', [$u]);
$out = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/test-app/install/upgrade.php') . ' 2>&1', $out);
$first = implode("\n", $out);
check('The upgrade switches off people terminated before the lock, and says how many', str_contains($first, 'Termination lock:') && $isActive($ids['w4']) === 0
      && (int) val("SELECT COUNT(*) FROM candidate_events WHERE candidate_id = ? AND event_type = 'portal access locked'", [$cands['w4']]) === 1, $first);
check('It leaves alone a worker terminated on a case still open', $isActive($ids['w']) === 1);
q('UPDATE users SET is_active = 1 WHERE id = ?', [$ids['w4']]);
$out = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/test-app/install/upgrade.php') . ' 2>&1', $out);
check('It runs once: switched back on by an administrator, the account stays on, and the next upgrade is silent', $isActive($ids['w4']) === 1 && ! str_contains(implode("\n", $out), 'Termination lock:'));
// w3's decided case predates the screens too; the suite's other workers keep theirs.
q('UPDATE users SET is_active = 1 WHERE id = ?', [$ids['w3']]);
if ($othersOn) { q('UPDATE users SET is_active = 1 WHERE id IN (' . implode(',', $othersOn) . ')'); }

file_put_contents(__DIR__ . '/review_fixes.json', json_encode(['ids' => $ids, 'cands' => $cands, 'places' => $places, 'job' => $job, 'case' => $case, 'case3' => $case3]));
file_put_contents(__DIR__ . '/rf-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
echo 'PASS Fixture for the review fixes' . PHP_EOL;
