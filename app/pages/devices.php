<?php
/**
 * Trusted phones (P5-M06b). A worker or a supervisor trusts the phone they
 * are on, sees their trusted phones and removes them. An administrator
 * sees every trusted phone and removes any.
 */

declare(strict_types=1);

require_once __DIR__ . '/../offline-sync.php';
require_once __DIR__ . '/../push.php';

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
    // P5-M06c: push notifications.
    if ($do === 'push_switch') {
        if (! $admin) { refuse(403, t('Only an administrator turns push notifications on or off.')); }
        $why = push_switch(($_POST['state'] ?? '') === 'on', uid());
        if ($why !== null) { refuse(422, $why); }
        flash(push_enabled() ? t('Push notifications are on. People turn them on for their own phone here.') : t('Push notifications are off. Nothing is sent.'));
        redirect('/devices#push');
    }
    if ($do === 'push_subscribe') {
        [$why] = push_subscribe($me, trim((string) ($_POST['endpoint'] ?? '')), (array) ($_POST['kinds'] ?? []), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($why !== null) { refuse(422, $why); }
        flash(t('Push notifications are on for this phone.'));
        redirect('/devices#push');
    }
    if ($do === 'push_unsubscribe') {
        push_end_cookie('removed');
        flash(t('Push notifications are off for this phone.'));
        redirect('/devices#push');
    }
    if ($do === 'push_remove') {
        $s = row('SELECT * FROM push_subscriptions WHERE id = ?', [(int) ($_POST['subscription_id'] ?? 0)]);
        if (! $s || ((int) $s['user_id'] !== uid() && ! $admin)) { refuse(404, t('That phone does not exist.')); }
        push_end((int) $s['id'], (int) $s['user_id'] === uid() ? 'removed' : 'removed_by_admin');
        flash(t('That phone gets no more push notifications.'));
        redirect('/devices#push');
    }
    if ($do === 'push_test') {
        [$why, $n] = push_test(uid());
        if ($why !== null) { refuse(422, $why); }
        flash(tn($n, 'A test push went to :n phone.', 'A test push went to :n phones.'));
        redirect('/devices#push');
    }
    refuse(400, t('Unknown action.'));
}

$current = os_current_grant();
$mine = os_grants_for(uid());
$everyone = $admin ? os_grants_for(null) : [];
$mayTrust = os_may_trust($me);
$push = ['on' => push_enabled(), 'key' => push_public_key(), 'here' => push_current_subscription(uid()),
         'mine' => rows('SELECT * FROM push_subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 20', [uid()]),
         'all' => $admin ? rows('SELECT s.*, u.name FROM push_subscriptions s JOIN users u ON u.id = s.user_id WHERE s.ended_at IS NULL ORDER BY u.name LIMIT 500') : []];
render('devices', compact('current', 'mine', 'everyone', 'mayTrust', 'admin', 'push'));
