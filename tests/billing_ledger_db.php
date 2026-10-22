<?php
/**
 * P5-M100 on the test database: who is billed, the append-only chained ledger, and the sealed month.
 *  - a day validated counts; two sites count once; assigned but never worked costs nothing; a pay cycle counts;
 *  - writing twice writes nothing twice;
 *  - UPDATE and DELETE are refused by MariaDB itself, as root;
 *  - with a trigger dropped and a line changed, the chain check finds the line;
 *  - a sealed month is frozen, never sealed twice, and a day validated late stays outside it;
 *  - the price: $10 a person, $2,500 minimum.
 * Months in 2024 so no other fixture lands in them.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/billing-ledger.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
$check = static function (string $label, bool $ok, $detail = ''): void {
    if (! $ok) { fwrite(STDERR, "FAIL $label " . json_encode($detail) . "\n"); exit(1); }
    echo "PASS $label\n";
};
$refused = static function (string $sql, array $args = []): string {
    try {
        q($sql, $args);
    } catch (Throwable $e) {
        return $e->getMessage();
    }

    return '';
};

$admin = (int) val('SELECT MIN(id) FROM users');
q("INSERT INTO clients (name) VALUES ('Billing test client')");
$client = (int) db()->lastInsertId();
$site = static function (string $title) use ($client): int {
    q("INSERT INTO jobs (client_id, title, status, site_state) VALUES (?, ?, 'active', 'IN')", [$client, $title]);
    return (int) db()->lastInsertId();
};
$plantA = $site('Billing plant A');
$plantB = $site('Billing plant B');
$person = static function (string $name): int {
    q("INSERT INTO candidates (full_name, email, phone, stage) VALUES (?, ?, '555-010-1000', 'placed')", [$name, strtolower(str_replace(' ', '.', $name)) . '@test.invalid']);
    return (int) db()->lastInsertId();
};
$place = static function (int $cid, int $job): int {
    q("INSERT INTO placements (candidate_id, job_id, status, start_date, pay_rate, bill_rate) VALUES (?, ?, 'on_site', '2024-01-08', 30, 45)", [$cid, $job]);
    return (int) db()->lastInsertId();
};
$day = static function (int $placement, string $date, string $status = 'validated'): int {
    q("INSERT INTO clock_ins (placement_id, work_date, clocked_in_at, validate_by, status) VALUES (?, ?, ?, ?, ?)", [$placement, $date, $date . ' 07:00:00', $date . ' 12:00:00', $status]);
    return (int) db()->lastInsertId();
};

bl_sync();
$before = bl_month('2024-02');

// One validated day.
$one = $person('Billing One Day');
$day($place($one, $plantA), '2024-02-05');
// Two sites, several days, one person.
$two = $person('Billing Two Sites');
$day($place($two, $plantA), '2024-02-06');
$day($place($two, $plantB), '2024-02-07');
$day((int) val('SELECT id FROM placements WHERE candidate_id = ? AND job_id = ?', [$two, $plantB]), '2024-02-08');
// Assigned, clocked in, never validated; another assigned and never came.
$never = $person('Billing Never Worked');
$p = $place($never, $plantA);
$day($p, '2024-02-09', 'waiting');
$day($p, '2024-02-12', 'not_present');
$place($person('Billing Never Came'), $plantB);
// A week in the pay cycle only, no clock-in.
$weekly = $person('Billing Pay Cycle');
q("INSERT INTO timesheets (placement_id, week_ending, hours_worked, status) VALUES (?, '2024-02-18', 40, 'approved')", [$place($weekly, $plantB)]);
// A draft timesheet pays nothing.
q("INSERT INTO timesheets (placement_id, week_ending, hours_worked, status) VALUES (?, '2024-02-25', 40, 'draft')", [$place($never, $plantB)]);

$written = bl_sync();
$m = bl_month('2024-02');
$check('Five records written: four days validated, one week approved, nothing for the rest', $written === 5 && $m['events'] - $before['events'] === 5, [$written, $m, $before]);
$check('Three people counted: one day, two sites once, the pay cycle; never-worked people count nothing', $m['people'] - $before['people'] === 3, $m);
$counted = array_map('intval', array_column(rows('SELECT DISTINCT candidate_id FROM billing_events WHERE period_month = ?', ['2024-02']), 'candidate_id'));
$check('The person who never worked is not in the ledger', ! in_array($never, $counted, true) && in_array($one, $counted, true) && in_array($two, $counted, true) && in_array($weekly, $counted, true));
$check('Writing again writes nothing twice', bl_sync() === 0 && bl_month('2024-02') === $m);

// The database refuses, as root.
$line = (int) val('SELECT id FROM billing_events WHERE candidate_id = ? LIMIT 1', [$one]);
$who = (string) val('SELECT CURRENT_USER()');
$err = $refused('DELETE FROM billing_events WHERE id = ?', [$line]);
echo "  as $who: DELETE FROM billing_events WHERE id = $line\n  -> $err\n";
$check('DELETE on the ledger is refused by MariaDB (SQLSTATE 45000)', str_contains($err, '45000') && str_contains($err, 'cannot be deleted') && (int) val('SELECT COUNT(*) FROM billing_events WHERE id = ?', [$line]) === 1, $err);
$err = $refused('UPDATE billing_events SET candidate_id = candidate_id + 1 WHERE id = ?', [$line]);
echo "  as $who: UPDATE billing_events SET candidate_id = ... WHERE id = $line\n  -> $err\n";
$check('UPDATE on the ledger is refused by MariaDB', str_contains($err, '45000') && str_contains($err, 'cannot be changed'), $err);

// Someone with the rights to drop the trigger: the chain shows the line.
$check('The chain is intact', bl_verify()['ok']);
$chainNo = (int) val('SELECT chain_no FROM billing_events WHERE id = ?', [$line]);
db()->exec('DROP TRIGGER billing_events_no_update');
q('UPDATE billing_events SET candidate_id = candidate_id + 1 WHERE id = ?', [$line]);
$v = bl_verify();
$check('A line changed behind the guard is found by the chain check, at that line', ! $v['ok'] && $v['broken_at'] === $chainNo, [$v, $chainNo]);
q('UPDATE billing_events SET candidate_id = candidate_id - 1 WHERE id = ?', [$line]);
db()->exec("CREATE TRIGGER billing_events_no_update BEFORE UPDATE ON billing_events FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Billing events cannot be changed'; END");
$check('Put back, the chain is intact again', bl_verify()['ok']);

// The seal.
[$closing, $why] = bl_close(date('Y-m'), $admin);
$check('The current month cannot be sealed', $closing === null && $why !== null);
[$closing, $why] = bl_close('2024-02', $admin);
$check('February 2024 is sealed with its people, records and a fingerprint', $closing && (int) $closing['billable_people'] === $m['people'] && (int) $closing['event_count'] === $m['events'] && strlen((string) $closing['ledger_hash']) === 64, [$closing, $why]);
[$again, $why] = bl_close('2024-02', $admin);
$check('Sealing it twice is refused', $again === null && $why !== null);
$err = $refused('UPDATE billing_closings SET billable_people = billable_people - 1 WHERE period_month = ?', ['2024-02']);
$check('UPDATE on a sealed close is refused by MariaDB', str_contains($err, 'cannot be changed'), $err);
$err = $refused('DELETE FROM billing_closings WHERE period_month = ?', ['2024-02']);
$check('DELETE on a sealed close is refused by MariaDB', str_contains($err, 'cannot be deleted'), $err);

// A day validated late for the sealed month: in the ledger, outside the close.
$late = $person('Billing Late Day');
$day($place($late, $plantA), '2024-02-27');
bl_sync();
$frozen = bl_closing('2024-02');
$check('A day validated after the seal is kept in the ledger and not added to the sealed count',
    bl_after_seal('2024-02') === 1 && (int) $frozen['billable_people'] === $m['people'] && $frozen['ledger_hash'] === $closing['ledger_hash'] && bl_month('2024-02')['people'] === $m['people'] + 1);
$names = array_column(bl_statement('2024-02'), 'full_name');
$check('The statement of the sealed month lists the sealed people only', in_array('Billing Two Sites', $names, true) && ! in_array('Billing Late Day', $names, true) && ! in_array('Billing Never Worked', $names, true), $names);
$two = array_values(array_filter(bl_statement('2024-02'), static fn($r) => $r['full_name'] === 'Billing Two Sites'))[0];
$check('Two sites show as one line, three days, both projects', (int) $two['days'] === 3 && str_contains($two['projects'], 'Billing plant A') && str_contains($two['projects'], 'Billing plant B'), $two);

// The price.
$check('$10 a person, $2,500 a month minimum', bl_amount(3) === 2500.0 && bl_amount(250) === 2500.0 && bl_amount(300) === 3000.0 && bl_amount(412) === 4120.0);
// Lines cut from the end, or the whole ledger emptied (TRUNCATE skips triggers): the sealed months show it.
db()->exec('DROP TRIGGER billing_events_no_delete');
db()->beginTransaction();
q('DELETE FROM billing_events WHERE chain_no >= ?', [(int) $closing['last_chain_no']]);
$v = bl_verify();
db()->rollBack();
db()->exec("CREATE TRIGGER billing_events_no_delete BEFORE DELETE ON billing_events FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Billing events cannot be deleted'; END");
$check('Lines cut from the end of the ledger are found: the sealed month no longer matches', ! $v['ok'] && $v['broken_at'] === (int) $closing['last_chain_no'], $v);
db()->exec('DROP TRIGGER billing_events_no_delete');
db()->beginTransaction();
q('DELETE FROM billing_events');
$v = bl_verify();
db()->rollBack();
db()->exec("CREATE TRIGGER billing_events_no_delete BEFORE DELETE ON billing_events FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Billing events cannot be deleted'; END");
$check('An emptied ledger is found too', ! $v['ok'], $v);
$check('The job seals nothing twice and the chain stays intact', ! in_array('2024-02', bl_close_due(), true) && bl_verify()['ok']);

// Approved, next step (seen by billing_http.py): three people approved two months ago on one requisition.
q("INSERT INTO vacancies (job_id, title, description) VALUES (?, 'Follow-up welders', 'Test requisition')", [$plantA]);
$vacancy = (int) db()->lastInsertId();
$approved = static function (string $name) use ($vacancy, $admin, $person): int {
    $cid = $person($name);
    q("INSERT INTO applications (candidate_id, vacancy_id, stage) VALUES (?, ?, 'accepted')", [$cid, $vacancy]);
    q("INSERT INTO application_reviews (application_id, requested_by, reviewer_id, rating, recommendation, status, decided_at) VALUES (?, ?, ?, 4, 'Good', 'validated', NOW() - INTERVAL 50 DAY)",
      [(int) db()->lastInsertId(), $admin, $admin]);
    return $cid;
};
$approved('Follow Quiet');
q("INSERT INTO candidate_calls (candidate_id, user_id, called_at) VALUES (?, ?, NOW() - INTERVAL 10 DAY)", [$approved('Follow Called'), $admin]);
$place($approved('Follow Deployed'), $plantA);
$check('Follow-up fixture: one quiet, one called since, one deployed', (int) val('SELECT COUNT(*) FROM applications WHERE vacancy_id = ?', [$vacancy]) === 3);
if (! val("SELECT id FROM users WHERE email = 'wk-hr@test.invalid'")) {
    q("INSERT INTO users (name, email, password_hash, role, must_change_pw) SELECT 'HR Tester', 'wk-hr@test.invalid', password_hash, 'hr', 0 FROM users WHERE email = 'wk-rec@test.invalid'");
}
// P5-M102 (seen by careers_feed_http.py): a job that pays no travel and no hotel.
q("UPDATE jobs SET travel_provided = 0, lodging_provided = 0, transport_provided = 0 WHERE id = ?", [$plantB]);
q("INSERT INTO vacancies (job_id, title, description) VALUES (?, 'Local packer', 'Test requisition, no travel')", [$plantB]);
// P5-M102b (seen by careers_feed_http.py): a closed position whose old link is still around.
q("INSERT INTO vacancies (job_id, title, description, is_open) VALUES (?, 'Closed welder role', 'Closed test requisition', 0)", [$plantA]);
