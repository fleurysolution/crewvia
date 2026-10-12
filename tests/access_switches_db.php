<?php
/**
 * P5-M11 at the database level, on the isolated test database only:
 * rollback and upgrade of the client portal switch (OFF where no client
 * signs in, left ON where one does), silence on a repeat; then the fixture
 * for access_switches_http.py: an administrator, a recruiter whose sign-in
 * will be locked, a client contact, a worker on site with one week frozen
 * for pay.
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
function rollback(string $app): void
{
    foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m11.sql')) as $chunk) {
        $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
        if ($statement !== '') { db()->exec($statement); }
    }
}
$switch = static fn(): ?string => ($v = val("SELECT setting_value FROM platform_settings WHERE setting_key = 'client_portal_enabled'")) === null || $v === false ? null : (string) $v;
$app = __DIR__ . '/test-app';

// Whatever client accounts earlier suites left are switched off for the first half.
$earlier = array_map('intval', array_column(rows("SELECT id FROM users WHERE role = 'client' AND is_active = 1"), 'id'));
if ($earlier) { q('UPDATE users SET is_active = 0 WHERE id IN (' . implode(',', $earlier) . ')'); }

check('P5-M11 is in place before the rollback', $switch() !== null);
rollback($app);
check('Rollback removes the switch', $switch() === null);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('With no client signing in, the upgrade puts the client portal OFF and says so', $code === 0 && str_contains($first, 'Client portal: OFF, no client account exists') && $switch() === '0', $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M11', $code === 0 && ! str_contains($second, 'Client portal:'), $second);

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['admin' => 'admin', 'rec' => 'recruiter', 'client' => 'client', 'w' => 'worker', 'pay' => 'payroll'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Switches ' . $k, 'ax-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
rollback($app);
[$code, $third] = run_php($app . '/install/upgrade.php');
check('Where a client account already signs in, the upgrade leaves the portal ON, so nobody loses access', $code === 0 && str_contains($third, 'Client portal: left ON, 1 active client account(s) sign in') && $switch() === '1', $third);
q("UPDATE platform_settings SET setting_value = '0' WHERE setting_key = 'client_portal_enabled'");
if ($earlier) { q('UPDATE users SET is_active = 1 WHERE id IN (' . implode(',', $earlier) . ')'); }

q("INSERT INTO clients(name) VALUES ('Switches client')");
$client = (int) db()->lastInsertId();
q('INSERT INTO client_access(user_id,client_id,granted_by) VALUES (?,?,?)', [$ids['client'], $client, $ids['admin']]);
q("INSERT INTO jobs(client_id,title,status) VALUES (?,'P5-M11 site','active')", [$client]);
$job = (int) db()->lastInsertId();
q("INSERT INTO candidates(full_name,email,stage) VALUES ('Switches worker','ax-cand-w@test.invalid','placed')");
$cand = (int) db()->lastInsertId();
q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$ids['w'], $cand]);
q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site',?,30,50)", [$cand, $job, date('Y-m-d', strtotime('-40 days'))]);
$pid = (int) db()->lastInsertId();
$frozenDay = date('Y-m-d', strtotime('-21 days'));
q("INSERT INTO timesheets(placement_id,week_ending,hours_worked,status,approved_by,approved_at) VALUES (?,?,40,'approved',?,NOW())", [$pid, week_ending($frozenDay), $ids['admin']]);

file_put_contents(__DIR__ . '/access_switches.json', json_encode(['ids' => $ids, 'client' => $client, 'placement' => $pid, 'frozen_day' => $frozenDay, 'open_day' => date('Y-m-d', strtotime('-1 day'))]));
file_put_contents(__DIR__ . '/ax-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
