<?php
/**
 * P5-M09 at the database level, on the isolated test database only:
 * rollback (qualifications and their two types stay) and upgrade, silence
 * on a repeat, then the fixture for driver_http.py: a recruiter, an
 * administrator, Logistics; a driver who already holds a medical
 * certificate verified until 2035; a second person.
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
$table = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'driver_checks'");
$types = static fn(): int => (int) val("SELECT COUNT(*) FROM qualification_types WHERE name IN ('Commercial driver''s licence (CDL)', 'DOT medical certificate')");
$app = __DIR__ . '/test-app';

check('P5-M09 is in place before the rollback, with the two qualification types', $table() === 1 && $types() === 2);
$quals = (int) val('SELECT COUNT(*) FROM candidate_qualifications');
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m09.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes the checks and keeps every qualification and the two types', $table() === 0 && $types() === 2 && (int) val('SELECT COUNT(*) FROM candidate_qualifications') === $quals);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade announces driving records, with no provider connected', $code === 0 && str_contains($first, 'Driving records: MVR (MVRNOW, with the CDL), the FMCSA Clearinghouse and PSP') && str_contains($first, 'No provider is connected'), $first);
check('The table is back', $table() === 1 && $types() === 2);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M09, and adds no type twice', $code === 0 && ! str_contains($second, 'Driving records:') && $types() === 2, $second);

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['rec' => 'recruiter', 'admin' => 'admin', 'hotels' => 'hotels'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Driver ' . $k, 'dr-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
$cands = [];
foreach (['dana' => 'Dana Driver', 'eli' => 'Eli Driver'] as $k => $name) {
    q("INSERT INTO candidates(full_name,email,stage) VALUES (?,?,'contacted')", [$name, 'dr-' . $k . '@test.invalid']);
    $cands[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO candidate_qualifications(candidate_id,type_id,detail,issued_on,expires_on,evidence_reference,status,reviewed_at)
   VALUES (?, (SELECT id FROM qualification_types WHERE name = 'DOT medical certificate'), 'Card', '2025-01-01', '2035-01-01', 'MED-1', 'verified', NOW())", [$cands['dana']]);
file_put_contents(__DIR__ . '/driver.json', json_encode(['ids' => $ids, 'cands' => $cands]));
file_put_contents(__DIR__ . '/dr-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
