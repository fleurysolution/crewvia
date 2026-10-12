<?php
/**
 * P5-M05 at the database level, on the isolated test database only:
 * rollback (awards already in HR records stay, a reward already paid stays
 * in payroll as a correction) and upgrade, silence on a repeat, then the
 * fixture for engagement_http.py: desks, a supervisor, a crew of three on
 * one project and a worker on another, a leave type with an allowance.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: this test only runs against rss_ops_test.\n"); exit(2); }

$results = [];
function check(string $label, bool $condition, string $detail = ''): void
{
    global $results;
    if (! $condition) { fwrite(STDERR, 'FAIL ' . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL); exit(1); }
    $results[] = $label;
    echo 'PASS ' . $label . PHP_EOL;
}
function run_php(string $script): array
{
    $out = []; $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}
$all = ['kudos', 'reward_catalog', 'award_programs', 'award_nominations', 'reward_points', 'reward_redemptions', 'feedback_requests',
        'feedback_request_people', 'feedback_entries', 'surveys', 'survey_questions', 'survey_invitations', 'survey_responses', 'survey_answers'];
$tables = static fn(): int => (int) val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN (' . implode(',', array_fill(0, count($all), '?')) . ')', $all);
$kindType = static fn(): string => (string) val("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payroll_adjustments' AND column_name = 'kind'");
$app = __DIR__ . '/test-app';
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);

check('P5-M05 is in place before the rollback, with the payroll kind "reward"', $tables() === 14 && str_contains($kindType(), "'reward'"));

// A reward already paid, before the rollback.
q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Engagement rollback payroll', 'en-rollback@test.invalid', $password, 'payroll']);
$rbUser = (int) db()->lastInsertId();
q("INSERT INTO candidates(full_name,email,stage) VALUES ('Engagement Rollback Worker','en-rollback-worker@test.invalid','placed')");
$rbCand = (int) db()->lastInsertId();
q("INSERT INTO payroll_runs (week_ending, opened_by) VALUES ('2001-01-06', ?)", [$rbUser]);
$rbRun = (int) db()->lastInsertId();
q("INSERT INTO payroll_adjustments (run_id, candidate_id, kind, amount, reason, created_by) VALUES (?,?,'reward',75,'Site bonus (reward #1)',?)", [$rbRun, $rbCand, $rbUser]);
$rbAdj = (int) db()->lastInsertId();
$recognitions = (int) val('SELECT COUNT(*) FROM recognitions');

foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m05.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
$adj = row('SELECT kind, amount, reason FROM payroll_adjustments WHERE id = ?', [$rbAdj]);
check('Rollback removes the fourteen tables and keeps every recognition', $tables() === 0 && (int) val('SELECT COUNT(*) FROM recognitions') === $recognitions);
check('and a reward already paid stays in payroll, as a correction of the same amount whose reason starts "Reward:"',
      $adj && $adj['kind'] === 'correction' && (float) $adj['amount'] === 75.0 && str_starts_with((string) $adj['reason'], 'Reward: Site bonus') && ! str_contains($kindType(), "'reward'"), json_encode($adj));
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade announces recognition and engagement, and that HR records and reviews stay where they are', $code === 0 && str_contains($first, 'Recognition and engagement:') && str_contains($first, 'performance reviews and HR cases stay'), $first);
check('The tables are back, with the payroll kind "reward"', $tables() === 14 && str_contains($kindType(), "'reward'"));
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M05', $code === 0 && ! str_contains($second, 'Recognition and engagement:'), $second);
q('DELETE FROM payroll_adjustments WHERE id = ?', [$rbAdj]);
q('DELETE FROM payroll_runs WHERE id = ?', [$rbRun]);

$ids = [];
foreach (['rec' => 'recruiter', 'rec2' => 'recruiter', 'pay' => 'payroll', 'hotels' => 'hotels', 'sup' => 'supervisor',
          'w1' => 'worker', 'w2' => 'worker', 'w3' => 'worker', 'wb' => 'worker'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Engagement ' . $k, 'en-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO clients(name) VALUES ('Engagement client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status) VALUES (?,'P5-M05 refinery','active')", [$client]);
$jobA = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status) VALUES (?,'P5-M05 bridge','active')", [$client]);
$jobB = (int) db()->lastInsertId();
$cands = [];
$worker = static function (string $key, int $job, ?int $supervisor) use ($ids, &$cands): void {
    q("INSERT INTO candidates(full_name,email,stage) VALUES (?,?,'placed')", ['Engagement ' . $key, 'en-cand-' . $key . '@test.invalid']);
    $cid = (int) db()->lastInsertId();
    q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site',CURDATE(),30,50)", [$cid, $job]);
    q('INSERT INTO assignment_details(placement_id,trade,supervisor_id) VALUES (?,?,?)', [(int) db()->lastInsertId(), 'Pipefitter', $supervisor]);
    q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$ids[$key], $cid]);
    $cands[$key] = $cid;
};
$worker('w1', $jobA, $ids['sup']);
$worker('w2', $jobA, $ids['sup']);
$worker('w3', $jobA, $ids['sup']);
$worker('wb', $jobB, null);
q("INSERT INTO leave_types (slug, label, days_allowed, is_paid, sort_order) VALUES ('p5reward', 'P5 floating day', 2, 1, 90)
   ON DUPLICATE KEY UPDATE days_allowed = 2, is_active = 1");

file_put_contents(__DIR__ . '/engagement.json', json_encode(['ids' => $ids, 'cands' => $cands, 'job_a' => $jobA, 'job_b' => $jobB]));
file_put_contents(__DIR__ . '/en-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
