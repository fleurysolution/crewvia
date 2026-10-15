<?php
/**
 * The money a placement starts life with.
 *
 * The chain runs down the agreement:
 *
 *     line of the scope of work  ->  requisition  ->  the placement
 *
 * The line is where the client and the agency settled the rate for a trade.
 * A requisition may override it for one order, and the placement takes a
 * copy at the moment it is created, so renegotiating a line in March cannot
 * rewrite what somebody was paid in January.
 *
 * There is deliberately no project-wide fallback any more. One rate on a
 * project was only ever right where everybody is the same trade; on an order
 * of engineers, machinists and labourers it quietly gave all of them the
 * engineer's number. A requisition with no line behind it and no rate of its
 * own now yields nothing, and the screens say so - which is the honest
 * answer, and a visible one somebody can go and fix.
 */

declare(strict_types=1);

/**
 * @return array{pay_rate:?float,bill_rate:?float,per_diem_rate:?float,
 *               guarantee_hours:?int,source:string}
 */
function placement_rates(int $jobId, ?int $vacancyId = null): array
{
    $role = [];
    $line = [];

    if ($vacancyId) {
        // The columns arrive with an upgrade, so an installation that has not
        // run it still creates placements rather than failing on the select.
        try {
            $role = row('SELECT pay_rate, bill_rate, per_diem_rate, guarantee_hours,
                                order_line_id
                         FROM vacancies WHERE id = ? AND job_id = ?',
                        [$vacancyId, $jobId]) ?: [];
        } catch (Throwable $e) {
            try {
                $role = row('SELECT pay_rate, bill_rate, per_diem_rate, guarantee_hours
                             FROM vacancies WHERE id = ? AND job_id = ?',
                            [$vacancyId, $jobId]) ?: [];
            } catch (Throwable $e) {
                $role = [];
            }
        }
    }

    if (! empty($role['order_line_id'])) {
        try {
            $line = row('SELECT pay_rate, bill_rate, per_diem_rate, guarantee_hours
                         FROM job_order_lines WHERE id = ? AND job_id = ?',
                        [(int) $role['order_line_id'], $jobId]) ?: [];
        } catch (Throwable $e) {
            $line = [];
        }
    }

    $pick = static function (string $field) use ($role, $line) {
        foreach ([$role, $line] as $source) {
            if (isset($source[$field]) && $source[$field] !== null && $source[$field] !== '') {
                return $source[$field];
            }
        }

        return null;
    };

    // Which of the two settled the rate matters the day somebody asks why a
    // contract says what it says, so it is reported rather than guessed at.
    $pay = $pick('pay_rate');

    if ($pay === null) {
        $source = 'nothing agreed';
    } elseif (isset($role['pay_rate']) && $role['pay_rate'] !== null) {
        $source = $line !== []
            ? 'the requisition, overriding its line of the scope'
            : 'the requisition';
    } else {
        $source = 'the line of the scope of work';
    }

    return [
        'pay_rate'        => $pay !== null ? (float) $pay : null,
        'bill_rate'       => $pick('bill_rate') !== null ? (float) $pick('bill_rate') : null,
        'per_diem_rate'   => $pick('per_diem_rate') !== null ? (float) $pick('per_diem_rate') : null,
        'guarantee_hours' => $pick('guarantee_hours') !== null ? (int) $pick('guarantee_hours') : null,
        'source'          => $source,
        // Where the copy came from, kept on the placement so a later
        // question - which trade, which order - has an answer that is not
        // a guess from the person's most recent application.
        'vacancy_id'      => $role !== [] ? $vacancyId : null,
        'order_line_id'   => $line !== [] ? (int) $role['order_line_id'] : null,
    ];
}

/** The requisition an application came through, if it still exists. */
function application_vacancy(int $applicationId): ?int
{
    $id = val('SELECT vacancy_id FROM applications WHERE id = ?', [$applicationId]);

    return $id ? (int) $id : null;
}

/**
 * What an offer for a requisition commits to, as the lines a worker reads
 * before signing: pay, per diem, guaranteed hours, start and shift, place,
 * and what is paid. The same lines are stored with the signature, so the
 * record holds what was shown (walkthrough finding: only the recruiter's
 * note was signed).
 *
 * @return list<string>
 */
function offer_terms(int $vacancyId): array
{
    $role = row('SELECT v.job_id, v.starts_on, v.shift, j.site_city, j.site_state, j.guarantee_hours,
                        j.lodging_provided, j.travel_provided, j.transport_provided
                 FROM vacancies v JOIN jobs j ON j.id = v.job_id WHERE v.id = ?', [$vacancyId]);
    if (! $role) {
        return [];
    }
    $rates = placement_rates((int) $role['job_id'], $vacancyId);
    $terms = [];
    if (($rates['pay_rate'] ?? null) !== null) {
        $terms[] = t('Pay: :rate an hour', ['rate' => money((float) $rates['pay_rate'])]);
    }
    if (($rates['per_diem_rate'] ?? null) !== null && (float) $rates['per_diem_rate'] > 0) {
        $terms[] = t('Per diem: :rate a day', ['rate' => money((float) $rates['per_diem_rate'])]);
    }
    $hours = $rates['guarantee_hours'] ?? $role['guarantee_hours'];
    if ($hours) {
        $terms[] = t('Guaranteed: :n hours a week', ['n' => (int) $hours]);
    }
    if (! empty($role['starts_on'])) {
        // P5-M53: a start date already passed reads "immediately", as on the job posters.
        $terms[] = ((string) $role['starts_on'] <= date('Y-m-d') ? t('Starts: immediately') : t('Starts: :date', ['date' => d((string) $role['starts_on'])]))
                 . (! empty($role['shift']) ? ' · ' . t('Shift: :shift', ['shift' => $role['shift']]) : '');
    }
    if (! empty($role['site_city'])) {
        $terms[] = t('Where: :place', ['place' => trim($role['site_city'] . ', ' . ($role['site_state'] ?? ''), ', ')]);
    }
    $paid = array_filter([! empty($role['lodging_provided']) ? t('Hotel paid') : null,
                          ! empty($role['travel_provided']) ? t('Travel to and from site paid') : null,
                          ! empty($role['transport_provided']) ? t('Transport on site provided') : null]);
    if ($paid) {
        $terms[] = implode(' · ', $paid);
    }

    return $terms;
}

/** The day a requisition starts, for the placement made from it (walkthrough finding: it was left empty). */
function offer_start_date(int $vacancyId): ?string
{
    $start = val('SELECT starts_on FROM vacancies WHERE id = ?', [$vacancyId]);

    return $start ? (string) $start : null;
}
