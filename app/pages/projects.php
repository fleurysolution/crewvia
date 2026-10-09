<?php
/**
 * Projects: the agreements this agency is working under.
 *
 * A project is an agreement between two parties. It has a description of the
 * work - "provide labour support to the plant in accordance with the scope of
 * work" - a client, a site, dates, and a statement of what it covers.
 *
 * It does not have a pay rate. The earlier version asked for one pay rate,
 * one bill rate, one per diem and one guarantee as though a project paid a
 * single number, which is not how this work is sold or delivered: an engineer
 * and a labourer on the same site are neither paid nor billed the same. Those
 * terms belong to the lines of the scope of work, written on the project's
 * own screen once it exists.
 */

require_role('admin');
require_once __DIR__ . '/../scope.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title  = trim((string) ($_POST['title'] ?? ''));
    $client = trim((string) ($_POST['client'] ?? ''));

    if ($title === '' || $client === '') {
        flash(t('An agreement needs a client and a name for the work.'), 'err');
        redirect('/projects');
    }

    $description = trim((string) ($_POST['description'] ?? ''));

    if (mb_strlen($description) > 8000) {
        flash(t('The scope description is too long.'), 'err');
        redirect('/projects');
    }

    $startsOn = (string) ($_POST['starts_on'] ?? '');
    $endsOn   = (string) ($_POST['ends_on'] ?? '');

    if ($startsOn !== '' && ! valid_date($startsOn)) { $startsOn = ''; }
    if ($endsOn   !== '' && ! valid_date($endsOn))   { $endsOn   = ''; }

    if ($startsOn !== '' && $endsOn !== '' && $endsOn < $startsOn) {
        flash(t('The agreement cannot end before it starts.'), 'err');
        redirect('/projects');
    }

    db()->beginTransaction();

    // An existing client is reused rather than duplicated: two rows carrying
    // the same company name would split that client's work apart in every
    // report that groups by client.
    $c = row('SELECT id FROM clients WHERE name = ?', [$client]);

    if (! $c) {
        q('INSERT INTO clients(name) VALUES (?)', [$client]);
        $c = ['id' => (int) db()->lastInsertId()];
    }

    q('INSERT INTO jobs (client_id, title, description, order_reference,
                         site_name, site_city, site_state,
                         lodging_provided, travel_provided, transport_provided,
                         starts_on, ends_on, status, headcount_target)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0)', [
        $c['id'], $title,
        $description !== '' ? $description : null,
        trim((string) ($_POST['order_reference'] ?? '')) ?: null,
        trim((string) ($_POST['site_name'] ?? '')) ?: null,
        trim((string) ($_POST['site_city'] ?? '')) ?: null,
        strtoupper(trim((string) ($_POST['site_state'] ?? ''))) ?: null,
        isset($_POST['lodging_provided'])   ? 1 : 0,
        isset($_POST['travel_provided'])    ? 1 : 0,
        isset($_POST['transport_provided']) ? 1 : 0,
        $startsOn ?: null, $endsOn ?: null,
        'planning',
    ]);

    $_SESSION['job_id'] = (int) db()->lastInsertId();
    db()->commit();

    log_activity('created project', 'job', $_SESSION['job_id'], $title . ' for ' . $client);
    flash(t(':name created. Now write the scope of work — how many of each trade, and what each one is paid.',
            ['name' => $title]));

    redirect('/job#scope');
}

$projects = rows("SELECT j.*, c.name client_name,
                         (SELECT COUNT(*) FROM job_order_lines l WHERE l.job_id = j.id) scope_lines,
                         (SELECT COALESCE(SUM(l.quantity), 0) FROM job_order_lines l
                           WHERE l.job_id = j.id) asked_for,
                         (SELECT COUNT(*) FROM placements p
                           WHERE p.job_id = j.id
                             AND p.status IN ('confirmed','travelling','on_site')) placed
                  FROM jobs j JOIN clients c ON c.id = j.client_id
                  ORDER BY j.id DESC");

$pageTitle = t('Projects') . ' · ' . $config['app_name'];

render('projects', compact('projects'));
