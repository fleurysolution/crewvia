<?php
/**
 * P5-M06c at the database level, on the isolated test database only:
 * rollback (Crewvia's own notifications stay) and upgrade, silence on a
 * repeat, push off afterwards, then the fixture for push_http.py: an
 * administrator, a recruiter, two workers (one French-speaking).
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
$tables = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('push_keys','push_subscriptions','push_deliveries')");
$app = __DIR__ . '/test-app';

check('P5-M06c is in place before the rollback', $tables() === 3);
$notifications = (int) val('SELECT COUNT(*) FROM notifications');
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m06c.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes the three tables and keeps every notification', $tables() === 0 && (int) val('SELECT COUNT(*) FROM notifications') === $notifications);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade announces push notifications, built and off', $code === 0 && str_contains($first, 'Push notifications: built and OFF'), $first);
check('The tables are back, and push is off', $tables() === 3 && (string) val("SELECT setting_value FROM platform_settings WHERE setting_key = 'push_enabled'") !== '1');
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M06c', $code === 0 && ! str_contains($second, 'Push notifications:'), $second);

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['admin' => 'admin', 'rec' => 'recruiter', 'w' => 'worker', 'w2' => 'worker'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Push ' . $k, 'pu-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("UPDATE users SET locale = 'fr' WHERE id = ?", [$ids['w2']]);
foreach (['w', 'w2'] as $k) {
    q("INSERT INTO candidates(full_name,email,stage) VALUES (?,?,'placed')", ['Push ' . $k, 'pu-cand-' . $k . '@test.invalid']);
    q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$ids[$k], (int) db()->lastInsertId()]);
}
file_put_contents(__DIR__ . '/push.json', json_encode(['ids' => $ids]));
file_put_contents(__DIR__ . '/pu-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
