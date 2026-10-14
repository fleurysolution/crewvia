<?php
/**
 * P5-M27: a plant's shifts. Each has its start, its end (a night shift ends the
 * next morning) and when the bus leaves the hotels. A worker's assignment points
 * at one; its label and start time are written beside the link, so lateness,
 * roll call and clock-in, which read assignment_details.shift_start, need no change.
 */

declare(strict_types=1);

/** @return list<array{id:int,job_id:int,name:string,starts_at:string,ends_at:string,bus_departs:?string}> */
function shifts_for(int $jobId): array
{
    try {
        return rows('SELECT * FROM project_shifts WHERE job_id = ? ORDER BY sort_order, starts_at', [$jobId]);
    } catch (Throwable $e) {
        return [];   // before the P5-M27 upgrade has run
    }
}

/** The usual two: day 06:00-18:00 with the bus at 05:00, night 18:00-06:00 with the bus at 17:00. */
function shifts_seed_defaults(int $jobId): int
{
    $added = 0;
    foreach ([['Day', '06:00:00', '18:00:00', '05:00:00', 1], ['Night', '18:00:00', '06:00:00', '17:00:00', 2]] as [$name, $start, $end, $bus, $order]) {
        if (! val('SELECT id FROM project_shifts WHERE job_id = ? AND name = ?', [$jobId, $name])) {
            q('INSERT INTO project_shifts (job_id, name, starts_at, ends_at, bus_departs, sort_order) VALUES (?,?,?,?,?,?)', [$jobId, $name, $start, $end, $bus, $order]);
            $added++;
        }
    }

    return $added;
}

/** A time typed as HH:MM (24 hours), or null. */
function shift_time(?string $value): ?string
{
    $v = trim((string) $value);

    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $v) ? $v . ':00' : null;
}

/**
 * Saves a shift on a plant. Returns an error message, or null.
 * A shift ending at or before its start ends the next day (a night shift).
 */
function shift_save(int $jobId, ?int $shiftId, array $input): ?string
{
    $name = trim((string) ($input['name'] ?? ''));
    $start = shift_time($input['starts_at'] ?? '');
    $end = shift_time($input['ends_at'] ?? '');
    $bus = trim((string) ($input['bus_departs'] ?? '')) === '' ? null : shift_time($input['bus_departs']);
    if ($name === '' || mb_strlen($name) > 60 || ! $start || ! $end || (trim((string) ($input['bus_departs'] ?? '')) !== '' && ! $bus)) {
        return t('A shift needs a name and its start and end as hours, like 06:00 and 18:00; the bus time too, if given.');
    }
    $clash = val('SELECT id FROM project_shifts WHERE job_id = ? AND name = ? AND id <> ?', [$jobId, $name, (int) $shiftId]);
    if ($clash) {
        return t('This plant already has a shift called :name.', ['name' => $name]);
    }
    if ($shiftId) {
        q('UPDATE project_shifts SET name = ?, starts_at = ?, ends_at = ?, bus_departs = ? WHERE id = ? AND job_id = ?', [$name, $start, $end, $bus, $shiftId, $jobId]);
        // Whoever works it keeps its label and start time.
        q('UPDATE assignment_details SET shift_label = ?, shift_start = ? WHERE shift_id = ?', [$name, $start, $shiftId]);
    } else {
        $order = (int) val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM project_shifts WHERE job_id = ?', [$jobId]);
        q('INSERT INTO project_shifts (job_id, name, starts_at, ends_at, bus_departs, sort_order) VALUES (?,?,?,?,?,?)', [$jobId, $name, $start, $end, $bus, $order]);
    }

    return null;
}

/** One of the plant's shifts, or null. */
function shift_of(int $jobId, int $shiftId): ?array
{
    return row('SELECT * FROM project_shifts WHERE id = ? AND job_id = ?', [$shiftId, $jobId]);
}

/** "06:00 - 18:00, bus 05:00", as a person reads it. */
function shift_hours(array $shift): string
{
    $h = static fn(?string $t) => $t ? substr($t, 0, 5) : '';
    $text = $h($shift['starts_at']) . '–' . $h($shift['ends_at']);
    if ($shift['ends_at'] <= $shift['starts_at']) {
        $text .= ' ' . t('(next day)');
    }

    return $shift['bus_departs'] ? $text . ' · ' . t('bus :time', ['time' => $h($shift['bus_departs'])]) : $text;
}
