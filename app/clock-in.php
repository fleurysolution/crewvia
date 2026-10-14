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

/** The worker clocks in. Returns the refusal, or null. */
function ci_clock_in(int $placementId, int $by): ?string
{
    $p = row("SELECT p.id, p.candidate_id, p.start_date, c.full_name, d.supervisor_id FROM placements p JOIN candidates c ON c.id = p.candidate_id
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
    q('INSERT INTO clock_ins (placement_id, work_date, clocked_in_at, shift_start, validate_by, clocked_by) VALUES (?,?,?,?,?,?)',
      [$placementId, $day, date('Y-m-d H:i:s', $now), $start, date('Y-m-d H:i:s', $validateBy), $by]);
    log_activity('clocked in', 'placement', $placementId, $label);
    if ($p['supervisor_id']) {
        notify_user((int) $p['supervisor_id'], t(':name clocked in at :time. Validate by :by.', ['name' => $p['full_name'], 'time' => date('g:i a', $now), 'by' => date('g:i a', $validateBy)]), '/my-team#clock-ins');
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
