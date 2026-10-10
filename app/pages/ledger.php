<?php
/**
 * Crewvia's ledger (P3-M03). Payroll and administrators see the journals,
 * the trial balance by month, each account's lines, the ledger beside what
 * went to QuickBooks, and the integrity of the chain. They draft manual
 * journals for what Crewvia never sees; someone else posts them. Only
 * administrators add accounts or switch them off.
 */

require_once __DIR__ . '/../ledger.php';

require_role('payroll');

// What was recorded since posts itself before anything is shown or decided.
[$synced, $skipped] = ledger_sync();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $back = '/ledger';

    if (in_array($do, ['account_add', 'account_active'], true)) {
        require_role('admin');
        $why = $do === 'account_add'
            ? ledger_account_add(trim((string) ($_POST['number'] ?? '')), (string) ($_POST['label'] ?? ''), (string) ($_POST['side'] ?? ''))
            : ledger_account_active((string) ($_POST['account'] ?? ''), ($_POST['active'] ?? '') === '1');
        if ($why !== null) {
            refuse(422, $why);
        }
        log_activity($do === 'account_add' ? 'added a ledger account' : 'changed a ledger account', 'accounting_account', 0,
                     $do === 'account_add' ? trim((string) $_POST['number']) . ' ' . trim((string) $_POST['label']) : (string) $_POST['account'] . (($_POST['active'] ?? '') === '1' ? ' on' : ' off'));
        flash($do === 'account_add' ? t('Account added. Map it to QuickBooks before a journal using it is exported.') : t('Account saved.'));
        redirect('/ledger#chart');
    }

    if (! ledger_lock()) {
        refuse(409, t('The ledger is busy. Try again in a moment.'));
    }
    db()->beginTransaction();
    $id = (int) ($_POST['journal_id'] ?? 0);
    $message = '';
    switch ($do) {
        case 'draft':
            [$id, $why] = ledger_manual_create(trim((string) ($_POST['date'] ?? '')), (string) ($_POST['memo'] ?? ''), ledger_lines_input($_POST));
            $message = t('Journal :n drafted. Someone else posts it.', ['n' => $id]);
            break;
        case 'approve':
            $why = ledger_manual_approve($id);
            $message = t('Journal :n posted.', ['n' => $id]);
            break;
        case 'discard':
            $why = ledger_manual_discard($id);
            $message = t('Draft :n discarded.', ['n' => $id]);
            break;
        case 'reverse':
            [$rev, $why] = ledger_reverse($id, trim((string) ($_POST['date'] ?? '')), (string) ($_POST['reason'] ?? ''));
            $message = t('Journal :n reversed by journal :r.', ['n' => $id, 'r' => $rev]);
            break;
        default:
            $why = t('Unknown action.');
    }
    if ($why !== null) {
        db()->rollBack();
        ledger_unlock();
        refuse(422, $why);
    }
    db()->commit();
    ledger_unlock();
    log_activity(['draft' => 'drafted a journal', 'approve' => 'posted a journal', 'discard' => 'discarded a draft journal', 'reverse' => 'reversed a journal'][$do],
                 'gl_journal', $id, $do === 'reverse' ? (string) $_POST['reason'] : '');
    flash($message);
    redirect($back);
}

$period = (string) ($_GET['period'] ?? date('Y-m'));
if (! period_valid($period)) {
    $period = date('Y-m');
}
$trial = ledger_trial_balance($period);
$integrity = ledger_integrity();
$drift = ledger_drift();
$compare = ledger_vs_export(date('Y-m-d'));
$accounts = accounting_accounts();
$manualAccounts = ledger_manual_accounts();

$account = (string) ($_GET['account'] ?? '');
$from = valid_date((string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : date('Y-m-01');
$to = valid_date((string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : date('Y-m-d');
$accountLines = isset($accounts[$account]) ? ledger_account_lines($account, $from, $to) : null;

$drafts = rows("SELECT j.*, u.name AS by_name FROM gl_journals j LEFT JOIN users u ON u.id = j.created_by WHERE j.status = 'draft' ORDER BY j.id");
$journals = rows("SELECT j.*, u.name AS by_name, a.name AS approved_name FROM gl_journals j LEFT JOIN users u ON u.id = j.created_by LEFT JOIN users a ON a.id = j.approved_by
                  WHERE j.status = 'posted' ORDER BY j.chain_no DESC LIMIT 60");
$ids = array_merge(array_column($drafts, 'id'), array_column($journals, 'id'));
$lines = [];
if ($ids) {
    foreach (rows('SELECT * FROM gl_lines WHERE journal_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY id') as $l) {
        $lines[(int) $l['journal_id']][] = $l;
    }
}
$projects = array_column(rows('SELECT DISTINCT title FROM jobs ORDER BY title'), 'title');

render('ledger', compact('synced', 'skipped', 'period', 'trial', 'integrity', 'drift', 'compare', 'accounts', 'manualAccounts',
                         'account', 'from', 'to', 'accountLines', 'drafts', 'journals', 'lines', 'projects'));
