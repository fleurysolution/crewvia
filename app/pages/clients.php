<?php
/**
 * P5-M69: the clients, before their projects (the owner, 17 Oct 2026: "before a project you have a client").
 * A client is created here - its name, address, billing contact, payment terms, the person to call - and then
 * chosen when a project is taken on. Its projects, and who from the client opens the client portal, are shown.
 * P5-M71: each client has a directory of contacts, one of them its main point of contact (app/client-contacts.php).
 */

require_role('admin');
require_once __DIR__ . '/../client-contacts.php';

$fields = static function (array $in): array {
    $v = static fn(string $k, int $max = 190): string => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);

    return ['name' => $v('name'), 'address' => $v('address'), 'city' => $v('city', 120), 'state' => strtoupper($v('state', 2)), 'zip' => $v('zip', 10),
            'contact_name' => $v('contact_name', 120), 'contact_email' => $v('contact_email'), 'contact_phone' => $v('contact_phone', 40),
            'billing_email' => $v('billing_email'), 'payment_terms_days' => max(0, min(180, (int) ($in['payment_terms_days'] ?? 30))), 'notes' => $v('notes', 2000)];
};
$check = static function (array $c, int $id = 0): ?string {
    if (mb_strlen($c['name']) < 2) {
        return t('Give the client\'s name.');
    }
    if (val('SELECT id FROM clients WHERE LOWER(name) = LOWER(?) AND id <> ?', [$c['name'], $id])) {
        return t('A client already has this name: open it instead of making a second one.');
    }
    foreach (['contact_email', 'billing_email'] as $k) {
        if ($c[$k] !== '' && ! filter_var($c[$k], FILTER_VALIDATE_EMAIL)) {
            return t('Check the email address.');
        }
    }
    if ($c['zip'] !== '' && ! preg_match('/^\d{5}(-\d{4})?$/D', $c['zip'])) {
        return t('Give the ZIP code: five digits, or five and four.');
    }
    if (! $id && $c['contact_name'] !== '' && $c['contact_email'] === '' && $c['contact_phone'] === '') {
        return t('Give an email or a phone number, so the contact can be reached.');
    }

    return null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $c = $fields($_POST);
    if ($do === 'create') {
        if ($why = $check($c)) {
            $_SESSION['client_old'] = $c;
            refuse(422, $why, '/clients#new');
        }
        q('INSERT INTO clients (name, address, city, state, zip, contact_name, contact_email, contact_phone, billing_email, payment_terms_days, notes, created_by)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [$c['name'], $c['address'] ?: null, $c['city'] ?: null, $c['state'] ?: null, $c['zip'] ?: null, $c['contact_name'] ?: null,
           $c['contact_email'] ?: null, $c['contact_phone'] ?: null, $c['billing_email'] ?: null, $c['payment_terms_days'], $c['notes'] ?: null, uid()]);
        $id = (int) db()->lastInsertId();
        // P5-M71: the contact given with the client is its first, main point of contact.
        if ($c['contact_name'] !== '' || $c['contact_email'] !== '' || $c['contact_phone'] !== '') {
            cc_save($id, ['name' => $c['contact_name'] ?: ($c['contact_email'] ?: $c['contact_phone']), 'title' => (string) ($_POST['contact_title'] ?? ''), 'email' => $c['contact_email'], 'phone' => $c['contact_phone'], 'is_main' => 1,
                              'receives_invoices' => ! empty($_POST['contact_invoices']) && $c['contact_email'] !== ''], 0, uid());
        }
        log_activity('created a client', 'client', $id, $c['name']);
        flash(t(':name added. Take on their first project now.', ['name' => $c['name']]));
        redirect('/projects?client_id=' . $id . '#new-project');
    }
    if ($do === 'update') {
        $id = (int) ($_POST['client_id'] ?? 0);
        if (! val('SELECT id FROM clients WHERE id = ?', [$id])) {
            refuse(404, t('That client does not exist.'), '/clients');
        }
        if ($why = $check($c, $id)) {
            refuse(422, $why, '/clients?id=' . $id);
        }
        // P5-M71: the contacts are kept in the client's directory, below; the main one is mirrored on the client.
        if (cc_invoice_recipients($id)) {
            $c['billing_email'] = (string) val('SELECT billing_email FROM clients WHERE id = ?', [$id]);
        }
        q('UPDATE clients SET name = ?, address = ?, city = ?, state = ?, zip = ?, billing_email = ?, payment_terms_days = ?, notes = ? WHERE id = ?',
          [$c['name'], $c['address'] ?: null, $c['city'] ?: null, $c['state'] ?: null, $c['zip'] ?: null, $c['billing_email'] ?: null, $c['payment_terms_days'], $c['notes'] ?: null, $id]);
        log_activity('updated a client', 'client', $id, $c['name']);
        flash(t('Saved.'));
        redirect('/clients?id=' . $id);
    }
    // ── P5-M71: the client's contacts ──
    if (in_array($do, ['contact_add', 'contact_update', 'contact_main', 'contact_remove'], true)) {
        $id = (int) ($_POST['client_id'] ?? 0);
        $back = '/clients?id=' . $id . '#contacts';
        if (! val('SELECT id FROM clients WHERE id = ?', [$id])) {
            refuse(404, t('That client does not exist.'), '/clients');
        }
        $contact = (int) ($_POST['contact_id'] ?? 0);
        if ($do !== 'contact_add' && ! val('SELECT id FROM client_contacts WHERE id = ? AND client_id = ?', [$contact, $id])) {
            refuse(404, t('That contact is not this client\'s.'), $back);
        }
        if ($do === 'contact_main') {
            cc_set_main($id, $contact);
            flash(t('Main point of contact changed.'));
        } elseif ($do === 'contact_remove') {
            cc_remove($id, $contact);
            flash(t('Contact removed. They are no longer on this client\'s projects.'));
        } else {
            if ($why = cc_save($id, [
                'name' => $_POST['cc_name'] ?? '', 'title' => $_POST['cc_title'] ?? '', 'email' => $_POST['cc_email'] ?? '', 'phone' => $_POST['cc_phone'] ?? '',
                'notes' => $_POST['cc_notes'] ?? '', 'is_main' => ! empty($_POST['cc_main']), 'receives_invoices' => ! empty($_POST['cc_invoices'])], $do === 'contact_update' ? $contact : 0, uid())) {
                refuse(422, $why, $back);
            }
            log_activity($do === 'contact_add' ? 'added a client contact' : 'updated a client contact', 'client', $id);
            flash($do === 'contact_add' ? t('Contact added. Choose them on the projects they are part of.') : t('Saved.'));
        }
        redirect($back);
    }
    refuse(422, t('Unknown action.'), '/clients');
}

$clients = rows("SELECT c.*, (SELECT COUNT(*) FROM client_contacts cc WHERE cc.client_id = c.id) AS contacts, COUNT(j.id) AS projects, SUM(j.status IN ('planning','active')) AS live,
                        (SELECT COUNT(*) FROM client_access a WHERE a.client_id = c.id) AS portal_users
                 FROM clients c LEFT JOIN jobs j ON j.client_id = c.id GROUP BY c.id ORDER BY c.name LIMIT 1000");
$open = isset($_GET['id']) ? row('SELECT * FROM clients WHERE id = ?', [(int) $_GET['id']]) : null;
$openProjects = $open ? rows('SELECT j.id, j.title, j.status, j.site_city, j.site_state, j.starts_on, j.ends_on,
                                     (SELECT c.name FROM job_contacts jc JOIN client_contacts c ON c.id = jc.contact_id WHERE jc.job_id = j.id AND jc.in_charge = 1 LIMIT 1) AS in_charge
                              FROM jobs j WHERE j.client_id = ? ORDER BY j.id DESC', [(int) $open['id']]) : [];
$contacts = $open ? rows('SELECT c.*, (SELECT COUNT(*) FROM job_contacts jc WHERE jc.contact_id = c.id) AS on_projects FROM client_contacts c WHERE c.client_id = ? ORDER BY c.is_main DESC, c.name', [(int) $open['id']]) : [];
$invoiceTo = $open ? cc_invoice_recipients((int) $open['id']) : [];
$editContact = isset($_GET['contact']) && $open ? row('SELECT * FROM client_contacts WHERE id = ? AND client_id = ?', [(int) $_GET['contact'], (int) $open['id']]) : null;
$old = $_SESSION['client_old'] ?? [];
unset($_SESSION['client_old']);
$pageTitle = t('Clients');
render('clients', compact('clients', 'open', 'openProjects', 'old', 'contacts', 'editContact', 'invoiceTo'));
