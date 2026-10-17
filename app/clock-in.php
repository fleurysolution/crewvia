<?php
/**
 * P5-M32: the room number, the clock-in, and the supervisor's validation.
 *
 * Decided with the owner, 14 Oct 2026:
 *  - The worker is told their hotel when it is booked, and enters their room
 *    number within 4 hours of being able to know it: from check-in time on
 *    the check-in day (15:00), or from being told, whichever is later.
 *    Without a room number on the stay they are in, they cannot clock in.
 *  - The worker clocks in from their portal. Their supervisor validates it
 *    by 30 minutes after the shift starts (6:30 for a 6:00 shift), or 30
 *    minutes after a late clock-in. A validation after that time is kept,
 *    and marked late.
 *  - A validated clock-in marks them present at roll call; "not here" marks
 *    them absent.
 */

declare(strict_types=1);

require_once __DIR__ . '/notices.php';

const ROOM_ENTRY_HOURS = 4;
const HOTEL_CHECKIN_TIME = '15:00:00';
const CLOCK_VALIDATE_MINUTES = 30;

/** The stay the worker must give a room number for, now: [lodging id, hotel, deadline, overdue], or null. */
function ci_room_due(int $placementId, ?int $now = null): ?array
{
    $now ??= time();
    $l = row("SELECT l.id, l.check_in, l.room_told_at, l.created_at, h.name AS hotel FROM lodging l JOIN hotels h ON h.id = l.hotel_id
              WHERE l.placement_id = ? AND l.status IN ('booked','checked_in') AND (l.room_number IS NULL OR l.room_number = '')
                AND l.check_in IS NOT NULL AND l.check_in <= ? ORDER BY l.check_in DESC, l.id DESC LIMIT 1", [$placementId, date('Y-m-d', $now)]);
    if (! $l) {
        return null;
    }
    $from = max(strtotime($l['check_in'] . ' ' . HOTEL_CHECKIN_TIME), strtotime((string) ($l['room_told_at'] ?: $l['created_at'])));
    $deadline = $from + ROOM_ENTRY_HOURS * 3600;

    return ['lodging_id' => (int) $l['id'], 'hotel' => $l['hotel'], 'deadline' => date('Y-m-d H:i:s', $deadline), 'overdue' => $now > $deadline];
}

/** The worker enters their room number on the stay that needs it. Returns the refusal, or null. */
function ci_room_save(int $placementId, string $room): ?string
{
    $room = trim($room);
    if (! preg_match('/^[A-Za-z0-9 -]{1,12}$/D', $room)) {
        return t('Enter the room number on your key card, for example 214.');
    }
    $due = ci_room_due($placementId);
    if (! $due) {
        return t('No hotel stay is waiting for a room number.');
    }
    q("UPDATE lodging SET room_number = ?, status = IF(status = 'booked', 'checked_in', status) WHERE id = ?", [$room, $due['lodging_id']]);
    $p = row('SELECT p.candidate_id, c.full_name FROM placements p JOIN candidates c ON c.id = p.candidate_id WHERE p.id = ?', [$placementId]);
    q('INSERT INTO candidate_events (candidate_id, event_type, detail) VALUES (?,?,?)', [(int) $p['candidate_id'], 'room number', 'Entered by the worker: ' . $due['hotel'] . ', room ' . $room . ($due['overdue'] ? ' (after the ' . ROOM_ENTRY_HOURS . '-hour limit)' : '')]);
    notify_desks(['hotels'], t(':name is in room :room at :hotel.', ['name' => $p['full_name'], 'room' => $room, 'hotel' => $due['hotel']]), '/hotels');

    return null;
}

/** The shift the worker is clocking in for: [work date, start as Y-m-d H:i:s or null, label]. */
function ci_shift_now(int $placementId, ?int $now = null): array
{
    $now ??= time();
    $d = row('SELECT shift_start, shift_label FROM assignment_details WHERE placement_id = ?', [$placementId]);
    if (! $d || ! $d['shift_start']) {
        return [date('Y-m-d', $now), null, (string) ($d['shift_label'] ?? '')];
    }
    $start = strtotime(date('Y-m-d', $now) . ' ' . $d['shift_start']);
    // Clocking in at 02:00 for an 18:00 shift is yesterday's night shift.
    if ($now < $start - 6 * 3600 && $start - $now > 12 * 3600) {
        $start -= 86400;
    }

    return [date('Y-m-d', $start), date('Y-m-d H:i:s', $start), (string) $d['shift_label']];
}

/** The worker's clock-in today (the shift they are on), or null. */
function ci_today(int $placementId): ?array
{
    [$day] = ci_shift_now($placementId);

    return row('SELECT * FROM clock_ins WHERE placement_id = ? AND work_date = ?', [$placementId, $day]) ?: null;
}

/** The worker clocks in. $ctx (P5-M67): method (qr|portal), the phone's position, the supervisor of the QR. Returns the refusal, or null. */
function ci_clock_in(int $placementId, int $by, array $ctx = []): ?string
{
    $p = row("SELECT p.id, p.job_id, p.candidate_id, p.start_date, c.full_name, d.supervisor_id FROM placements p JOIN candidates c ON c.id = p.candidate_id
              LEFT JOIN assignment_details d ON d.placement_id = p.id WHERE p.id = ? AND p.status IN ('confirmed','travelling','on_site')", [$placementId]);
    if (! $p) {
        return t('There is no assignment to clock in to.');
    }
    if ($p['start_date'] && $p['start_date'] > date('Y-m-d')) {
        return t('Your assignment starts on :date. Clock in from that day.', ['date' => d($p['start_date'])]);
    }
    if ($room = ci_room_due($placementId)) {
        return t('Enter your room number at :hotel first. Without it you cannot clock in.', ['hotel' => $room['hotel']]);
    }
    [$day, $start, $label] = ci_shift_now($placementId);
    if (val('SELECT id FROM clock_ins WHERE placement_id = ? AND work_date = ?', [$placementId, $day])) {
        return t('You are already clocked in for this shift.');
    }
    $now = time();
    $validateBy = max($start ? strtotime($start) : 0, $now) + CLOCK_VALIDATE_MINUTES * 60;
    // P5-M67: where the phone was, how they clocked in; anything unusual is flagged to the supervisor.
    [$lat, $lng, $acc, $distance, $flag] = ci_place((int) $p['job_id'], $ctx);
    $method = ($ctx['method'] ?? 'portal') === 'qr' ? 'qr' : 'portal';
    if ($method === 'portal') {
        $flag = trim(t('Clocked in without the site QR.') . ' ' . (string) $flag);
    }
    q('INSERT INTO clock_ins (placement_id, work_date, clocked_in_at, shift_start, validate_by, clocked_by, in_method, in_lat, in_lng, in_accuracy_m, in_distance_m, in_flag, qr_supervisor_id)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [$placementId, $day, date('Y-m-d H:i:s', $now), $start, date('Y-m-d H:i:s', $validateBy), $by, $method, $lat, $lng, $acc, $distance, $flag ?: null, $ctx['qr_supervisor_id'] ?? null]);
    log_activity('clocked in', 'placement', $placementId, trim($label . ' ' . (string) $flag));
    if ($p['supervisor_id']) {
        notify_user((int) $p['supervisor_id'], t(':name clocked in at :time. Validate by :by.', ['name' => $p['full_name'], 'time' => date('g:i a', $now), 'by' => date('g:i a', $validateBy)]) . ($flag ? ' ' . $flag : ''), '/my-team#clock-ins');
    }

    return null;
}

/** The supervisor validates a clock-in of their own crew, or says the person is not here. Returns the refusal, or null. */
function ci_decide(int $clockInId, bool $present, int $by): ?string
{
    $c = row("SELECT ci.*, c.full_name, c.id AS candidate_id FROM clock_ins ci JOIN placements p ON p.id = ci.placement_id JOIN candidates c ON c.id = p.candidate_id
              JOIN assignment_details d ON d.placement_id = p.id WHERE ci.id = ? AND d.supervisor_id = ?", [$clockInId, $by]);
    if (! $c) {
        return t('Validate only your own crew\'s clock-ins.');
    }
    if ($c['status'] !== 'waiting') {
        return t('This clock-in is already decided.');
    }
    $late = time() > strtotime((string) $c['validate_by']) ? 1 : 0;
    q("UPDATE clock_ins SET status = ?, decided_by = ?, decided_at = NOW(), decided_late = ? WHERE id = ? AND status = 'waiting'", [$present ? 'validated' : 'not_present', $by, $late, $clockInId]);
    q('INSERT INTO assignment_checkins (placement_id, work_date, present, marked_by, note) VALUES (?,?,?,?,?)
       ON DUPLICATE KEY UPDATE present = VALUES(present), marked_by = VALUES(marked_by), note = VALUES(note), marked_at = NOW()',
      [(int) $c['placement_id'], $c['work_date'], $present ? 1 : 0, $by, $present ? 'Clock-in validated' : 'Clocked in, not on site']);
    log_activity($present ? 'validated a clock-in' : 'refused a clock-in', 'placement', (int) $c['placement_id'], $c['full_name'] . ($late ? ' (late)' : ''));
    notify_candidate((int) $c['candidate_id'], $present ? t('Your supervisor validated your clock-in.') : t('Your supervisor did not see you on site. Call them now.'));

    return null;
}

/** A supervisor's crew today: clock-ins waiting and decided, and anyone whose room number is overdue. */
function ci_crew_today(int $supervisorId): array
{
    $clockIns = rows("SELECT ci.*, c.full_name, d.shift_label FROM clock_ins ci JOIN placements p ON p.id = ci.placement_id JOIN candidates c ON c.id = p.candidate_id
                      JOIN assignment_details d ON d.placement_id = p.id WHERE d.supervisor_id = ? AND ci.work_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                      ORDER BY ci.status = 'waiting' DESC, ci.validate_by, c.full_name", [$supervisorId]);
    $rooms = [];
    foreach (rows("SELECT p.id, c.full_name FROM placements p JOIN candidates c ON c.id = p.candidate_id JOIN assignment_details d ON d.placement_id = p.id
                   WHERE d.supervisor_id = ? AND p.status IN ('confirmed','travelling','on_site')", [$supervisorId]) as $p) {
        $due = ci_room_due((int) $p['id']);
        if ($due && $due['overdue']) {
            $rooms[] = $p + $due;
        }
    }

    return ['clock_ins' => $clockIns, 'rooms' => $rooms];
}

/*
 * P5-M67: clocking in and out on the site (the owner, 17 Oct 2026).
 *  - The supervisor shows a QR code on their own phone; it changes every 30 seconds. The worker scans it with
 *    their phone, signed in to their own account: a photo sent to a colleague is dead within a minute, and it
 *    clocks in only the account that scans it, only on that supervisor's project.
 *  - The phone gives its position. Away from the site (beyond its radius, the GPS accuracy allowed for) the
 *    clock-in is kept and flagged to the supervisor, as is a clock-in without the QR or without a position:
 *    GPS is often wrong inside plants, so the supervisor decides.
 *  - Clocking out the same way; for an hourly worker the hours between the two are shown.
 */
const CLOCK_QR_SECONDS = 30;
const CLOCK_QR_GRACE_WINDOWS = 2;

function ci_qr_key(): string
{
    global $config;

    return hash('sha256', 'clock-qr|' . (string) ($config['encryption_key'] ?? $config['app_url'] ?? 'crewvia'), true);
}

/** The token the supervisor's screen shows now: supervisor, project, 30-second window, signature. */
function ci_qr_token(int $supervisorId, int $jobId, ?int $window = null): string
{
    $window ??= intdiv(time(), CLOCK_QR_SECONDS);
    $body = $supervisorId . '.' . $jobId . '.' . $window;

    return $body . '.' . substr(hash_hmac('sha256', $body, ci_qr_key()), 0, 24);
}

/** A scanned token, if genuine and fresh (this window or the two before): [supervisor, job], or null. */
function ci_qr_check(string $token): ?array
{
    if (! preg_match('/^(\d+)\.(\d+)\.(\d+)\.([a-f0-9]{24})$/D', trim($token), $m)) {
        return null;
    }
    $body = $m[1] . '.' . $m[2] . '.' . $m[3];
    if (! hash_equals(substr(hash_hmac('sha256', $body, ci_qr_key()), 0, 24), $m[4])) {
        return null;
    }
    $now = intdiv(time(), CLOCK_QR_SECONDS);
    if ((int) $m[3] > $now || (int) $m[3] < $now - CLOCK_QR_GRACE_WINDOWS) {
        return null;
    }

    return [(int) $m[1], (int) $m[2]];
}

/** Metres between two points (haversine). */
function ci_distance_m(float $lat1, float $lng1, float $lat2, float $lng2): int
{
    $r = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

    return (int) round(2 * $r * asin(min(1, sqrt($a))));
}

/**
 * Where the phone was, against the site: [lat, lng, accuracy, distance, flag]. The flag is null when it
 * was on the site (or the site has no position yet); otherwise it says why the supervisor should look.
 */
function ci_place(int $jobId, array $in): array
{
    $lat = is_numeric($in['lat'] ?? null) ? (float) $in['lat'] : null;
    $lng = is_numeric($in['lng'] ?? null) ? (float) $in['lng'] : null;
    $acc = is_numeric($in['accuracy'] ?? null) ? (int) round((float) $in['accuracy']) : null;
    if ($lat !== null && (abs($lat) > 90 || abs((float) $lng) > 180)) {
        $lat = $lng = null;
    }
    $site = row('SELECT site_lat, site_lng, site_radius_m FROM jobs WHERE id = ?', [$jobId]);
    if ($lat === null || $lng === null) {
        return [null, null, null, null, t('No position from the phone (location refused or unavailable).')];
    }
    if (! $site || $site['site_lat'] === null || $site['site_lng'] === null) {
        return [$lat, $lng, $acc, null, null];
    }
    $distance = ci_distance_m($lat, $lng, (float) $site['site_lat'], (float) $site['site_lng']);
    $radius = (int) ($site['site_radius_m'] ?: 300);
    $flag = $distance - min((int) ($acc ?? 0), 500) > $radius
        ? t('Away from the site: :d m from it (allowed :r m, phone accuracy :a m).', ['d' => number_format($distance), 'r' => $radius, 'a' => (int) ($acc ?? 0)])
        : null;

    return [$lat, $lng, $acc, $distance, $flag];
}

/** The worker clocks out of the shift they clocked in to. $ctx: method (qr|portal), position. */
function ci_clock_out(int $placementId, int $by, array $ctx = []): ?string
{
    $c = row("SELECT ci.*, p.job_id, c.full_name, d.supervisor_id FROM clock_ins ci JOIN placements p ON p.id = ci.placement_id JOIN candidates c ON c.id = p.candidate_id
              LEFT JOIN assignment_details d ON d.placement_id = p.id
              WHERE ci.placement_id = ? AND ci.clocked_out_at IS NULL AND ci.work_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) ORDER BY ci.id DESC LIMIT 1", [$placementId]);
    if (! $c) {
        return t('You are not clocked in.');
    }
    [$lat, $lng, $acc, $distance, $flag] = ci_place((int) $c['job_id'], $ctx);
    $method = ($ctx['method'] ?? 'portal') === 'qr' ? 'qr' : 'portal';
    if ($method === 'portal') {
        $flag = trim(t('Clocked out without the site QR.') . ' ' . (string) $flag);
    }
    q('UPDATE clock_ins SET clocked_out_at = NOW(), out_method = ?, out_lat = ?, out_lng = ?, out_accuracy_m = ?, out_distance_m = ?, out_flag = ?, out_by = ? WHERE id = ?',
      [$method, $lat, $lng, $acc, $distance, $flag ?: null, $by, (int) $c['id']]);
    log_activity('clocked out', 'placement', $placementId, (string) $flag);
    $hours = ci_hours(['clocked_in_at' => $c['clocked_in_at'], 'clocked_out_at' => date('Y-m-d H:i:s')]);
    if ($c['supervisor_id']) {
        notify_user((int) $c['supervisor_id'], t(':name clocked out at :time (:h h).', ['name' => $c['full_name'], 'time' => date('g:i a'), 'h' => $hours]) . ($flag ? ' ' . $flag : ''), '/my-team#clock-ins');
    }

    return null;
}

/** The hours between clocking in and out, as decimals; null while still in. */
function ci_hours(array $c): ?string
{
    if (empty($c['clocked_out_at'])) {
        return null;
    }
    $minutes = max(0, (int) round((strtotime((string) $c['clocked_out_at']) - strtotime((string) $c['clocked_in_at'])) / 60));

    return number_format($minutes / 60, 2);
}

/**
 * The worker scanned their supervisor's QR: clock in, or out when already in. Only on that supervisor's
 * project, for the worker's own assignment there. Returns [refusal or null, 'in'|'out'].
 */
function ci_qr_scan(int $candidateId, string $token, array $ctx, int $by): array
{
    $qr = ci_qr_check($token);
    if (! $qr) {
        return [t('This code has expired. Scan the code on your supervisor’s phone again: it changes every 30 seconds.'), ''];
    }
    [$supervisorId, $jobId] = $qr;
    $pid = (int) val("SELECT id FROM placements WHERE candidate_id = ? AND job_id = ? AND status IN ('confirmed','travelling','on_site') ORDER BY id DESC LIMIT 1", [$candidateId, $jobId]);
    if (! $pid) {
        return [t('You have no assignment on this project. Ask your supervisor.'), ''];
    }
    $ctx = ['method' => 'qr', 'qr_supervisor_id' => $supervisorId] + $ctx;
    if (val('SELECT id FROM clock_ins WHERE placement_id = ? AND clocked_out_at IS NULL AND work_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)', [$pid])) {
        return [ci_clock_out($pid, $by, $ctx), 'out'];
    }

    return [ci_clock_in($pid, $by, $ctx), 'in'];
}

/** Whether the person is paid by the hour (their profile; hourly when nothing says otherwise). */
function ci_is_hourly(int $candidateId): bool
{
    return (string) (val('SELECT employment_type FROM employee_profiles WHERE candidate_id = ?', [$candidateId]) ?: 'hourly') === 'hourly';
}
