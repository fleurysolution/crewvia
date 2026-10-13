<?php
/**
 * Lateness (T19, R29): late arrivals docked from pay, with the reason the
 * worker sees, so nobody can say they were paid short without knowing why.
 *
 * Decided by the owner, 12 Oct 2026:
 *   - Measured as the arrival time against the shift start: the
 *     assignment's own start when it has one, otherwise the project's.
 *   - What is docked is unpaid time: nothing within the grace period, then
 *     the whole lateness rounded up to the project's step (a quarter hour
 *     unless set otherwise). A fixed fine is never taken.
 *   - Each project says whether the docked time is still billed.
 *   - The supervisor records it with the reason; the worker sees it at
 *     once and can dispute it; payroll settles the dispute (upheld,
 *     reduced, or cancelled) before the week is approved for pay.
 *
 * What reaches pay: when hours are imported into the weekly sheet, the
 * docked time of each day comes off that day's approved hours (never below
 * zero), so the flat calculation and the pay rules both see it. A disputed
 * or cancelled lateness docks nothing. A week frozen for pay is not changed
 * here: the office settles it as an adjustment, as for any frozen week.
 * Rules recorded, not legal advice.
 */

declare(strict_types=1);

require_once __DIR__ . '/attendance-controls.php';

/** The statuses that dock pay. */
const LATENESS_DOCKING = ['recorded', 'upheld', 'reduced'];

/** @return array{shift_start:?string,grace_minutes:int,round_to_minutes:int,bill_docked:bool} */
function lateness_policy(int $jobId): array
{
    $r = row('SELECT * FROM lateness_policies WHERE job_id = ?', [$jobId]);

    return ['shift_start' => $r['shift_start'] ?? null, 'grace_minutes' => (int) ($r['grace_minutes'] ?? 5),
            'round_to_minutes' => (int) ($r['round_to_minutes'] ?? 15), 'bill_docked' => (bool) ($r['bill_docked'] ?? 0)];
}

/** "07:00" or "7:00" => "07:00:00"; anything else => null. */
function lateness_time(?string $value): ?string
{
    return preg_match('/^\s*([01]?\d|2[0-3]):([0-5]\d)\s*$/', (string) $value, $m) ? sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2]) : null;
}

function lateness_policy_save(int $jobId, array $in, int $by): ?string
{
    $start = trim((string) ($in['shift_start'] ?? '')) === '' ? null : lateness_time((string) $in['shift_start']);
    if (trim((string) ($in['shift_start'] ?? '')) !== '' && $start === null) {
        return t('Enter the shift start as a time, such as 07:00.');
    }
    $grace = (int) ($in['grace_minutes'] ?? -1);
    $round = (int) ($in['round_to_minutes'] ?? 0);
    if ($grace < 0 || $grace > 30 || ! in_array($round, [1, 5, 6, 10, 15, 30], true)) {
        return t('Grace is 0 to 30 minutes, and rounding is to 1, 5, 6, 10, 15 or 30 minutes.');
    }
    q('INSERT INTO lateness_policies (job_id, shift_start, grace_minutes, round_to_minutes, bill_docked, updated_by) VALUES (?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE shift_start = VALUES(shift_start), grace_minutes = VALUES(grace_minutes), round_to_minutes = VALUES(round_to_minutes),
                               bill_docked = VALUES(bill_docked), updated_by = VALUES(updated_by)',
      [$jobId, $start, $grace, $round, ! empty($in['bill_docked']) ? 1 : 0, $by]);
    log_activity('set the lateness rules', 'project', $jobId, ($start ?? '-') . ' grace ' . $grace . ' round ' . $round);

    return null;
}

/** Minutes late, from a shift start to an arrival; a night shift's arrival after midnight counts from the evening before. */
function lateness_minutes(string $shiftStart, string $arrived): int
{
    $toMinutes = static fn(string $t): int => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
    $diff = $toMinutes($arrived) - $toMinutes($shiftStart);
    if ($diff <= -720) {
        $diff += 1440;
    }

    return $diff;
}

/** What is docked for a lateness: nothing within the grace, otherwise the whole lateness rounded up to the step. */
function lateness_docked(int $minutesLate, int $grace, int $roundTo): int
{
    if ($minutesLate <= $grace) {
        return 0;
    }

    return (int) (ceil($minutesLate / max(1, $roundTo)) * max(1, $roundTo));
}

/** The placement with what lateness needs, if this user may record against it. */
function lateness_placement(int $placementId): ?array
{
    $p = row("SELECT p.*, c.full_name, d.supervisor_id, d.shift_start AS own_shift_start, w.user_id AS worker_user
              FROM placements p JOIN candidates c ON c.id = p.candidate_id
              LEFT JOIN assignment_details d ON d.placement_id = p.id
              LEFT JOIN worker_accounts w ON w.candidate_id = p.candidate_id
              WHERE p.id = ?", [$placementId]);
    if (! $p) {
        return null;
    }
    $mayRecord = can('payroll') || (user()['role'] === 'supervisor' && (int) $p['supervisor_id'] === uid());

    return $mayRecord ? $p : null;
}

/** Records a late arrival. Returns the refusal, or null. */
function lateness_record(int $placementId, string $date, string $arrived, string $reason, int $by): ?string
{
    $p = lateness_placement($placementId);
    $reason = trim($reason);
    if (! $p) {
        return t('Select a placement on your crew.');
    }
    if (! valid_date($date) || $date > date('Y-m-d') || ($p['start_date'] && $date < $p['start_date']) || ($p['end_date'] && $date > $p['end_date'])) {
        return t('Choose a day of the assignment, today or before.');
    }
    if (attendance_week_frozen($placementId, $date)) {
        return t('That week is already approved for pay. Ask the office for a correction.');
    }
    $policy = lateness_policy((int) $p['job_id']);
    $start = $p['own_shift_start'] ?: $policy['shift_start'];
    if (! $start) {
        return t('This project has no shift start yet. Payroll sets it on the Lateness page.');
    }
    $at = lateness_time($arrived);
    if (! $at) {
        return t('Enter the arrival time, such as 07:20.');
    }
    $late = lateness_minutes((string) $start, $at);
    if ($late <= 0) {
        return t('That arrival is not after the shift start.');
    }
    if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
        return t('Write the reason the worker will read, 3 to 500 characters.');
    }
    if (val('SELECT COUNT(*) FROM attendance_lateness WHERE placement_id = ? AND work_date = ?', [$placementId, $date])) {
        return t('A late arrival is already recorded for that day.');
    }
    $docked = lateness_docked($late, $policy['grace_minutes'], $policy['round_to_minutes']);
    q('INSERT INTO attendance_lateness (placement_id, work_date, shift_start, arrived_at, minutes_late, docked_minutes, grace_minutes, round_to_minutes, reason, recorded_by)
       VALUES (?,?,?,?,?,?,?,?,?,?)', [$placementId, $date, $start, $at, $late, $docked, $policy['grace_minutes'], $policy['round_to_minutes'], $reason, $by]);
    $id = (int) db()->lastInsertId();
    log_activity('recorded a late arrival', 'placement', $placementId, $date . ' ' . $late . ' min, ' . $docked . ' docked');
    if ($p['worker_user']) {
        lateness_notify((int) $p['worker_user'], t('Late arrival recorded for :date: :late minutes late, :docked minutes unpaid. Reason and dispute on Lateness.',
                                                   ['date' => d($date), 'late' => $late, 'docked' => $docked]), '/lateness?id=' . $id);
    }

    return null;
}

/** The worker disputes a lateness of theirs. Returns the refusal, or null. */
function lateness_dispute(int $id, string $note, int $by): ?string
{
    $l = row('SELECT l.*, p.job_id FROM attendance_lateness l JOIN placements p ON p.id = l.placement_id JOIN worker_accounts w ON w.candidate_id = p.candidate_id
              WHERE l.id = ? AND w.user_id = ?', [$id, $by]);
    $note = trim($note);
    if (! $l) {
        return t('That late arrival is not yours.');
    }
    if ($l['status'] !== 'recorded') {
        return t('This one is already disputed or settled.');
    }
    if (attendance_week_frozen((int) $l['placement_id'], (string) $l['work_date'])) {
        return t('That week is already approved for pay. Ask the office for a correction.');
    }
    if (mb_strlen($note) < 5 || mb_strlen($note) > 1000) {
        return t('Say why in 5 to 1000 characters.');
    }
    q("UPDATE attendance_lateness SET status = 'disputed', dispute_note = ?, disputed_at = NOW() WHERE id = ?", [$note, $id]);
    log_activity('disputed a late arrival', 'placement', (int) $l['placement_id'], (string) $l['work_date']);
    foreach (rows("SELECT id FROM users WHERE role = 'payroll' AND is_active = 1") as $u) {
        lateness_notify((int) $u['id'], t('A worker disputes a late arrival on :date. Settle it before the week is approved.', ['date' => d((string) $l['work_date'])]), '/lateness?id=' . $id);
    }

    return null;
}

/** Payroll settles a lateness: upheld, reduced to some minutes, or cancelled, with a note. Returns the refusal, or null. */
function lateness_decide(int $id, string $decision, int $minutes, string $note, int $by): ?string
{
    $l = row('SELECT l.*, w.user_id AS worker_user FROM attendance_lateness l JOIN placements p ON p.id = l.placement_id
              LEFT JOIN worker_accounts w ON w.candidate_id = p.candidate_id WHERE l.id = ?', [$id]);
    $note = trim($note);
    if (! $l) {
        return t('Late arrival unavailable.');
    }
    if (! in_array($l['status'], ['recorded', 'disputed'], true)) {
        return t('This one is already settled.');
    }
    if (attendance_week_frozen((int) $l['placement_id'], (string) $l['work_date'])) {
        return t('That week is already approved for pay. Settle it as an adjustment.');
    }
    if (! in_array($decision, ['upheld', 'reduced', 'cancelled'], true)) {
        return t('Unknown action.');
    }
    if ($decision === 'reduced' && ($minutes < 0 || $minutes >= (int) $l['docked_minutes'])) {
        return t('Reduce it to fewer minutes than were docked.');
    }
    if (mb_strlen($note) < 3 || mb_strlen($note) > 1000) {
        return t('Write the decision the worker will read, 3 to 1000 characters.');
    }
    q('UPDATE attendance_lateness SET status = ?, docked_minutes = ?, decision_note = ?, decided_by = ?, decided_at = NOW() WHERE id = ?',
      [$decision, $decision === 'reduced' ? $minutes : (int) $l['docked_minutes'], $note, $by, $id]);
    log_activity('settled a late arrival', 'placement', (int) $l['placement_id'], $decision . ' ' . (string) $l['work_date']);
    if ($l['worker_user']) {
        lateness_notify((int) $l['worker_user'], t('Your late arrival on :date was settled: :decision.', ['date' => d((string) $l['work_date']), 'decision' => lateness_statuses()[$decision]]), '/lateness?id=' . $id);
    }

    return null;
}

/** @return array<string,string> */
function lateness_statuses(): array
{
    return ['recorded' => t('Docked'), 'disputed' => t('Disputed - not docked until settled'), 'upheld' => t('Upheld'),
            'reduced' => t('Reduced'), 'cancelled' => t('Cancelled - nothing docked')];
}

function lateness_notify(int $userId, string $message, string $target): void
{
    q('INSERT INTO notifications (user_id, message, target) VALUES (?,?,?)', [$userId, mb_substr($message, 0, 255), $target]);
}

/**
 * The hours docked each day of a week for a placement: date => hours.
 * @return array<string,float>
 */
function lateness_docked_by_day(int $placementId, string $start, string $end): array
{
    $out = [];
    foreach (rows("SELECT work_date, docked_minutes FROM attendance_lateness
                   WHERE placement_id = ? AND work_date BETWEEN ? AND ? AND status IN ('recorded','upheld','reduced')", [$placementId, $start, $end]) as $r) {
        $out[(string) $r['work_date']] = round((int) $r['docked_minutes'] / 60, 2);
    }

    return $out;
}

/**
 * A week's approved days less what lateness docks from each, never below zero.
 * @param array<int,array{work_date:string,hours:mixed}> $days
 * @return array{hours:float, docked:float}
 */
function lateness_apply(int $placementId, array $days, string $start, string $end): array
{
    $docked = lateness_docked_by_day($placementId, $start, $end);
    $hours = 0.0;
    $taken = 0.0;
    foreach ($days as $d) {
        $h = (float) $d['hours'];
        $cut = min($h, $docked[(string) $d['work_date']] ?? 0.0);
        $hours += $h - $cut;
        $taken += $cut;
    }

    return ['hours' => round($hours, 2), 'docked' => round($taken, 2)];
}

/** Whether a week of a project still has a lateness dispute to settle. */
function lateness_open_disputes(int $jobId, string $weekEnding): int
{
    return (int) val("SELECT COUNT(*) FROM attendance_lateness l JOIN placements p ON p.id = l.placement_id
                      WHERE p.job_id = ? AND l.status = 'disputed' AND l.work_date BETWEEN ? AND ?",
                     [$jobId, date('Y-m-d', strtotime($weekEnding . ' -6 days')), $weekEnding]);
}
