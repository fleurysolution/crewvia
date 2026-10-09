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
