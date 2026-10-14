<?php
/**
 * P5-M32 at the database level, on the isolated test database only:
 * rollback (room numbers and roll call kept; clock-ins gone) and upgrade,
 * silence on a repeat; then the fixture for clock_in_http.py, on the
 * walkthrough fixture: a hotel coordinator, a hotel, a second supervisor,
 * and three workers under the walkthrough supervisor, on site from today:
 * one to book and clock in, one clocked in long ago and never validated,
 * one whose room number is overdue since yesterday.
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
$parts = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'clock_ins'")
                           + (int) val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'lodging' AND column_name = 'room_told_at'");
$app = __DIR__ . '/test-app';
$wk = json_decode((string) file_get_contents(__DIR__ . '/walkthrough.json'), true);

check('P5-M32 is in place before the rollback', $parts() === 2);
$rooms = (int) val("SELECT COUNT(*) FROM lodging WHERE room_number IS NOT NULL");
$checkins = (int) val('SELECT COUNT(*) FROM assignment_checkins');
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m32.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes the clock-ins and when workers were told their hotel, and keeps room numbers and roll call',
      $parts() === 0 && (int) val("SELECT COUNT(*) FROM lodging WHERE room_number IS NOT NULL") === $rooms && (int) val('SELECT COUNT(*) FROM assignment_checkins') === $checkins);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade announces the clock-in and its rules', $code === 0 && str_contains($first, 'Clock-in: workers enter their room number within 4 hours of check-in') && $parts() === 2, $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M32', $code === 0 && ! str_contains($second, 'Clock-in:'), $second);

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$user = static function (string $name, string $email, string $role) use ($password): int {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', [$name, $email, $password, $role]);
    return (int) db()->lastInsertId();
};
$hotels = $user('Clock coordinator', 'ci-hotels@test.invalid', 'hotels');
$sup2 = $user('Clock other supervisor', 'ci-sup2@test.invalid', 'supervisor');
q("INSERT INTO hotels(name, city, nightly_rate) VALUES ('Walk Inn', 'Houston', 89)");
$hotel = (int) db()->lastInsertId();
// The shift starts in an hour: 30 minutes after it is the deadline to validate.
$shiftStart = date('H:i:00', time() + 3600);
$people = [];
foreach (['clock' => true, 'late' => false, 'room' => false] as $k => $account) {
    q("INSERT INTO candidates(full_name,email,stage) VALUES (?,?,'placed')", ['Aaa Clock ' . $k, 'ci-' . $k . '@test.invalid']);
    $c = (int) db()->lastInsertId();
    $u = null;
    if ($account) {
        $u = $user('Clock worker', 'ci-worker@test.invalid', 'worker');
        q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$u, $c]);
    }
    q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate) VALUES (?,?,'on_site',CURDATE(),45)", [$c, $wk['job']]);
    $p = (int) db()->lastInsertId();
    q("INSERT INTO assignment_details(placement_id,trade,supervisor_id,shift_label,shift_start) VALUES (?,'Walk welder',?,'Day',?)", [$p, $wk['ids']['sup'], $shiftStart]);
    $people[$k] = ['candidate' => $c, 'placement' => $p, 'user' => $u];
}
// Clocked in long ago, never validated.
q("INSERT INTO clock_ins(placement_id,work_date,clocked_in_at,shift_start,validate_by) VALUES (?,CURDATE(),DATE_SUB(NOW(), INTERVAL 3 HOUR),DATE_SUB(NOW(), INTERVAL 3 HOUR),DATE_SUB(NOW(), INTERVAL 150 MINUTE))", [$people['late']['placement']]);
$lateClockIn = (int) db()->lastInsertId();
// At the hotel since yesterday, told yesterday morning, no room number: overdue since yesterday evening.
q("INSERT INTO lodging(placement_id,hotel_id,check_in,check_out,status,room_told_at) VALUES (?,?,DATE_SUB(CURDATE(), INTERVAL 1 DAY),DATE_ADD(CURDATE(), INTERVAL 20 DAY),'booked',CONCAT(DATE_SUB(CURDATE(), INTERVAL 1 DAY), ' 08:00:00'))", [$people['room']['placement'], $hotel]);
file_put_contents(__DIR__ . '/clock_in.json', json_encode(compact('hotels', 'sup2', 'hotel', 'shiftStart', 'people', 'lateClockIn')));
file_put_contents(__DIR__ . '/ci-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
