<?php
/**
 * Activity: everything waiting on the person reading it.
 *
 * Nothing is completed here. Each line links to the screen that does the
 * work, so a number falls because the job was done - not because somebody
 * dismissed it.
 */

require_login();
require_once __DIR__ . '/../activity.php';

$items = activity_items();
$total = activity_total();

$job = current_job();

// The countdown Jerry asked for: how many of the promised headcount are
// actually on the job, and how many are left to place.
$target = (int) ($job['headcount_target'] ?? 0);
$filled = $job
    ? (int) val("SELECT COUNT(*) FROM placements
                 WHERE job_id = ? AND status IN ('confirmed','travelling','on_site')",
                [(int) $job['id']])
    : 0;

$recent = rows('SELECT message, target, created_at, read_at
                FROM notifications WHERE user_id = ?
                ORDER BY id DESC LIMIT 12', [uid()]);

// The workforce at a glance, for staff on the working project.
require_once __DIR__ . '/../workforce-overview.php';
$wf = null;

if ($job && workforce_overview_visible()) {
    $jobId = (int) $job['id'];
    $wf = [
        'today'   => workforce_today($jobId),
        'present' => workforce_daily($jobId, 30, true),
        'absent'  => workforce_daily($jobId, 15, false),
        'recent'  => workforce_recently_placed($jobId),
    ];
}

$pageTitle = t('Activity') . ' · ' . $config['app_name'];

render('activity', compact('items', 'total', 'job', 'target', 'filled', 'recent', 'wf'));
