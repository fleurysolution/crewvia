<?php
/**
 * Attendance after the worker has submitted it.
 *
 * Three things the daily record could not do before:
 *
 *   - correct an approved day. Approval used to be final, so a day
 *     approved at 8 hours that was really 10 had no way back short of a
 *     database edit nobody could see.
 *   - enter a day the worker could not (R26): their phone died, they have
 *     no account yet. The supervisor validates manually, with a note.
 *   - say where the record disagrees with itself: present at roll call
 *     with no hours, hours on a day marked absent, a day on two jobs, a
 *     weekly sheet that no longer matches the days behind it.
 *
 * One rule runs through all of it: once a week is approved for pay it is
 * frozen. A day inside a frozen week is not edited here - the difference
 * is shown, and settled as an adjustment in payroll.
 */

declare(strict_types=1);

/** Above this a single day is flagged for a second look, not refused. */
const ATTENDANCE_LONG_DAY = 16.0;

/** A submission waiting longer than this many days is flagged. */
const ATTENDANCE_STALE_DAYS = 2;

/**
 * The placement, if the signed-in person may act on its attendance:
 * payroll (and admin) on the current project, a supervisor on their own
 * crew. Nobody else.
 */
function attendance_placement_in_scope(int $placementId): ?array
{
    $user = user();

    if (! $user) {
        return null;
    }

    if ($user['role'] === 'supervisor') {
        return row('SELECT p.* FROM placements p
                    JOIN assignment_details d ON d.placement_id = p.id
                    WHERE p.id = ? AND d.supervisor_id = ?', [$placementId, uid()]) ?: null;
    }

    if (! can('payroll')) {
        return null;
    }

    return row('SELECT * FROM placements WHERE id = ? AND job_id = ?',
               [$placementId, (int) (current_job()['id'] ?? 0)]) ?: null;
}

/** The pay week a day belongs to is approved or paid, so the day is frozen. */
function attendance_week_frozen(int $placementId, string $date): bool
{
    // Frozen when its own sheet is approved, or when the week's pay
    // period has gone past open for everybody (P1-M06).
    require_once __DIR__ . '/pay-periods.php';

    return payroll_week_locked(week_ending($date))
        || (bool) val("SELECT COUNT(*) FROM timesheets
                       WHERE placement_id = ? AND week_ending = ? AND status IN ('approved','paid')",
                      [$placementId, week_ending($date)]);
}

/** Hours as typed: a number from 0 to 24 in quarter hours or finer. */
function attendance_hours_value(mixed $given): ?float
{
    $given = trim((string) $given);

    if ($given === '' || ! is_numeric($given)) {
        return null;
    }

    $hours = (float) $given;

    return is_finite($hours) && $hours >= 0 && $hours <= 24 ? round($hours, 2) : null;
}

/**
 * Correct the hours of an approved day. Returns the refusal, or null.
 *
 * The caller owns the transaction.
 */
function attendance_correct(int $attendanceId, mixed $hoursGiven, string $reason, int $userId): ?string
{
    $record = row('SELECT * FROM attendance_records WHERE id = ? FOR UPDATE', [$attendanceId]);

    if (! $record || ! attendance_placement_in_scope((int) $record['placement_id'])) {
        return t('That day is not one you can correct.');
    }

    if ($record['status'] !== 'approved') {
        return t('Only an approved day is corrected. A submitted day is approved or rejected; a rejected one is resubmitted by the worker.');
    }

    if ((int) $record['submitted_by'] === $userId && $record['source'] === 'worker') {
        return t('Nobody corrects their own hours.');
    }

    $hours = attendance_hours_value($hoursGiven);

    if ($hours === null) {
        return t('Hours for one day are a number from 0 to 24.');
    }

    if (abs($hours - (float) $record['hours']) < 0.005) {
        return t('Those are already the hours on record.');
    }

    $reason = trim($reason);

    if (mb_strlen($reason) < 3) {
        return t('Say why the hours changed. The worker will see it.');
    }

    if (mb_strlen($reason) > 500) {
        return t('That reason is too long.');
    }

    if (attendance_week_frozen((int) $record['placement_id'], (string) $record['work_date'])) {
        return t('That week is already approved for pay. Record the difference as a payroll adjustment instead.');
    }

    q('INSERT INTO attendance_corrections (attendance_id, old_hours, new_hours, reason, corrected_by)
       VALUES (?,?,?,?,?)', [$attendanceId, $record['hours'], $hours, $reason, $userId]);

    q('UPDATE attendance_records SET hours = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?',
      [$hours, $userId, $attendanceId]);

    return null;
}

/**
 * A day the worker could not enter, entered and validated by the
 * supervisor or payroll, with a note saying why. Returns the refusal, or
 * null.
 */
function attendance_enter_for_worker(int $placementId, string $date, mixed $hoursGiven, string $note, int $userId): ?string
{
    $placement = attendance_placement_in_scope($placementId);

    if (! $placement || ! in_array($placement['status'], ['confirmed', 'travelling', 'on_site', 'completed'], true)) {
        return t('Choose somebody on your crew who is on the assignment.');
    }

    if (! valid_date($date) || $date > date('Y-m-d')) {
        return t('Give a working date no later than today.');
    }

    if (($placement['start_date'] && $date < $placement['start_date'])
        || ($placement['end_date'] && $date > $placement['end_date'])) {
        return t('That date is outside the assignment.');
    }

    $hours = attendance_hours_value($hoursGiven);

    if ($hours === null) {
        return t('Hours for one day are a number from 0 to 24.');
    }

    $note = trim($note);

    if (mb_strlen($note) < 3) {
        return t('Say why the worker could not enter this day themselves.');
    }

    if (mb_strlen($note) > 500) {
        return t('That note is too long.');
    }

    if (attendance_week_frozen($placementId, $date)) {
        return t('That week is already approved for pay. Record the difference as a payroll adjustment instead.');
    }

    $existing = row('SELECT status FROM attendance_records WHERE placement_id = ? AND work_date = ? FOR UPDATE',
                    [$placementId, $date]);

    if ($existing) {
        return match ($existing['status']) {
            'submitted' => t('The worker already submitted this day. Approve or reject it instead.'),
            'approved'  => t('This day is already approved. Correct it instead.'),
            default     => t('The worker\'s entry for this day was rejected. They resubmit it, or it is corrected once approved.'),
        };
    }

    q("INSERT INTO attendance_records
         (placement_id, work_date, hours, source, note, status, submitted_by, reviewed_by, reviewed_at)
       VALUES (?,?,?,'staff',?,'approved',?,?,NOW())",
      [$placementId, $date, $hours, $note, $userId, $userId]);

    return null;
}

/** Corrections for a set of days, keyed by attendance id, newest first. */
function attendance_corrections_for(array $attendanceIds): array
{
    $ids = array_values(array_filter(array_map('intval', $attendanceIds)));

    if (! $ids) {
        return [];
    }

    $found = rows('SELECT c.*, u.name AS corrected_by_name FROM attendance_corrections c
                   LEFT JOIN users u ON u.id = c.corrected_by
                   WHERE c.attendance_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                   ORDER BY c.id DESC', $ids);

    $by = [];

    foreach ($found as $row) {
        $by[(int) $row['attendance_id']][] = $row;
    }

    return $by;
}

/**
 * What does not add up on a project between two dates.
 *
 * Read-only. Each entry: kind, placement_id, full_name, work_date, detail.
 * The kinds are named in attendance_exception_kinds().
 */
function attendance_exceptions(int $jobId, string $from, string $to): array
{
    $found = [];

    $add = static function (string $kind, array $r, string $detail = '') use (&$found): void {
        $found[] = ['kind' => $kind, 'placement_id' => (int) $r['placement_id'],
                    'full_name' => (string) $r['full_name'], 'work_date' => (string) $r['work_date'],
                    'detail' => $detail];
    };

    // Marked present at roll call, but no hours for that day.
    foreach (rows("SELECT k.placement_id, k.work_date, c.full_name
                   FROM assignment_checkins k
                   JOIN placements p ON p.id = k.placement_id JOIN candidates c ON c.id = p.candidate_id
                   LEFT JOIN attendance_records a ON a.placement_id = k.placement_id AND a.work_date = k.work_date
                        AND a.status <> 'rejected'
                   WHERE p.job_id = ? AND k.present = 1 AND k.work_date BETWEEN ? AND ? AND a.id IS NULL
                   ORDER BY k.work_date, c.full_name", [$jobId, $from, $to]) as $r) {
        $add('present_no_hours', $r);
    }

    // Hours for a day the roll call says they were absent.
    foreach (rows("SELECT a.placement_id, a.work_date, a.hours, c.full_name
                   FROM attendance_records a
                   JOIN assignment_checkins k ON k.placement_id = a.placement_id AND k.work_date = a.work_date
                   JOIN placements p ON p.id = a.placement_id JOIN candidates c ON c.id = p.candidate_id
                   WHERE p.job_id = ? AND k.present = 0 AND a.hours > 0 AND a.status <> 'rejected'
                     AND a.work_date BETWEEN ? AND ?
                   ORDER BY a.work_date, c.full_name", [$jobId, $from, $to]) as $r) {
        $add('hours_while_absent', $r, (string) (float) $r['hours']);
    }

    // A long day: possible, and worth a second look before it is paid.
    foreach (rows("SELECT a.placement_id, a.work_date, a.hours, c.full_name
                   FROM attendance_records a
                   JOIN placements p ON p.id = a.placement_id JOIN candidates c ON c.id = p.candidate_id
                   WHERE p.job_id = ? AND a.hours > ? AND a.status <> 'rejected' AND a.work_date BETWEEN ? AND ?
                   ORDER BY a.work_date, c.full_name", [$jobId, ATTENDANCE_LONG_DAY, $from, $to]) as $r) {
        $add('long_day', $r, (string) (float) $r['hours']);
    }

    // The same person with hours on two assignments the same day.
    foreach (rows("SELECT a.placement_id, a.work_date, c.full_name,
                          SUM(o.hours) + a.hours AS total, GROUP_CONCAT(DISTINCT j.title SEPARATOR ', ') AS other_jobs
                   FROM attendance_records a
                   JOIN placements p ON p.id = a.placement_id JOIN candidates c ON c.id = p.candidate_id
                   JOIN placements op ON op.candidate_id = p.candidate_id AND op.id <> p.id
                   JOIN attendance_records o ON o.placement_id = op.id AND o.work_date = a.work_date
                        AND o.status <> 'rejected' AND o.hours > 0
                   JOIN jobs j ON j.id = op.job_id
                   WHERE p.job_id = ? AND a.status <> 'rejected' AND a.hours > 0 AND a.work_date BETWEEN ? AND ?
                   GROUP BY a.id, a.placement_id, a.work_date, c.full_name, a.hours
                   ORDER BY a.work_date, c.full_name", [$jobId, $from, $to]) as $r) {
        $add('two_assignments', $r, (string) $r['other_jobs'] . ' · ' . (string) (float) $r['total']);
    }

    // Waiting for a decision too long - a day nobody approves is a day
    // nobody is paid for.
    foreach (rows("SELECT a.placement_id, a.work_date, c.full_name
                   FROM attendance_records a
                   JOIN placements p ON p.id = a.placement_id JOIN candidates c ON c.id = p.candidate_id
                   WHERE p.job_id = ? AND a.status = 'submitted' AND a.work_date BETWEEN ? AND ?
                     AND a.work_date < DATE_SUB(CURDATE(), INTERVAL ? DAY)
                   ORDER BY a.work_date, c.full_name", [$jobId, $from, $to, ATTENDANCE_STALE_DAYS]) as $r) {
        $add('waiting_review', $r);
    }

    // Hours worked on a day the person is on approved leave: one of the
    // two is wrong, and paying both pays the day twice.
    foreach (rows("SELECT a.placement_id, a.work_date, a.hours, c.full_name, r.request_type
                   FROM attendance_records a
                   JOIN placements p ON p.id = a.placement_id JOIN candidates c ON c.id = p.candidate_id
                   JOIN time_off_requests r ON r.placement_id = a.placement_id AND r.status = 'approved'
                        AND a.work_date BETWEEN r.starts_on AND r.ends_on
                   WHERE p.job_id = ? AND a.hours > 0 AND a.status <> 'rejected' AND a.work_date BETWEEN ? AND ?
                   ORDER BY a.work_date, c.full_name", [$jobId, $from, $to]) as $r) {
        $add('hours_on_leave', $r, (string) $r['request_type'] . ' · ' . (string) (float) $r['hours']);
    }

    // A day outside the assignment's dates, which change after the fact.
    foreach (rows("SELECT a.placement_id, a.work_date, c.full_name
                   FROM attendance_records a
                   JOIN placements p ON p.id = a.placement_id JOIN candidates c ON c.id = p.candidate_id
                   WHERE p.job_id = ? AND a.status <> 'rejected' AND a.work_date BETWEEN ? AND ?
                     AND ((p.start_date IS NOT NULL AND a.work_date < p.start_date)
                       OR (p.end_date IS NOT NULL AND a.work_date > p.end_date))
                   ORDER BY a.work_date, c.full_name", [$jobId, $from, $to]) as $r) {
        $add('outside_assignment', $r);
    }

    return $found;
}

/** What each kind of exception means, in words. One list (S6). */
function attendance_exception_kinds(): array
{
    return [
        'present_no_hours'   => t('Present at roll call, no hours for the day'),
        'hours_while_absent' => t('Hours on a day marked absent at roll call'),
        'long_day'           => t('More than 16 hours in one day'),
        'two_assignments'    => t('Hours on two assignments the same day'),
        'waiting_review'     => t('Submitted more than two days ago and not yet reviewed'),
        'outside_assignment' => t('A day outside the assignment\'s dates'),
        'hours_on_leave'     => t('Hours worked on a day of approved leave'),
    ];
}

/**
 * The week on a project, placement by placement: the approved days, the
 * weekly sheet, what was imported into it, and whether they agree.
 *
 * Each row carries a state named in attendance_reconciliation_states().
 */
function attendance_week_reconciliation(int $jobId, string $weekEnding): array
{
    $start = date('Y-m-d', strtotime($weekEnding . ' -6 days'));

    $rows = rows("SELECT p.id AS placement_id, c.full_name,
                         (SELECT COALESCE(SUM(a.hours), 0) FROM attendance_records a
                           WHERE a.placement_id = p.id AND a.status = 'approved'
                             AND a.work_date BETWEEN ? AND ?) AS approved_hours,
                         (SELECT COUNT(*) FROM attendance_records a
                           WHERE a.placement_id = p.id AND a.status = 'approved'
                             AND a.work_date BETWEEN ? AND ?) AS approved_days,
                         (SELECT COUNT(*) FROM attendance_records a
                           WHERE a.placement_id = p.id AND a.status = 'submitted'
                             AND a.work_date BETWEEN ? AND ?) AS pending_days,
                         ts.id AS timesheet_id, ts.hours_worked AS sheet_hours, ts.status AS sheet_status,
                         src.attendance_json
                  FROM placements p
                  JOIN candidates c ON c.id = p.candidate_id
                  LEFT JOIN timesheets ts ON ts.placement_id = p.id AND ts.week_ending = ?
                  LEFT JOIN attendance_payroll_sources src ON src.timesheet_id = ts.id
                  WHERE p.job_id = ?
                    AND (ts.id IS NOT NULL OR EXISTS (SELECT 1 FROM attendance_records a2
                          WHERE a2.placement_id = p.id AND a2.work_date BETWEEN ? AND ?))
                  ORDER BY c.full_name",
                 [$start, $weekEnding, $start, $weekEnding, $start, $weekEnding,
                  $weekEnding, $jobId, $start, $weekEnding]);

    foreach ($rows as &$r) {
        $approved = round((float) $r['approved_hours'], 2);
        $imported = null;

        if ($r['attendance_json'] !== null) {
            $imported = 0.0;

            foreach ((array) json_decode((string) $r['attendance_json'], true) as $day) {
                $imported += (float) ($day['hours'] ?? 0);
            }

            $imported = round($imported, 2);
        }

        $sheet = $r['timesheet_id'] !== null ? round((float) $r['sheet_hours'], 2) : null;
        $frozen = in_array($r['sheet_status'], ['approved', 'paid'], true);

        $r['approved_hours'] = $approved;
        $r['imported_hours'] = $imported;
        $r['sheet_hours'] = $sheet;
        $r['frozen'] = $frozen;
        $r['state'] = match (true) {
            $sheet === null && $approved > 0                       => 'not_on_sheet',
            $sheet === null                                         => 'nothing_approved',
            $imported === null && (int) $r['approved_days'] === 0   => 'typed_without_attendance',
            abs($sheet - $approved) < 0.005                         => 'agrees',
            $frozen                                                 => 'frozen_differs',
            $imported !== null && abs($imported - $approved) >= 0.005 => 'changed_since_import',
            default                                                 => 'sheet_differs',
        };
    }
    unset($r);

    return $rows;
}

/** The reconciliation states, in words. */
function attendance_reconciliation_states(): array
{
    return [
        'agrees'                   => t('The weekly sheet matches the approved days'),
        'not_on_sheet'             => t('Approved days, but no weekly sheet yet - import them'),
        'nothing_approved'         => t('Days submitted, none approved yet'),
        'typed_without_attendance' => t('Hours typed on the sheet with no approved days behind them'),
        'changed_since_import'     => t('Days approved or corrected since the import - import again'),
        'sheet_differs'            => t('The weekly sheet differs from the approved days'),
        'frozen_differs'           => t('Approved for pay with a difference - settle it as an adjustment'),
    ];
}

/**
 * People with hours on more than one assignment in a week, across every
 * project, with their total. Overtime is a weekly fact about a person, not
 * about one assignment, so the total is what the next module rules on.
 */
function attendance_people_on_several_assignments(int $jobId, string $weekEnding): array
{
    $start = date('Y-m-d', strtotime($weekEnding . ' -6 days'));

    // Only people who are on this project: the page is about this job, and
    // somebody who never worked on it is none of its business.
    return rows("SELECT c.id AS candidate_id, c.full_name,
                        COUNT(DISTINCT p.id) AS assignments,
                        SUM(a.hours) AS total_hours,
                        GROUP_CONCAT(DISTINCT j.title ORDER BY j.title SEPARATOR ', ') AS projects
                 FROM attendance_records a
                 JOIN placements p ON p.id = a.placement_id
                 JOIN candidates c ON c.id = p.candidate_id
                 JOIN jobs j ON j.id = p.job_id
                 WHERE a.status = 'approved' AND a.work_date BETWEEN ? AND ?
                   AND EXISTS (SELECT 1 FROM placements mine WHERE mine.candidate_id = c.id AND mine.job_id = ?)
                 GROUP BY c.id, c.full_name
                 HAVING COUNT(DISTINCT p.id) > 1
                 ORDER BY total_hours DESC, c.full_name", [$start, $weekEnding, $jobId]);
}
