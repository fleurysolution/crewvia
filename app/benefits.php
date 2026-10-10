<?php
/**
 * Benefits (P2-M04): plans, who is eligible and from when, enrollment and
 * waivers with effective dates, what the person pays and what the agency
 * adds - and payroll taking both with nothing else to set.
 *
 *   - a plan owns two pay items (P1-M05): the person's deduction (before
 *     tax, unless the plan says otherwise) and the agency's contribution.
 *     A fixed plan costs so much a week per coverage level; a percent plan
 *     lets the person elect a share of gross, and the agency adds its own.
 *   - eligibility: the person's kind of employment is one the plan covers,
 *     and the waiting period has passed since their first assignment began.
 *   - enrolling puts both pay items on the person from the coverage date.
 *     Ending sets their end. Gross to net does the rest, week by week.
 *   - an enrollment is never edited. A change of coverage (a marriage, a
 *     child, another election) ends one the day before and starts the next.
 *     A waiver - coverage offered and declined - is kept with its reason.
 *   - nothing reaches into a week already approved for pay: that is an
 *     adjustment in an open pay period (P1-M06).
 *   - the pay items an enrollment put on someone are changed here, not by
 *     hand on the employee folder.
 */

declare(strict_types=1);

require_once __DIR__ . '/gross-to-net.php';
require_once __DIR__ . '/classification.php';

function benefit_kinds(): array
{
    return ['medical' => t('Medical'), 'dental' => t('Dental'), 'vision' => t('Vision'), 'life' => t('Life insurance'),
            'disability' => t('Disability'), 'retirement' => t('Retirement'), 'other' => t('Other')];
}

function benefit_tiers(): array
{
    return ['employee' => t('Employee only'), 'employee_spouse' => t('Employee and spouse'),
            'employee_children' => t('Employee and children'), 'family' => t('Family')];
}

function benefit_plans(bool $activeOnly = true): array
{
    $plans = rows('SELECT p.*, (SELECT COUNT(*) FROM benefit_enrollments e WHERE e.plan_id = p.id AND e.status = \'enrolled\') AS enrolled
                   FROM benefit_plans p' . ($activeOnly ? ' WHERE p.is_active = 1' : '') . ' ORDER BY p.is_active DESC, p.kind, p.name');
    foreach ($plans as &$p) {
        $p['tiers'] = [];
        foreach (rows('SELECT * FROM benefit_plan_tiers WHERE plan_id = ?', [(int) $p['id']]) as $t) {
            $p['tiers'][$t['tier']] = $t;
        }
    }
    unset($p);

    return $plans;
}

/** Is this pay item one a plan owns? Then it is not set by hand. */
function benefit_owns_pay_item(int $payItemId): bool
{
    return (bool) val('SELECT COUNT(*) FROM benefit_plans WHERE deduction_item_id = ? OR employer_item_id = ?', [$payItemId, $payItemId]);
}

/** Create a plan and its two pay items. Returns [id, refusal]. */
function benefit_plan_create(array $in): array
{
    $name = trim((string) ($in['name'] ?? ''));
    $code = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
    $kind = (string) ($in['kind'] ?? '');
    $method = (string) ($in['method'] ?? 'fixed');
    $types = array_values(array_intersect(array_keys(employment_types()), is_array($in['eligible_types'] ?? null) ? $in['eligible_types'] : []));
    $wait = trim((string) ($in['waiting_days'] ?? '0'));

    if (mb_strlen($name) < 3 || mb_strlen($name) > 120 || $code === '' || strlen($code) > 30) {
        return [0, t('Name the plan in 3 to 120 characters.')];
    }
    if (val('SELECT COUNT(*) FROM benefit_plans WHERE code = ?', [$code])) {
        return [0, t('A plan with that name already exists.')];
    }
    if (! isset(benefit_kinds()[$kind]) || ! in_array($method, ['fixed', 'percent'], true)) {
        return [0, t('Choose what the plan is and how it is paid for.')];
    }
    if (! $types) {
        return [0, t('Say which kinds of employment the plan covers.')];
    }
    if (! ctype_digit($wait) || (int) $wait > 365) {
        return [0, t('A waiting period is 0 to 365 days.')];
    }

    $tiers = [];
    $employerPct = $maxPct = null;
    if ($method === 'fixed') {
        foreach (array_keys(benefit_tiers()) as $tier) {
            $ee = trim((string) ($in['employee_amount'][$tier] ?? ''));
            $er = trim((string) ($in['employer_amount'][$tier] ?? ''));
            if ($ee === '' && $er === '') {
                continue;
            }
            if (! is_numeric($ee) || ! is_numeric($er) || (float) $ee < 0 || (float) $er < 0 || (float) $ee > 10000 || (float) $er > 10000) {
                return [0, t('A weekly cost is 0 to 10,000, for the person and for the agency.')];
            }
            $tiers[$tier] = [round((float) $ee, 2), round((float) $er, 2)];
        }
        if (! isset($tiers['employee'])) {
            return [0, t('A plan has at least the employee-only cost.')];
        }
    } else {
        $employerPct = trim((string) ($in['employer_percent'] ?? ''));
        $maxPct = trim((string) ($in['max_employee_percent'] ?? ''));
        if (! is_numeric($employerPct) || (float) $employerPct < 0 || (float) $employerPct > 25 || ! is_numeric($maxPct) || (float) $maxPct <= 0 || (float) $maxPct > 100) {
            return [0, t('The agency adds 0 to 25 % of gross; the person elects up to a limit above 0 and at most 100 %.')];
        }
    }

    $itemMethod = $method === 'fixed' ? 'fixed' : 'percent_of_gross';
    $order = (int) val('SELECT COALESCE(MAX(sort_order), 0) FROM pay_items') + 1;
    q("INSERT INTO pay_items (code, label, side, method, pre_tax, sort_order, created_by) VALUES (?,?, 'deduction', ?,?,?,?)",
      ['ben_' . $code . '_ee', $name . ' (employee)', $itemMethod, ! empty($in['pre_tax']) ? 1 : 0, $order, uid() ?: null]);
    $ded = (int) db()->lastInsertId();
    q("INSERT INTO pay_items (code, label, side, method, pre_tax, sort_order, created_by) VALUES (?,?, 'employer_contribution', ?, 0, ?, ?)",
      ['ben_' . $code . '_er', $name . ' (employer)', $itemMethod, $order + 1, uid() ?: null]);
    $emp = (int) db()->lastInsertId();
    q('INSERT INTO benefit_plans (code, name, kind, provider, method, employer_percent, max_employee_percent, pre_tax, eligible_types, waiting_days, deduction_item_id, employer_item_id, created_by)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [$code, $name, $kind, mb_substr(trim((string) ($in['provider'] ?? '')), 0, 190) ?: null, $method, $employerPct !== null ? round((float) $employerPct, 2) : null,
       $maxPct !== null ? round((float) $maxPct, 2) : null, ! empty($in['pre_tax']) ? 1 : 0, implode(',', $types), (int) $wait, $ded, $emp, uid() ?: null]);
    $id = (int) db()->lastInsertId();
    foreach ($tiers as $tier => [$ee, $er]) {
        q('INSERT INTO benefit_plan_tiers (plan_id, tier, employee_amount, employer_amount) VALUES (?,?,?,?)', [$id, $tier, $ee, $er]);
    }

    return [$id, null];
}

/** The day the person's first assignment began, or null. */
function benefit_hire_date(int $candidateId): ?string
{
    $d = val("SELECT MIN(start_date) FROM placements WHERE candidate_id = ? AND status <> 'cancelled' AND start_date IS NOT NULL", [$candidateId]);

    return $d ? (string) $d : null;
}

/** From when the person may join the plan. Returns [date, refusal]. */
function benefit_eligible_from(int $candidateId, array $plan): array
{
    $type = (string) (val('SELECT employment_type FROM employee_profiles WHERE candidate_id = ?', [$candidateId]) ?: 'hourly');
    if (! in_array($type, explode(',', (string) $plan['eligible_types']), true)) {
        return [null, t('This plan does not cover :type.', ['type' => mb_strtolower(employment_types()[$type] ?? $type)])];
    }
    $hired = benefit_hire_date($candidateId);
    if (! $hired) {
        return [null, t('This person has no assignment yet: the waiting period has not begun.')];
    }

    return [date('Y-m-d', strtotime($hired . ' +' . (int) $plan['waiting_days'] . ' days')), null];
}

/** The last week already approved for pay for this person, or null. */
function benefit_frozen_week(int $candidateId): ?string
{
    $w = val("SELECT MAX(t.week_ending) FROM timesheets t JOIN placements p ON p.id = t.placement_id WHERE p.candidate_id = ? AND t.status IN ('approved','paid')", [$candidateId]);

    return $w ? (string) $w : null;
}

/** The person's enrollment or waiver in force for a plan on or after a date, if any. */
function benefit_current(int $candidateId, int $planId, string $on): ?array
{
    return row("SELECT * FROM benefit_enrollments WHERE candidate_id = ? AND plan_id = ? AND status IN ('enrolled','waived','ended')
                AND (ends_on IS NULL OR ends_on >= ?) ORDER BY id DESC LIMIT 1", [$candidateId, $planId, $on]) ?: null;
}

/** Enroll, or record a waiver. The caller holds the transaction. Returns [id, refusal]. */
function benefit_enroll(int $candidateId, int $planId, array $in, bool $waive): array
{
    $plan = row('SELECT * FROM benefit_plans WHERE id = ? AND is_active = 1', [$planId]);
    if (! $plan) {
        return [0, t('Choose a plan in use.')];
    }
    $start = (string) ($in['starts_on'] ?? '');
    if (! valid_date($start)) {
        return [0, t('Give the date coverage starts.')];
    }
    [$from, $why] = benefit_eligible_from($candidateId, $plan);
    if ($why !== null) {
        return [0, $why];
    }
    if ($start < $from) {
        return [0, t('This person is eligible from :date.', ['date' => d($from)])];
    }
    if (benefit_current($candidateId, $planId, $start)) {
        return [0, t('This person already has an enrollment or a waiver for this plan from that date. End it first.')];
    }
    $frozen = benefit_frozen_week($candidateId);
    if (! $waive && $frozen && week_ending($start) <= $frozen) {
        return [0, t('Pay for the week ending :date is already approved. Start coverage after it, and settle the difference with an adjustment.', ['date' => d($frozen)])];
    }
    $reason = trim((string) ($in['reason'] ?? ''));

    if ($waive) {
        if (mb_strlen($reason) < 3) {
            return [0, t('Say why the coverage is declined.')];
        }
        q("INSERT INTO benefit_enrollments (candidate_id, plan_id, status, starts_on, reason, created_by) VALUES (?,?, 'waived', ?,?,?)",
          [$candidateId, $planId, $start, mb_substr($reason, 0, 500), uid() ?: null]);

        return [(int) db()->lastInsertId(), null];
    }

    $tier = null;
    $pct = null;
    if ($plan['method'] === 'fixed') {
        $tier = (string) ($in['tier'] ?? '');
        $cost = row('SELECT * FROM benefit_plan_tiers WHERE plan_id = ? AND tier = ?', [$planId, $tier]);
        if (! $cost) {
            return [0, t('Choose a coverage level the plan offers.')];
        }
        [$ee, $er] = [(float) $cost['employee_amount'], (float) $cost['employer_amount']];
    } else {
        $pct = trim((string) ($in['employee_percent'] ?? ''));
        if (! is_numeric($pct) || (float) $pct <= 0 || (float) $pct > (float) $plan['max_employee_percent']) {
            return [0, t('Elect above 0 and up to :max % of gross.', ['max' => rtrim(rtrim((string) $plan['max_employee_percent'], '0'), '.')])];
        }
        [$ee, $er] = [round((float) $pct, 2), (float) $plan['employer_percent']];
    }

    q("INSERT INTO benefit_enrollments (candidate_id, plan_id, status, tier, employee_percent, employee_amount, employer_amount, starts_on, reason, created_by)
       VALUES (?,?, 'enrolled', ?,?,?,?,?,?,?)", [$candidateId, $planId, $tier, $pct !== null ? round((float) $pct, 2) : null, $ee, $er, $start, mb_substr($reason, 0, 500) ?: null, uid() ?: null]);
    $id = (int) db()->lastInsertId();
    foreach ([[(int) $plan['deduction_item_id'], $ee], [(int) $plan['employer_item_id'], $er]] as [$item, $amount]) {
        if ($amount > 0) {
            q('INSERT INTO employee_pay_items (candidate_id, pay_item_id, amount, starts_on, note, created_by, benefit_enrollment_id) VALUES (?,?,?,?,?,?,?)',
              [$candidateId, $item, $amount, $start, mb_substr($plan['name'], 0, 255), uid() ?: null, $id]);
        }
    }

    return [$id, null];
}

/** End an enrollment or waiver on a date. The caller holds the transaction. */
function benefit_end(int $enrollmentId, string $endsOn, string $reason): ?string
{
    $e = row("SELECT * FROM benefit_enrollments WHERE id = ? AND ends_on IS NULL AND status IN ('enrolled','waived') FOR UPDATE", [$enrollmentId]);
    if (! $e) {
        return t('That coverage is not running.');
    }
    if (! valid_date($endsOn) || $endsOn < $e['starts_on']) {
        return t('Give an end date on or after the day it started.');
    }
    if (mb_strlen(trim($reason)) < 3) {
        return t('Say why it ends.');
    }
    // The week the end falls in is still covered; weeks after it are not. None of those may already be paid.
    $frozen = benefit_frozen_week((int) $e['candidate_id']);
    if ($e['status'] === 'enrolled' && $frozen && $frozen > week_ending($endsOn)) {
        return t('Pay for the week ending :date is already approved with this deduction. End it after that week, and settle the difference with an adjustment.', ['date' => d($frozen)]);
    }
    q("UPDATE benefit_enrollments SET status = 'ended', ends_on = ?, end_reason = ?, ended_by = ?, ended_at = NOW() WHERE id = ?",
      [$endsOn, mb_substr(trim($reason), 0, 500), uid() ?: null, $enrollmentId]);
    q('UPDATE employee_pay_items SET ends_on = ? WHERE benefit_enrollment_id = ? AND ends_on IS NULL', [$endsOn, $enrollmentId]);

    return null;
}

/** A change of coverage: end the current one the day before, start the next. */
function benefit_change(int $enrollmentId, array $in): array
{
    $e = row("SELECT * FROM benefit_enrollments WHERE id = ? AND status = 'enrolled' AND ends_on IS NULL", [$enrollmentId]);
    if (! $e) {
        return [0, t('That coverage is not running.')];
    }
    $start = (string) ($in['starts_on'] ?? '');
    if (! valid_date($start) || $start <= $e['starts_on']) {
        return [0, t('A change takes effect after the current coverage started.')];
    }
    if (mb_strlen(trim((string) ($in['reason'] ?? ''))) < 3) {
        return [0, t('Say what changed: for example, a marriage or a birth.')];
    }
    if ($why = benefit_end($enrollmentId, date('Y-m-d', strtotime($start . ' -1 day')), 'Changed: ' . trim((string) $in['reason']))) {
        return [0, $why];
    }

    return benefit_enroll((int) $e['candidate_id'], (int) $e['plan_id'], $in, false);
}

/** Every person currently on assignment, with where they stand on each plan. */
function benefit_eligibility(array $plans): array
{
    $out = [];
    foreach (rows("SELECT DISTINCT c.id, c.full_name, COALESCE(e.employment_type, 'hourly') AS employment_type FROM candidates c
                   JOIN placements p ON p.candidate_id = c.id LEFT JOIN employee_profiles e ON e.candidate_id = c.id
                   WHERE p.status IN ('confirmed','travelling','on_site') ORDER BY c.full_name") as $person) {
        foreach ($plans as $plan) {
            $current = benefit_current((int) $person['id'], (int) $plan['id'], date('Y-m-d'));
            [$from, $why] = benefit_eligible_from((int) $person['id'], $plan);
            if ($current && $current['status'] !== 'ended') {
                continue;
            }
            if ($why === null) {
                $out[] = ['candidate_id' => (int) $person['id'], 'name' => $person['full_name'], 'plan' => $plan, 'from' => $from, 'due' => $from <= date('Y-m-d')];
            }
        }
    }

    return $out;
}
