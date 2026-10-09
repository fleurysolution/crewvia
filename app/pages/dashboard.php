<?php
/**
 * The one screen Jerry opens first.
 *
 * It answers the four questions a strike job turns on: have we got the
 * people, are they getting here, have they got a bed, and what is the week
 * worth. Everything else is a click away.
 */

$job = current_job();

$jobId   = (int) ($job['id'] ?? 0);
$target  = (int) ($job['headcount_target'] ?? 0);

$placed   = (int) val("SELECT COUNT(*) FROM placements WHERE job_id = ? AND status IN ('confirmed','travelling','on_site')", [$jobId]);
$onSite   = (int) val("SELECT COUNT(*) FROM placements WHERE job_id = ? AND status = 'on_site'", [$jobId]);
$offered  = (int) val("SELECT COUNT(*) FROM placements WHERE job_id = ? AND status = 'offered'", [$jobId]);

// ── The journey, as five numbers ────────────────────────────────────────
//
// Each one is the count of people sitting at that step right now. Shown in
// order on the front page so the product explains itself: this is what a
// staffing desk does, and this is where everybody currently is.
$journey = ['applied' => 0, 'hiring' => 0, 'ready' => 0, 'onsite' => 0, 'paid' => 0];

try {
    // Applied: somebody answered one of THIS project's adverts and no
    // decision has been taken. Counting the whole database here instead
    // put a number from a different population at the head of a funnel
    // whose other four steps are this project only.
    $journey['applied'] = (int) val(
        "SELECT COUNT(*) FROM applications a
          JOIN vacancies v ON v.id = a.vacancy_id
          WHERE v.job_id = ? AND a.stage IN ('new','screening','interview')", [$jobId]);

    // Being hired: offered on this project, or accepted and not yet
    // standing on the roster.
    $journey['hiring'] = (int) val(
        "SELECT COUNT(*) FROM applications a
          JOIN vacancies v ON v.id = a.vacancy_id
          WHERE v.job_id = ? AND a.stage IN ('offered','accepted')", [$jobId]);

    // Ready to travel: on the job, confirmed, not yet on site.
    $journey['ready'] = (int) val(
        "SELECT COUNT(*) FROM placements WHERE job_id = ? AND status IN ('confirmed','travelling')",
        [$jobId]);

    // On site: working.
    $journey['onsite'] = (int) val(
        "SELECT COUNT(*) FROM placements WHERE job_id = ? AND status = 'on_site'", [$jobId]);

    // Paid: people with an approved sheet in the current week.
    $journey['paid'] = (int) val(
        "SELECT COUNT(DISTINCT ts.placement_id) FROM timesheets ts
         JOIN placements p ON p.id = ts.placement_id
         WHERE p.job_id = ? AND ts.week_ending = ? AND ts.status IN ('approved','paid')",
        [$jobId, week_ending()]);
} catch (Throwable $e) {
    log_message('error', '[dashboard journey] ' . $e->getMessage());
}

$pipeline = rows("SELECT stage, COUNT(*) n FROM candidates GROUP BY stage");
$byStage  = array_column($pipeline, 'n', 'stage');
$pool     = array_sum($byStage);
$uncalled = (int) ($byStage['new'] ?? 0);

// Beds. A placement that is confirmed or already here and has no room is the
// thing that ruins a Saturday, so it gets counted on the front page.
$noBed = (int) val(
    "SELECT COUNT(*) FROM placements p
     LEFT JOIN lodging l ON l.placement_id = p.id AND l.status IN ('held','booked','checked_in')
     WHERE p.job_id = ? AND p.status IN ('confirmed','travelling','on_site') AND l.id IS NULL",
    [$jobId]);

$sharedRoom = (int) val(
    "SELECT COUNT(*) FROM lodging l
     JOIN placements p ON p.id = l.placement_id
     WHERE p.job_id = ? AND l.private_room = 0 AND l.status <> 'cancelled'", [$jobId]);

// Travel not yet booked for anybody who is meant to be coming.
$noFlight = (int) val(
    "SELECT COUNT(*) FROM placements p
     LEFT JOIN travel t ON t.placement_id = p.id AND t.direction = 'inbound' AND t.status <> 'cancelled'
     WHERE p.job_id = ? AND p.status IN ('confirmed','travelling') AND t.id IS NULL",
    [$jobId]);

$week = week_ending();

$sheets = rows(
    "SELECT ts.*,snap.result_json snapshot_json, p.pay_rate, p.bill_rate, p.per_diem_rate
     FROM timesheets ts JOIN placements p ON p.id = ts.placement_id LEFT JOIN pay_snapshots snap ON snap.timesheet_id=ts.id
     WHERE p.job_id = ? AND ts.week_ending = ? AND ts.status IN ('approved','paid')", [$jobId, $week]);

$payTotal = $billTotal = $shortHours = 0.0;

foreach ($sheets as $t) {
    $m = week_money($t, $t, $job ?? []);
    $payTotal   += $m['pay_total'];
    $billTotal  += $m['bill_total'];
    $shortHours += $m['short_by'];
}

$arrivals = rows(
    "SELECT p.id, c.full_name, c.discipline, t.arrive_time, t.arrive_at, t.carrier, t.reference,
            t.pickup_needed, h.name AS hotel, l.room_number
     FROM placements p
     JOIN candidates c ON c.id = p.candidate_id
     LEFT JOIN travel t ON t.placement_id = p.id AND t.direction = 'inbound' AND t.status <> 'cancelled'
     LEFT JOIN lodging l ON l.placement_id = p.id AND l.status <> 'cancelled'
     LEFT JOIN hotels  h ON h.id = l.hotel_id
     WHERE p.job_id = ? AND p.status IN ('confirmed','travelling')
     ORDER BY t.arrive_time IS NULL, t.arrive_time ASC LIMIT 12", [$jobId]);

$pageTitle = t('Dashboard').' · '.$config['app_name'];
render('dashboard', compact(
    'job','target','placed','onSite','offered','pool','uncalled','byStage',
    'noBed','sharedRoom','noFlight','week','payTotal','billTotal','shortHours','arrivals'
, 'journey'));
