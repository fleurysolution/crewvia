<?php
/**
 * P5-M07 at the database level, on the isolated test database only:
 * rollback (texts already logged stay as contacts, noted "Text:") and
 * upgrade (six messages to start from), silence on a repeat, then the
 * fixture for messaging_http.py: a recruiter, logistics, payroll; a new
 * candidate with a US number on an assignment with a hotel and a flight;
 * one with a number abroad on an assignment with neither; one with no
 * number.
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
$tables = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('message_templates','contact_preferences')");
$outcomeType = static fn(): string => (string) val("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'candidate_calls' AND column_name = 'outcome'");
$app = __DIR__ . '/test-app';
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);

check('P5-M07 is in place before the rollback: texts are contacts', $tables() === 2 && str_contains($outcomeType(), "'texted'"));
q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Messaging rollback recruiter', 'ms-rollback@test.invalid', $password, 'recruiter']);
$rbUser = (int) db()->lastInsertId();
q("INSERT INTO candidates(full_name,email,stage) VALUES ('Messaging Rollback Person','ms-rollback-person@test.invalid','contacted')");
$rbCand = (int) db()->lastInsertId();
q("INSERT INTO candidate_calls (candidate_id, user_id, outcome, note) VALUES (?,?,'texted','Following up: see you Monday')", [$rbCand, $rbUser]);
$rbCall = (int) db()->lastInsertId();
$contacts = (int) val('SELECT COUNT(*) FROM candidate_calls');
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m07.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
$kept = row('SELECT outcome, note FROM candidate_calls WHERE id = ?', [$rbCall]);
check('Rollback removes the two tables and keeps every contact', $tables() === 0 && (int) val('SELECT COUNT(*) FROM candidate_calls') === $contacts);
check('and a text already logged stays, as a contact noted "Text:"', $kept && $kept['outcome'] === 'reached' && $kept['note'] === 'Text: Following up: see you Monday' && ! str_contains($outcomeType(), "'texted'"), json_encode($kept));
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade announces texting, with six messages to start from and no provider connected', $code === 0 && str_contains($first, 'Texting: messages written once') && str_contains($first, '6 message(s) to start from')
      && str_contains($first, 'No SMS or travel provider is connected'), $first);
check('The tables are back, and texts are contacts again', $tables() === 2 && str_contains($outcomeType(), "'texted'")
      && (bool) val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'candidate_calls' AND column_name = 'template_id'"));
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M07, and adds no message twice', $code === 0 && ! str_contains($second, 'Texting:') && (int) val('SELECT COUNT(*) FROM message_templates') === 6, $second);
q('DELETE FROM candidate_calls WHERE id = ?', [$rbCall]);

$ids = [];
foreach (['rec' => 'recruiter', 'hotels' => 'hotels', 'pay' => 'payroll'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Messaging ' . $k, 'ms-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO clients(name) VALUES ('Messaging client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status) VALUES (?,'P5-M07 turnaround','active')", [$client]);
$job = (int) db()->lastInsertId();
$cands = $places = [];
foreach ([['ana', 'Ana Messaging', '(555) 201-3344', 'new'], ['abroad', 'Luis Messaging', '+52 55 1234 5678', 'contacted'], ['nophone', 'Kim Messaging', null, 'contacted']] as [$k, $name, $phone, $stage]) {
    q('INSERT INTO candidates(full_name,email,phone,stage) VALUES (?,?,?,?)', [$name, 'ms-' . $k . '@test.invalid', $phone, $stage]);
    $cands[$k] = (int) db()->lastInsertId();
    q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'confirmed','2026-11-02',30,50)", [$cands[$k], $job]);
    $places[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO hotels(name,address,city,state) VALUES ('Harbor Inn','12 Dock Rd','Baytown','TX')");
q('INSERT INTO lodging(placement_id,hotel_id,room_number,check_in) VALUES (?,?,?,?)', [$places['ana'], (int) db()->lastInsertId(), '214', '2026-11-01']);
q("INSERT INTO travel(placement_id,direction,mode,carrier,reference,depart_from,arrive_at,depart_time,pickup_needed,pickup_by) VALUES (?,'inbound','flight','United','QX7Y2B','IAH','Baytown','2026-11-01 09:30:00',1,'Van 3')",
  [$places['ana']]);
file_put_contents(__DIR__ . '/messaging.json', json_encode(['ids' => $ids, 'cands' => $cands, 'places' => $places]));
file_put_contents(__DIR__ . '/ms-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
