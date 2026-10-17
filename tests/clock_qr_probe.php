<?php
/**
 * The P5-M67 test's eyes, on the test database only.
 *   ids               the walkthrough supervisor, their project, the clock-in workers' placements
 *   site JOB LAT LNG   sets the site position (radius 300 m)
 *   clock PLACEMENT    the clock-ins of a placement, newest first
 *   token SUP JOB AGE  a token as the supervisor's screen would have shown it AGE seconds ago
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/clock-in.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
$mode = $argv[1] ?? '';
if ($mode === 'ids') {
    $sup = (int) val("SELECT id FROM users WHERE email = 'wk-sup@test.invalid'");
    $placement = static fn(string $email): ?array => row('SELECT p.id, p.job_id FROM worker_accounts w JOIN users u ON u.id = w.user_id JOIN placements p ON p.candidate_id = w.candidate_id
                                                         WHERE u.email = ? AND p.status = ? ORDER BY p.id DESC LIMIT 1', [$email, 'on_site']) ?: null;
    echo json_encode(['sup' => $sup, 'jobs' => supervised_job_ids($sup), 'ci_worker' => $placement('ci-worker@test.invalid'), 'wk_w' => $placement('wk-w@test.invalid')]);
} elseif ($mode === 'site') {
    q('UPDATE jobs SET site_lat = ?, site_lng = ?, site_radius_m = 300 WHERE id = ?', [(float) $argv[3], (float) $argv[4], (int) $argv[2]]);
    echo '{}';
} elseif ($mode === 'clock') {
    echo json_encode(rows('SELECT id, work_date, status, in_method, in_flag, in_distance_m, clocked_out_at, out_method, out_flag, out_distance_m, qr_supervisor_id FROM clock_ins WHERE placement_id = ? ORDER BY id DESC', [(int) $argv[2]]));
} elseif ($mode === 'token') {
    echo json_encode(ci_qr_token((int) $argv[2], (int) $argv[3], intdiv(time() - (int) $argv[4], CLOCK_QR_SECONDS)));
}
