<?php
/** P5-M79: /adp - payroll creates the new hires in ADP: the new-hire file, or one click through ADP's API. */

require_role('payroll');
require_once __DIR__ . '/../adp.php';
require_once __DIR__ . '/../adp-registration.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $ids = array_values(array_unique(array_map('intval', (array) ($_POST['people'] ?? []))));
    $ready = array_column(array_filter(adp_new_hires(), static fn ($h) => $h['ready']), null, 'candidate_id');
    $ids = array_values(array_filter($ids, static fn ($i) => isset($ready[$i])));
    if ($do === 'file') {
        if (! $ids) {
            refuse(422, t('Tick the people to put in the file: only those ready for ADP.'), '/adp');
        }
        $records = array_values(array_filter(array_map('adp_hire_record', $ids)));
        foreach ($records as $r) {
            adp_log('hire', (int) $r['crewvia_id'], 'file', 'downloaded', null, null, 'new-hire file', uid());
        }
        log_activity('downloaded the ADP new-hire file', 'adp', 0, count($records) . ' person(s)');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="adp-new-hires-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-store');
        echo adp_hire_csv($records);
        exit;
    }
    if ($do === 'send') {
        if (! $ids) {
            refuse(422, t('Tick the people to send: only those ready for ADP.'), '/adp');
        }
        $sent = 0;
        $notes = [];
        foreach ($ids as $id) {
            [$ok, $what] = adp_api_send_hire($id, uid());
            $sent += (int) $ok;
            $notes[] = $ready[$id]['name'] . ': ' . $what;
        }
        log_activity('sent new hires to ADP', 'adp', 0, $sent . ' of ' . count($ids));
        flash(implode(' ', $notes), $sent === count($ids) ? 'ok' : 'err');
        redirect('/adp');
    }
    if ($do === 'test') {
        [$token, $why] = adp_api_token();
        adp_log('test', null, 'api', $token ? 'sent' : 'failed', null, null, $token ? 'connection works' : (string) $why, uid());
        flash($token ? t('Connected to ADP.') : (string) $why, $token ? 'ok' : 'err');
        redirect('/adp#connection');
    }
    if ($do === 'employee_id') {
        if ($why = adp_reg_set_id((int) ($_POST['candidate_id'] ?? 0), (string) ($_POST['adp_employee_id'] ?? ''))) {
            refuse(422, $why, '/adp');
        }
        flash(t('ADP employee ID saved.'));
        redirect('/adp');
    }
    refuse(422, t('Unknown action.'), '/adp');
}

$hires = adp_new_hires();
$notStarted = (int) ($GLOBALS['adp_not_started'] ?? 0);
$apiMissing = adp_api_missing();
$history = rows('SELECT s.*, c.full_name, u.name AS by_name FROM adp_sends s LEFT JOIN candidates c ON c.id = s.candidate_id LEFT JOIN users u ON u.id = s.created_by ORDER BY s.id DESC LIMIT 50');
$pageTitle = t('ADP') . ' · ' . $config['app_name'];
render('adp', compact('hires', 'apiMissing', 'history', 'notStarted'));
