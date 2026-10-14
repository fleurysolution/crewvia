<?php
/**
 * P5-M34: what an absence does to the weekly guarantee, and whether the
 * guaranteed hours above the overtime threshold are paid as overtime.
 *
 * The owner's terms, 14 Oct 2026: 50 hours guaranteed, paid as 40 regular
 * and 10 overtime, plus the per diem; an absence policy decides what is left
 * of the guarantee when a worker does not come. Each project chooses:
 *   keep        the guarantee stands whatever happens (as before);
 *   reduce_day  each unexcused absence takes one day's hours off it;
 *   forfeit     one unexcused absence and the week is paid as worked.
 * An absence is a day the worker was marked absent at roll call, or clocked
 * in and not seen on site, that no approved time off covers.
 */

declare(strict_types=1);

const GUARANTEE_ABSENCE_RULES = ['keep' => 'The guarantee stands', 'reduce_day' => 'Each unexcused absence takes a day off the guarantee', 'forfeit' => 'An unexcused absence loses the week\'s guarantee'];

/** Unexcused absences in the week ending on this Saturday. */
function gp_unexcused_absences(int $placementId, string $weekEnding): int
{
    $start = date('Y-m-d', strtotime($weekEnding . ' -6 days'));

    return (int) val("SELECT COUNT(DISTINCT a.work_date) FROM assignment_checkins a
                      WHERE a.placement_id = ? AND a.present = 0 AND a.work_date BETWEEN ? AND ?
                        AND NOT EXISTS (SELECT 1 FROM time_off_requests r WHERE r.placement_id = a.placement_id AND r.status = 'approved' AND a.work_date BETWEEN r.starts_on AND r.ends_on)",
                     [$placementId, $start, $weekEnding]);
}

/** The guarantee left after the absences, under the project's rule. */
function gp_guarantee_after(float $guarantee, int $absences, string $rule, float $dayHours): float
{
    if ($absences <= 0 || $rule === 'keep') {
        return $guarantee;
    }
    if ($rule === 'forfeit') {
        return 0.0;
    }

    return max(0.0, $guarantee - $absences * max(0.0, $dayHours));
}
