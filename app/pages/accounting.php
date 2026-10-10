<?php
/**
 * QuickBooks export (P3-M02). Payroll and administrators preview what is
 * waiting, export it as a batch, download the file, and reverse a batch
 * with a correcting one. Administrators keep the account mapping and
 * decide whether payroll goes out (off while ADP posts it).
 */

require_once __DIR__ . '/../accounting.php';

require_role('payroll');

// What was recorded since reaches the ledger first, so the export has it.
ledger_sync();

if (isset($_GET['download'])) {
    $b = row('SELECT * FROM accounting_batches WHERE id = ?', [(int) $_GET['download']]);
    if (! $b) {
        refuse(404, t('That export does not exist.'));
    }
    $csv = accounting_csv((int) $b['id']);
    // The file is rebuilt from stored lines; if it ever differed from what
    // was recorded, it must not be handed out as the same file.
    if (! hash_equals((string) $b['file_sha256'], hash('sha256', $csv))) {
        refuse(409, t('This export no longer matches its recorded fingerprint. Nothing was downloaded.'));
    }
    log_activity('downloaded a QuickBooks export', 'accounting_batch', (int) $b['id']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="crewvia-quickbooks-' . ($b['kind'] === 'reversal' ? 'reversal-' : '') . (int) $b['id'] . '.csv"');
    header('X-Content-SHA256: ' . $b['file_sha256']);
    echo $csv;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'export') {
        db()->beginTransaction();
        try {
            [$batch, $why] = accounting_export(trim((string) ($_POST['through'] ?? '')));
        } catch (PDOException $e) {
            // The unique source key: another export took these records first.
            db()->rollBack();
            refuse(409, t('Another export took some of these records first. Nothing was exported; look again.'));
        }
        if ($why !== null) {
            db()->rollBack();
            refuse(422, $why);
        }
        db()->commit();
        log_activity('exported to QuickBooks', 'accounting_batch', $batch);
        flash(t('Export :n made. Download its file and import it into QuickBooks as journal entries.', ['n' => $batch]));
        redirect('/accounting');
    }

    if ($do === 'reverse') {
        db()->beginTransaction();
        [$rev, $why] = accounting_reverse((int) ($_POST['batch_id'] ?? 0), trim((string) ($_POST['date'] ?? '')), (string) ($_POST['reason'] ?? ''));
        if ($why !== null) {
            db()->rollBack();
            refuse(422, $why);
        }
        db()->commit();
        log_activity('reversed a QuickBooks export', 'accounting_batch', (int) $_POST['batch_id'], (string) $_POST['reason']);
        flash(t('Reversal :n made. Import its file into QuickBooks to cancel the export; its records can be exported again.', ['n' => $rev]));
        redirect('/accounting');
    }

    if ($do === 'mapping') {
        require_role('admin');
        $accounts = accounting_accounts();
        $names = is_array($_POST['qb_account'] ?? null) ? $_POST['qb_account'] : [];
        $confirm = is_array($_POST['confirmed'] ?? null) ? $_POST['confirmed'] : [];
        foreach ($accounts as $key => $a) {
            $name = trim((string) ($names[$key] ?? $a['qb_account']));
            if ($name === '' || mb_strlen($name) > 190 || preg_match('/^[=+@-]/', $name)) {
                refuse(422, t('Give each account its QuickBooks name, as the chart of accounts spells it.'));
            }
        }
        db()->beginTransaction();
        foreach ($accounts as $key => $a) {
            $name = trim((string) ($names[$key] ?? $a['qb_account']));
            $ok = ! empty($confirm[$key]) ? 1 : 0;
            if ($name !== $a['qb_account'] || $ok !== (int) $a['confirmed']) {
                q('UPDATE accounting_accounts SET qb_account = ?, confirmed = ?, updated_by = ?, updated_at = NOW() WHERE account_key = ?', [$name, $ok, uid(), $key]);
                log_activity('mapped an account to QuickBooks', 'accounting_account', 0, $key . ' -> ' . $name . ($ok ? ' (confirmed)' : ''));
            }
        }
        db()->commit();
        flash(t('Account mapping saved. Exports already made keep the names they were sent with.'));
        redirect('/accounting');
    }

    if ($do === 'payroll') {
        require_role('admin');
        $on = ($_POST['payroll'] ?? '') === '1';
        if ($on && mb_strlen(trim((string) ($_POST['reason'] ?? ''))) < 3) {
            refuse(422, t('Say why payroll goes out from Crewvia. If ADP posts payroll to QuickBooks, both would count it.'));
        }
        q("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('accounting_export_payroll', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$on ? '1' : '0']);
        log_activity($on ? 'turned on payroll export to QuickBooks' : 'turned off payroll export to QuickBooks', 'setting', 0, (string) ($_POST['reason'] ?? ''));
        flash($on ? t('Payroll periods will be exported from now on.') : t('Payroll is no longer exported.'));
        redirect('/accounting');
    }

    refuse(400, t('Unknown action.'));
}

$through = (string) ($_GET['through'] ?? date('Y-m-d'));
if (! valid_date($through)) {
    $through = date('Y-m-d');
}
$pending = accounting_pending($through);
$blockers = accounting_blockers($pending);
$accounts = accounting_accounts();
$batches = rows('SELECT b.*, u.name AS by_name FROM accounting_batches b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC LIMIT 100');
$payroll = accounting_payroll_enabled();

render('accounting', compact('through', 'pending', 'blockers', 'accounts', 'batches', 'payroll'));
