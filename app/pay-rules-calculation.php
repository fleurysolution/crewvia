<?php
/**
 * Gross pay for one assignment's week, under a project's pay rules.
 *
 * Pure: no database, no session. Everything it needs is passed in, so the
 * arithmetic is tested on its own (tests/pay_rules_unit.php).
 *
 * Nothing about the law is decided here. The thresholds, multipliers,
 * holidays and premiums are a rule set somebody configured and confirmed
 * for the jurisdiction. This only applies them, in one fixed order, and
 * says when the result needs a person - the payroll provider - to check it.
 *
 * Order, so nothing is paid twice (no pyramiding):
 *   1. each day: hours over the double-time line, then over the daily
 *      overtime line; the rest of the day is regular
 *   2. the week: regular hours beyond the weekly line become overtime -
 *      counting the person's hours on other assignments that week first,
 *      because the line is a fact about the person, not the assignment
 *   3. holidays: regular hours on a holiday are paid at the holiday rate;
 *      hours that are already overtime are not paid a second premium
 *   4. the guarantee tops paid hours up to its floor, at the base rate;
 *      it never creates overtime
 *   5. a shift premium is part of the hourly rate, so overtime is paid on it
 */

declare(strict_types=1);

/**
 * @param array<string,float> $days        date => hours worked on this assignment, approved
 * @param float               $weekHours   hours on the weekly sheet (may differ from the days)
 * @param float               $otherHours  approved hours on the person's other assignments that week
 * @param array{weekly_after:?float,weekly_multiplier:float,daily_after:?float,daily_multiplier:float,
 *              double_after:?float,double_multiplier:float,holiday_multiplier:?float} $rules
 * @param string[]            $holidays    dates
 * @param string              $flsa        non_exempt | exempt | not_applicable | not_determined
 */
function pay_rules_week(array $days, float $weekHours, float $otherHours, float $guarantee, float $rate,
                        array $rules, array $holidays, float $shiftPremium, string $flsa,
                        string $employmentType, ?float $salary): array
{
    foreach ([$weekHours, $otherHours, $guarantee, $rate, $shiftPremium] as $n) {
        if (! is_finite($n) || $n < 0) {
            throw new InvalidArgumentException('Invalid payroll inputs.');
        }
    }

    foreach ($days as $date => $hours) {
        if (! is_finite((float) $hours) || $hours < 0 || $hours > 24 || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            throw new InvalidArgumentException('Invalid day.');
        }
    }

    foreach (['weekly_multiplier', 'daily_multiplier', 'double_multiplier'] as $k) {
        if (! isset($rules[$k]) || ! is_finite((float) $rules[$k]) || $rules[$k] < 1 || $rules[$k] > 5) {
            throw new InvalidArgumentException('Invalid payroll policy.');
        }
    }

    $reasons = [];
    $overtimeLaw = in_array($flsa, ['non_exempt', 'not_determined'], true);

    if ($flsa === 'not_determined') {
        // Paid as non-exempt, which never under-pays, until somebody decides.
        $reasons[] = 'overtime_status_not_determined';
    }

    if (in_array($employmentType, ['contractor', 'external'], true)) {
        $overtimeLaw = false;
        $reasons[] = 'not_an_employee';
    }

    $dayTotal = round(array_sum(array_map('floatval', $days)), 2);
    $useDays = $days !== [] && abs($dayTotal - $weekHours) < 0.005;

    if (! $useDays && ($rules['daily_after'] !== null || $rules['double_after'] !== null || $holidays)) {
        // The sheet was typed, or differs from the approved days: there is
        // no honest way to know which day the hours fell on.
        $reasons[] = 'daily_rules_not_applied';
    }

    $double = $daily = $regular = $holidayRegular = 0.0;
    $holidaySet = array_flip($holidays);

    if ($useDays && $overtimeLaw) {
        foreach ($days as $date => $h) {
            $h = (float) $h;
            $dt = $rules['double_after'] !== null ? max(0.0, $h - (float) $rules['double_after']) : 0.0;
            $rest = $h - $dt;
            $ot = $rules['daily_after'] !== null ? max(0.0, $rest - (float) $rules['daily_after']) : 0.0;
            $double += $dt;
            $daily += $ot;
            $regular += $rest - $ot;

            if (isset($holidaySet[$date])) {
                $holidayRegular += $rest - $ot;
            }
        }
    } else {
        $regular = $useDays ? $dayTotal : $weekHours;

        if ($useDays) {
            foreach ($days as $date => $h) {
                if (isset($holidaySet[$date])) {
                    $holidayRegular += (float) $h;
                }
            }
        }
    }

    $weekly = 0.0;

    if ($overtimeLaw && $rules['weekly_after'] !== null) {
        $line = (float) $rules['weekly_after'];
        $weekly = max(0.0, $otherHours + $regular - $line) - max(0.0, $otherHours - $line);
        $weekly = min($regular, $weekly);
        $regular -= $weekly;
        // Weekly overtime is taken from the non-holiday hours first, so a
        // holiday is not paid a holiday premium and an overtime premium.
        $holidayRegular = min($holidayRegular, $regular);
    }

    if ($otherHours > 0 && $overtimeLaw && $rules['weekly_after'] !== null) {
        // Two assignments at two rates make the legal overtime rate a
        // blended one; that is the provider's calculation, not ours.
        $reasons[] = 'hours_on_other_assignments';
    }

    $worked = $useDays ? $dayTotal : $weekHours;
    $topUp = max(0.0, $guarantee - $worked);
    $hourly = $rate + $shiftPremium;
    $holidayMultiplier = $rules['holiday_multiplier'] !== null ? (float) $rules['holiday_multiplier'] : 1.0;

    if ($salary !== null) {
        if ($daily + $weekly + $double > 0) {
            $reasons[] = 'salaried_overtime';
        }
        $cost = $salary;
    } else {
        $cost = ($regular - $holidayRegular) * $hourly
              + $holidayRegular * $hourly * $holidayMultiplier
              + ($daily * (float) $rules['daily_multiplier'] + $weekly * (float) $rules['weekly_multiplier']) * $hourly
              + $double * (float) $rules['double_multiplier'] * $hourly
              + $topUp * $rate;
    }

    $overtime = $daily + $weekly;

    return [
        'method'              => 'pay_rules',
        'regular_hours'       => round($regular + $topUp, 2),
        'overtime_hours'      => round($overtime, 2),
        'daily_overtime_hours'  => round($daily, 2),
        'weekly_overtime_hours' => round($weekly, 2),
        'double_hours'        => round($double, 2),
        'holiday_hours'       => round($holidayRegular, 2),
        'guarantee_topup'     => round($topUp, 2),
        'overtime_multiplier' => (float) $rules['weekly_multiplier'],
        'daily_multiplier'    => (float) $rules['daily_multiplier'],
        'double_multiplier'   => (float) $rules['double_multiplier'],
        'holiday_multiplier'  => $holidayMultiplier,
        'shift_premium'       => $shiftPremium,
        'labour_cost'         => round($cost, 2),
        'salary_basis'        => $salary,
        'requires_provider_review' => in_array('salaried_overtime', $reasons, true)
                                   || in_array('hours_on_other_assignments', $reasons, true),
        'review_reasons'      => array_values(array_unique($reasons)),
    ];
}
