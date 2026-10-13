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
$pageTitle = t('Jobs for you') . ' · ' . $config['app_name'];
render('my-jobs', compact('jmJobs', 'jmWorker'));
