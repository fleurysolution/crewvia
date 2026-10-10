<?php
/**
 * Procurement for the working project: requests, quotations, purchase
 * orders, their approval, receipts, commitments, units of measure.
 *
 * Hotels and payroll buy. A supervisor raises requests and, as the
 * project's budget owner, decides its orders. An administrator sets the
 * budget owner and whether hires raise lodging requests.
 */

require_once __DIR__ . '/../procurement.php';

require_login();

if (! procurement_can_use()) {
    require_role('hotels', 'payroll');
}

$job   = current_job();
$jobId = (int) ($job['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');

    if (! $jobId) {
        refuse(422, t('Open a project first.'));
    }

    $request = static function (int $id) use ($jobId): array {
        $r = row('SELECT * FROM purchase_requests WHERE id = ? AND job_id = ?', [$id, $jobId]);
        if (! $r) { refuse(404, t('That request is not on this project.')); }
        return $r;
    };
    $order = static function (int $id) use ($jobId): array {
        $o = row('SELECT * FROM purchase_orders WHERE id = ? AND job_id = ?', [$id, $jobId]);
        if (! $o) { refuse(404, t('That purchase order is not on this project.')); }
        return $o;
    };

    switch ($do) {
        case 'settings':
            require_role('admin');
            $owner = (int) ($_POST['budget_owner_id'] ?? 0);
            if ($owner && ! val("SELECT COUNT(*) FROM users WHERE id = ? AND is_active = 1 AND role IN ('admin','supervisor','payroll','hotels','recruiter')", [$owner])) {
                refuse(422, t('Choose the budget owner from the list.'));
            }
            q('UPDATE jobs SET budget_owner_id = ?, auto_lodging = ? WHERE id = ?', [$owner ?: null, ! empty($_POST['auto_lodging']) ? 1 : 0, $jobId]);
            log_activity('set procurement settings', 'project', $jobId, 'owner #' . $owner . ', auto lodging ' . (! empty($_POST['auto_lodging']) ? 'on' : 'off'));
            flash(t('Procurement settings saved for this project.'));
            break;

        case 'request':
            [$v, $refusal] = procurement_request_input($_POST);
            if ($refusal !== null) { refuse(422, $refusal); }
            q('INSERT INTO purchase_requests (job_id, category, title, description, quantity, unit_id, needed_from, needed_to,
                 estimated_unit_cost, hotel_id, requested_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$jobId, ...array_values($v), uid()]);
            log_activity('raised a purchase request', 'project', $jobId, $v['title']);
            flash(t('Request raised.'));
            break;

        case 'quote':
            if (! procurement_can_buy()) { require_role('hotels', 'payroll'); }
            $r = $request((int) ($_POST['request_id'] ?? 0));
            $vendor = trim((string) ($_POST['vendor_name'] ?? ''));
            $price = trim((string) ($_POST['unit_price'] ?? ''));
            if ($r['status'] !== 'requested') { refuse(422, t('A quotation is attached before the order is raised.')); }
            if (mb_strlen($vendor) < 2 || mb_strlen($vendor) > 190 || ! is_numeric($price) || (float) $price < 0 || (float) $price > 1000000) {
                refuse(422, t('A quotation needs the vendor and a unit price from 0 to 1,000,000.'));
            }
            q('INSERT INTO purchase_quotations (request_id, vendor_name, unit_price, note, created_by) VALUES (?,?,?,?,?)',
              [(int) $r['id'], $vendor, round((float) $price, 2), mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500) ?: null, uid()]);
            break;

        case 'order':
            if (! procurement_can_buy()) { require_role('hotels', 'payroll'); }
            db()->beginTransaction();
            $r = $request((int) ($_POST['request_id'] ?? 0));
            q('SELECT id FROM purchase_requests WHERE id = ? FOR UPDATE', [(int) $r['id']]);
            $vendor = trim((string) ($_POST['vendor_name'] ?? ''));
            $price = trim((string) ($_POST['unit_price'] ?? ''));
            $hotel = (int) ($_POST['hotel_id'] ?? 0) ?: (int) $r['hotel_id'];
            // A rejected order leaves the request open to be ordered again;
            // only a live order stops another.
            if ($r['status'] !== 'requested' || val("SELECT COUNT(*) FROM purchase_orders WHERE request_id = ? AND status IN ('awaiting_approval','approved','closed')", [(int) $r['id']])) {
                db()->rollBack(); refuse(422, t('This request already has its order.'));
            }
            if (mb_strlen($vendor) < 2 || mb_strlen($vendor) > 190 || ! is_numeric($price) || (float) $price < 0 || (float) $price > 1000000) {
                db()->rollBack(); refuse(422, t('An order needs the vendor and a unit price from 0 to 1,000,000.'));
            }
            if ($r['category'] === 'lodging' && (! $hotel || ! val('SELECT COUNT(*) FROM hotels WHERE id = ?', [$hotel]))) {
                db()->rollBack(); refuse(422, t('A lodging order names the hotel, so the rooms it confirms reach the hotel board.'));
            }
            // Lodging is priced per room per night: the total covers the dates.
            $nights = $r['category'] === 'lodging' ? procurement_nights($r['needed_from'], $r['needed_to']) : 1;
            $total = round((float) $r['quantity'] * (float) $price * $nights, 2);
            q('INSERT INTO purchase_orders (reference, job_id, request_id, vendor_name, hotel_id, quantity, unit_id, unit_price, total, created_by)
               VALUES (?,?,?,?,?,?,?,?,?,?)',
              [procurement_next_reference(), $jobId, (int) $r['id'], $vendor, $r['category'] === 'lodging' ? $hotel : null,
               $r['quantity'], $r['unit_id'], round((float) $price, 2), $total, uid()]);
            q("UPDATE purchase_requests SET status = 'ordered' WHERE id = ?", [(int) $r['id']]);
            db()->commit();
            log_activity('raised a purchase order', 'project', $jobId, $r['title'] . ' ' . $total);
            flash(t('Purchase order raised for :total. It goes to the budget owner for approval.', ['total' => money($total)]));
            break;

        case 'approve':
        case 'reject':
            db()->beginTransaction();
            $o = $order((int) ($_POST['purchase_order_id'] ?? 0));
            q('SELECT id FROM purchase_orders WHERE id = ? FOR UPDATE', [(int) $o['id']]);
            $note = trim((string) ($_POST['note'] ?? ''));
            if ($o['status'] !== 'awaiting_approval') { db()->rollBack(); refuse(422, t('That order has already been decided.')); }
            if (! procurement_can_decide($o)) {
                db()->rollBack();
                refuse(403, (int) $o['created_by'] === uid() ? t('Whoever raised an order does not approve it.') : t('Only the project\'s budget owner, or an administrator, decides its orders.'));
            }
            if ($do === 'reject' && mb_strlen($note) < 3) { db()->rollBack(); refuse(422, t('Say why the order is rejected.')); }
            q('UPDATE purchase_orders SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?',
              [$do === 'approve' ? 'approved' : 'rejected', uid(), $note ?: null, (int) $o['id']]);
            if ($do === 'reject') {
                // Back to the request, which can be ordered again differently.
                q("UPDATE purchase_requests SET status = 'requested' WHERE id = ?", [(int) $o['request_id']]);
            }
            db()->commit();
            log_activity($do === 'approve' ? 'approved a purchase order' : 'rejected a purchase order', 'project', $jobId, $o['reference'] . ($note ? ' - ' . $note : ''));
            flash($do === 'approve' ? t('Approved. The order can be placed.') : t('Rejected. The request is open again.'));
            break;

        case 'receive':
            if (! procurement_can_buy()) { require_role('hotels', 'payroll'); }
            db()->beginTransaction();
            $o = $order((int) ($_POST['purchase_order_id'] ?? 0));
            q('SELECT id FROM purchase_orders WHERE id = ? FOR UPDATE', [(int) $o['id']]);
            $qty = trim((string) ($_POST['quantity'] ?? ''));
            $on = (string) ($_POST['received_on'] ?? '');
            $already = (float) val('SELECT COALESCE(SUM(quantity), 0) FROM purchase_receipts WHERE purchase_order_id = ?', [(int) $o['id']]);
            if ($o['status'] !== 'approved') { db()->rollBack(); refuse(422, t('Only an approved order is received.')); }
            if (! is_numeric($qty) || (float) $qty <= 0 || ! valid_date($on)) { db()->rollBack(); refuse(422, t('Say how many arrived, and when.')); }
            if ($already + (float) $qty > (float) $o['quantity'] + 0.001) {
                db()->rollBack();
                refuse(422, t('That is more than was ordered: :ordered ordered, :received already received.', ['ordered' => (string) (float) $o['quantity'], 'received' => (string) $already]));
            }
            q('INSERT INTO purchase_receipts (purchase_order_id, quantity, received_on, note, received_by) VALUES (?,?,?,?,?)',
              [(int) $o['id'], round((float) $qty, 2), $on, mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500) ?: null, uid()]);
            $r = row('SELECT * FROM purchase_requests WHERE id = ?', [(int) $o['request_id']]);
            if ($r['category'] === 'lodging' && $o['hotel_id']) {
                // The rooms the hotel confirmed join its block: one count,
                // the one the hotel board already shows.
                q('UPDATE hotels SET rooms_held = COALESCE(rooms_held, 0) + ?,
                     block_starts = CASE WHEN ? IS NULL THEN block_starts WHEN block_starts IS NULL OR block_starts > ? THEN ? ELSE block_starts END,
                     block_ends = CASE WHEN ? IS NULL THEN block_ends WHEN block_ends IS NULL OR block_ends < ? THEN ? ELSE block_ends END
                   WHERE id = ?',
                  [(int) round((float) $qty), $r['needed_from'], $r['needed_from'], $r['needed_from'], $r['needed_to'], $r['needed_to'], $r['needed_to'], (int) $o['hotel_id']]);
            }
            if ($already + (float) $qty >= (float) $o['quantity'] - 0.001 && $r['status'] === 'ordered') {
                q("UPDATE purchase_requests SET status = 'received' WHERE id = ?", [(int) $r['id']]);
            }
            db()->commit();
            log_activity('recorded a receipt', 'project', $jobId, $o['reference'] . ' ' . $qty);
            flash($r['category'] === 'lodging' ? t('Received. The rooms are now in the hotel\'s block on the hotel board.') : t('Received.'));
            break;

        case 'close':
            if (! procurement_can_buy()) { require_role('hotels', 'payroll'); }
            $o = $order((int) ($_POST['purchase_order_id'] ?? 0));
            if ($o['status'] !== 'approved') { refuse(422, t('Only an approved order is closed.')); }
            q("UPDATE purchase_orders SET status = 'closed' WHERE id = ?", [(int) $o['id']]);
            q("UPDATE purchase_requests SET status = 'closed' WHERE id = ? AND status <> 'cancelled'", [(int) $o['request_id']]);
            log_activity('closed a purchase order', 'project', $jobId, $o['reference']);
            break;

        case 'cancel':
            if (! procurement_can_buy()) { require_role('hotels', 'payroll'); }
            $r = $request((int) ($_POST['request_id'] ?? 0));
            $why = trim((string) ($_POST['reason'] ?? ''));
            if (! in_array($r['status'], ['requested', 'ordered'], true)) { refuse(422, t('Only a request not yet received is cancelled.')); }
            if (mb_strlen($why) < 3) { refuse(422, t('Say why it is cancelled.')); }
            if (val("SELECT COUNT(*) FROM purchase_orders WHERE request_id = ? AND status = 'approved'", [(int) $r['id']])
                && val('SELECT COUNT(*) FROM purchase_receipts x JOIN purchase_orders o ON o.id = x.purchase_order_id WHERE o.request_id = ?', [(int) $r['id']])) {
                refuse(422, t('Something has already been received against this request. Close its order instead.'));
            }
            q("UPDATE purchase_requests SET status = 'cancelled', cancel_reason = ? WHERE id = ?", [mb_substr($why, 0, 500), (int) $r['id']]);
            q("UPDATE purchase_orders SET status = 'cancelled' WHERE request_id = ? AND status IN ('awaiting_approval','approved')", [(int) $r['id']]);
            log_activity('cancelled a purchase request', 'project', $jobId, $r['title'] . ' - ' . $why);
            break;

        case 'unit':
            if (! procurement_can_buy()) { require_role('hotels', 'payroll'); }
            $label = trim((string) ($_POST['label'] ?? ''));
            $code = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_');
            if (mb_strlen($label) < 2 || mb_strlen($label) > 80 || $code === '') { refuse(422, t('Name the unit in 2 to 80 characters.')); }
            if (val('SELECT COUNT(*) FROM procurement_units WHERE code = ?', [$code])) { refuse(422, t('That unit already exists.')); }
            q('INSERT INTO procurement_units (code, label) VALUES (?,?)', [$code, $label]);
            break;

        default:
            refuse(422, t('Unknown action.'));
    }

    redirect('/procurement');
}

$requests = $jobId ? rows("SELECT r.*, u.label AS unit_label, h.name AS hotel_name, c.full_name AS for_person, us.name AS requested_by_name,
                                  o.id AS order_id, o.reference, o.status AS order_status
                           FROM purchase_requests r JOIN procurement_units u ON u.id = r.unit_id
                           LEFT JOIN hotels h ON h.id = r.hotel_id
                           LEFT JOIN placements p ON p.id = r.placement_id LEFT JOIN candidates c ON c.id = p.candidate_id
                           LEFT JOIN users us ON us.id = r.requested_by
                           LEFT JOIN purchase_orders o ON o.id = (SELECT MAX(o2.id) FROM purchase_orders o2 WHERE o2.request_id = r.id)
                           WHERE r.job_id = ? ORDER BY FIELD(r.status,'requested','ordered','received','closed','cancelled'), r.id DESC", [$jobId]) : [];
$quotes = [];
foreach ($requests ? rows('SELECT * FROM purchase_quotations WHERE request_id IN (' . implode(',', array_map('intval', array_column($requests, 'id'))) . ') ORDER BY unit_price') : [] as $q) {
    $quotes[(int) $q['request_id']][] = $q;
}
$orders = $jobId ? rows("SELECT o.*, u.label AS unit_label, r.title, r.category, h.name AS hotel_name, cb.name AS created_by_name, db.name AS decided_by_name,
                                (SELECT COALESCE(SUM(x.quantity), 0) FROM purchase_receipts x WHERE x.purchase_order_id = o.id) AS received
                         FROM purchase_orders o JOIN procurement_units u ON u.id = o.unit_id JOIN purchase_requests r ON r.id = o.request_id
                         LEFT JOIN hotels h ON h.id = o.hotel_id LEFT JOIN users cb ON cb.id = o.created_by LEFT JOIN users db ON db.id = o.decided_by
                         WHERE o.job_id = ? ORDER BY FIELD(o.status,'awaiting_approval','approved','closed','rejected','cancelled'), o.id DESC", [$jobId]) : [];
$commitments = $jobId ? procurement_commitments($jobId) : [];
$units = procurement_units();
$hotels = rows('SELECT id, name FROM hotels ORDER BY name');
$owners = can('admin') ? rows("SELECT id, name, role FROM users WHERE is_active = 1 AND role IN ('admin','supervisor','payroll','hotels','recruiter') ORDER BY name") : [];
$owner = $jobId ? row('SELECT u.id, u.name FROM jobs j JOIN users u ON u.id = j.budget_owner_id WHERE j.id = ?', [$jobId]) : null;

$pageTitle = t('Procurement') . ' · ' . $config['app_name'];
render('procurement', compact('job', 'requests', 'quotes', 'orders', 'commitments', 'units', 'hotels', 'owners', 'owner'));
