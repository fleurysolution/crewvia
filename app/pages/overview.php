<?php
/**
 * Executive overview: every project at once.
 *
 * This was four counters and a list. The question an owner actually opens it
 * with is different: how many people are out, across how many jobs, what is
 * that worth this week, and what is broken right now.
 *
 * Each project is read through the same functions the project workspace uses,
 * so the portfolio and the project can never disagree.
 */

require_role('admin');
require_once __DIR__ . '/../lifecycle.php';
require_once __DIR__ . '/../projects.php';

$jobs = rows('SELECT j.*, c.name AS client_name
              FROM jobs j LEFT JOIN clients c ON c.id = j.client_id
              ORDER BY j.id DESC');

$projects = [];

$totals = ['placed' => 0, 'onsite' => 0, 'target' => 0, 'offered' => 0,
           'weekly_pay' => 0.0, 'weekly_bill' => 0.0, 'exceptions' => 0,
           'running' => 0, 'hours' => 0.0];

foreach ($jobs as $job) {
    $figures    = project_figures($job);
    $stage      = lifecycle_stage($job);
    $signals    = lifecycle_signals($job);
    $exceptions = project_exceptions($figures);

    // How each trade on the order stands. A portfolio row that only gives a
    // total cannot say which line is the one that is short.
    $trades = rows(
        "SELECT l.id, l.role_title, l.quantity,
                (SELECT COUNT(*) FROM placements p
                   JOIN applications a ON a.candidate_id = p.candidate_id
                   JOIN vacancies v ON v.id = a.vacancy_id AND v.job_id = p.job_id
                  WHERE v.order_line_id = l.id
                    AND p.status IN ('confirmed','travelling','on_site')) placed
         FROM job_order_lines l
         WHERE l.job_id = ?
         ORDER BY l.sort_order, l.id", [(int) $job['id']]);

    $projects[] = [
        'job'        => $job,
        'trades'     => $trades,
        'stage'      => $stage,
        'figures'    => $figures,
        'exceptions' => $exceptions,
        'met'        => lifecycle_met($signals),
        'signals'    => count($signals),
        'next'       => lifecycle_next($stage),
    ];

    if ($stage === 'closed') {
        continue;
    }

    $totals['running']++;
    $totals['placed']      += $figures['placed'];
    $totals['onsite']      += $figures['onsite'];
    $totals['target']      += $figures['target'];
    $totals['offered']     += $figures['offered'];
    $totals['weekly_pay']  += $figures['weekly_pay'];
    $totals['weekly_bill'] += $figures['weekly_bill'];
    $totals['hours']       += $figures['hours'];
    $totals['exceptions']  += array_sum(array_column($exceptions, 'count'));
}

// A project in flight sits above one that is finished, and the busiest first.
usort($projects, static function (array $a, array $b): int {
    $closed = ($a['stage'] === 'closed' ? 1 : 0) <=> ($b['stage'] === 'closed' ? 1 : 0);

    return $closed !== 0
        ? $closed
        : ($b['figures']['placed'] <=> $a['figures']['placed']);
});

$incidents = (int) val("SELECT COUNT(*) FROM safety_incidents WHERE status <> 'resolved'");
$unplaced  = max(0, $totals['target'] - $totals['placed']);

$pageTitle = t('Executive overview') . ' · ' . $config['app_name'];

render('overview', compact('projects', 'totals', 'incidents', 'unplaced'));
