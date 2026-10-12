<?php
/**
 * P5-M08 at the database level, on the isolated test database only:
 * rollback (the contact register and Twilio's audit lines stay) and
 * upgrade, silence on a repeat, Twilio in sandbox with nothing entered,
 * then the fixture for twilio_http.py: an administrator, a recruiter with
 * no phone of their own yet, Logistics, a candidate with a US number on an
 * assignment, one with no number.
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
$tables = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('provider_credentials','sms_messages','voice_calls')");
$col = static fn(string $t, string $c): string => (string) val('SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
$app = __DIR__ . '/test-app';

check('P5-M08 is in place before the rollback', $tables() === 3 && str_contains($col('connector_modes', 'kind'), "'messaging'") && str_contains($col('integration_events', 'provider'), "'twilio'"));
q("INSERT INTO integration_events (provider, action, outcome, reference) VALUES ('twilio', 'send', 'ok', 'rollback-probe')");
$contacts = (int) val('SELECT COUNT(*) FROM candidate_calls');
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m08.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes the three tables, keeps the contact register and Twilio\'s audit lines', $tables() === 0 && (int) val('SELECT COUNT(*) FROM candidate_calls') === $contacts
      && (string) val("SELECT provider FROM integration_events WHERE reference = 'rollback-probe'") === 'twilio' && ! str_contains($col('connector_modes', 'kind'), "'messaging'"));
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade announces Twilio prepared, in sandbox', $code === 0 && str_contains($first, 'Twilio: texts and calls from Crewvia prepared, in sandbox'), $first);
check('The tables are back, with a connector kind for messaging', $tables() === 3 && str_contains($col('connector_modes', 'kind'), "'messaging'"));
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M08', $code === 0 && ! str_contains($second, 'Twilio:'), $second);
check('Nothing is entered: no credentials, and never-recorded means sandbox', ! val("SELECT COUNT(*) FROM provider_credentials WHERE provider = 'twilio'")
      && (string) (val("SELECT mode FROM connector_modes WHERE connector = 'twilio'") ?? 'sandbox') === 'sandbox');
q("DELETE FROM integration_events WHERE reference = 'rollback-probe'");

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['admin' => 'admin', 'rec' => 'recruiter', 'hotels' => 'hotels'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Twilio ' . $k, 'tw-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO clients(name) VALUES ('Twilio client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status) VALUES (?,'P5-M08 shutdown','active')", [$client]);
$job = (int) db()->lastInsertId();
$cands = $places = [];
foreach ([['ana', 'Ana Twilio', '(555) 301-4455'], ['nophone', 'Kim Twilio', null]] as [$k, $name, $phone]) {
    q("INSERT INTO candidates(full_name,email,phone,stage) VALUES (?,?,?,'contacted')", [$name, 'tw-' . $k . '@test.invalid', $phone]);
    $cands[$k] = (int) db()->lastInsertId();
    q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'confirmed','2026-11-02',30,50)", [$cands[$k], $job]);
    $places[$k] = (int) db()->lastInsertId();
}
file_put_contents(__DIR__ . '/twilio.json', json_encode(['ids' => $ids, 'cands' => $cands, 'places' => $places]));
file_put_contents(__DIR__ . '/tw-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
