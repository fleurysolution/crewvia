<?php
/** The team. Max and Jerry only. */

require_role('admin');
require_once __DIR__ . '/../security.php';

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
        // P5-M57: back on the person just added, whatever page of the staff they fall on.
        redirect('/people?tab=staff&q=' . rawurlencode($email));
    }

    if ($do === 'role') {
        $uidT = (int) ($_POST['user_id'] ?? 0);
        $role = (string) ($_POST['role'] ?? '');

        if (! array_key_exists($role, roles())) {
            redirect('/people');
        }
        // Walkthrough finding: choosing "Worker portal" here used to do nothing, silently.
        if ($role === 'worker') {
            flash(t('A worker signs in through the invitation sent from their candidate file, not by changing a desk.'), 'err');
            redirect('/people');
        }
        if ($role === 'client' && ! client_portal_enabled()) {
            refuse(422, t('Switch the client portal on in Client access before giving anyone the client desk.'), '/people');
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
        $before = (string) val('SELECT role FROM users WHERE id = ?', [$uidT]);
        q('UPDATE users SET role = ? WHERE id = ?', [$role, $uidT]);
        log_activity('changed a desk', 'user', $uidT, $before . ' -> ' . $role);
        flash(t('Desk changed.'));
        redirect('/people');
    }

    // Q1: an administrator clears a sign-in locked by failed attempts.
    if ($do === 'unlock') {
        $target = row('SELECT id, name, email FROM users WHERE id = ?', [(int) ($_POST['user_id'] ?? 0)]);
        if (! $target) {
            refuse(422, t('Select an account.'), '/people');
        }
        security_rate_clear('login-email', mb_strtolower(trim((string) $target['email'])));
        log_activity('unlocked a sign-in', 'user', (int) $target['id'], (string) $target['name']);
        flash(t('Sign-in unlocked for :name.', ['name' => $target['name']]));
        redirect('/people');
    }

    // P5-M88: who may hire, whatever their role.
    if ($do === 'hiring_manager') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $on = ! empty($_POST['on']) ? 1 : 0;
        if (val("SELECT id FROM users WHERE id = ? AND role NOT IN ('worker','client')", [$uid])) {
            q('UPDATE users SET hiring_manager = ? WHERE id = ?', [$on, $uid]);
            log_activity($on ? 'made a hiring manager' : 'removed a hiring manager', 'user', $uid);
            flash($on ? t(':name is a hiring manager.', ['name' => (string) val('SELECT name FROM users WHERE id = ?', [$uid])]) : t(':name is no longer a hiring manager.', ['name' => (string) val('SELECT name FROM users WHERE id = ?', [$uid])]));
        }
        // P5-M109: back to the list as it was - the search kept.
        $q = trim((string) ($_POST['q'] ?? ''));
        redirect('/people' . ($q !== '' ? '?q=' . rawurlencode($q) : ''));
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
        log_activity((int) val('SELECT is_active FROM users WHERE id = ?', [$uidT]) ? 'switched an account on' : 'switched an account off', 'user', $uidT);
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

// P5-M57: the staff first, on their own tab, with the form to add somebody on top. The employees'
// and clients' portal accounts (a thousand and more) have their own tabs, searched and paged; listed
// all together, they buried the staff and the form under them, and "Add somebody" could not be found.
const PEOPLE_STAFF = ['admin', 'recruiter', 'hr', 'hotels', 'payroll', 'supervisor'];
$peopleTab = in_array($_GET['tab'] ?? '', ['staff', 'clients', 'workers'], true) ? (string) $_GET['tab'] : 'staff';
$peopleQ = trim((string) ($_GET['q'] ?? ''));
$peoplePage = max(1, (int) ($_GET['page'] ?? 1));
$tabWhere = ['staff' => "role IN ('admin','recruiter','hr','hotels','payroll','supervisor')", 'clients' => "role = 'client'", 'workers' => "role = 'worker'"];
$peopleCounts = [];
foreach ($tabWhere as $k => $w) { $peopleCounts[$k] = (int) val('SELECT COUNT(*) FROM users WHERE ' . $w); }
$where = $tabWhere[$peopleTab];
$args = [];
if ($peopleQ !== '') { $where .= ' AND (name LIKE ? OR email LIKE ?)'; $args = ['%' . $peopleQ . '%', '%' . $peopleQ . '%']; }
$peopleFound = (int) val('SELECT COUNT(*) FROM users WHERE ' . $where, $args);
$peoplePer = 100;
$peoplePages = max(1, (int) ceil($peopleFound / $peoplePer));
$peoplePage = min($peoplePage, $peoplePages);
$team = rows('SELECT * FROM users WHERE ' . $where . ' ORDER BY is_active DESC, FIELD(role,"admin","recruiter","hotels","payroll","supervisor"), name LIMIT ' . $peoplePer . ' OFFSET ' . (($peoplePage - 1) * $peoplePer), $args);
$staffDesks = array_intersect_key(roles(), array_flip(PEOPLE_STAFF));
$lockedUntil = [];
foreach ($team as $member) {
    if ($until = security_login_locked_until((string) $member['email'])) {
        $lockedUntil[(int) $member['id']] = $until;
    }
}
$recent = rows('SELECT a.*, u.name AS who FROM activity a LEFT JOIN users u ON u.id = a.user_id
                ORDER BY a.id DESC LIMIT 25');

$pageTitle = t('Team').' · '.$config['app_name'];
render('people', compact('team','recent','lockedUntil','peopleTab','peopleQ','peoplePage','peoplePages','peopleFound','peopleCounts','staffDesks'));
