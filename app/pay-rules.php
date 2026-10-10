<?php
/**
 * Pay rule sets: storing them, confirming them, and applying them to a week.
 *
 * The life of a set:
 *   draft   - payroll writes it and can change anything in it
 *   active  - an administrator confirmed it, naming who validated the
 *             values (the payroll provider, counsel). It can no longer be
 *             edited: a rule that changes under weeks already worked is how
 *             two people paid for the same week end up on different terms.
 *             To change it, copy it into a new draft.
 *   retired - no project can be given it any more; a project that still
 *             uses it keeps working and is told to move.
 *
 * Holidays are the one exception to "active is fixed": a calendar gets a
 * new year every year, and adding a date is logged.
 */

declare(strict_types=1);

require_once __DIR__ . '/pay-rules-calculation.php';

function pay_rule_statuses(): array
{
    return ['draft' => t('Draft'), 'active' => t('Active'), 'retired' => t('Retired')];
}

function pay_rule_set(int $id): ?array
{
    return $id ? (row('SELECT * FROM pay_rule_sets WHERE id = ?', [$id]) ?: null) : null;
}

function pay_rule_sets(): array
{
    return rows("SELECT s.*, (SELECT COUNT(*) FROM jobs j WHERE j.pay_rule_set_id = s.id) AS projects
                 FROM pay_rule_sets s ORDER BY FIELD(s.status,'active','draft','retired'), s.name");
}

/** The calculation's view of a stored set. */
function pay_rules_of(array $set): array
{
    $n = static fn($v) => $v === null || $v === '' ? null : (float) $v;

    return [
        'weekly_after'       => $n($set['weekly_overtime_after']),
        'weekly_multiplier'  => (float) $set['weekly_overtime_multiplier'],
        'daily_after'        => $n($set['daily_overtime_after']),
        'daily_multiplier'   => (float) $set['daily_overtime_multiplier'],
        'double_after'       => $n($set['daily_double_after']),
        'double_multiplier'  => (float) $set['daily_double_multiplier'],
        'holiday_multiplier' => $n($set['holiday_multiplier']),
    ];
}

/**
 * Validate a draft from a form. Returns [values, refusal|null].
 *
 * @return array{0:array,1:?string}
 */
function pay_rule_set_input(array $in, ?int $exceptId = null): array
{
    $text = static fn(string $k): string => trim((string) ($in[$k] ?? ''));
    $hours = static function (string $k, float $max) use ($text): array {
        $v = $text($k);
        if ($v === '') { return [null, true]; }
        if (! is_numeric($v) || (float) $v < 0 || (float) $v > $max) { return [null, false]; }
        return [round((float) $v, 2), true];
    };
    $mult = static function (string $k, bool $optional) use ($text): array {
        $v = $text($k);
        if ($v === '' && $optional) { return [null, true]; }
        if (! is_numeric($v) || (float) $v < 1 || (float) $v > 5) { return [null, false]; }
        return [round((float) $v, 2), true];
    };

    $name = $text('name');
    $jurisdiction = $text('jurisdiction');

    if (mb_strlen($name) < 3 || mb_strlen($name) > 120) {
        return [[], t('Give the rule set a name of 3 to 120 characters.')];
    }

    if ((int) val('SELECT COUNT(*) FROM pay_rule_sets WHERE name = ? AND id <> ?', [$name, (int) $exceptId])) {
        return [[], t('Another rule set already has that name.')];
    }

    if (mb_strlen($jurisdiction) < 2 || mb_strlen($jurisdiction) > 80) {
        return [[], t('Say which jurisdiction the rules are for, for example a state.')];
    }

    [$weekly, $ok1] = $hours('weekly_overtime_after', 168);
    [$daily, $ok2]  = $hours('daily_overtime_after', 24);
    [$double, $ok3] = $hours('daily_double_after', 24);

    if (! $ok1 || ! $ok2 || ! $ok3) {
        return [[], t('Hour lines are blank, or a number of hours: up to 168 for the week, up to 24 for a day.')];
    }

    if ($daily !== null && $double !== null && $double <= $daily) {
        return [[], t('Double time starts after more hours than daily overtime does.')];
    }

    [$wm, $okA] = $mult('weekly_overtime_multiplier', false);
    [$dm, $okB] = $mult('daily_overtime_multiplier', false);
    [$xm, $okC] = $mult('daily_double_multiplier', false);
    [$hm, $okD] = $mult('holiday_multiplier', true);

    if (! $okA || ! $okB || ! $okC || ! $okD) {
        return [[], t('A multiplier is a number from 1 to 5, for example 1.5.')];
    }

    $notes = $text('notes');

    if (mb_strlen($notes) > 1000) {
        return [[], t('The notes are too long.')];
    }

    return [[
        'name' => $name, 'jurisdiction' => $jurisdiction,
        'weekly_overtime_after' => $weekly, 'weekly_overtime_multiplier' => $wm,
        'daily_overtime_after' => $daily, 'daily_overtime_multiplier' => $dm,
        'daily_double_after' => $double, 'daily_double_multiplier' => $xm,
        'holiday_multiplier' => $hm, 'notes' => $notes !== '' ? $notes : null,
    ], null];
}

/** The shift premium that applies to an assignment under a set, by its shift name. */
function pay_rule_shift_premium(int $setId, ?string $shiftLabel): float
{
    $label = trim((string) $shiftLabel);

    if ($label === '') {
        return 0.0;
    }

    return (float) (val('SELECT amount_per_hour FROM pay_rule_shift_premiums
                         WHERE rule_set_id = ? AND LOWER(TRIM(shift_label)) = LOWER(?)', [$setId, $label]) ?? 0);
}

/**
 * A week under the project's pay rules, or null when the project has none -
 * in which case week_money() pays it exactly as it always has.
 *
 * Reads the approved days behind the sheet, the person's approved hours on
 * other assignments that week, their overtime status and their shift.
 */
function pay_rules_week_for(array $sheet, array $placement, array $job, ?float $guaranteeHours = null): ?array
{
    $setId = (int) ($job['pay_rule_set_id'] ?? 0);
    $placementId = (int) ($sheet['placement_id'] ?? 0);
    $weekEnding = (string) ($sheet['week_ending'] ?? '');

    if (! $setId || ! $placementId || $weekEnding === '') {
        return null;
    }

    $set = pay_rule_set($setId);

    if (! $set || $set['status'] === 'draft') {
        return null;
    }

    $start = date('Y-m-d', strtotime($weekEnding . ' -6 days'));
    $days = [];

    foreach (rows("SELECT work_date, hours FROM attendance_records
                   WHERE placement_id = ? AND status = 'approved' AND work_date BETWEEN ? AND ?
                   ORDER BY work_date", [$placementId, $start, $weekEnding]) as $d) {
        $days[(string) $d['work_date']] = (float) $d['hours'];
    }

    $person = row('SELECT p.candidate_id, d.shift_label, e.employment_type, e.flsa_status, e.salary_per_period
                   FROM placements p
                   LEFT JOIN assignment_details d ON d.placement_id = p.id
                   LEFT JOIN employee_profiles e ON e.candidate_id = p.candidate_id
                   WHERE p.id = ?', [$placementId]) ?: [];

    $other = (float) val("SELECT COALESCE(SUM(a.hours), 0) FROM attendance_records a
                          JOIN placements o ON o.id = a.placement_id
                          WHERE o.candidate_id = ? AND o.id <> ? AND a.status = 'approved'
                            AND a.work_date BETWEEN ? AND ?",
                         [(int) ($person['candidate_id'] ?? 0), $placementId, $start, $weekEnding]);

    $holidays = array_column(rows('SELECT holiday_date FROM pay_rule_holidays
                                   WHERE rule_set_id = ? AND holiday_date BETWEEN ? AND ?',
                                  [$setId, $start, $weekEnding]), 'holiday_date');

    $type = (string) ($person['employment_type'] ?? $placement['employment_type'] ?? 'hourly');
    $salaryValue = $person['salary_per_period'] ?? $placement['salary_per_period'] ?? null;
    $salary = $type === 'salaried' && $salaryValue !== null ? (float) $salaryValue : null;
    $guarantee = $guaranteeHours ?? (float) (($job['strike_live'] ?? 0) ? ($job['strike_hours'] ?? 0) : ($job['guarantee_hours'] ?? 0));
    $rate = (float) ($placement['pay_rate'] ?? $job['pay_rate'] ?? 0);

    $result = pay_rules_week($days, (float) $sheet['hours_worked'], $other, $guarantee, $rate,
                             pay_rules_of($set), array_map('strval', $holidays),
                             pay_rule_shift_premium($setId, $person['shift_label'] ?? null),
                             (string) ($person['flsa_status'] ?? 'not_determined'), $type, $salary);

    return $result + ['rule_set_id' => $setId, 'rule_set_name' => (string) $set['name'],
                      'other_hours' => $other];
}

/** What a review reason means, in words. */
function pay_rule_review_reasons(): array
{
    return [
        'overtime_status_not_determined' => t('Overtime status not yet decided - paid as non-exempt meanwhile'),
        'not_an_employee'                => t('Contractor or another company\'s employee - no overtime computed'),
        'daily_rules_not_applied'        => t('No approved days behind the sheet - only the weekly line was applied'),
        'hours_on_other_assignments'     => t('Hours on another assignment this week - the provider blends the overtime rate'),
        'salaried_overtime'              => t('Salaried with overtime - the provider reconciles it'),
    ];
}
