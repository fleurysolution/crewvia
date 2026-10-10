<?php
/**
 * What a person is entitled to under a leave type, and whether they may
 * use it yet. Pure: no database. Tested in tests/leave_unit.php.
 *
 * Units are days. A type earns them one of two ways:
 *   annual        - a fixed number of days each calendar year
 *   hours_worked  - one day for every N hours worked in the year
 * Unused days carry into the next year up to the type's carryover limit,
 * and only the previous year's own entitlement carries - not what was
 * carried into it. A cap, when set, limits the total available at once.
 *
 * These are the rules as configured. Whether a configuration satisfies a
 * jurisdiction's leave law is for a person to decide.
 */

declare(strict_types=1);

/** Days earned in one year, before carryover. Null means unlimited. */
function leave_grant(array $type, float $hoursWorked): ?float
{
    if (($type['accrual_method'] ?? 'annual') === 'hours_worked') {
        $per = (float) ($type['accrual_hours_per_day'] ?? 0);

        if ($per <= 0) {
            return 0.0;
        }

        // Earned in whole hundredths, rounded down: nobody is credited a
        // fraction of a day they have not finished earning.
        return floor(max(0.0, $hoursWorked) / $per * 100) / 100;
    }

    return ($type['days_allowed'] ?? null) === null ? null : (float) $type['days_allowed'];
}

/**
 * @return array{grant:?float,carryover:float,allowed:?float}
 */
function leave_entitlement(array $type, float $hoursThisYear, float $hoursLastYear, float $takenLastYear): array
{
    $grant = leave_grant($type, $hoursThisYear);

    if ($grant === null) {
        return ['grant' => null, 'carryover' => 0.0, 'allowed' => null];
    }

    $carry = 0.0;
    $limit = (float) ($type['carryover_max_days'] ?? 0);

    if ($limit > 0) {
        $lastGrant = (float) leave_grant($type, $hoursLastYear);
        $carry = min($limit, max(0.0, $lastGrant - $takenLastYear));
    }

    $allowed = $grant + $carry;
    $cap = $type['accrual_cap_days'] ?? null;

    if ($cap !== null && $cap !== '') {
        $allowed = min((float) $cap, $allowed);
    }

    return ['grant' => round($grant, 2), 'carryover' => round($carry, 2), 'allowed' => round($allowed, 2)];
}

/**
 * Why somebody may not take this kind of leave on a date, or null.
 * Codes are named in leave_ineligibility_reasons().
 */
function leave_ineligibility(array $type, string $employmentType, ?string $firstStart, string $onDate): ?string
{
    $allowed = trim((string) ($type['eligible_employment_types'] ?? ''));

    if ($allowed !== '' && ! in_array($employmentType, array_map('trim', explode(',', $allowed)), true)) {
        return 'employment_type';
    }

    $wait = (int) ($type['eligible_after_days'] ?? 0);

    if ($wait > 0) {
        if ($firstStart === null || $firstStart === '') {
            return 'not_started';
        }

        if ((strtotime($onDate) - strtotime($firstStart)) / 86400 < $wait) {
            return 'waiting_period';
        }
    }

    return null;
}

/** Days of a request that fall between two dates, both ends included. */
function leave_days_between(string $starts, string $ends, string $from, string $to): int
{
    $a = max($starts, $from);
    $b = min($ends, $to);

    return $a > $b ? 0 : (int) ((strtotime($b) - strtotime($a)) / 86400) + 1;
}
