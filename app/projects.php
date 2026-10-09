<?php
/**
 * What a project is worth and what is wrong with it.
 *
 * The project workspace and the executive overview both answer the same
 * questions - how many are out, what a week costs, who has no bed - and they
 * must never answer them differently. Twice already in this application a
 * second hand-kept copy of something drifted from the first: the worker menu
 * listed screens the pages refused, and the roster's gate knew things the
 * roster did not show. One function, read by both.
 */

declare(strict_types=1);

require_once __DIR__ . '/deployment.php';

/**
 * @return array{target:int,placed:int,onsite:int,offered:int,beds:int,travel:int,
 *               sharing:int,blocked:int,unapproved:int,hours:float,priced:int,
 *               weekly_pay:float,weekly_bill:float,scope_pay:float,
 *               scope_bill:float,scope_lines:int,scope_priced:int}
 */
function project_figures(array $job): array
{
    $id = (int) $job['id'];

    $count = static fn (string $sql): int => (int) val($sql, [$id]);

    $f = [
        'target'  => (int) $job['headcount_target'],
        'placed'  => $count("SELECT COUNT(*) FROM placements
                             WHERE job_id = ? AND status IN ('confirmed','travelling','on_site')"),
        'onsite'  => $count("SELECT COUNT(*) FROM placements
                             WHERE job_id = ? AND status = 'on_site'"),
        'offered' => $count("SELECT COUNT(*) FROM placements
                             WHERE job_id = ? AND status = 'offered'"),

        'beds'    => $count("SELECT COUNT(*) FROM placements p
                             LEFT JOIN lodging l ON l.placement_id = p.id
                                  AND l.status IN ('held','booked','checked_in')
                             WHERE p.job_id = ? AND p.status IN ('confirmed','travelling','on_site')
                               AND l.id IS NULL"),

        'sharing' => $count("SELECT COUNT(*) FROM lodging l
                             JOIN placements p ON p.id = l.placement_id
                             WHERE p.job_id = ? AND l.private_room = 0
                               AND l.status <> 'cancelled'"),

        'travel'  => $count("SELECT COUNT(*) FROM placements p
                             LEFT JOIN travel t ON t.placement_id = p.id
                                  AND t.direction = 'inbound' AND t.status <> 'cancelled'
                             WHERE p.job_id = ? AND p.status IN ('confirmed','travelling')
                               AND t.id IS NULL"),

        'unapproved' => $count("SELECT COUNT(*) FROM timesheets t
                                JOIN placements p ON p.id = t.placement_id
                                WHERE p.job_id = ? AND t.status IN ('draft','submitted')"),

        'hours'   => (float) val('SELECT COALESCE(SUM(t.hours_worked), 0) FROM timesheets t
                                  JOIN placements p ON p.id = t.placement_id
                                  WHERE p.job_id = ?', [$id]),

        'blocked' => 0,
    ];

    // Deployment blockers are six queries per person, so they are counted only
    // for the people the answer changes anything for.
    foreach (rows("SELECT id, candidate_id FROM placements
                   WHERE job_id = ? AND status = 'offered' LIMIT 200", [$id]) as $p) {
        if (deployment_blockers((int) $p['id'], (int) $p['candidate_id'], $id)) {
            $f['blocked']++;
        }
    }

    // What this week costs is summed person by person, from the rates each
    // of them was actually signed at.
    //
    // It used to be placed x one project rate x one project guarantee. That
    // was only ever right for a project where everybody is the same trade:
    // on an order of engineers, machinists and labourers it reported one
    // trade's rate for all of them. A placement carries its own terms, so
    // they are what gets added up, and the count of placements that have no
    // agreed rate is reported alongside rather than silently treated as nil.
    $priced = row("SELECT COUNT(*) n,
                          COALESCE(SUM(p.pay_rate * COALESCE(p.guarantee_hours, 0)), 0) pay,
                          COALESCE(SUM(p.per_diem_rate * 7), 0) diem,
                          COALESCE(SUM(p.bill_rate * COALESCE(p.guarantee_hours, 0)), 0) bill
                   FROM placements p
                   WHERE p.job_id = ? AND p.status IN ('confirmed','travelling','on_site')
                     AND p.pay_rate IS NOT NULL", [$id]) ?: [];

    $f['priced']      = (int) ($priced['n'] ?? 0);
    $f['weekly_pay']  = (float) ($priced['pay'] ?? 0) + (float) ($priced['diem'] ?? 0);
    $f['weekly_bill'] = (float) ($priced['bill'] ?? 0);

    // What the agreement commits to at full strength is a different question,
    // answered by the scope of work rather than by who happens to be placed.
    require_once __DIR__ . '/scope.php';

    $scope = scope_totals($id, (bool) $job['strike_live']);

    $f['target']        = (int) $scope['people'];
    $f['scope_pay']     = (float) $scope['weekly_pay'];
    $f['scope_bill']    = (float) $scope['weekly_bill'];
    $f['scope_lines']   = (int) $scope['lines'];
    $f['scope_priced']  = (int) $scope['priced'];

    return $f;
}

/**
 * The things on a project that need a person, in the order they hurt.
 *
 * @return list<array{count:int,label:string,where:string,tone:string}>
 */
function project_exceptions(array $figures): array
{
    $out = [];

    foreach ([
        ['beds',       'with no bed',            '/hotels', 'red'],
        ['sharing',    'sharing a room',         '/hotels', 'red'],
        ['blocked',    'blocked from deploying', '/roster', 'amber'],
        ['travel',     'with travel unbooked',   '/travel', 'amber'],
        ['unapproved', 'weeks unapproved',       '/hours',  'amber'],
    ] as [$key, $label, $where, $tone]) {
        if (($figures[$key] ?? 0) > 0) {
            $out[] = ['count' => (int) $figures[$key], 'label' => $label,
                      'where' => $where, 'tone' => $tone];
        }
    }

    return $out;
}
