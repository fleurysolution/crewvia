<?php
/**
 * Procurement, the way RSS buys (REQUIREMENTS R41-R47).
 *
 *   request  -> purchase order -> receipt -> commitment
 *
 * There is no bid analysis and no committee: a quotation may be attached
 * to a request, and the order is raised from it. The order is approved by
 * the project's budget owner - or an administrator when none is named -
 * and never by the person who raised it. A lodging receipt is the rooms
 * the hotel confirmed: it is added to the hotel's block, which is what
 * the hotel board already counts down, so there is one count, not two.
 * A commitment is what was ordered less what has been invoiced against it.
 */

declare(strict_types=1);

function procurement_categories(): array
{
    return ['lodging' => t('Lodging'), 'vehicle' => t('Vehicles'), 'safety_equipment' => t('Safety equipment'), 'other' => t('Other')];
}

function procurement_request_statuses(): array
{
    return ['requested' => t('Requested'), 'ordered' => t('Ordered'), 'received' => t('Received'),
            'closed' => t('Closed'), 'cancelled' => t('Cancelled')];
}

function procurement_order_statuses(): array
{
    return ['awaiting_approval' => t('Waiting for the budget owner'), 'approved' => t('Approved'),
            'rejected' => t('Rejected'), 'closed' => t('Closed'), 'cancelled' => t('Cancelled')];
}

function procurement_units(bool $activeOnly = true): array
{
    return rows('SELECT * FROM procurement_units' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY label');
}

function procurement_unit_label(array $unit): string
{
    return t((string) $unit['label']);
}

/** May this person act on the project's procurement at all? */
function procurement_can_use(): bool
{
    return can('hotels') || can('payroll') || (user()['role'] ?? '') === 'supervisor';
}

/** May this person raise orders, record receipts, close and cancel? */
function procurement_can_buy(): bool
{
    return can('hotels') || can('payroll');
}

/**
 * May this person decide the order? The budget owner, or an
 * administrator when the project names none - and never its author.
 */
function procurement_can_decide(array $order): bool
{
    if ((int) $order['created_by'] === uid()) {
        return false;
    }

    $owner = (int) val('SELECT budget_owner_id FROM jobs WHERE id = ?', [(int) $order['job_id']]);

    return can('admin') || ($owner !== 0 && $owner === uid());
}

/** Nights between two dates, at least one. */
function procurement_nights(?string $from, ?string $to): int
{
    if (! $from || ! $to) {
        return 1;
    }

    return max(1, (int) round((strtotime($to) - strtotime($from)) / 86400));
}

/** A request from a form. Returns [values, refusal]. */
function procurement_request_input(array $in): array
{
    $category = (string) ($in['category'] ?? '');
    $title = trim((string) ($in['title'] ?? ''));
    $qty = trim((string) ($in['quantity'] ?? ''));
    $unit = (int) ($in['unit_id'] ?? 0);
    $from = trim((string) ($in['needed_from'] ?? ''));
    $to = trim((string) ($in['needed_to'] ?? ''));
    $cost = trim((string) ($in['estimated_unit_cost'] ?? ''));
    $hotel = (int) ($in['hotel_id'] ?? 0);

    if (! array_key_exists($category, procurement_categories())) {
        return [[], t('Choose what is being bought.')];
    }
    if (mb_strlen($title) < 3 || mb_strlen($title) > 190) {
        return [[], t('Say what is needed, in 3 to 190 characters.')];
    }
    if (! is_numeric($qty) || (float) $qty <= 0 || (float) $qty > 100000) {
        return [[], t('A quantity is above 0 and at most 100,000.')];
    }
    if (! val('SELECT COUNT(*) FROM procurement_units WHERE id = ? AND is_active = 1', [$unit])) {
        return [[], t('Choose the unit it is counted in.')];
    }
    if (($from !== '' && ! valid_date($from)) || ($to !== '' && ! valid_date($to)) || ($from !== '' && $to !== '' && $to < $from)) {
        return [[], t('Check the dates: the end cannot come before the start.')];
    }
    if ($cost !== '' && (! is_numeric($cost) || (float) $cost < 0 || (float) $cost > 1000000)) {
        return [[], t('An estimated cost is a number from 0 to 1,000,000.')];
    }
    if ($hotel && ($category !== 'lodging' || ! val('SELECT COUNT(*) FROM hotels WHERE id = ?', [$hotel]))) {
        return [[], t('A hotel is chosen only for lodging, and from the list.')];
    }

    return [['category' => $category, 'title' => $title, 'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 1000) ?: null,
             'quantity' => round((float) $qty, 2), 'unit_id' => $unit, 'needed_from' => $from ?: null, 'needed_to' => $to ?: null,
             'estimated_unit_cost' => $cost !== '' ? round((float) $cost, 2) : null, 'hotel_id' => $hotel ?: null], null];
}

/**
 * The lodging request a hire raises (R42), on projects that ask for it.
 * Nothing is raised twice for one placement, nor for somebody who already
 * has a bed booked.
 */
function procurement_request_lodging_for(int $placementId): ?int
{
    try {
        $p = row('SELECT p.id, p.job_id, p.start_date, p.end_date, c.full_name, j.auto_lodging
                  FROM placements p JOIN candidates c ON c.id = p.candidate_id JOIN jobs j ON j.id = p.job_id
                  WHERE p.id = ?', [$placementId]);
    } catch (Throwable $e) {
        return null;   // before the procurement upgrade has run
    }

    if (! $p || (int) $p['auto_lodging'] !== 1) {
        return null;
    }

    if (val("SELECT COUNT(*) FROM purchase_requests WHERE placement_id = ? AND category = 'lodging' AND status <> 'cancelled'", [$placementId])
        || val("SELECT COUNT(*) FROM lodging WHERE placement_id = ? AND status IN ('held','booked','checked_in')", [$placementId])) {
        return null;
    }

    $room = (int) val("SELECT id FROM procurement_units WHERE code = 'room'");

    if (! $room) {
        return null;
    }

    q("INSERT INTO purchase_requests (job_id, category, title, quantity, unit_id, needed_from, needed_to, placement_id, source, requested_by)
       VALUES (?, 'lodging', ?, 1, ?, ?, ?, ?, 'hire', ?)",
      [(int) $p['job_id'], 'Room for ' . $p['full_name'], $room, $p['start_date'], $p['end_date'], $placementId, uid() ?: null]);

    return (int) db()->lastInsertId();
}

/** When a bed is booked for somebody, their lodging request is done. */
function procurement_lodging_booked(int $placementId): void
{
    try {
        q("UPDATE purchase_requests SET status = 'closed'
           WHERE placement_id = ? AND category = 'lodging' AND status IN ('requested','ordered','received')", [$placementId]);
    } catch (Throwable $e) {
        // before the procurement upgrade has run
    }
}

function procurement_next_reference(): string
{
    $n = (int) val('SELECT COALESCE(MAX(id), 0) + 1 FROM purchase_orders');

    return 'PO-' . date('Y') . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

/** What was ordered, received, invoiced and is still to be billed. */
function procurement_commitments(int $jobId): array
{
    // An order split across projects (P3-M06) is this project's for its
    // share: share and invoiced_share are this project's part.
    $split = (bool) val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'purchase_order_allocations'");
    $rows = rows("SELECT o.id, o.reference, o.vendor_name, o.status, o.total, o.quantity, u.label AS unit_label, r.title, r.category, o.job_id,
                         (SELECT COALESCE(SUM(x.quantity), 0) FROM purchase_receipts x WHERE x.purchase_order_id = o.id) AS received,
                         (SELECT COALESCE(SUM(v.amount), 0) FROM vendor_invoices v WHERE v.purchase_order_id = o.id) AS invoiced"
               . ($split ? ", (SELECT SUM(a.amount) FROM purchase_order_allocations a WHERE a.purchase_order_id = o.id AND a.job_id = ?) AS share,
                            (SELECT COUNT(*) FROM purchase_order_allocations a WHERE a.purchase_order_id = o.id) AS shares" : '') . "
                  FROM purchase_orders o JOIN purchase_requests r ON r.id = o.request_id
                  JOIN procurement_units u ON u.id = o.unit_id
                  WHERE o.status IN ('approved','closed') AND "
               . ($split ? "(EXISTS (SELECT 1 FROM purchase_order_allocations a WHERE a.purchase_order_id = o.id AND a.job_id = ?)
                             OR (o.job_id = ? AND NOT EXISTS (SELECT 1 FROM purchase_order_allocations a WHERE a.purchase_order_id = o.id)))" : 'o.job_id = ?') . "
                  ORDER BY o.id DESC", $split ? [$jobId, $jobId, $jobId] : [$jobId]);
    foreach ($rows as &$r) {
        $r['share'] = $split && (int) $r['shares'] > 0 ? (float) $r['share'] : (float) $r['total'];
        $r['invoiced_share'] = (float) $r['total'] > 0 ? round((float) $r['invoiced'] * $r['share'] / (float) $r['total'], 2) : 0.0;
    }
    unset($r);

    return $rows;
}
