<?php
/**
 * P5-M67: the supervisor's clock-in code, on their own phone. It changes every 30 seconds; the workers of
 * this project scan it to clock in and out. The screen shows who scanned today, and what is unusual.
 */

require_role('supervisor', 'recruiter');
require_once __DIR__ . '/../clock-in.php';

$jobs = can('recruiter') ? array_column(rows("SELECT id FROM jobs WHERE status = 'active'"), 'id') : supervised_job_ids(uid());
$jobId = (int) ($_SESSION['job_id'] ?? 0);
if (! in_array($jobId, array_map('intval', $jobs), true)) {
    $jobId = (int) ($jobs[0] ?? 0);
}
$job = $jobId ? row('SELECT id, title, site_lat, site_radius_m FROM jobs WHERE id = ?', [$jobId]) : null;

$feed = static function () use ($jobId): array {
    global $config;
    $token = ci_qr_token(uid(), $jobId);
    $scans = [];
    foreach (rows("SELECT ci.*, c.full_name FROM clock_ins ci JOIN placements p ON p.id = ci.placement_id JOIN candidates c ON c.id = p.candidate_id
                   WHERE p.job_id = ? AND ci.work_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) ORDER BY GREATEST(ci.clocked_in_at, COALESCE(ci.clocked_out_at, ci.clocked_in_at)) DESC LIMIT 40", [$jobId]) as $c) {
        $out = $c['clocked_out_at'] !== null;
        $flag = trim((string) ($out ? $c['out_flag'] : $c['in_flag']));
        $scans[] = ['text' => $c['full_name'] . ' · ' . ($out ? t('out at :time (:h h)', ['time' => date('g:i a', strtotime($c['clocked_out_at'])), 'h' => ci_hours($c)]) : t('in at :time', ['time' => date('g:i a', strtotime($c['clocked_in_at']))])) . ($flag !== '' ? ' · ' . $flag : ''), 'flag' => $flag !== ''];
    }

    return ['url' => rtrim((string) ($config['app_url'] ?? ''), '/') . '/clock?t=' . $token, 'expires_in' => CLOCK_QR_SECONDS - time() % CLOCK_QR_SECONDS, 'scans' => $scans];
};

if (isset($_GET['feed'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($jobId ? $feed() : ['url' => '', 'expires_in' => 30, 'scans' => []]);
    exit;
}
$now = $jobId ? $feed() : null;
$pageTitle = t('Clock-in code');
render('clock-qr', compact('job', 'now'));
