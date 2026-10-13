<?php
/**
 * P5-M15 (T20) at the database level, on the isolated test database only:
 * rollback (bookings and bills kept) and upgrade, silence on a repeat;
 * then the fixture for folios_http.py: Logistics, payroll, a recruiter;
 * a project with two hotels; last week's bookings - one worker the whole
 * week at the hotel's rate, one for three nights at their own rate, one
 * cancelled, one at the other hotel - and one night marked absent.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/folios.php';

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
$tables = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('hotel_folios','hotel_folio_lines')");
$app = __DIR__ . '/test-app';

check('P5-M15 is in place before the rollback', $tables() === 2);
$lodging = (int) val('SELECT COUNT(*) FROM lodging');
$bills = (int) val('SELECT COUNT(*) FROM vendor_invoices');
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m15.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes the folios and keeps every booking and bill', $tables() === 0 && (int) val('SELECT COUNT(*) FROM lodging') === $lodging && (int) val('SELECT COUNT(*) FROM vendor_invoices') === $bills);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade announces hotel folios', $code === 0 && str_contains($first, 'Hotel folios: each hotel\'s weekly folio checked against the rooms booked') && $tables() === 2, $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M15', $code === 0 && ! str_contains($second, 'Hotel folios:'), $second);

// ── the nights of a stay ───────────────────────────────────────────────
$we = week_ending(date('Y-m-d', strtotime('-7 days')));
$day = static fn(int $back): string => date('Y-m-d', strtotime($we . ' -' . $back . ' days'));
check('A stay through the week is seven nights', count(fo_nights(['check_in' => $day(10), 'check_out' => null], $we)) === 7);
check('Check-out day is not a night', fo_nights(['check_in' => $day(6), 'check_out' => $day(3)], $we) === [$day(6), $day(5), $day(4)]);
check('A stay that starts after the week is none', fo_nights(['check_in' => date('Y-m-d', strtotime($we . ' +1 day')), 'check_out' => null], $we) === []);

// ── the fixture ────────────────────────────────────────────────────────
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['hotels' => 'hotels', 'pay' => 'payroll', 'rec' => 'recruiter', 'w' => 'worker', 'w2' => 'worker'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Folio ' . $k, 'fo-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO clients(name) VALUES ('Folio client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status) VALUES (?,'P5-M15 site','active')", [$client]);
$job = (int) db()->lastInsertId();
q("INSERT INTO hotels(name,city,state,nightly_rate) VALUES ('P5-M15 Riverside Inn','Katy','TX',100)");
$hotel = (int) db()->lastInsertId();
q("INSERT INTO hotels(name,city,state,nightly_rate) VALUES ('P5-M15 Highway Lodge','Katy','TX',80)");
$hotel2 = (int) db()->lastInsertId();
$places = [];
foreach (['a' => 'w', 'b' => 'w2', 'c' => null, 'd' => null] as $k => $account) {
    q("INSERT INTO candidates(full_name,email,stage) VALUES (?,?,'placed')", ['Folio guest ' . strtoupper($k), 'fo-guest-' . $k . '@test.invalid']);
    $cand = (int) db()->lastInsertId();
    if ($account) { q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$ids[$account], $cand]); }
    q("INSERT INTO placements(candidate_id,job_id,status,start_date) VALUES (?,?,'on_site',?)", [$cand, $job, $day(30)]);
    $places[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO lodging(placement_id,hotel_id,room_number,check_in,check_out,nightly_rate,status) VALUES (?,?,'101',?,NULL,NULL,'checked_in')", [$places['a'], $hotel, $day(10)]);
q("INSERT INTO lodging(placement_id,hotel_id,room_number,check_in,check_out,nightly_rate,status) VALUES (?,?,'102',?,?,90,'checked_out')", [$places['b'], $hotel, $day(6), $day(3)]);
q("INSERT INTO lodging(placement_id,hotel_id,room_number,check_in,check_out,nightly_rate,status) VALUES (?,?,'201',?,NULL,NULL,'checked_in')", [$places['c'], $hotel2, $day(10)]);
q("INSERT INTO lodging(placement_id,hotel_id,room_number,check_in,check_out,nightly_rate,status) VALUES (?,?,'103',?,NULL,100,'cancelled')", [$places['d'], $hotel, $day(10)]);
q('INSERT INTO assignment_checkins(placement_id,work_date,present,marked_by) VALUES (?,?,0,?)', [$places['a'], $day(4), $ids['hotels']]);

file_put_contents(__DIR__ . '/folios.json', json_encode(['ids' => $ids, 'places' => $places, 'job' => $job, 'hotel' => $hotel, 'hotel2' => $hotel2, 'week' => $we, 'any_day' => $day(2)]));
file_put_contents(__DIR__ . '/fo-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
