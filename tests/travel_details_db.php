<?php
/**
 * P5-M30 at the database level, on the isolated test database only:
 * rollback (bookings kept; the travel details, advice, tickets and worker
 * mail gone) and upgrade, silence on a repeat; then the fixture for
 * travel_details_http.py, on the walkthrough fixture: a travel coordinator,
 * a worker with a portal and a confirmed assignment starting in a week, and
 * mail captured to a file (debug only).
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
$tables = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('travel_profiles','travel_advisories','mail_outbox')");
$ticket = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'travel' AND column_name = 'ticket_details'");
$app = __DIR__ . '/test-app';
$wk = json_decode((string) file_get_contents(__DIR__ . '/walkthrough.json'), true);

check('P5-M30 is in place before the rollback', $tables() === 3 && $ticket() === 1);
$legs = (int) val('SELECT COUNT(*) FROM travel');
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m30.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes the travel details, advice, worker mail and tickets, and keeps every booking', $tables() === 0 && $ticket() === 0 && (int) val('SELECT COUNT(*) FROM travel') === $legs);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade announces the travel details and the cron line for worker mail', $code === 0 && str_contains($first, 'Travel details: workers enter their name as on their ID') && str_contains($first, 'deliver-mail.php') && $tables() === 3 && $ticket() === 1, $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M30', $code === 0 && ! str_contains($second, 'Travel details:'), $second);
check('The new secret columns are named encrypted_*, so a key rotation covers them',
      (int) val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = 'travel_profiles' AND column_name = 'encrypted_address') OR (table_name = 'mail_outbox' AND column_name = 'encrypted_message'))") === 2);

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Trip coordinator', 'trip-hotels@test.invalid', $password, 'hotels']);
$hotels = (int) db()->lastInsertId();
q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Trip worker', 'trip-worker@test.invalid', $password, 'worker']);
$workerUser = (int) db()->lastInsertId();
q("INSERT INTO candidates(full_name,email,phone,city,state,stage) VALUES ('Aaa Trip Worker','trip-worker@test.invalid','713-555-0199','Austin','TX','placed')");
$worker = (int) db()->lastInsertId();
q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$workerUser, $worker]);
q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate) VALUES (?,?,'confirmed',DATE_ADD(CURDATE(), INTERVAL 7 DAY),45)", [$worker, $wk['job']]);
$placement = (int) db()->lastInsertId();
$capture = __DIR__ . '/travel-mail.jsonl';
@unlink($capture);
q("INSERT INTO platform_settings(setting_key,setting_value) VALUES ('mail_capture_path',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)", [$capture]);
q('DELETE FROM travel_advisories');
file_put_contents(__DIR__ . '/travel_details.json', json_encode(compact('hotels', 'workerUser', 'worker', 'placement', 'capture')));
file_put_contents(__DIR__ . '/trip-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
