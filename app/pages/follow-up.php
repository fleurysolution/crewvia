<?php
/**
 * P5-M100: approved, waiting for the next step - a service report. People a hiring manager approved who are not
 * deployed yet, not declined, and not contacted again since. Counters per requisition and a list: work in progress
 * that may have stalled, and a pool of approved people ready for the next job. Figures only, no judgement.
 */

declare(strict_types=1);

require_role('recruiter');

$days = in_array((int) ($_GET['days'] ?? 14), [7, 14, 30, 60], true) ? (int) ($_GET['days'] ?? 14) : 14;
$since = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));

// The latest approval of each application, and where the person stands since.
$base = "SELECT a.id AS application_id, a.candidate_id, a.vacancy_id, a.stage, v.title AS requisition, j.title AS project, c.full_name, r.decided_at,
                EXISTS (SELECT 1 FROM placements p WHERE p.candidate_id = a.candidate_id AND p.job_id = v.job_id AND p.status IN ('travelling','on_site','completed')) AS deployed,
                (SELECT MAX(k.called_at) FROM candidate_calls k WHERE k.candidate_id = a.candidate_id AND k.called_at > r.decided_at) AS contacted_at
         FROM application_reviews r
         JOIN applications a ON a.id = r.application_id
         JOIN vacancies v ON v.id = a.vacancy_id
         JOIN jobs j ON j.id = v.job_id
         JOIN candidates c ON c.id = a.candidate_id
         WHERE r.status = 'validated' AND r.decided_at IS NOT NULL
           AND r.id = (SELECT MAX(r2.id) FROM application_reviews r2 WHERE r2.application_id = r.application_id AND r2.status = 'validated')";
$all = rows($base . ' ORDER BY r.decided_at');

$byReq = [];
$waiting = [];
foreach ($all as $x) {
    $k = (int) $x['vacancy_id'];
    $byReq[$k] ??= ['requisition' => $x['requisition'], 'project' => $x['project'], 'approved' => 0, 'deployed' => 0, 'waiting' => 0, 'quiet' => 0];
    $byReq[$k]['approved']++;
    if ((int) $x['deployed']) {
        $byReq[$k]['deployed']++;
        continue;
    }
    if (in_array($x['stage'], ['rejected', 'withdrawn'], true)) {
        continue;
    }
    $byReq[$k]['waiting']++;
    if ((string) $x['decided_at'] < $since && $x['contacted_at'] === null) {
        $byReq[$k]['quiet']++;
        $waiting[] = $x;
    }
}
uasort($byReq, static fn($a, $b) => $b['quiet'] <=> $a['quiet'] ?: strcmp((string) $a['requisition'], (string) $b['requisition']));

$pageTitle = t('Approved, next step') . ' · ' . $config['app_name'];
render('follow-up', compact('days', 'byReq', 'waiting'));
