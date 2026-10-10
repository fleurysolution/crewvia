<?php
/**
 * Grades, pay bands, and pay changes that take effect on a date (P2-M01).
 *
 * A change - of salary, of an assignment's hourly rate, or of grade - is
 * recorded with the date it takes effect and why. It applies to every week
 * ending on or after that date, so a raise agreed on a Wednesday pays the
 * whole of that week. It cannot reach back into a week already approved
 * for pay: that is an adjustment in an open pay period (P1-M06).
 *
 * A grade carries a band. Pay set outside the band of the person's grade
 * is allowed - sometimes the market says so - but only by an
 * administrator, and the change is marked as outside the band.
 *
 * Until its date comes a change can be cancelled. The current values that
 * the rest of the application reads (the salary on the profile, the rate
 * on the assignment, the grade) are brought up to date when a page that
 * reads them is opened; the pay calculation itself always asks for the
 * value on the week, so nothing is paid on a stale figure meanwhile.
 */

declare(strict_types=1);

function compensation_kinds(): array
{
    return ['salary' => t('Salary per pay period'), 'hourly_rate' => t('Hourly rate on an assignment'), 'grade' => t('Grade')];
}

function pay_grades(bool $activeOnly = true): array
{
    try {
        return rows('SELECT * FROM pay_grades' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, label');
    } catch (Throwable $e) {
        return [];
    }
}

/** The latest change of a kind in effect for a week. */
function compensation_latest(string $kind, string $column, int $id, string $onDate): ?array
{
    try {
        return row("SELECT * FROM compensation_changes WHERE kind = ? AND `$column` = ? AND effective_from <= ? AND cancelled_at IS NULL
                    ORDER BY effective_from DESC, id DESC LIMIT 1", [$kind, $id, $onDate]) ?: null;
    } catch (Throwable $e) {
        return null;    // before the P2-M01 upgrade has run
    }
}

/**
 * The amount in effect for a week, from the dated changes alone, or null
 * when no change says anything about it.
 *
 * Once a change is in effect the assignment or profile already holds the
 * new figure, so a week *before* the first change cannot fall back to it:
 * that week is paid the first later change's "before" amount.
 */
function compensation_amount_on(string $kind, string $column, int $id, string $weekEnding): ?float
{
    $c = compensation_latest($kind, $column, $id, $weekEnding);

    if ($c) {
        return (float) $c['new_amount'];
    }

    try {
        $later = row("SELECT old_amount FROM compensation_changes WHERE kind = ? AND `$column` = ? AND effective_from > ? AND cancelled_at IS NULL
                      ORDER BY effective_from, id LIMIT 1", [$kind, $id, $weekEnding]);
    } catch (Throwable $e) {
        return null;
    }

    return $later && $later['old_amount'] !== null ? (float) $later['old_amount'] : null;
}

/** The hourly rate the dated changes set for an assignment's week, or null. */
function compensation_rate_on(int $placementId, string $weekEnding): ?float
{
    return compensation_amount_on('hourly_rate', 'placement_id', $placementId, $weekEnding);
}

/** The salary the dated changes set for a person's week, or null. */
function compensation_salary_on(int $candidateId, string $weekEnding): ?float
{
    return compensation_amount_on('salary', 'candidate_id', $candidateId, $weekEnding);
}

/** The person's grade on a date, or null. */
function compensation_grade_on(int $candidateId, string $onDate): ?array
{
    $c = compensation_latest('grade', 'candidate_id', $candidateId, $onDate);
    $id = $c ? (int) $c['grade_id'] : (int) (val('SELECT grade_id FROM employee_profiles WHERE candidate_id = ?', [$candidateId]) ?? 0);

    return $id ? (row('SELECT * FROM pay_grades WHERE id = ?', [$id]) ?: null) : null;
}

/** Bring the current values up to the changes whose date has come. */
function compensation_apply_due(?int $candidateId = null): void
{
    try {
        $due = rows('SELECT * FROM compensation_changes WHERE applied_at IS NULL AND cancelled_at IS NULL AND effective_from <= CURDATE()'
                    . ($candidateId ? ' AND candidate_id = ?' : '') . ' ORDER BY effective_from, id', $candidateId ? [$candidateId] : []);
    } catch (Throwable $e) {
        return;
    }

    foreach ($due as $c) {
        match ($c['kind']) {
            'salary'      => q('UPDATE employee_profiles SET salary_per_period = ? WHERE candidate_id = ?', [$c['new_amount'], (int) $c['candidate_id']]),
            'hourly_rate' => q('UPDATE placements SET pay_rate = ? WHERE id = ?', [$c['new_amount'], (int) $c['placement_id']]),
            'grade'       => q('UPDATE employee_profiles SET grade_id = ? WHERE candidate_id = ?', [(int) $c['grade_id'], (int) $c['candidate_id']]),
        };
        q('UPDATE compensation_changes SET applied_at = NOW() WHERE id = ? AND applied_at IS NULL', [(int) $c['id']]);
    }
}

/** Is an amount outside a grade's band for this kind of pay? */
function compensation_outside_band(?array $grade, string $kind, float $amount): bool
{
    if (! $grade) {
        return false;
    }

    [$min, $max] = $kind === 'salary' ? [$grade['salary_min'], $grade['salary_max']] : [$grade['rate_min'], $grade['rate_max']];

    return ($min !== null && $amount < (float) $min) || ($max !== null && $amount > (float) $max);
}

/**
 * Record a change. Returns the refusal, or null. The caller owns the
 * transaction and has checked the person may record pay at all.
 */
function compensation_record(int $candidateId, array $in, int $userId, bool $isAdmin): ?string
{
    $kind = (string) ($in['kind'] ?? '');
    $effective = trim((string) ($in['effective_from'] ?? ''));
    $reason = trim((string) ($in['reason'] ?? ''));
    $placementId = (int) ($in['placement_id'] ?? 0);

    if (! array_key_exists($kind, compensation_kinds())) {
        return t('Choose what changes: salary, an hourly rate or the grade.');
    }

    if (! valid_date($effective)) {
        return t('Give the date the change takes effect.');
    }

    if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
        return t('Say why pay changes, in up to 500 characters.');
    }

    $amount = null;
    $gradeId = null;
    $old = null;

    if ($kind === 'grade') {
        $gradeId = (int) ($in['grade_id'] ?? 0);
        if (! val('SELECT COUNT(*) FROM pay_grades WHERE id = ? AND is_active = 1', [$gradeId])) {
            return t('Choose a grade from the list.');
        }
        if ((int) (compensation_grade_on($candidateId, $effective)['id'] ?? 0) === $gradeId) {
            return t('That is already the grade on that date.');
        }
    } else {
        $given = trim((string) ($in['amount'] ?? ''));
        $ceiling = $kind === 'salary' ? 1000000 : 1000;

        if (! is_numeric($given) || (float) $given <= 0 || (float) $given > $ceiling) {
            return $kind === 'salary' ? t('A salary per pay period is above 0 and at most 1,000,000.') : t('An hourly rate is above 0 and at most 1,000.');
        }

        $amount = round((float) $given, 2);
    }

    if ($kind === 'hourly_rate') {
        $placement = row('SELECT * FROM placements WHERE id = ? AND candidate_id = ?', [$placementId, $candidateId]);

        if (! $placement || in_array($placement['status'], ['completed', 'cancelled'], true)) {
            return t('Choose one of this person\'s current assignments.');
        }

        $old = compensation_rate_on($placementId, $effective) ?? ($placement['pay_rate'] !== null ? (float) $placement['pay_rate'] : null);
        $frozen = val("SELECT MAX(week_ending) FROM timesheets WHERE placement_id = ? AND status IN ('approved','paid')", [$placementId]);
    } else {
        $placementId = 0;
        if ($kind === 'salary') {
            $old = compensation_salary_on($candidateId, $effective)
                ?? (($s = val('SELECT salary_per_period FROM employee_profiles WHERE candidate_id = ?', [$candidateId])) !== null ? (float) $s : null);
        }
        $frozen = $kind === 'grade' ? null : val("SELECT MAX(t.week_ending) FROM timesheets t JOIN placements p ON p.id = t.placement_id
                                                    WHERE p.candidate_id = ? AND t.status IN ('approved','paid')", [$candidateId]);
    }

    // A change reaches every week ending on or after its date. The first
    // such week must not be one already approved.
    if ($frozen && week_ending($effective) <= $frozen) {
        return t('Pay for the week ending :date is already approved. Make the change take effect after it, and settle the difference with an adjustment.',
                 ['date' => d((string) $frozen)]);
    }

    if ($amount !== null && $old !== null && abs($amount - $old) < 0.005) {
        return t('That is already the pay on that date.');
    }

    $outside = $amount !== null && compensation_outside_band(compensation_grade_on($candidateId, $effective), $kind, $amount);

    if ($outside && ! $isAdmin) {
        return t('That is outside the band of the person\'s grade. An administrator can set it, with the reason.');
    }

    if (val('SELECT COUNT(*) FROM compensation_changes WHERE candidate_id = ? AND kind = ? AND COALESCE(placement_id, 0) = ?
             AND effective_from = ? AND cancelled_at IS NULL', [$candidateId, $kind, $placementId, $effective])) {
        return t('A change of this kind already takes effect on that date. Cancel it first.');
    }

    q('INSERT INTO compensation_changes (candidate_id, placement_id, kind, old_amount, new_amount, grade_id, effective_from, reason, outside_band, recorded_by)
       VALUES (?,?,?,?,?,?,?,?,?,?)',
      [$candidateId, $placementId ?: null, $kind, $old, $amount, $gradeId, $effective, $reason, $outside ? 1 : 0, $userId]);

    compensation_apply_due($candidateId);

    return null;
}

/** Cancel a change whose date has not come. */
function compensation_cancel(int $changeId, int $candidateId, int $userId): ?string
{
    $c = row('SELECT * FROM compensation_changes WHERE id = ? AND candidate_id = ?', [$changeId, $candidateId]);

    if (! $c || $c['cancelled_at'] !== null) {
        return t('That change is not waiting to take effect.');
    }

    if ($c['applied_at'] !== null || $c['effective_from'] <= date('Y-m-d')) {
        return t('That change is already in effect. Record a new one instead.');
    }

    q('UPDATE compensation_changes SET cancelled_by = ?, cancelled_at = NOW() WHERE id = ?', [$userId, $changeId]);

    return null;
}

/**
 * Everything that happened to a person's employment, newest first:
 * assignments, promotions and transfers, classification, grade and pay.
 * Amounts are left out unless $withPay.
 */
function employment_history(int $candidateId, bool $withPay): array
{
    $events = [];
    $add = static function (?string $date, string $what, string $detail = '', ?string $amount = null) use (&$events): void {
        if ($date) { $events[] = ['date' => substr($date, 0, 10), 'what' => $what, 'detail' => $detail, 'amount' => $amount]; }
    };

    foreach (rows('SELECT p.start_date, p.end_date, p.status, j.title, d.trade FROM placements p JOIN jobs j ON j.id = p.job_id
                   LEFT JOIN assignment_details d ON d.placement_id = p.id WHERE p.candidate_id = ?', [$candidateId]) as $p) {
        $add($p['start_date'], t('Assignment started'), trim($p['title'] . ' · ' . ($p['trade'] ?? ''), ' ·'));
        if ($p['end_date'] && in_array($p['status'], ['completed', 'cancelled'], true)) {
            $add($p['end_date'], t('Assignment ended'), $p['title']);
        }
    }

    foreach (rows('SELECT x.*, j.title AS target FROM personnel_changes x JOIN placements p ON p.id = x.placement_id
                   LEFT JOIN jobs j ON j.id = x.target_job_id WHERE p.candidate_id = ?', [$candidateId]) as $x) {
        $add($x['effective_on'], $x['kind'] === 'promotion' ? t('Promoted') : t('Transferred'),
             trim(($x['previous_trade'] ?? '') . ' → ' . ($x['new_trade'] ?? $x['target'] ?? ''), ' →') . ' · ' . $x['reason']);
    }

    try {
        foreach (rows('SELECT * FROM employee_classifications WHERE candidate_id = ? AND effective_from IS NOT NULL', [$candidateId]) as $k) {
            $add($k['effective_from'], t('Classification changed'), (employment_types()[$k['employment_type']] ?? $k['employment_type']) . ' · ' . $k['reason']);
        }
    } catch (Throwable $e) {
    }

    foreach (rows('SELECT c.*, g.label AS grade_label, j.title FROM compensation_changes c LEFT JOIN pay_grades g ON g.id = c.grade_id
                   LEFT JOIN placements p ON p.id = c.placement_id LEFT JOIN jobs j ON j.id = p.job_id
                   WHERE c.candidate_id = ? AND c.cancelled_at IS NULL', [$candidateId]) as $c) {
        if ($c['kind'] === 'grade') {
            $add($c['effective_from'], t('Grade changed'), $c['grade_label'] . ' · ' . $c['reason']);
        } elseif ($withPay) {
            $add($c['effective_from'], $c['kind'] === 'salary' ? t('Salary changed') : t('Hourly rate changed'),
                 trim(($c['title'] ?? '') . ' · ' . $c['reason'], ' ·') . ($c['outside_band'] ? ' · ' . t('outside the band') : ''),
                 ($c['old_amount'] !== null ? money($c['old_amount']) : '—') . ' → ' . money($c['new_amount']));
        }
    }

    try {
        foreach (rows("SELECT a.decided_at, a.score_percent, a.grade, t.label, j.title FROM appraisals a JOIN appraisal_templates t ON t.id = a.template_id
                       JOIN placements p ON p.id = a.placement_id JOIN jobs j ON j.id = p.job_id
                       WHERE a.candidate_id = ? AND a.status = 'approved'", [$candidateId]) as $r) {
            $add($r['decided_at'], t('Performance review'), t($r['label']) . ' · ' . $r['title'] . ' · ' . number_format((float) $r['score_percent'], 1) . '% · ' . $r['grade']);
        }
    } catch (Throwable $e) {
    }

    usort($events, fn($a, $b) => strcmp($b['date'], $a['date']));

    return $events;
}
