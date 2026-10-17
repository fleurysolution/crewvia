<?php
/**
 * P5-M67: the worker scanned their supervisor's code. One button: clock in, or clock out when already in;
 * the phone's position goes with it. The code is checked when the button is pressed (it lives 60 to 90 s).
 */

require_login();
require_once __DIR__ . '/../clock-in.php';

$cid = (int) val('SELECT candidate_id FROM worker_accounts WHERE user_id = ?', [uid()]);
if (! $cid) {
    refuse(403, t('Clock in from your own worker account.'), '/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$why, $done] = ci_qr_scan($cid, (string) ($_POST['t'] ?? ''), $_POST, uid());
    if ($why !== null) {
        log_activity('was refused a clock-in by code', 'candidate', $cid, $why);
        refuse(422, $why, '/portal#clock');
    }
    flash($done === 'out' ? t('Clocked out. Your supervisor is told.') : t('Clocked in. Your supervisor validates it.'));
    redirect('/portal#clock');
}

$token = (string) ($_GET['t'] ?? '');
$qr = ci_qr_check($token);
$job = $qr ? row('SELECT id, title FROM jobs WHERE id = ?', [$qr[1]]) : null;
$pid = $job ? (int) val("SELECT id FROM placements WHERE candidate_id = ? AND job_id = ? AND status IN ('confirmed','travelling','on_site') ORDER BY id DESC LIMIT 1", [$cid, (int) $job['id']]) : 0;
$open = $pid ? row('SELECT * FROM clock_ins WHERE placement_id = ? AND clocked_out_at IS NULL AND work_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) ORDER BY id DESC LIMIT 1', [$pid]) : null;
$pageTitle = t('Clock in');
render('clock', compact('token', 'qr', 'job', 'pid', 'open'));
