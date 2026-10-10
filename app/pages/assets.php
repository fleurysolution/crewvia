<?php
/**
 * The asset register: every item the agency issues, where it is, its state,
 * its next inspection and its history. Recruiters see it; logistics
 * (hotels) and administrators register items and record repairs,
 * inspections, losses and retirement. Issuing and taking back stay on
 * Operations, where the crew is.
 */

require_once __DIR__ . '/../assets.php';

require_role('recruiter', 'hotels');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role('hotels');
    $do = (string) ($_POST['do'] ?? '');
    $id = (int) ($_POST['equipment_id'] ?? 0);

    if ($do === 'register') {
        db()->beginTransaction();
        [$id, $why] = asset_register($_POST);
        if ($why !== null) {
            db()->rollBack();
            refuse(422, $why);
        }
        db()->commit();
        log_activity('registered an asset', 'equipment', $id, (string) ($_POST['asset_tag'] ?? ''));
        flash(t('Item registered.'));
        redirect('/assets?id=' . $id);
    }

    if ($do === 'from_po') {
        $po = (int) ($_POST['purchase_order_id'] ?? 0);
        $count = (int) ($_POST['count'] ?? 0);
        $prefix = strtoupper(trim((string) ($_POST['tag_prefix'] ?? '')));

        db()->beginTransaction();
        $order = row("SELECT o.* FROM purchase_orders o JOIN purchase_requests r ON r.id = o.request_id
                      WHERE o.id = ? AND o.status IN ('approved','closed') AND r.category <> 'lodging' FOR UPDATE", [$po]);
        if (! $order) {
            db()->rollBack();
            refuse(422, t('Only an approved order for equipment can be registered.'));
        }
        $left = asset_registrable_from($po);
        if ($count < 1 || $count > $left) {
            db()->rollBack();
            refuse(422, t('That order has :n item(s) received and not yet registered.', ['n' => $left]));
        }
        if (! preg_match('/^[A-Z0-9][A-Z0-9-]{1,30}$/', $prefix)) {
            db()->rollBack();
            refuse(422, t('A tag prefix is 2 to 31 letters, digits or dashes.'));
        }

        $n = (int) val('SELECT COUNT(*) FROM equipment WHERE purchase_order_id = ?', [$po]);
        for ($k = 1; $k <= $count; $k++) {
            [$new, $why] = asset_register([
                'name' => $_POST['name'] ?? '', 'asset_tag' => sprintf('%s-%03d', $prefix, $n + $k),
                'category_id' => $_POST['category_id'] ?? 0, 'owner_type' => 'agency',
                'purchase_date' => substr((string) $order['created_at'], 0, 10),
            ], $po, (float) $order['unit_price']);
            if ($why !== null) {
                db()->rollBack();
                refuse(422, $why);
            }
        }
        db()->commit();
        log_activity('registered assets from an order', 'purchase_order', $po, $count . ' × ' . $prefix);
        flash(t(':n item(s) registered from :ref.', ['n' => $count, 'ref' => $order['reference']]));
        redirect('/assets');
    }

    if ($do === 'act') {
        db()->beginTransaction();
        $why = asset_action($id, (string) ($_POST['action'] ?? ''), (string) ($_POST['note'] ?? ''), (string) ($_POST['cost'] ?? ''), (string) ($_POST['next_due'] ?? ''));
        if ($why !== null) {
            db()->rollBack();
            refuse(422, $why);
        }
        db()->commit();
        log_activity('asset ' . (string) $_POST['action'], 'equipment', $id);
        flash(t('Recorded.'));
        redirect('/assets?id=' . $id);
    }

    refuse(400, t('Unknown action.'));
}

$statuses = asset_statuses();
$categories = asset_categories(false);

if (isset($_GET['id'])) {
    $item = asset((int) $_GET['id']);
    if (! $item) {
        refuse(404, t('That item does not exist.'));
    }
    $holder = $item['issue_id'] ? row('SELECT c.full_name, j.title AS project, i.issued_at, i.issue_condition
                                       FROM equipment_issues i JOIN placements p ON p.id = i.placement_id
                                       JOIN candidates c ON c.id = p.candidate_id JOIN jobs j ON j.id = p.job_id
                                       WHERE i.id = ?', [(int) $item['issue_id']]) : null;
    $events = rows('SELECT a.*, u.name AS by_name FROM asset_events a LEFT JOIN users u ON u.id = a.user_id WHERE a.equipment_id = ? ORDER BY a.id DESC', [(int) $item['id']]);
    $issues = rows('SELECT i.*, c.full_name, j.title AS project FROM equipment_issues i JOIN placements p ON p.id = i.placement_id
                    JOIN candidates c ON c.id = p.candidate_id JOIN jobs j ON j.id = p.job_id
                    WHERE i.equipment_id = ? ORDER BY i.id DESC', [(int) $item['id']]);
    $order = $item['purchase_order_id'] ? row('SELECT reference FROM purchase_orders WHERE id = ?', [(int) $item['purchase_order_id']]) : null;
    render('assets', compact('item', 'holder', 'events', 'issues', 'order', 'statuses', 'categories'));
    return;
}

$state = (string) ($_GET['state'] ?? '');
$category = (int) ($_GET['category'] ?? 0);
$search = trim((string) ($_GET['q'] ?? ''));
$where = [];
$args = [];
if (isset($statuses[$state])) {
    $where[] = $state === 'issued' ? 'i.id IS NOT NULL' : 'i.id IS NULL AND e.status = ?';
    if ($state !== 'issued') { $args[] = $state; }
}
if ($state === 'overdue') {
    $where[] = "e.inspection_due < CURDATE() AND e.status NOT IN ('lost','retired')";
}
if ($category) {
    $where[] = 'e.category_id = ?';
    $args[] = $category;
}
if ($search !== '') {
    $where[] = '(e.name LIKE ? OR e.asset_tag LIKE ? OR o.serial_number LIKE ?)';
    array_push($args, "%$search%", "%$search%", "%$search%");
}

$items = rows('SELECT e.*, o.owner_type, o.serial_number, cl.name AS owner_client, k.label AS category,
                      i.id AS issue_id, cd.full_name AS holder, j.title AS project
               FROM equipment e LEFT JOIN equipment_ownership o ON o.equipment_id = e.id
               LEFT JOIN clients cl ON cl.id = o.client_id LEFT JOIN asset_categories k ON k.id = e.category_id
               LEFT JOIN equipment_issues i ON i.equipment_id = e.id AND i.returned_at IS NULL
               LEFT JOIN placements p ON p.id = i.placement_id LEFT JOIN candidates cd ON cd.id = p.candidate_id
               LEFT JOIN jobs j ON j.id = p.job_id'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY e.asset_tag LIMIT 500', $args);

$counts = ['issued' => 0, 'available' => 0, 'in_repair' => 0, 'lost' => 0, 'retired' => 0, 'overdue' => 0];
foreach (rows("SELECT e.status, e.inspection_due, (SELECT COUNT(*) FROM equipment_issues i WHERE i.equipment_id = e.id AND i.returned_at IS NULL) AS out_now FROM equipment e") as $r) {
    $counts[$r['out_now'] ? 'issued' : $r['status']]++;
    if ($r['inspection_due'] && $r['inspection_due'] < date('Y-m-d') && ! in_array($r['status'], ['lost', 'retired'], true)) {
        $counts['overdue']++;
    }
}

$orders = [];
if (can('hotels')) {
    foreach (rows("SELECT o.id, o.reference, o.vendor_name, r.title FROM purchase_orders o JOIN purchase_requests r ON r.id = o.request_id
                   WHERE o.status IN ('approved','closed') AND r.category <> 'lodging' ORDER BY o.id DESC LIMIT 100") as $o) {
        $o['left'] = asset_registrable_from((int) $o['id']);
        if ($o['left'] > 0) {
            $orders[] = $o;
        }
    }
}
$clients = rows('SELECT id, name FROM clients ORDER BY name');

render('assets', compact('items', 'counts', 'statuses', 'categories', 'orders', 'clients', 'state', 'category', 'search'));
