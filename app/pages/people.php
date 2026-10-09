<?php
/** The team. Max and Jerry only. */

require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';

    if ($do === 'add') {
        $name  = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $role  = (string) ($_POST['role'] ?? 'recruiter');

        if ($name === '' || $email === '' || (! array_key_exists($role, roles()) || in_array($role,['worker','client'],true))) {
            flash(t('Name, email and a desk are all needed.'), 'err');
            redirect('/people');
        }

        if (row('SELECT id FROM users WHERE email = ?', [$email])) {
            flash(t('Somebody already uses that email.'), 'err');
            redirect('/people');
        }

        $temp = bin2hex(random_bytes(8));

        q('INSERT INTO users (name, email, phone, password_hash, role, job_title, must_change_pw)
           VALUES (?,?,?,?,?,?,1)',
          [$name, $email, trim((string) ($_POST['phone'] ?? '')) ?: null,
           password_hash($temp, PASSWORD_DEFAULT), $role,
           trim((string) ($_POST['job_title'] ?? '')) ?: null]);

        log_activity('added a user', 'user', (int) db()->lastInsertId(), $name . ' (' . $role . ')');
        flash(t(':name added. Their temporary password is: :password', ['name'=>$name,'password'=>$temp]));
        redirect('/people');
    }

    if ($do === 'role') {
        $uidT = (int) ($_POST['user_id'] ?? 0);
        $role = (string) ($_POST['role'] ?? '');

        if ((! array_key_exists($role, roles()) || $role === 'worker')) {
            redirect('/people');
        }

        // Never leave the account with nobody who can get back in.
        if ($role !== 'admin') {
            $admins = (int) val("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1 AND id <> ?", [$uidT]);

            if ($admins === 0) {
                flash(t('That is the last person who can see everything. Promote somebody else first.'), 'err');
                redirect('/people');
            }
        }

        if (row('SELECT user_id FROM worker_accounts WHERE user_id=?', [$uidT])) { flash(t('Worker identity cannot be converted to a staff account.'), 'err'); redirect('/people'); }
        q('UPDATE users SET role = ? WHERE id = ?', [$role, $uidT]);
        log_activity('changed a desk', 'user', $uidT, $role);
        flash(t('Desk changed.'));
        redirect('/people');
    }

    if ($do === 'toggle') {
        $uidT = (int) ($_POST['user_id'] ?? 0);

        if ($uidT === uid()) {
            flash(t('You cannot switch off your own account.'), 'err');
            redirect('/people');
        }

        $target=row('SELECT role,is_active FROM users WHERE id=?',[$uidT]);
        if ($target && $target['role']==='admin' && $target['is_active'] && !(int)val("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1 AND id<>?",[$uidT])) { flash(t('Keep at least one active administrator.'),'err'); redirect('/people'); }
        q('UPDATE users SET is_active = 1 - is_active WHERE id = ?', [$uidT]);
        flash(t('Account updated.'));
        redirect('/people');
    }

    if ($do === 'reset') {
        $uidT = (int) ($_POST['user_id'] ?? 0);
        $temp = bin2hex(random_bytes(8));

        q('UPDATE users SET password_hash = ?, must_change_pw = 1 WHERE id = ?',
          [password_hash($temp, PASSWORD_DEFAULT), $uidT]);

        $who = row('SELECT name FROM users WHERE id = ?', [$uidT]);
        log_activity('reset a password', 'user', $uidT, (string) ($who['name'] ?? ''));
        flash(t('New temporary password for :name: :password', ['name'=>$who['name'] ?? '', 'password'=>$temp]));
        redirect('/people');
    }
}

$team = rows('SELECT * FROM users ORDER BY FIELD(role,"admin","recruiter","hotels","payroll"), name');
$recent = rows('SELECT a.*, u.name AS who FROM activity a LEFT JOIN users u ON u.id = a.user_id
                ORDER BY a.id DESC LIMIT 25');

$pageTitle = t('Team').' · '.$config['app_name'];
render('people', compact('team','recent'));
