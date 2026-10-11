<?php
/**
 * P4-M06 at the database level, on the isolated test database only:
 * rollback and upgrade (privacy tables, the audit log's address column and
 * its append-only triggers), silence on a repeat, then the fixture for
 * security_review_http.py: desks, a supervisor with a crew on one project
 * and none on another, a placed worker with a pay change on record, an
 * applicant never placed.
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
function sql_file(string $path): void
{
    foreach (preg_split('/;\s*\n/', (string) file_get_contents($path)) as $chunk) {
        $lines = array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'));
        $statement = trim(implode("\n", $lines));
        if ($statement !== '') { db()->exec($statement); }
    }
}
function run_php(string $script): array
{
    $out = []; $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}
$table = static fn(string $t): bool => (bool) val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
$triggers = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND trigger_name LIKE 'activity\\_no\\_%'");
$ipCol = static fn(): bool => (bool) val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activity' AND column_name = 'ip'");
$app = __DIR__ . '/test-app';

check('P4-M06 is in place before the rollback', $table('privacy_notices') && $table('consent_records') && $ipCol() && $triggers() === 2);
$entries = (int) val('SELECT COUNT(*) FROM activity');
sql_file($app . '/install/rollback/p4-m06.sql');
check('Rollback removes the privacy tables, the address column and the triggers, and keeps every audit entry',
      ! $table('privacy_notices') && ! $table('consent_records') && ! $ipCol() && $triggers() === 0 && (int) val('SELECT COUNT(*) FROM activity') === $entries);
log_activity('logged while the column is gone');
check('Until the upgrade runs again, an entry is kept without its address rather than lost', val("SELECT COUNT(*) FROM activity WHERE action = 'logged while the column is gone'") == 1);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade announces the review: sessions bound, the log append-only, no notice published, two-step not yet required',
      $code === 0 && str_contains($first, 'Security review: sessions bound to their workspace') && str_contains($first, 'no privacy notice is published yet') && ! str_contains($first, 'WARNING'), $first);
check('Both audit triggers are back', $triggers() === 2 && $ipCol());
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P4-M06', $code === 0 && ! str_contains($second, 'Security review:'), $second);
try { q("UPDATE activity SET detail = 'x' WHERE id = (SELECT * FROM (SELECT MAX(id) FROM activity) t)"); $changed = true; } catch (Throwable $e) { $changed = ! str_contains($e->getMessage(), 'The audit log cannot be changed'); }
check('The database refuses to change an audit entry', ! $changed);

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['admin' => 'admin', 'admin2' => 'admin', 'payroll' => 'payroll', 'payroll2' => 'payroll', 'recruiter' => 'recruiter', 'super' => 'supervisor', 'weak' => 'recruiter'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Review ' . $k, 'sr-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO clients(name) VALUES ('Review client')");
$client = (int) db()->lastInsertId();
$job = static function (string $t) use ($client): int {
    q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status) VALUES (?,?,'Synthetic.',30,50,0,0,0,'active')", [$client, $t]);
    return (int) db()->lastInsertId();
};
$jobCrew = $job('P4-M06 supervised project');
$jobOther = $job('P4-M06 other project');
q("INSERT INTO candidates(full_name,email,stage) VALUES ('Wren Placed','sr-placed@test.invalid','placed')");
$placed = (int) db()->lastInsertId();
q("INSERT INTO employee_profiles(candidate_id,employment_type,payment_method) VALUES (?,'hourly','direct_deposit')", [$placed]);
q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site',CURDATE(),30,50)", [$placed, $jobCrew]);
$pl = (int) db()->lastInsertId();
q('INSERT INTO assignment_details(placement_id,trade,supervisor_id) VALUES (?,?,?)', [$pl, 'Electrician', $ids['super']]);
q("INSERT INTO activity(user_id,action,entity,entity_id,detail) VALUES (?,'recorded a pay change','candidate',?,'hourly_rate from 2026-10-01: 42.50 for the new site')", [$ids['payroll'], $placed]);
q("INSERT INTO activity(user_id,action,entity,entity_id,detail) VALUES (?,'noted a call','candidate',?,'Available from Monday')", [$ids['recruiter'], $placed]);
q("INSERT INTO candidates(full_name,email,phone,stage,notes,resume_text) VALUES ('Ivy Applicant','sr-applicant@test.invalid','555-0100','new','Met at the fair','Ten years of welding')");
$applicant = (int) db()->lastInsertId();

$cn = json_decode((string) file_get_contents(__DIR__ . '/connectors.json'), true);
file_put_contents(__DIR__ . '/security-review.json', json_encode(['ids' => $ids, 'job_crew' => $jobCrew, 'job_other' => $jobOther, 'placed' => $placed, 'applicant' => $applicant,
    'cn_job' => $cn['job'], 'cn_week' => $cn['week'], 'cn_ada' => (int) val("SELECT id FROM candidates WHERE full_name = 'Ada Exported'")]));
file_put_contents(__DIR__ . '/sr-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
