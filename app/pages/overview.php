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
// T25: each kind of problem added up across the running projects, so the
// page says "3 with no bed" once instead of once per project.
$problems = [];

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
    foreach ($exceptions as $x) {
        $problems[$x['label']] ??= ['count' => 0, 'label' => $x['label'], 'where' => $x['where'], 'tone' => $x['tone'], 'projects' => 0];
        $problems[$x['label']]['count'] += $x['count'];
        $problems[$x['label']]['projects']++;
    }
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

// T25: the figures above the table, in three groups - people, this week's
// money, and what needs somebody today - each leading to where it is worked.
$running = "SELECT j.id FROM jobs j WHERE j.status <> 'closed'";
$hiring = row("SELECT COALESCE(SUM(a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)), 0) AS new_week,
                      COALESCE(SUM(a.stage IN ('screening','interview')), 0) AS reviewing,
                      COALESCE(SUM(a.stage = 'offered'), 0) AS offered,
                      COUNT(DISTINCT CASE WHEN v.is_open = 1 THEN v.id END) AS open_reqs
               FROM vacancies v LEFT JOIN applications a ON a.vacancy_id = v.id
               WHERE v.job_id IN ($running)") ?: ['new_week' => 0, 'reviewing' => 0, 'offered' => 0, 'open_reqs' => 0];
$attention = array_values($problems);
foreach ([
    [$incidents, 'open safety incidents', '/learning', 'red'],
    [(int) val("SELECT COUNT(*) FROM attendance_lateness WHERE status = 'disputed'"), 'lateness disputes to settle', '/lateness', 'amber'],
    [(int) val("SELECT COUNT(*) FROM hotel_folios WHERE status = 'open'"), 'hotel folios still open', '/folios', 'amber'],
    [(int) val("SELECT COUNT(*) FROM vendor_invoices WHERE status <> 'paid' AND due_on < CURDATE()"), 'bills past due', '/accounts-payable', 'red'],
] as [$n, $label, $where, $tone]) {
    if ($n > 0) {
        $attention[] = ['count' => $n, 'label' => $label, 'where' => $where, 'tone' => $tone, 'projects' => null];
    }
}
usort($attention, static fn($a, $b) => [$a['tone'] !== 'red', -$a['count']] <=> [$b['tone'] !== 'red', -$b['count']]);

// The table, narrowed to one stage when asked.
$stageCounts = array_count_values(array_column($projects, 'stage'));
$stageFilter = isset(lifecycle_stages()[$_GET['stage'] ?? '']) ? (string) $_GET['stage'] : '';
$shown = $stageFilter === '' ? $projects : array_values(array_filter($projects, static fn($p) => $p['stage'] === $stageFilter));

$pageTitle = t('Executive overview') . ' · ' . $config['app_name'];

render('overview', compact('projects', 'totals', 'incidents', 'unplaced', 'hiring', 'attention', 'stageCounts', 'stageFilter', 'shown'));
