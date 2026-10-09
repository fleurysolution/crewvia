<?php
/** Change your own password. */

require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cur = (string) ($_POST['current'] ?? '');
    $new = (string) ($_POST['new'] ?? '');
    $rpt = (string) ($_POST['repeat'] ?? '');

    $me = row('SELECT * FROM users WHERE id = ?', [uid()]);

    if (! $me || ! password_verify($cur, $me['password_hash'])) {
        flash(t('That is not your current password.'), 'err');
        redirect('/account');
    }

    if (strlen($new) < 10) {
        flash(t('Use at least 10 characters. Length beats cleverness.'), 'err');
        redirect('/account');
    }

    if ($new !== $rpt) {
        flash(t('The two new passwords do not match.'), 'err');
        redirect('/account');
    }

    q('UPDATE users SET password_hash = ?, must_change_pw = 0 WHERE id = ?',
      [password_hash($new, PASSWORD_DEFAULT), uid()]);

    q('INSERT INTO account_security(user_id,session_version) VALUES (?,1) ON DUPLICATE KEY UPDATE session_version=session_version+1',[uid()]);
    $_SESSION['auth_version']=(int)val('SELECT session_version FROM account_security WHERE user_id=?',[uid()]);
    log_activity('changed their password');
    flash(t('Password changed.'));
    redirect('/');
}

$me = row('SELECT * FROM users WHERE id = ?', [uid()]);

$pageTitle = t('My account').' · '.$config['app_name'];
render('account', compact('me'));
