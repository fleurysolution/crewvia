<?php
/**
 * Driving records (P5-M09): MVR (MVRNOW), the FMCSA Clearinghouse and PSP,
 * run in their own portals and recorded here. Recruiters and
 * administrators. See app/driver-records.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../driver-records.php';

require_role('recruiter');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $id = (int) ($_POST['check_id'] ?? 0);
    switch ($do) {
        case 'request':
            [$why, $id] = dr_request($_POST, uid());
            if ($why !== null) { refuse(422, $why); }
            flash(t('Requested. Run it in :provider, then record the result here.', ['provider' => dr_provider_names()[(string) $_POST['kind']]]));
            redirect('/driver-checks?check=' . $id);
        case 'record':
            $why = dr_record($id, $_POST, uid());
            if ($why !== null) { refuse(422, $why); }
            flash(t('Recorded.'));
            redirect('/driver-checks?check=' . $id);
        case 'cancel':
            $why = dr_cancel($id, (string) ($_POST['reason'] ?? ''), uid());
            if ($why !== null) { refuse(422, $why); }
            redirect('/driver-checks?check=' . $id);
        case 'links':
            require_role('admin');
            $why = dr_links_save($_POST);
            if ($why !== null) { refuse(422, $why); }
            flash(t('Saved.'));
            redirect('/driver-checks#links');
        default:
            refuse(400, t('Unknown action.'));
    }
}

$providerLinks = dr_links();
if (isset($_GET['check'])) {
    $check = row('SELECT d.*, c.full_name FROM driver_checks d JOIN candidates c ON c.id = d.candidate_id WHERE d.id = ?', [(int) $_GET['check']]);
    if (! $check) { refuse(404, t('That check does not exist.')); }
    render('driver-checks', compact('check', 'providerLinks'));
    return;
}
$person = isset($_GET['candidate']) ? row('SELECT id, full_name FROM candidates WHERE id = ?', [(int) $_GET['candidate']]) : null;
if (isset($_GET['candidate']) && ! $person) { refuse(404, t('That person does not exist.')); }
$checks = $person ? rows('SELECT * FROM driver_checks WHERE candidate_id = ? ORDER BY id DESC', [(int) $person['id']]) : [];
$open = rows("SELECT d.*, c.full_name FROM driver_checks d JOIN candidates c ON c.id = d.candidate_id WHERE d.status = 'requested' ORDER BY d.id");
$latest = dr_latest();
render('driver-checks', compact('person', 'checks', 'open', 'latest', 'providerLinks'));
