<?php
/**
 * P5-M71: a client's directory of contacts, and who from the client is on each project (the owner and RSS,
 * 17 Oct 2026: "each client could have multiple points of contact... one main point of contact... in the
 * projects, select who from that company is in charge of that particular project").
 *
 * A client has as many contacts as it needs - the hiring manager, the site manager, accounts payable - and
 * one of them is its main point of contact (kept on the client too, where older screens read it). A project
 * chooses, from its client's contacts, the ones relevant to it and the one in charge of it.
 * The contacts ticked as receiving invoices are where the client's invoices go ("you could select which
 * person is set to billing email"); their emails are the client's invoice email.
 */

declare(strict_types=1);

function cc_contacts(int $clientId): array
{
    return rows('SELECT * FROM client_contacts WHERE client_id = ? ORDER BY is_main DESC, name', [$clientId]);
}

/** The main point of contact is mirrored on the client, where the list and older screens read it. */
function cc_sync_main(int $clientId): void
{
    $main = row('SELECT name, email, phone FROM client_contacts WHERE client_id = ? ORDER BY is_main DESC, id LIMIT 1', [$clientId]);
    q('UPDATE clients SET contact_name = ?, contact_email = ?, contact_phone = ? WHERE id = ?', [$main['name'] ?? null, $main['email'] ?? null, $main['phone'] ?? null, $clientId]);
    // The invoice email is the emails of the contacts receiving invoices; with none ticked, the one typed on the client stays.
    $billing = cc_invoice_recipients($clientId);
    if ($billing) {
        q('UPDATE clients SET billing_email = ? WHERE id = ?', [mb_substr(implode(', ', array_column($billing, 'email')), 0, 190), $clientId]);
    }
}

/** The contacts the client's invoices go to: ticked as receiving invoices, with an email. */
function cc_invoice_recipients(int $clientId): array
{
    return rows("SELECT * FROM client_contacts WHERE client_id = ? AND receives_invoices = 1 AND NULLIF(email, '') IS NOT NULL ORDER BY is_main DESC, name", [$clientId]);
}

/** Adds ($id 0) or updates a contact of the client. Returns the refusal, or null. */
function cc_save(int $clientId, array $in, int $id = 0, ?int $by = null): ?string
{
    $v = static fn(string $k, int $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
    $c = ['name' => $v('name', 120), 'title' => $v('title', 120), 'email' => $v('email', 190), 'phone' => $v('phone', 40), 'notes' => $v('notes', 500)];
    if (mb_strlen($c['name']) < 2) {
        return t('Give the contact\'s name.');
    }
    if ($c['email'] !== '' && ! filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
        return t('Check the email address.');
    }
    if ($c['email'] === '' && $c['phone'] === '') {
        return t('Give an email or a phone number, so the contact can be reached.');
    }
    $invoices = ! empty($in['receives_invoices']) ? 1 : 0;
    if ($invoices && $c['email'] === '') {
        return t('A contact who receives the invoices needs an email.');
    }
    $main = ! empty($in['is_main']) || ! val('SELECT COUNT(*) FROM client_contacts WHERE client_id = ? AND id <> ?', [$clientId, $id]);
    if ($id) {
        if (! val('SELECT id FROM client_contacts WHERE id = ? AND client_id = ?', [$id, $clientId])) {
            return t('That contact is not this client\'s.');
        }
        q('UPDATE client_contacts SET name = ?, title = ?, email = ?, phone = ?, notes = ?, receives_invoices = ? WHERE id = ?', [$c['name'], $c['title'] ?: null, $c['email'] ?: null, $c['phone'] ?: null, $c['notes'] ?: null, $invoices, $id]);
    } else {
        q('INSERT INTO client_contacts (client_id, name, title, email, phone, notes, receives_invoices, created_by) VALUES (?,?,?,?,?,?,?,?)',
          [$clientId, $c['name'], $c['title'] ?: null, $c['email'] ?: null, $c['phone'] ?: null, $c['notes'] ?: null, $invoices, $by]);
        $id = (int) db()->lastInsertId();
    }
    if ($main) {
        cc_set_main($clientId, $id);
    }
    cc_sync_main($clientId);

    return null;
}

function cc_set_main(int $clientId, int $id): void
{
    q('UPDATE client_contacts SET is_main = (id = ?) WHERE client_id = ?', [$id, $clientId]);
    cc_sync_main($clientId);
}

/** Removes a contact; it leaves the projects it was on. The next one becomes the main contact if it was. */
function cc_remove(int $clientId, int $id): void
{
    $wasMain = (int) val('SELECT is_main FROM client_contacts WHERE id = ? AND client_id = ?', [$id, $clientId]);
    q('DELETE FROM job_contacts WHERE contact_id = ?', [$id]);
    q('DELETE FROM client_contacts WHERE id = ? AND client_id = ?', [$id, $clientId]);
    if ($wasMain && ($next = (int) val('SELECT id FROM client_contacts WHERE client_id = ? ORDER BY id LIMIT 1', [$clientId]))) {
        cc_set_main($clientId, $next);
    }
    cc_sync_main($clientId);
}

/** The project's contacts, the one in charge first. */
function cc_job_contacts(int $jobId): array
{
    return rows('SELECT c.*, jc.in_charge FROM job_contacts jc JOIN client_contacts c ON c.id = jc.contact_id WHERE jc.job_id = ? ORDER BY jc.in_charge DESC, c.name', [$jobId]);
}

/** Sets who from the client is on the project, and who is in charge (one of them). Returns the refusal, or null. */
function cc_job_save(int $jobId, int $clientId, array $ids, int $inCharge): ?string
{
    $own = array_map('intval', array_column(cc_contacts($clientId), 'id'));
    $ids = array_values(array_intersect(array_map('intval', $ids), $own));
    if ($inCharge && ! in_array($inCharge, $own, true)) {
        return t('That contact is not this client\'s.');
    }
    if ($inCharge && ! in_array($inCharge, $ids, true)) {
        $ids[] = $inCharge;
    }
    q('DELETE FROM job_contacts WHERE job_id = ?', [$jobId]);
    foreach ($ids as $id) {
        q('INSERT INTO job_contacts (job_id, contact_id, in_charge) VALUES (?,?,?)', [$jobId, $id, (int) ($id === $inCharge)]);
    }
    log_activity('set the client contacts of the project', 'job', $jobId);

    return null;
}
