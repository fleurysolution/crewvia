<?php
/**
 * The scope of work, and the money that hangs off it.
 *
 * A project is an agreement. Its scope is a schedule of lines - twenty
 * engineers, forty mechanical, a hundred labourers - and each line carries
 * its own terms, because those trades are neither paid nor billed the same.
 *
 * Everything commercial resolves down this chain:
 *
 *     order line  ->  requisition  ->  the person
 *
 * Each step may override the one above it and inherits where it is silent.
 * A placement takes a copy at the moment it is created, so renegotiating a
 * line in March cannot rewrite what somebody was paid in January.
 */

declare(strict_types=1);

/** The lines of the agreement, in the order they were written. */
function scope_lines(int $jobId): array
{
    return rows('SELECT * FROM job_order_lines WHERE job_id = ? ORDER BY sort_order, id',
                [$jobId]);
}

/** One line, scoped to its project so an id from elsewhere cannot be used. */
function scope_line(int $lineId, int $jobId): ?array
{
    return row('SELECT * FROM job_order_lines WHERE id = ? AND job_id = ?', [$lineId, $jobId]);
}

/**
 * What the whole agreement commits to, summed from its lines.
 *
 * @return array{people:int,placed:int,weekly_pay:float,weekly_bill:float,
 *               weekly_margin:float,lines:int,priced:int}
 */
function scope_totals(int $jobId, bool $strikeLive = false): array
{
    $totals = ['people' => 0, 'placed' => 0, 'weekly_pay' => 0.0, 'weekly_bill' => 0.0,
               'weekly_margin' => 0.0, 'lines' => 0, 'priced' => 0];

    foreach (scope_lines($jobId) as $line) {
        $quantity  = max(0, (int) $line['quantity']);
        $guarantee = scope_guarantee($line, $strikeLive);

        $totals['lines']++;
        $totals['people'] += $quantity;

        if ($line['pay_rate'] === null) {
            continue;   // a line with no agreed rate is not yet worth anything
        }

        $totals['priced']++;

        // A full week at the guaranteed hours, plus the daily allowance, which
        // is paid per day and not per hour.
        $totals['weekly_pay'] += $quantity * $guarantee * (float) $line['pay_rate']
                               + $quantity * 7 * (float) ($line['per_diem_rate'] ?? 0);

        if ($line['bill_rate'] !== null) {
            $totals['weekly_bill'] += $quantity * $guarantee * (float) $line['bill_rate'];
        }
    }

    $totals['weekly_margin'] = $totals['weekly_bill'] - $totals['weekly_pay'];

    $totals['placed'] = (int) val("SELECT COUNT(*) FROM placements
                                   WHERE job_id = ? AND status IN ('confirmed','travelling','on_site')",
                                  [$jobId]);

    return $totals;
}

/** The guaranteed week for a line, which rises once the strike is live. */
function scope_guarantee(array $line, bool $strikeLive): int
{
    if ($strikeLive && $line['strike_guarantee_hours'] !== null) {
        return (int) $line['strike_guarantee_hours'];
    }

    return (int) ($line['guarantee_hours'] ?? 0);
}

/**
 * How many of a line have actually been placed, through the requisitions
 * raised against it.
 */
function scope_line_filled(int $lineId): int
{
    return (int) val("SELECT COUNT(*) FROM placements p
                      JOIN applications a ON a.candidate_id = p.candidate_id
                      JOIN vacancies v ON v.id = a.vacancy_id AND v.job_id = p.job_id
                      WHERE v.order_line_id = ?
                        AND p.status IN ('confirmed','travelling','on_site')", [$lineId]);
}

/**
 * The terms a requisition should start from: its order line, or nothing.
 *
 * There is deliberately no project-level fallback any more. A requisition
 * with no line behind it has no agreed rate, and saying so is better than
 * inventing one from a number that should never have been on the project.
 */
function scope_terms_for_line(?int $lineId, int $jobId): array
{
    $empty = ['pay_rate' => null, 'bill_rate' => null, 'per_diem_rate' => null,
              'guarantee_hours' => null];

    if (! $lineId) {
        return $empty;
    }

    $line = scope_line($lineId, $jobId);

    if (! $line) {
        return $empty;
    }

    return [
        'pay_rate'        => $line['pay_rate'] !== null ? (float) $line['pay_rate'] : null,
        'bill_rate'       => $line['bill_rate'] !== null ? (float) $line['bill_rate'] : null,
        'per_diem_rate'   => $line['per_diem_rate'] !== null ? (float) $line['per_diem_rate'] : null,
        'guarantee_hours' => $line['guarantee_hours'] !== null ? (int) $line['guarantee_hours'] : null,
    ];
}

/**
 * The line of the scope a placement sits under, through the requisition the
 * person applied to.
 *
 * This is what the fallback used to be: one rate on the project. A person
 * hired as a labourer was then shown, and paid against, whatever single
 * number the project carried. The line they were actually recruited against
 * is the agreed answer, so it is the one used.
 */
function scope_line_for_placement(int $candidateId, int $jobId): ?array
{
    try {
        return row('SELECT l.* FROM applications a
                    JOIN vacancies v ON v.id = a.vacancy_id AND v.job_id = ?
                    JOIN job_order_lines l ON l.id = v.order_line_id
                    WHERE a.candidate_id = ?
                    ORDER BY a.id DESC LIMIT 1', [$jobId, $candidateId]);
    } catch (Throwable $e) {
        // The columns arrive with the scope-of-work upgrade; until it has
        // run, a placement simply has no line behind it.
        return null;
    }
}

/**
 * The terms a line can carry, in the order they are asked for on screen.
 *
 * One list, so the form, the validation message and the printed order all
 * name the same thing the same way.
 *
 * @return array<string,array{label:string,hint:string,kind:string}>
 */
function scope_terms(): array
{
    return [
        'pay_rate' => [
            'label' => 'Pay rate (per hour)',
            'hint'  => 'What this trade is paid',
            'kind'  => 'money',
        ],
        'bill_rate' => [
            'label' => 'Bill rate (per hour)',
            'hint'  => 'What the client is invoiced',
            'kind'  => 'money',
        ],
        'per_diem_rate' => [
            'label' => 'Per diem (per day)',
            'hint'  => 'Paid every day away, not per hour',
            'kind'  => 'money',
        ],
        'guarantee_hours' => [
            'label' => 'Guaranteed week (hours)',
            'hint'  => 'Paid whether or not the hours are worked',
            'kind'  => 'hours',
        ],
        'strike_guarantee_hours' => [
            'label' => 'Guarantee once the strike is live',
            'hint'  => 'Replaces the guarantee above while the strike runs',
            'kind'  => 'hours',
        ],
        'overtime_after' => [
            'label' => 'Overtime after (hours)',
            'hint'  => 'Hours in a week before the multiplier applies',
            'kind'  => 'hours',
        ],
        'overtime_multiplier' => [
            'label' => 'Overtime multiplier',
            'hint'  => 'e.g. 1.5 for time and a half',
            'kind'  => 'factor',
        ],
    ];
}

/** The name of one term, for a message that has to say which was wrong. */
function scope_term_label(string $field): string
{
    return scope_terms()[$field]['label'] ?? $field;
}

/** The lines of an agreement that have no agreed pay rate yet. */
function scope_unpriced(int $jobId): int
{
    return (int) val('SELECT COUNT(*) FROM job_order_lines
                      WHERE job_id = ? AND pay_rate IS NULL', [$jobId]);
}

/** What the agreement covers beyond the hourly rate, in plain words. */
function scope_provisions(array $job): array
{
    return [
        ['label' => 'Lodging paid',   'on' => ! empty($job['lodging_provided'])],
        ['label' => 'Travel paid',    'on' => ! empty($job['travel_provided'])],
        ['label' => 'Transport on site', 'on' => ! empty($job['transport_provided'])],
    ];
}
