<?php
/**
 * End-of-assignment reviews, and advances against wages.
 *
 * Both are carried over from the Fleury Solutions HR system. The
 * concepts are its; the implementation is not.
 *
 * The review answers what RSS asked for in the interview: a way to know,
 * a year later, that somebody did five jobs and finished all five. The
 * grade is for reading at a glance; would_rehire is the question that
 * decides whether the telephone rings, and it is deliberately separate
 * from the grade - a steady C who always turns up is worth calling, and
 * an A who walked off on day two is not.
 */

declare(strict_types=1);

/** What an assignment is scored on. Data, so the form can change. */
function assignment_review_criteria(bool $includeRetired = false): array
{
    static $cache = [];

    $key = $includeRetired ? 'all' : 'active';

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    try {
        $found = rows('SELECT slug, label FROM assignment_review_criteria'
                      . ($includeRetired ? '' : ' WHERE is_active = 1')
                      . ' ORDER BY sort_order, label');
    } catch (Throwable $e) {
        return $cache[$key] = [];
    }

    $list = [];

    foreach ($found as $row) {
        $list[(string) $row['slug']] = (string) $row['label'];
    }

    return $cache[$key] = $list;
}

/** The grades, in words, so nobody has to remember what a D means. */
function review_grades(): array
{
    return [
        'A' => 'A — would take them on any job',
        'B' => 'B — solid, no complaints',
        'C' => 'C — did the work, nothing more',
        'D' => 'D — problems, needed watching',
        'F' => 'F — should not have been there',
    ];
}

/** The short form, for a tag beside a name. */
function review_grade_tone(string $grade): string
{
    return match ($grade) {
        'A', 'B' => 'green',
        'C'      => 'blue',
        'D'      => 'amber',
        default  => 'red',
    };
}

/** The review on one assignment, if it has been done. */
function assignment_review(int $placementId): ?array
{
    try {
        return row('SELECT r.*, u.name AS reviewer
                    FROM assignment_reviews r
                    LEFT JOIN users u ON u.id = r.reviewed_by
                    WHERE r.placement_id = ?', [$placementId]);
    } catch (Throwable $e) {
        return null;
    }
}

/** What each criterion scored on one review. */
function assignment_review_scores(int $reviewId): array
{
    try {
        return array_column(
            rows('SELECT criterion_slug, score FROM assignment_review_scores
                  WHERE review_id = ?', [$reviewId]),
            'score', 'criterion_slug');
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Somebody's record across every project they have worked.
 *
 * This is the sentence a recruiter wanted to be able to read: five jobs,
 * five finished, average B, and nobody has said they would not have them
 * back.
 *
 * @return array{assignments:int,completed:int,reviewed:int,grade:?string,
 *               refused:int,history:list<array<string,mixed>>}
 */
function person_work_history(int $candidateId): array
{
    try {
        $history = rows(
            "SELECT p.id, p.status, p.start_date, p.end_date,
                    j.title AS project, c.name AS client,
                    d.trade, u.name AS supervisor,
                    r.grade, r.would_rehire, r.note, r.reviewed_at,
                    rv.name AS reviewer
             FROM placements p
             JOIN jobs j ON j.id = p.job_id
             LEFT JOIN clients c ON c.id = j.client_id
             LEFT JOIN assignment_details d ON d.placement_id = p.id
             LEFT JOIN users u ON u.id = d.supervisor_id
             LEFT JOIN assignment_reviews r ON r.placement_id = p.id
             LEFT JOIN users rv ON rv.id = r.reviewed_by
             WHERE p.candidate_id = ?
             ORDER BY p.start_date IS NULL, p.start_date DESC, p.id DESC",
            [$candidateId]);
    } catch (Throwable $e) {
        $history = [];
    }

    $points = ['A' => 4, 'B' => 3, 'C' => 2, 'D' => 1, 'F' => 0];
    $scored = [];
    $completed = 0;
    $refused = 0;

    foreach ($history as $row) {
        if ($row['status'] === 'completed') {
            $completed++;
        }

        if ($row['grade'] !== null) {
            $scored[] = $points[$row['grade']] ?? 0;
        }

        if ($row['grade'] !== null && ! (int) $row['would_rehire']) {
            $refused++;
        }
    }

    // The average is reported as a letter, because that is how it was
    // asked for and how it will be read.
    $grade = null;

    if ($scored) {
        $mean = array_sum($scored) / count($scored);
        $grade = array_search(
            (int) round($mean), $points, true) ?: null;
    }

    return [
        'assignments' => count($history),
        'completed'   => $completed,
        'reviewed'    => count($scored),
        'grade'       => $grade,
        'refused'     => $refused,
        'history'     => $history,
    ];
}

/** What is still owed on an advance. */
function advance_balance(array $advance): float
{
    try {
        $repaid = (float) val('SELECT COALESCE(SUM(amount), 0) FROM wage_advance_payments
                               WHERE advance_id = ?', [(int) $advance['id']]);
    } catch (Throwable $e) {
        $repaid = 0.0;
    }

    return max(0.0, round((float) $advance['amount'] - $repaid, 2));
}

/** The states an advance moves through, in words. */
function advance_states(): array
{
    return [
        'requested' => 'Asked for',
        'approved'  => 'Approved, not yet paid',
        'paid_out'  => 'Paid out, repaying',
        'cleared'   => 'Repaid in full',
        'cancelled' => 'Cancelled',
    ];
}

function advance_tone(string $status): string
{
    return match ($status) {
        'cleared'   => 'green',
        'paid_out'  => 'amber',
        'approved'  => 'blue',
        'cancelled' => 'grey',
        default     => 'grey',
    };
}

/** Every advance for one person, newest first. */
function advances_for(int $candidateId): array
{
    try {
        return rows('SELECT * FROM wage_advances WHERE candidate_id = ?
                     ORDER BY id DESC', [$candidateId]);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * What a person still owes across every advance.
 *
 * Read before handing over another one: three small advances nobody
 * added up is how somebody ends up owing a week's wages.
 */
function advance_owed(int $candidateId): float
{
    $owed = 0.0;

    foreach (advances_for($candidateId) as $advance) {
        if (in_array($advance['status'], ['paid_out', 'approved'], true)) {
            $owed += advance_balance($advance);
        }
    }

    return round($owed, 2);
}

/** The kinds of leave, and how many days each one allows. */
function leave_types(bool $includeRetired = false): array
{
    static $cache = [];

    $key = $includeRetired ? 'all' : 'active';

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    try {
        $found = rows('SELECT * FROM leave_types'
                      . ($includeRetired ? '' : ' WHERE is_active = 1')
                      . ' ORDER BY sort_order, label');
    } catch (Throwable $e) {
        return $cache[$key] = [];
    }

    $list = [];

    foreach ($found as $row) {
        $list[(string) $row['slug']] = $row;
    }

    return $cache[$key] = $list;
}

/**
 * How much of an allowance somebody has, has used this year, and has left.
 *
 * Counted from approved requests only: a pending request is not yet a
 * day off, and refusing somebody on the strength of a request nobody has
 * decided would be wrong.
 *
 * The allowance follows the leave type: a yearly grant, or days earned
 * from approved hours worked in the year, plus what carries over from
 * last year, within the type's cap (app/leave-calculation.php). An
 * allowance of null means there is none - unpaid leave does not run out -
 * and that is reported as null rather than as a large number.
 *
 * $exceptRequest leaves one request out of "taken", so an approval can
 * ask whether this request still fits.
 *
 * @return array{allowed:?float,taken:float,left:?float,grant:?float,carryover:float}
 */
function leave_balance(int $candidateId, string $slug, ?int $year = null, int $exceptRequest = 0): array
{
    require_once __DIR__ . '/leave-calculation.php';

    $year = $year ?? (int) date('Y');
    $type = leave_types(true)[$slug] ?? null;

    if (! $type) {
        return ['allowed' => 0.0, 'taken' => 0.0, 'left' => 0.0, 'grant' => 0.0, 'carryover' => 0.0];
    }

    // Inclusive of both ends: somebody off Monday to Friday has taken
    // five days, not four.
    $taken = static fn(int $y): float => (float) val("SELECT COALESCE(SUM(DATEDIFF(r.ends_on, r.starts_on) + 1), 0)
                            FROM time_off_requests r
                            JOIN placements p ON p.id = r.placement_id
                            WHERE p.candidate_id = ? AND r.leave_type = ?
                              AND r.status = 'approved' AND r.id <> ?
                              AND YEAR(r.starts_on) = ?",
                           [$candidateId, $slug, $exceptRequest, $y]);

    // What was earned is what was paid as worked: approved weekly sheets.
    $hours = static fn(int $y): float => ($type['accrual_method'] ?? 'annual') !== 'hours_worked' ? 0.0
        : (float) val("SELECT COALESCE(SUM(t.hours_worked), 0) FROM timesheets t
                       JOIN placements p ON p.id = t.placement_id
                       WHERE p.candidate_id = ? AND t.status IN ('approved','paid') AND YEAR(t.week_ending) = ?",
                      [$candidateId, $y]);

    $e = leave_entitlement($type, $hours($year), $hours($year - 1), $taken($year - 1));
    $usedNow = $taken($year);

    return [
        'allowed'   => $e['allowed'],
        'taken'     => $usedNow,
        'left'      => $e['allowed'] === null ? null : max(0.0, round($e['allowed'] - $usedNow, 2)),
        'grant'     => $e['grant'],
        'carryover' => $e['carryover'],
    ];
}

/**
 * Why somebody may not take a kind of leave on a date, in words, or null.
 */
function leave_refusal_for(int $candidateId, string $slug, string $onDate): ?string
{
    require_once __DIR__ . '/leave-calculation.php';

    $type = leave_types(true)[$slug] ?? null;

    if (! $type) {
        return t('Choose what kind of time off this is.');
    }

    $type['eligible_after_days'] ??= 0;
    $employment = (string) (val('SELECT employment_type FROM employee_profiles WHERE candidate_id = ?', [$candidateId]) ?? 'hourly');
    $first = val("SELECT MIN(start_date) FROM placements WHERE candidate_id = ? AND status <> 'cancelled'", [$candidateId]);

    return match (leave_ineligibility($type, $employment, $first !== null ? (string) $first : null, $onDate)) {
        'employment_type' => t(':kind is not available to this kind of employment.', ['kind' => t((string) $type['label'])]),
        'waiting_period'  => t(':kind is available after :n days on assignment.', ['kind' => t((string) $type['label']), 'n' => (int) $type['eligible_after_days']]),
        'not_started'     => t(':kind is available once an assignment has started.', ['kind' => t((string) $type['label'])]),
        default           => null,
    };
}

/** A number of days as people write it: 3, 2.5, 0.25. */
function leave_days_text(?float $days): string
{
    if ($days === null) {
        return '∞';
    }

    return rtrim(rtrim(number_format($days, 2, '.', ''), '0'), '.') ?: '0';
}

/**
 * Paid leave hours for one assignment in one week: approved, paid kinds of
 * leave, the days of each request inside the week, at the type's hours per
 * day. Read when approved attendance is imported into the weekly sheet.
 */
function leave_paid_hours_for_week(int $placementId, string $weekEnding): float
{
    require_once __DIR__ . '/leave-calculation.php';

    $start = date('Y-m-d', strtotime($weekEnding . ' -6 days'));
    $hours = 0.0;

    foreach (rows("SELECT r.starts_on, r.ends_on, t.hours_per_day
                   FROM time_off_requests r JOIN leave_types t ON t.slug = r.leave_type
                   WHERE r.placement_id = ? AND r.status = 'approved' AND t.is_paid = 1
                     AND r.starts_on <= ? AND r.ends_on >= ?",
                  [$placementId, $weekEnding, $start]) as $r) {
        $hours += leave_days_between((string) $r['starts_on'], (string) $r['ends_on'], $start, $weekEnding)
                * (float) $r['hours_per_day'];
    }

    return round($hours, 2);
}

/**
 * Somebody's bank details, decrypted, and the read recorded.
 *
 * Every path to the plaintext goes through here, so the access log
 * cannot be bypassed by calling something else. Returns null when there
 * is nothing on file or when the record cannot be decrypted - a key
 * that has been changed must fail loudly on screen, not hand back
 * rubbish that looks like an account number.
 */
function worker_bank_details(int $candidateId, string $why = 'viewed'): ?array
{
    try {
        $row = row('SELECT * FROM worker_bank_details WHERE candidate_id = ?', [$candidateId]);
    } catch (Throwable $e) {
        return null;
    }

    if (! $row) {
        return null;
    }

    try {
        $plain = token_decrypt((string) $row['encrypted_details']);
    } catch (Throwable $e) {
        $row['unreadable'] = true;
        $row['details'] = [];

        return $row;
    }

    q('INSERT INTO worker_bank_access (candidate_id, user_id, action) VALUES (?,?,?)',
      [$candidateId, uid(), mb_substr($why, 0, 40)]);

    $row['details'] = $plain;
    $row['unreadable'] = false;

    return $row;
}

/**
 * What is safe to show without reading the record.
 *
 * The last four digits and the bank's name are kept outside the
 * encrypted blob precisely so a list can be rendered without anybody
 * decrypting anything.
 */
function worker_bank_summary(int $candidateId): ?array
{
    try {
        return row('SELECT candidate_id, last_four, bank_label, status, reviewed_at
                    FROM worker_bank_details WHERE candidate_id = ?', [$candidateId]);
    } catch (Throwable $e) {
        return null;
    }
}

/** The states a bank record moves through, in words. */
function bank_states(): array
{
    return [
        'pending_review' => 'Waiting to be checked',
        'verified'       => 'Checked and in use',
        'rejected'       => 'Rejected, not in use',
    ];
}

function bank_tone(string $status): string
{
    return match ($status) {
        'verified' => 'green',
        'rejected' => 'red',
        default    => 'amber',
    };
}
