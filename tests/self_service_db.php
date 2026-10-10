<?php
/**
 * HR self-service at the database level, on the isolated test database
 * only: rollback, upgrade, silence on a repeat, then the people
 * self_service_http.py is tested on.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/gmail.php';

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
$app = __DIR__ . '/test-app';
$tables = ['worker_bank_change_requests', 'profile_change_requests', 'details_confirmations'];

check('Self-service is in place before the rollback', ! in_array(false, array_map($table, $tables), true));
$bank = (int) val('SELECT COUNT(*) FROM worker_bank_details');
sql_file($app . '/install/rollback/self-service.sql');
check('Rollback removes every self-service table', ! in_array(true, array_map($table, $tables), true));
check('Rollback keeps the bank details in use', (int) val('SELECT COUNT(*) FROM worker_bank_details') === $bank);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback succeeds and announces self-service', $code === 0 && str_contains($first, 'Self-service: workers can propose'), $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for self-service', $code === 0 && ! str_contains($second, 'Self-service:'), $second);

$fixture  = json_decode((string) file_get_contents(__DIR__ . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
foreach (['payroll', 'recruiter', 'hotels'] as $role) {
    q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)", ['Self-service ' . $role, 'ss-' . $role . '@test.invalid', $password, $role]);
}
$worker = static function (string $label) use ($password, $fixture): int {
    $email = 'ss-' . $label . '@test.invalid';
    q("INSERT INTO candidates(full_name,email,phone,city,state) VALUES (?,?,'555 0100','Gary','IN')", ['Self-service ' . $label, $email]);
    $cid = (int) db()->lastInsertId();
    q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,'worker',0)", ['Self-service ' . $label, $email, $password]);
    q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [(int) db()->lastInsertId(), $cid]);
    q("INSERT INTO placements(candidate_id,job_id,status,start_date) VALUES (?,?,'on_site',?)", [$cid, (int) $fixture['a'], date('Y-m-d', strtotime('-30 days'))]);
    return $cid;
};
$me = $worker('worker');
$other = $worker('other');
// Bank details already in use, so a proposal can be seen not to replace them.
q("INSERT INTO worker_bank_details (candidate_id, encrypted_details, last_four, bank_label, status) VALUES (?,?,?,?,'verified')",
  [$me, token_encrypt(['account_number' => '99991111', 'routing_number' => '021000021', 'bank_name' => 'Old Bank', 'account_holder' => 'Self-service worker']), '1111', 'Old Bank']);

file_put_contents(__DIR__ . '/ss.json', json_encode(['me' => $me, 'other' => $other]));
file_put_contents(__DIR__ . '/ss-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
