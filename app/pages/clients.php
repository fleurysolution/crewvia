<?php
/**
 * P5-M69: the clients, before their projects (the owner, 17 Oct 2026: "before a project you have a client").
 * A client is created here - its name, address, billing contact, payment terms, the person to call - and then
 * chosen when a project is taken on. Its projects, and who from the client opens the client portal, are shown.
 */

require_role('admin');

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
        q('UPDATE clients SET name = ?, address = ?, city = ?, state = ?, zip = ?, contact_name = ?, contact_email = ?, contact_phone = ?, billing_email = ?, payment_terms_days = ?, notes = ? WHERE id = ?',
          [$c['name'], $c['address'] ?: null, $c['city'] ?: null, $c['state'] ?: null, $c['zip'] ?: null, $c['contact_name'] ?: null, $c['contact_email'] ?: null,
           $c['contact_phone'] ?: null, $c['billing_email'] ?: null, $c['payment_terms_days'], $c['notes'] ?: null, $id]);
        log_activity('updated a client', 'client', $id, $c['name']);
        flash(t('Saved.'));
        redirect('/clients?id=' . $id);
    }
    refuse(422, t('Unknown action.'), '/clients');
}

$clients = rows("SELECT c.*, COUNT(j.id) AS projects, SUM(j.status IN ('planning','active')) AS live,
                        (SELECT COUNT(*) FROM client_access a WHERE a.client_id = c.id) AS portal_users
                 FROM clients c LEFT JOIN jobs j ON j.client_id = c.id GROUP BY c.id ORDER BY c.name LIMIT 1000");
$open = isset($_GET['id']) ? row('SELECT * FROM clients WHERE id = ?', [(int) $_GET['id']]) : null;
$openProjects = $open ? rows('SELECT id, title, status, site_city, site_state, starts_on, ends_on FROM jobs WHERE client_id = ? ORDER BY id DESC', [(int) $open['id']]) : [];
$old = $_SESSION['client_old'] ?? [];
unset($_SESSION['client_old']);
$pageTitle = t('Clients');
render('clients', compact('clients', 'open', 'openProjects', 'old'));
