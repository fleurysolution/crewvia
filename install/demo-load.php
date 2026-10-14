<?php
/**
 * Loads the demonstration workspace (P5-M35). See app/demo.php.
 *
 *   DEMO_PASSWORD='...' php install/demo-load.php --confirm-demo [--mailbox=name@example.com] [--employees=1000]
 *
 * Every account gets the same password, read from the DEMO_PASSWORD
 * environment variable (at least 12 characters). It is never printed,
 * written to a file or kept anywhere but as each account's password hash.
 * Every address is a plus-address of one mailbox: employee12 becomes
 * name+employee12@example.com. Nothing is emailed or texted by the load.
 *
 * Remove it all with install/demo-flush.php.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/demo.php';
require_once __DIR__ . '/../app/shifts.php';

$opts = getopt('', ['confirm-demo', 'mailbox:', 'employees:']);
if (! isset($opts['confirm-demo'])) {
    fwrite(STDERR, "Refusing: add --confirm-demo. This creates about 1,100 accounts and 1,000 people on three demonstration plants.\n");
    exit(2);
}
$password = (string) getenv('DEMO_PASSWORD');
if (mb_strlen($password) < 12) {
    fwrite(STDERR, "Refusing: set DEMO_PASSWORD (at least 12 characters) in the environment. It is never printed.\n");
    exit(2);
}
$mailbox = (string) ($opts['mailbox'] ?? 'solutionsfleury@gmail.com');
if (! filter_var($mailbox, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Refusing: --mailbox is not an email address.\n");
    exit(2);
}
$employees = max(30, min(2000, (int) ($opts['employees'] ?? 1000)));
[$local, $domain] = explode('@', $mailbox, 2);
$address = static fn(string $label): string => $local . '+' . $label . '@' . $domain;

demo_registry_ready();
if ($n = demo_loaded()) {
    fwrite(STDERR, "Refusing: a demonstration is already loaded ($n records). Flush it first: php install/demo-flush.php\n");
    exit(1);
}
if ($taken = (int) val('SELECT COUNT(*) FROM users WHERE email LIKE ?', [$local . '+%@' . $domain])) {
    fwrite(STDERR, "Refusing: accounts already use plus-addresses of $mailbox ($taken of them) and are not demonstration records. Nothing was created.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$started = microtime(true);
$count = [];
$user = static function (string $name, string $label, string $role) use ($hash, $address, &$count): int {
    q('INSERT INTO users (name, email, password_hash, role, must_change_pw) VALUES (?,?,?,?,0)', [$name, $address($label), $hash, $role]);
    $id = (int) db()->lastInsertId();
    demo_mark('users', $id);
    $count['accounts'] = ($count['accounts'] ?? 0) + 1;

    return $id;
};

db()->beginTransaction();
try {
    // ── the office ───────────────────────────────────────────────────────
    $staff = [];
    foreach ([['Owner', 3, 'admin'], ['VP', 3, 'admin'], ['Recruiter', 4, 'recruiter'], ['Coordinator', 2, 'hotels'], ['Payroll', 2, 'payroll'], ['Manager', 10, 'supervisor'], ['Supervisor', 65, 'supervisor']] as [$word, $n, $role]) {
        for ($i = 1; $i <= $n; $i++) {
            $staff[$word][$i] = $user($word . ' Number ' . $i, strtolower($word) . $i, $role);
        }
    }

    // ── the client and its three plants ──────────────────────────────────
    q("INSERT INTO clients (name, contact_name, contact_email, contact_phone) VALUES ('Demo Gulf Fabrication', 'Client contact', ?, '713-555-0100')", [$address('client')]);
    $client = (int) db()->lastInsertId();
    demo_mark('clients', $client);
    $plants = [
        1 => ['Plant 1', 'Baytown', 'TX', 500, [['Chemical engineer', 'chemical', null, 100, 50], ['Welder', 'mechanical', 'welder', 200, 38], ['Painter', 'other', 'painter', 50, 30],
                                            ['Quality inspector', 'other', null, 10, 42], ['Mechanical engineer', 'mechanical', null, 10, 52], ['CNC machinist', 'mechanical', 'cnc_operator', 130, 36]]],
        2 => ['Plant 2', 'Port Arthur', 'TX', 300, [['Welder', 'mechanical', 'welder', 150, 38], ['CNC machinist', 'mechanical', 'cnc_operator', 100, 36], ['Painter', 'other', 'painter', 50, 30]]],
        3 => ['Plant 3', 'Lake Charles', 'LA', 200, [['Welder', 'mechanical', 'welder', 100, 38], ['CNC machinist', 'mechanical', 'cnc_operator', 60, 36], ['Painter', 'other', 'painter', 40, 30]]],
    ];
    $scale = $employees / 1000;
    $supervisorsByPlant = [1 => range(1, 33), 2 => range(34, 52), 3 => range(53, 65)];
    $markup = 40;
    $n = 0;
    $hotelNo = 0;
    foreach ($plants as $p => [$title, $city, $state, $headcount, $lines]) {
        q("INSERT INTO jobs (client_id, project_manager_id, title, site_name, site_city, site_state, pay_rate, bill_rate, guarantee_hours, per_diem_rate, headcount_target, starts_on, status,
                             lifecycle_stage, description, lodging_provided, travel_provided, transport_provided)
           VALUES (?,?,?,?,?,?,38,53.20,50,30,?,DATE_SUB(CURDATE(), INTERVAL 30 DAY),'active','on_site',?,1,1,1)",
          [$client, $staff['Manager'][$p], $title, 'Demo Gulf Fabrication ' . $title, $city, $state, (int) round($headcount * $scale), 'Demonstration plant. Day and night shifts, sectors 1 to 3.']);
        $job = (int) db()->lastInsertId();
        demo_mark('jobs', $job);
        shifts_seed_defaults($job);
        $shifts = array_column(rows('SELECT id, name, starts_at FROM project_shifts WHERE job_id = ? ORDER BY sort_order', [$job]), null, 'name');
        q("INSERT INTO project_pay_policies (job_id, weekly_overtime_after, overtime_multiplier, guarantee_absence_rule, guarantee_day_hours, guarantee_overtime, reviewed_by) VALUES (?, 40, 1.5, 'reduce_day', 10, 1, ?)", [$job, $staff['Owner'][1]]);
        $sectors = [];
        foreach ([1, 2, 3] as $s) {
            q('INSERT INTO project_departments (job_id, name) VALUES (?,?)', [$job, 'Sector ' . $s]);
            $sectors[$s] = (int) db()->lastInsertId();
        }
        // Hotels near the plant: rooms for everyone, a private room each.
        $hotels = [];
        foreach (range(1, $p === 1 ? 4 : 3) as $h) {
            $hotelNo++;
            q('INSERT INTO hotels (name, address, city, state, phone, nightly_rate) VALUES (?,?,?,?,?,89)',
              ['Demo Inn ' . $city . ' ' . $h, (100 + $hotelNo * 10) . ' Demo Parkway', $city, $state, '713-555-02' . str_pad((string) $hotelNo, 2, '0', STR_PAD_LEFT)]);
            $hotels[] = (int) db()->lastInsertId();
            demo_mark('hotels', end($hotels));
        }
        $crew = $supervisorsByPlant[$p];
        $people = array_sum(array_map(static fn($l) => max(1, (int) round($l[3] * $scale)), $lines));
        $k = 0;
        foreach ($lines as [$role, $discipline, $skill, $qty, $pay]) {
            $qty = max(1, (int) round($qty * $scale));
            $bill = round($pay * (1 + $markup / 100), 2);
            q('INSERT INTO job_order_lines (job_id, role_title, discipline, quantity, pay_rate, bill_rate, per_diem_rate) VALUES (?,?,?,?,?,?,30)', [$job, $role, $discipline, $qty, $pay, $bill]);
            $line = (int) db()->lastInsertId();
            for ($i = 0; $i < $qty; $i++) {
                $n++;
                $k++;
                // The plant's people spread evenly across its supervisors: about 15 each.
                $sup = $crew[intdiv(($k - 1) * count($crew), $people)];
                $shift = ($sup % 2) ? 'Day' : 'Night';
                q("INSERT INTO candidates (full_name, email, phone, city, state, discipline, stage, source) VALUES (?,?,?,?,?,?,'placed','Demonstration')",
                  ['Employee Number ' . $n, $address('employee' . $n), '713-555-' . str_pad((string) ($n % 10000), 4, '0', STR_PAD_LEFT), $city, $state, $discipline]);
                $cand = (int) db()->lastInsertId();
                demo_mark('candidates', $cand);
                if ($skill) { q('INSERT INTO candidate_skills (candidate_id, skill_slug, years, confirmed) VALUES (?,?,?,1)', [$cand, $skill, 2 + $n % 12]); }
                $account = $user('Employee Number ' . $n, 'employee' . $n, 'worker');
                q('INSERT INTO worker_accounts (user_id, candidate_id) VALUES (?,?)', [$account, $cand]);
                q("INSERT INTO employee_profiles (candidate_id, employee_number, availability) VALUES (?,?,'on_assignment')", [$cand, 'DEMO-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT)]);
                q("INSERT INTO placements (candidate_id, job_id, order_line_id, status, start_date, pay_rate, bill_rate, per_diem_rate) VALUES (?,?,?,'on_site',DATE_SUB(CURDATE(), INTERVAL 21 DAY),?,?,30)",
                  [$cand, $job, $line, $pay, $bill]);
                $pl = (int) db()->lastInsertId();
                q('INSERT INTO assignment_details (placement_id, trade, supervisor_id, shift_label, shift_id, shift_start) VALUES (?,?,?,?,?,?)',
                  [$pl, $role, $staff['Supervisor'][$sup], $shift, (int) $shifts[$shift]['id'], $shifts[$shift]['starts_at']]);
                q('INSERT INTO placement_departments (placement_id, department_id) VALUES (?,?)', [$pl, $sectors[1 + ($k % 3)]]);
                // The last ten of Plant 1 have just arrived: told their hotel, no room number yet (P5-M32).
                $arrived = $p === 1 && $k > (int) round($headcount * $scale) - 10;
                q("INSERT INTO lodging (placement_id, hotel_id, room_number, room_told_at, check_in, check_out, private_room, nightly_rate, status) VALUES (?,?,?,?,?,DATE_ADD(CURDATE(), INTERVAL 60 DAY),1,89,?)",
                  [$pl, $hotels[$k % count($hotels)], $arrived ? null : (string) (100 + intdiv($k, count($hotels))), date('Y-m-d H:i:s'), $arrived ? date('Y-m-d') : date('Y-m-d', strtotime('-21 days')), $arrived ? 'booked' : 'checked_in']);
            }
            // An open requisition on each line of Plant 1, for the recruiting walk-through.
            if ($p === 1) {
                q("INSERT INTO vacancies (job_id, order_line_id, title, discipline, description, is_open, openings, starts_on, shift) VALUES (?,?,?,?,?,1,?,DATE_ADD(CURDATE(), INTERVAL 14 DAY),'Day')",
                  [$job, $line, $role . ' — Plant 1', $discipline, 'Demonstration requisition.', max(2, (int) round($qty * 0.1))]);
            }
        }
    }
    $count['people on site'] = $n;

    // ── candidates for the recruiting walk-through: welders and machinists near the plants ──
    foreach (range(1, 60) as $i) {
        [$skill, $discipline] = $i % 3 ? ['welder', 'mechanical'] : ['cnc_operator', 'mechanical'];
        q("INSERT INTO candidates (full_name, email, phone, city, state, discipline, stage, source) VALUES (?,?,?,?,?,?,'new','Demonstration')",
          ['Candidate Number ' . $i, $address('candidate' . $i), '832-555-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT), ['Houston', 'Pasadena', 'Beaumont', 'Lake Charles'][$i % 4], $i % 4 === 3 ? 'LA' : 'TX', $discipline]);
        $cand = (int) db()->lastInsertId();
        demo_mark('candidates', $cand);
        q('INSERT INTO candidate_skills (candidate_id, skill_slug, years, confirmed) VALUES (?,?,?,0)', [$cand, $skill, 1 + $i % 15]);
    }
    $count['candidates to recruit'] = 60;

    // ── 65 vans, 12 to 15 seats ──────────────────────────────────────────
    foreach (range(1, 65) as $v) {
        q("INSERT INTO vehicles (name, plate, seats, status) VALUES (?,?,?,'available')", ['Van ' . str_pad((string) $v, 2, '0', STR_PAD_LEFT), 'DEMO-' . str_pad((string) $v, 3, '0', STR_PAD_LEFT), 12 + $v % 4]);
        demo_mark('vehicles', (int) db()->lastInsertId());
    }
    $count['vans'] = 65;
    db()->commit();
} catch (Throwable $e) {
    if (db()->inTransaction()) { db()->rollBack(); }
    fwrite(STDERR, 'Demonstration load FAILED, nothing was kept: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

foreach ($count as $what => $c) {
    printf("  %-24s %6d\n", $what, $c);
}
printf("Demonstration loaded in %.1f s. Every account signs in with %s and the password in DEMO_PASSWORD.\n", microtime(true) - $started, $local . '+<label>@' . $domain);
echo "Remove it with: php install/demo-flush.php (shows what it removes), then --execute.\n";
