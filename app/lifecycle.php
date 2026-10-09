<?php
/**
 * Where a project is in its life, and what has to be true to move on.
 *
 * Ported from BPMS247's project phase gate - the idea that a project does not
 * simply have a status somebody types in, but a stage it earns. BPMS shows
 * "0 of 2 signals met" with each signal named and measured, and refuses to
 * advance until they are. That is the single best thing on its project page,
 * and it is exactly what a strike mobilisation needs: you do not go to site
 * because somebody set a dropdown, you go because everybody has a bed, a
 * flight and signed paperwork.
 *
 * What changes here is the subject. BPMS gates a construction project on
 * punch lists and closeout checklists; this gates a crew on whether they can
 * actually travel, work and be paid.
 *
 * Every signal is a count against a real table. Nothing is a checkbox
 * somebody ticks.
 */

declare(strict_types=1);

/** The stages, in order, with what each one means. */
function lifecycle_stages(): array
{
    return [
        'planning' => [
            'label' => 'Planning',
            'note'  => 'Terms agreed with the client, orders written.',
        ],
        'mobilising' => [
            'label' => 'Mobilising',
            'note'  => 'Hiring, paperwork, beds and flights.',
        ],
        'on_site' => [
            'label' => 'On site',
            'note'  => 'The crew is working and hours are being recorded.',
        ],
        'demobilising' => [
            'label' => 'Demobilising',
            'note'  => 'Coming home, equipment back, last week paid.',
        ],
        'closed' => [
            'label' => 'Closed',
            'note'  => 'Nothing outstanding on either side.',
        ],
    ];
}

/** Which stage a project is in. */
function lifecycle_stage(array $job): string
{
    $stage = (string) ($job['lifecycle_stage'] ?? '');

    if (array_key_exists($stage, lifecycle_stages())) {
        return $stage;
    }

    // An installation upgraded from the old three-value status still has to
    // read as something, so the old value is mapped rather than ignored.
    return match ((string) ($job['status'] ?? 'planning')) {
        'active' => 'on_site',
        'closed' => 'closed',
        default  => 'planning',
    };
}

/** The stage after this one, or null at the end. */
function lifecycle_next(string $stage): ?string
{
    $keys = array_keys(lifecycle_stages());
    $at   = array_search($stage, $keys, true);

    return ($at === false || $at >= count($keys) - 1) ? null : $keys[$at + 1];
}

/** How far through, as a percentage, for the bar. */
function lifecycle_progress(string $stage): int
{
    $keys = array_keys(lifecycle_stages());
    $at   = array_search($stage, $keys, true);

    return $at === false ? 0 : (int) round($at / (count($keys) - 1) * 100);
}

/**
 * What must be true before this project can move to the next stage.
 *
 * @return list<array{label:string,met:bool,detail:string,where:string}>
 */
function lifecycle_signals(array $job): array
{
    $id    = (int) $job['id'];
    $stage = lifecycle_stage($job);

    $count = static fn (string $sql, array $args = []): int => (int) val($sql, $args);

    $confirmed = $count("SELECT COUNT(*) FROM placements
                         WHERE job_id = ? AND status IN ('confirmed','travelling','on_site')", [$id]);

    // The gate reads the scope of work rather than one rate on the project,
    // because one rate was never what was agreed: a line per trade is.
    require_once __DIR__ . '/scope.php';

    $scopeLines    = $count('SELECT COUNT(*) FROM job_order_lines WHERE job_id = ?', [$id]);
    $scopePeople   = $count('SELECT COALESCE(SUM(quantity), 0) FROM job_order_lines WHERE job_id = ?', [$id]);
    $scopeUnpriced = scope_unpriced($id);

    return match ($stage) {
        'planning' => [
            lifecycle_signal(
                'The agreement says what the work is',
                trim((string) ($job['description'] ?? '')) !== '',
                trim((string) ($job['description'] ?? '')) !== ''
                    ? 'Described in ' . str_word_count((string) $job['description']) . ' words'
                    : 'No description of the work on the agreement',
                '/job'),

            lifecycle_signal(
                'A scope of work is written',
                $scopeLines > 0,
                $scopeLines > 0
                    ? sprintf('%d people asked for across %d lines', $scopePeople, $scopeLines)
                    : 'No trades on the order yet',
                '/job#scope'),

            lifecycle_signal(
                'Every line of the scope is priced',
                $scopeLines > 0 && $scopeUnpriced === 0,
                $scopeLines === 0
                    ? 'Nothing to price yet'
                    : ($scopeUnpriced === 0
                        ? 'All ' . $scopeLines . ' lines carry a pay rate'
                        : $scopeUnpriced . ' of ' . $scopeLines . ' lines have no agreed pay rate'),
                '/job#scope'),

            lifecycle_signal(
                'At least one order open to applicants',
                $count('SELECT COUNT(*) FROM vacancies WHERE job_id = ? AND is_open = 1', [$id]) > 0,
                $count('SELECT COUNT(*) FROM vacancies WHERE job_id = ? AND is_open = 1', [$id]) . ' open',
                '/requisitions'),
        ],

        'mobilising' => [
            lifecycle_signal(
                'Somebody is confirmed for the job',
                $confirmed > 0,
                $confirmed . ' confirmed',
                '/roster'),

            lifecycle_signal(
                'Everybody confirmed has a bed',
                0 === ($n = $count("SELECT COUNT(*) FROM placements p
                                    LEFT JOIN lodging l ON l.placement_id = p.id
                                         AND l.status IN ('held','booked','checked_in')
                                    WHERE p.job_id = ? AND p.status IN ('confirmed','travelling','on_site')
                                      AND l.id IS NULL", [$id])),
                $n === 0 ? 'Nobody without a room' : $n . ' with nowhere to sleep',
                '/hotels'),

            lifecycle_signal(
                'Nobody is sharing a room',
                0 === ($n = $count("SELECT COUNT(*) FROM lodging l
                                    JOIN placements p ON p.id = l.placement_id
                                    WHERE p.job_id = ? AND l.private_room = 0
                                      AND l.status <> 'cancelled'", [$id])),
                $n === 0 ? 'Every room is private, as promised' : $n . ' sharing',
                '/hotels'),

            lifecycle_signal(
                'Everybody confirmed is travelling',
                0 === ($n = $count("SELECT COUNT(*) FROM placements p
                                    LEFT JOIN travel t ON t.placement_id = p.id
                                         AND t.direction = 'inbound' AND t.status <> 'cancelled'
                                    WHERE p.job_id = ? AND p.status IN ('confirmed','travelling')
                                      AND t.id IS NULL", [$id])),
                $n === 0 ? 'Every journey is booked' : $n . ' with no way to get there',
                '/travel'),
        ],

        'on_site' => [
            lifecycle_signal(
                'Somebody has actually arrived',
                0 < ($n = $count("SELECT COUNT(*) FROM placements
                                  WHERE job_id = ? AND status = 'on_site'", [$id])),
                $n . ' on site',
                '/roster'),

            lifecycle_signal(
                'Hours are being recorded',
                0 < ($n = $count('SELECT COUNT(*) FROM timesheets t
                                  JOIN placements p ON p.id = t.placement_id
                                  WHERE p.job_id = ?', [$id])),
                $n . ' weeks recorded',
                '/hours'),
        ],

        'demobilising' => [
            lifecycle_signal(
                'Nobody is still deployed',
                0 === $confirmed,
                $confirmed === 0 ? 'Everybody is home' : $confirmed . ' still out',
                '/roster'),

            lifecycle_signal(
                'Every week is approved or paid',
                0 === ($n = $count("SELECT COUNT(*) FROM timesheets t
                                    JOIN placements p ON p.id = t.placement_id
                                    WHERE p.job_id = ? AND t.status IN ('draft','submitted')", [$id])),
                $n === 0 ? 'Payroll is settled' : $n . ' weeks unapproved',
                '/hours'),

            lifecycle_signal(
                'Equipment and vehicles are back',
                0 === ($n = $count('SELECT COUNT(*) FROM equipment_issues e
                                    JOIN placements p ON p.id = e.placement_id
                                    WHERE p.job_id = ? AND e.returned_at IS NULL', [$id])
                           + $count('SELECT COUNT(*) FROM vehicle_assignments v
                                     JOIN placements p ON p.id = v.placement_id
                                     WHERE p.job_id = ? AND v.checked_in_at IS NULL', [$id])),
                $n === 0 ? 'Nothing outstanding' : $n . ' items still out',
                '/operations'),
        ],

        default => [],
    };
}

/** @return array{label:string,met:bool,detail:string,where:string} */
function lifecycle_signal(string $label, bool $met, string $detail, string $where): array
{
    return ['label' => $label, 'met' => $met, 'detail' => $detail, 'where' => $where];
}

/** How many of the signals are met. */
function lifecycle_met(array $signals): int
{
    return count(array_filter($signals, static fn ($s) => $s['met']));
}

/**
 * Move a project on, if it has earned it.
 *
 * @return array{ok:bool,message:string}
 */
function lifecycle_advance(int $jobId): array
{
    $job = row('SELECT * FROM jobs WHERE id = ?', [$jobId]);

    if (! $job) {
        return ['ok' => false, 'message' => 'That project no longer exists.'];
    }

    $stage = lifecycle_stage($job);
    $next  = lifecycle_next($stage);

    if (! $next) {
        return ['ok' => false, 'message' => 'This project is already closed.'];
    }

    $signals = lifecycle_signals($job);
    $unmet   = array_values(array_filter($signals, static fn ($s) => ! $s['met']));

    if ($unmet) {
        return ['ok' => false, 'message' => t(
            'Not yet: :first. :n of :total signals met.',
            ['first'  => t($unmet[0]['label']),
             'n'      => lifecycle_met($signals),
             'total'  => count($signals)])];
    }

    // The old three-value status is kept in step, because other screens and
    // the project switcher still read it.
    $status = match ($next) {
        'closed' => 'closed',
        'on_site', 'demobilising' => 'active',
        default  => 'planning',
    };

    q('UPDATE jobs SET lifecycle_stage = ?, status = ? WHERE id = ?', [$next, $status, $jobId]);
    log_activity('advanced project stage', 'job', $jobId, $stage . ' to ' . $next);

    return ['ok' => true, 'message' => t('Moved to :stage.',
        ['stage' => t(lifecycle_stages()[$next]['label'])])];
}
