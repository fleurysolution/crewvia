<?php
/**
 * P5-M70 at the database level, on the test database only: one approved week billed by a project's terms.
 * 50 hours worked (10 over the 40 of the week), on the night shift with a $3 premium, 5 days of $75 per
 * diem, 7 hotel nights at $100, a $350 flight leaving that week, transport $12 a day.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/billing-terms.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
$check = static function (string $label, bool $ok, $detail = ''): void {
    if (! $ok) { fwrite(STDERR, "FAIL $label " . json_encode($detail) . "\n"); exit(1); }
    echo "PASS $label\n";
};

q("INSERT INTO clients (name) VALUES ('Billing terms client')");
q("INSERT INTO jobs (client_id, title, status) VALUES (?, 'Billing terms plant', 'active')", [(int) db()->lastInsertId()]);
$job = (int) db()->lastInsertId();
q("INSERT INTO project_pay_policies (job_id, weekly_overtime_after, overtime_multiplier, guarantee_absence_rule, guarantee_day_hours, guarantee_overtime, reviewed_by) VALUES (?, 40, 1.5, 'keep', 10, 0, (SELECT MIN(id) FROM users))", [$job]);
q("INSERT INTO project_shifts (job_id, name, starts_at, ends_at, sort_order) VALUES (?, 'Night', '18:00:00', '06:00:00', 2)", [$job]);
q("INSERT INTO candidates (full_name, email, stage) VALUES ('Billing Tester', 'billing-tester@test.invalid', 'new')");
$cid = (int) db()->lastInsertId();
q("INSERT INTO placements (candidate_id, job_id, status, start_date, pay_rate, bill_rate, per_diem_rate) VALUES (?, ?, 'on_site', '2026-09-01', 30, 50, 75)", [$cid, $job]);
$pid = (int) db()->lastInsertId();
q("INSERT INTO assignment_details (placement_id, trade, shift_label) VALUES (?, 'Pipefitter', 'Night')", [$pid]);
$week = '2026-09-13';
q("INSERT INTO timesheets (placement_id, week_ending, hours_worked, per_diem_days, status) VALUES (?, ?, 50, 5, 'approved')", [$pid, $week]);
$ts = (int) db()->lastInsertId();
q("INSERT INTO hotels (name, city, nightly_rate) VALUES ('Billing Inn', 'Gary', 100)");
q("INSERT INTO lodging (placement_id, hotel_id, check_in, check_out, status) VALUES (?, ?, '2026-09-06', '2026-09-20', 'checked_in')", [$pid, (int) db()->lastInsertId()]);
q("INSERT INTO travel (placement_id, direction, mode, depart_time, cost, status) VALUES (?, 'inbound', 'flight', '2026-09-07 08:00:00', 350, 'booked')", [$pid]);

$jobRow = row('SELECT j.*, pp.weekly_overtime_after, pp.overtime_multiplier, pp.guarantee_absence_rule, pp.guarantee_day_hours, pp.guarantee_overtime FROM jobs j LEFT JOIN project_pay_policies pp ON pp.job_id = j.id WHERE j.id = ?', [$job]);
$sheet = row('SELECT t.*, p.pay_rate, p.bill_rate, p.per_diem_rate FROM timesheets t JOIN placements p ON p.id = t.placement_id WHERE t.id = ?', [$ts]);
$m = week_money($sheet, $sheet, $jobRow);
$parts = bt_week_parts($sheet, $m, $jobRow, bt_terms($job));
$check('Without terms, the week bills as before: every hour at the bill rate, nothing else', bt_total($parts) == 2500.0 && count($parts) === 1, $parts);

$why = bt_save($job, ['ot_mode' => 'multiplier', 'ot_multiplier' => '1.5', 'per_diem_mode' => 'cost_plus', 'per_diem_markup_pct' => '10', 'lodging_mode' => 'cost',
                      'travel_mode' => 'cost_plus', 'travel_markup_pct' => '5', 'transport_mode' => 'per_day', 'transport_rate' => '12',
                      'shift_premium' => [(int) val("SELECT id FROM project_shifts WHERE job_id = ? AND name = 'Night'", [$job]) => '3']], 1);
$check('The terms are saved', $why === null, $why);
$bad = bt_save($job, ['ot_mode' => 'rate', 'ot_rate' => ''], 1);
$check('Overtime at its own rate needs the rate', $bad !== null && str_contains($bad, 'overtime rate'));
$parts = bt_week_parts($sheet, $m, $jobRow, bt_terms($job));
$by = array_column($parts, null, 'kind');
$check('40 regular hours at $50', isset($by['regular']) && $by['regular']['qty'] == 40 && $by['regular']['amount'] == 2000.0, $by['regular'] ?? null);
$check('10 overtime hours at 1.5 times $50', isset($by['overtime']) && $by['overtime']['qty'] == 10 && $by['overtime']['amount'] == 750.0, $by['overtime'] ?? null);
$check('The night shift premium: $3 on each of the 50 hours', isset($by['shift']) && $by['shift']['amount'] == 150.0, $by['shift'] ?? null);
$check('Per diem at cost plus 10%: 5 x $75 = $375, billed $412.50', isset($by['per_diem']) && $by['per_diem']['amount'] == 412.5, $by['per_diem'] ?? null);
$check('Hotel at cost: the 7 nights of the week at $100', isset($by['lodging']) && $by['lodging']['qty'] == 7 && $by['lodging']['amount'] == 700.0, $by['lodging'] ?? null);
$check('The flight leaving that week at cost plus 5%: $367.50', isset($by['travel']) && $by['travel']['amount'] == 367.5, $by['travel'] ?? null);
$check('Transport: 5 days on site at $12', isset($by['transport']) && $by['transport']['amount'] == 60.0, $by['transport'] ?? null);
$check('The week: $4,440', bt_total($parts) == 4440.0, bt_total($parts));
bt_save($job, ['ot_mode' => 'straight', 'lodging_mode' => 'flat_night', 'lodging_night_rate' => '120'], 1);
$by = array_column(bt_week_parts($sheet, $m, $jobRow, bt_terms($job)), null, 'kind');
$check('Overtime at the bill rate reads as one line of 50 hours, hotel at a flat $120 a night, the shift premium kept', ! isset($by['overtime']) && $by['regular']['qty'] == 50 && $by['regular']['amount'] == 2500.0 && $by['lodging']['amount'] == 840.0 && $by['shift']['amount'] == 150.0 && ! isset($by['per_diem']), $by);

// P5-M72: no markup on "at cost", no flat rate unless chosen, and "cost plus" needs its percentage.
bt_save($job, ['ot_mode' => 'straight', 'lodging_mode' => 'cost', 'lodging_markup_pct' => '20', 'lodging_night_rate' => '150', 'per_diem_mode' => 'none', 'per_diem_markup_pct' => '15'], 1);
$t = bt_terms($job);
$check('At cost keeps no markup and no flat rate, whatever was typed', (float) $t['lodging_markup_pct'] == 0.0 && $t['lodging_night_rate'] === null && (float) $t['per_diem_markup_pct'] == 0.0, $t);
$why = bt_save($job, ['lodging_mode' => 'cost_plus', 'lodging_markup_pct' => ''], 1);
$check('At cost plus a percentage needs the percentage', $why !== null && str_contains($why, 'percentage'), $why);

// P5-M76: the project's hotels - each with its block and its price billed per night.
require_once __DIR__ . '/test-app/app/project-hotels.php';
$inn = (int) val("SELECT id FROM hotels WHERE name = 'Billing Inn'");
$check('A block smaller than the crew already there is refused', ph_link($job, $inn, ['rooms_held' => '0']) !== null);
$check('The hotel is linked to the project with its block, its cost and its price per night', ph_link($job, $inn, ['rooms_held' => '20', 'block_starts' => '2026-09-01', 'block_ends' => '2026-12-31', 'nightly_rate' => '100', 'client_night_rate' => '140']) === null);
$ph = ph_hotels($job);
$check('The project lists the hotel and how many of its crew are in it', count($ph) === 1 && (int) $ph[0]['taken'] === 1 && (int) $ph[0]['rooms_held'] === 20, $ph);
bt_save($job, ['ot_mode' => 'straight', 'lodging_mode' => 'hotel_rate'], 1);
$by = array_column(bt_week_parts($sheet, $m, $jobRow, bt_terms($job)), null, 'kind');
$check('Billed at the rate agreed for each hotel: 7 nights at $140', isset($by['lodging']) && $by['lodging']['qty'] == 7 && $by['lodging']['amount'] == 980.0, $by['lodging'] ?? null);
$check('A hotel the crew is booked in cannot be taken off the project', ph_unlink($job, $inn) !== null && count(ph_hotels($job)) === 1);
