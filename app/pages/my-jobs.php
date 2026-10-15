<?php
/**
 * Jobs for you (T10): the open jobs a worker's file fits, and "I'm
 * interested". See app/job-matches.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../job-matches.php';

require_role('worker', 'supervisor');
$worker = jm_worker();
if (! $worker) {
    render('unlinked', []);
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    // P5-M45: when they can work, said by them.
    if ($do === 'availability') {
        require_once __DIR__ . '/../availability.php';
        $why = availability_set((int) $worker['id'], (string) ($_POST['availability'] ?? ''), (string) ($_POST['available_from'] ?? ''));
        if ($why !== null) { refuse(422, $why, '/my-jobs'); }
        flash(t('Thank you. Recruiting sees it right away.'));
        redirect('/my-jobs');
    }
    $why = match ($do) {
        'interest' => jm_interest($worker, (int) ($_POST['vacancy_id'] ?? 0), is_array($_POST['screening'] ?? null) ? $_POST['screening'] : []),
        'withdraw' => jm_withdraw($worker, (int) ($_POST['application_id'] ?? 0)),
        default    => t('Unknown action.'),
    };
    if ($why !== null) {
        refuse(422, $why, '/my-jobs');
    }
    flash($do === 'interest' ? t('Thank you. A recruiter will look at your interest and contact you.') : t('Done. You are no longer listed for this job.'));
    redirect('/my-jobs');
}

$jmJobs = jm_jobs_for($worker);
$jmWorker = $worker;
// P5-M44/M45: the interviews they are invited to, so the date reaches them in their portal.
$jmInterviews = val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'interviews'")
    ? rows("SELECT i.scheduled_at, i.duration_min, i.mode, i.place, v.title FROM interviews i JOIN applications a ON a.id = i.application_id JOIN vacancies v ON v.id = a.vacancy_id
            WHERE a.candidate_id = ? AND i.status = 'scheduled' AND i.scheduled_at >= NOW() - INTERVAL 2 HOUR ORDER BY i.scheduled_at", [(int) $worker['id']]) : [];
$pageTitle = t('Jobs for you') . ' · ' . $config['app_name'];
render('my-jobs', compact('jmJobs', 'jmWorker', 'jmInterviews'));
