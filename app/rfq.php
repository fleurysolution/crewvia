<?php
/**
 * Requests for quotation, supplier quotations, order revisions and their
 * authorization (P3-M07).
 *
 * No bid analysis (R43). A request may go out to several approved vendors.
 * Their answers are recorded as quotations and listed as they came, never
 * ranked, and any one still valid can become the order. Nothing here picks
 * a winner.
 *
 * An order is revised, not edited: quantity, unit price and the split
 * across projects can change, with a reason. A revision keeps the order as
 * it was and as it became, starts its authorization again under the tier of
 * its new total (P3-M06), and never goes below what was already received
 * or invoiced.
 */

declare(strict_types=1);

require_once __DIR__ . '/vendors.php';

function rfq_statuses(): array
{
    return ['open' => t('Waiting for an answer'), 'answered' => t('Answered'), 'declined' => t('Declined'), 'withdrawn' => t('Withdrawn')];
}

function rfq_next_reference(): string
{
    $prefix = 'RFQ-' . date('Ym') . '-';
    $n = (int) val('SELECT COUNT(*) FROM purchase_rfqs WHERE reference LIKE ?', [$prefix . '%']);
    do {
        $ref = $prefix . str_pad((string) ++$n, 3, '0', STR_PAD_LEFT);
    } while (val('SELECT COUNT(*) FROM purchase_rfqs WHERE reference = ?', [$ref]));

    return $ref;
}

/** Ask vendors to quote for a request. Returns [references, refusal]. */
function rfq_send(array $request, array $vendors, string $replyBy): array
{
    if ($request['status'] !== 'requested') {
        return [[], t('Quotations are asked for before the order is raised.')];
    }
    if (! valid_date($replyBy) || $replyBy < date('Y-m-d')) {
        return [[], t('Give a reply-by date, today or later.')];
    }
    $vendors = array_values(array_unique(array_filter(array_map('trim', $vendors))));
    if (! $vendors) {
        return [[], t('Choose at least one vendor.')];
    }
    foreach ($vendors as $name) {
        if ($why = vendor_refusal($name, (string) $request['category'])) {
            return [[], $why];
        }
        if (val("SELECT COUNT(*) FROM purchase_rfqs WHERE request_id = ? AND vendor_name = ? AND status = 'open'", [(int) $request['id'], $name])) {
            return [[], t(':name was already asked and has not answered.', ['name' => $name])];
        }
    }
    $refs = [];
    foreach ($vendors as $name) {
        $ref = rfq_next_reference();
        q('INSERT INTO purchase_rfqs (reference, request_id, vendor_name, reply_by, created_by) VALUES (?,?,?,?,?)',
          [$ref, (int) $request['id'], vendor_by_name($name)['name'], $replyBy, uid() ?: null]);
        $refs[] = $ref;
    }

    return [$refs, null];
}

/** The vendor's answer: a price, or a decline. Returns the refusal, or null. */
function rfq_answer(array $rfq, string $price, string $validUntil, string $note): ?string
{
    if ($rfq['status'] !== 'open') {
        return t('That request for quotation is already closed.');
    }
    if (! is_numeric($price) || (float) $price < 0 || (float) $price > 1000000) {
        return t('A quotation needs a unit price from 0 to 1,000,000.');
    }
    if ($validUntil !== '' && (! valid_date($validUntil) || $validUntil < date('Y-m-d'))) {
        return t('A quotation is valid until today or later.');
    }
    q('INSERT INTO purchase_quotations (request_id, vendor_name, unit_price, note, created_by, rfq_id, valid_until) VALUES (?,?,?,?,?,?,?)',
      [(int) $rfq['request_id'], $rfq['vendor_name'], round((float) $price, 2), mb_substr(trim($note), 0, 500) ?: null, uid() ?: null, (int) $rfq['id'], $validUntil ?: null]);
    q("UPDATE purchase_rfqs SET status = 'answered', closed_at = NOW() WHERE id = ?", [(int) $rfq['id']]);

    return null;
}

function rfq_close(array $rfq, string $as, string $note): ?string
{
    if ($rfq['status'] !== 'open') {
        return t('That request for quotation is already closed.');
    }
    if (mb_strlen(trim($note)) < 3) {
        return t('Say why.');
    }
    q('UPDATE purchase_rfqs SET status = ?, status_note = ?, closed_at = NOW() WHERE id = ?', [$as === 'declined' ? 'declined' : 'withdrawn', mb_substr(trim($note), 0, 500), (int) $rfq['id']]);

    return null;
}

/** The vendor and price an order takes from a quotation. Returns [vendor, price, refusal]. */
function rfq_quotation_for_order(int $quotationId, array $request): array
{
    $qt = row('SELECT * FROM purchase_quotations WHERE id = ? AND request_id = ?', [$quotationId, (int) $request['id']]);
    if (! $qt) {
        return ['', '', t('That quotation is not on this request.')];
    }
    if ($qt['valid_until'] && $qt['valid_until'] < date('Y-m-d')) {
        return ['', '', t('That quotation expired on :date. Ask the vendor again.', ['date' => d((string) $qt['valid_until'])])];
    }

    return [(string) $qt['vendor_name'], (string) $qt['unit_price'], null];
}

/** The order as a revision records it. */
function po_state(int $orderId): array
{
    $o = row('SELECT quantity, unit_price, total, approvers, over_budget, status, revision FROM purchase_orders WHERE id = ?', [$orderId]);

    return ['quantity' => (float) $o['quantity'], 'unit_price' => (float) $o['unit_price'], 'total' => (float) $o['total'],
            'approvers' => $o['approvers'], 'over_budget' => (int) $o['over_budget'], 'status' => $o['status'], 'revision' => (int) $o['revision'],
            'shares' => array_map(fn($s) => ['job_id' => (int) $s['job_id'], 'amount' => (float) $s['amount']],
                                  rows('SELECT job_id, amount FROM purchase_order_allocations WHERE purchase_order_id = ? ORDER BY id', [$orderId]))];
}

/**
 * Revise an order. The caller holds the transaction and has locked the
 * order. Returns the refusal, or null.
 */
function po_revise(array $o, string $quantity, string $price, string $reason, array $shareJobs, array $shareAmounts): ?string
{
    if (! in_array($o['status'], ['awaiting_approval', 'approved'], true)) {
        return t('Only an order waiting for approval or approved is revised.');
    }
    if (mb_strlen(trim($reason)) < 3) {
        return t('Say why the order is revised.');
    }
    if (! is_numeric($quantity) || (float) $quantity <= 0 || ! is_numeric($price) || (float) $price < 0 || (float) $price > 1000000) {
        return t('A revision needs a quantity above 0 and a unit price from 0 to 1,000,000.');
    }
    $r = row('SELECT * FROM purchase_requests WHERE id = ?', [(int) $o['request_id']]);
    $received = (float) val('SELECT COALESCE(SUM(quantity), 0) FROM purchase_receipts WHERE purchase_order_id = ?', [(int) $o['id']]);
    $invoiced = (float) val('SELECT COALESCE(SUM(amount), 0) FROM vendor_invoices WHERE purchase_order_id = ?', [(int) $o['id']]);
    if ((float) $quantity < $received - 0.001) {
        return t(':received already arrived: the order cannot ask for fewer.', ['received' => (string) (float) $received]);
    }
    $nights = $r['category'] === 'lodging' ? procurement_nights($r['needed_from'], $r['needed_to']) : 1;
    $total = round((float) $quantity * (float) $price * $nights, 2);
    if ($total < $invoiced - 0.004) {
        return t(':invoiced is already invoiced on this order: it cannot total less.', ['invoiced' => money($invoiced)]);
    }
    if (abs((float) $quantity - (float) $o['quantity']) < 0.001 && abs((float) $price - (float) $o['unit_price']) < 0.005 && ! array_filter($shareJobs)) {
        return t('Nothing changes in that revision.');
    }

    $before = po_state((int) $o['id']);
    if (array_filter($shareJobs)) {
        [$split, $why] = procurement_allocation_input($shareJobs, $shareAmounts, (int) $o['job_id'], $total);
        if ($why !== null) {
            return $why;
        }
    } else {
        // The same split, scaled to the new total; the last share takes the cents.
        $split = [];
        $left = $total;
        foreach ($before['shares'] ?: [['job_id' => (int) $o['job_id'], 'amount' => (float) $o['total']]] as $k => $s) {
            $part = $k === count($before['shares']) - 1 || ! $before['shares'] ? $left
                  : round($total * $s['amount'] / max(0.01, $before['total']), 2);
            $split[$s['job_id']] = round(($split[$s['job_id']] ?? 0) + $part, 2);
            $left = round($left - $part, 2);
        }
    }

    // Past a budget line, counting this order's new share instead of its old one.
    $line = procurement_budget_line((string) $r['category']);
    $over = false;
    foreach ($split as $sj => $share) {
        $left = procurement_budget_left((int) $sj, $line);
        if ($left === null) {
            continue;
        }
        if ($o['status'] === 'approved' && $r['category'] !== 'lodging') {
            foreach ($before['shares'] as $s) {
                if ($s['job_id'] === (int) $sj) {
                    $left += $s['amount'] * max(0.0, 1 - $invoiced / max(0.01, $before['total']));
                }
            }
        }
        $over = $over || $share > $left + 0.004;
    }

    $revision = (int) $o['revision'] + 1;
    q("UPDATE purchase_orders SET quantity = ?, unit_price = ?, total = ?, approvers = ?, over_budget = ?, status = 'awaiting_approval',
                                  decided_by = NULL, decided_at = NULL, decision_note = NULL, revision = ? WHERE id = ?",
      [round((float) $quantity, 2), round((float) $price, 2), $total, procurement_approvers_for($total), $over ? 1 : 0, $revision, (int) $o['id']]);
    q('DELETE FROM purchase_order_allocations WHERE purchase_order_id = ?', [(int) $o['id']]);
    foreach ($split as $sj => $share) {
        q('INSERT INTO purchase_order_allocations (purchase_order_id, job_id, amount) VALUES (?,?,?)', [(int) $o['id'], (int) $sj, $share]);
    }
    if ($r['status'] === 'received' && (float) $quantity > $received + 0.001) {
        q("UPDATE purchase_requests SET status = 'ordered' WHERE id = ?", [(int) $r['id']]);
    }
    q('INSERT INTO purchase_order_revisions (purchase_order_id, revision, before_json, after_json, reason, created_by) VALUES (?,?,?,?,?,?)',
      [(int) $o['id'], $revision, json_encode($before), json_encode(po_state((int) $o['id'])), mb_substr(trim($reason), 0, 500), uid() ?: null]);

    return null;
}
