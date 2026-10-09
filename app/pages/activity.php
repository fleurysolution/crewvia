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

$pageTitle = t('Activity') . ' · ' . $config['app_name'];

render('activity', compact('items', 'total', 'job', 'target', 'filled', 'recent'));
