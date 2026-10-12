<?php
/**
 * Trusted phones (P5-M06b). A worker or a supervisor trusts the phone they
 * are on, sees their trusted phones and removes them. An administrator
 * sees every trusted phone and removes any.
 */

declare(strict_types=1);

require_once __DIR__ . '/../offline-sync.php';

$me = user();
$admin = can('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'trust') {
        if (! os_may_trust($me)) { refuse(403, t('Offline attendance is for workers and supervisors.')); }
        os_grant_create($me, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        flash(t('This phone is trusted until :date. Open Attendance without signal from the offline page, or from here.', ['date' => d(date('Y-m-d', time() + os_trust_days() * 86400))]));
        redirect('/devices');
    }
    if ($do === 'forget') {
        os_revoke_cookie_grant(uid(), 'forgotten');
        flash(t('This phone is no longer trusted.'));
        redirect('/devices');
    }
    if ($do === 'revoke') {
        $g = row('SELECT * FROM device_grants WHERE id = ?', [(int) ($_POST['grant_id'] ?? 0)]);
        if (! $g || ((int) $g['user_id'] !== uid() && ! $admin)) { refuse(404, t('That phone does not exist.')); }
        os_revoke((int) $g['id'], uid(), (int) $g['user_id'] === uid() ? 'removed' : 'removed_by_admin');
        log_activity('removed a trusted phone', 'device_grant', (int) $g['id'], (string) $g['label']);
        flash(t('That phone is no longer trusted.'));
        redirect('/devices');
    }
    refuse(400, t('Unknown action.'));
}

$current = os_current_grant();
$mine = os_grants_for(uid());
$everyone = $admin ? os_grants_for(null) : [];
$mayTrust = os_may_trust($me);
render('devices', compact('current', 'mine', 'everyone', 'mayTrust', 'admin'));
