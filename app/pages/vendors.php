<?php
/**
 * Vendors and purchasing controls (P3-M06). Procurement (hotels, payroll)
 * proposes vendors and keeps their papers; an administrator other than the
 * proposer approves them, suspends them, and sets who approves orders of
 * what size.
 */

require_once __DIR__ . '/../vendors.php';

require_role('hotels', 'payroll');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $id = (int) ($_POST['vendor_id'] ?? 0);

    db()->beginTransaction();
    if ($do === 'save') {
        [$id, $why] = vendor_save($_POST, $id);
    } elseif (in_array($do, ['approve', 'suspend', 'reinstate'], true)) {
        require_role('admin');
        $why = vendor_decide($id, $do, (string) ($_POST['reason'] ?? ''));
    } elseif ($do === 'thresholds') {
        require_role('admin');
        $why = procurement_thresholds_save(is_array($_POST['up_to'] ?? null) ? $_POST['up_to'] : [], is_array($_POST['approvers'] ?? null) ? $_POST['approvers'] : []);
    } else {
        $why = t('Unknown action.');
    }
    if ($why !== null) {
        db()->rollBack();
        refuse(422, $why);
    }
    db()->commit();
    log_activity('vendors ' . $do, 'vendor', $id, (string) ($_POST['name'] ?? $_POST['reason'] ?? ''));
    flash(t('Recorded.'));
    redirect('/vendors' . ($id ? '?id=' . $id : ''));
}

$vendors = rows('SELECT v.*, u.name AS proposed_by, d.name AS decided_by_name,
                        (SELECT COUNT(*) FROM purchase_orders o WHERE o.vendor_name = v.name) AS orders
                 FROM vendors v LEFT JOIN users u ON u.id = v.created_by LEFT JOIN users d ON d.id = v.decided_by
                 ORDER BY FIELD(v.status, \'pending\', \'approved\', \'suspended\'), v.name');
$edit = isset($_GET['id']) ? (row('SELECT * FROM vendors WHERE id = ?', [(int) $_GET['id']]) ?: null) : null;
$history = $edit ? rows('SELECT e.*, u.name AS by_name FROM vendor_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.vendor_id = ? ORDER BY e.id DESC', [(int) $edit['id']]) : [];
$thresholds = procurement_thresholds();

render('vendors', compact('vendors', 'edit', 'history', 'thresholds'));
