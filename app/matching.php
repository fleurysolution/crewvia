<?php
/**
 * Three-way matching and payment readiness (P3-M08).
 *
 * A bill against a purchase order is matched to the order and to what was
 * accepted when it arrived (rejected quantities are not received):
 *
 *   order_not_authorised  the order is not approved (rejected, cancelled,
 *                         or a revision waiting for approval)
 *   vendor_mismatch       the bill's vendor is not the order's
 *   over_order            the order's bills, up to this one, total more
 *                         than the order
 *   not_received          nothing has been accepted against the order
 *   over_receipt          the order's bills, up to this one, total more
 *                         than the value of what was accepted
 *   price_variance        the bill states a quantity, and its unit price
 *                         is not the order's
 *
 * Differences within the tolerance (a percentage, or an amount, whichever
 * is more) are not exceptions. A bill with exceptions is ready to pay only
 * once an administrator clears exactly those exceptions, with a reason: if
 * the exceptions change, the clearance no longer counts.
 *
 * A bill with no order is checked by its approval alone, as before.
 */

declare(strict_types=1);

require_once __DIR__ . '/procurement.php';

function match_labels(): array
{
    return [
        'order_not_authorised' => t('The order is not approved'),
        'vendor_mismatch'      => t('The vendor is not the order\'s'),
        'over_order'           => t('Billed beyond the order'),
        'not_received'         => t('Nothing received yet'),
        'over_receipt'         => t('Billed beyond what was received'),
        'price_variance'       => t('Unit price differs from the order'),
    ];
}

function match_tolerance(): array
{
    $get = static function (string $key, float $default): float {
        $v = val('SELECT setting_value FROM platform_settings WHERE setting_key = ?', [$key]);
        return is_numeric($v) ? (float) $v : $default;
    };

    return ['percent' => $get('match_tolerance_percent', 2), 'amount' => $get('match_tolerance_amount', 10)];
}

function match_allowed(float $base, array $tol): float
{
    return max($base * $tol['percent'] / 100, $tol['amount']);
}

/**
 * The match of one bill: its exceptions, the figures they come from,
 * whether a clearance covers them, and whether it is ready to pay.
 */
function bill_match(array $bill): array
{
    $tol = match_tolerance();
    $out = ['codes' => [], 'order' => null, 'ordered' => null, 'received_value' => null, 'billed_through' => null,
            'received_qty' => null, 'clearance' => null, 'two_way' => empty($bill['purchase_order_id'])];

    if (! $out['two_way']) {
        $o = row('SELECT o.*, r.category, r.needed_from, r.needed_to FROM purchase_orders o JOIN purchase_requests r ON r.id = o.request_id WHERE o.id = ?', [(int) $bill['purchase_order_id']]);
        $out['order'] = $o;
        $nights = $o['category'] === 'lodging' ? procurement_nights($o['needed_from'], $o['needed_to']) : 1;
        $accepted = (float) val('SELECT COALESCE(SUM(quantity), 0) FROM purchase_receipts WHERE purchase_order_id = ?', [(int) $o['id']]);
        // The bills entered before this one, and this one: an earlier bill is not blamed for a later one.
        $through = (float) val('SELECT COALESCE(SUM(amount), 0) FROM vendor_invoices WHERE purchase_order_id = ? AND id <= ?', [(int) $o['id'], (int) $bill['id']]);
        $out['ordered'] = (float) $o['total'];
        $out['received_qty'] = $accepted;
        $out['received_value'] = round($accepted * (float) $o['unit_price'] * $nights, 2);
        $out['billed_through'] = round($through, 2);

        if (! in_array($o['status'], ['approved', 'closed'], true)) {
            $out['codes'][] = 'order_not_authorised';
        }
        if (mb_strtolower(trim((string) $bill['vendor_name'])) !== mb_strtolower(trim((string) $o['vendor_name']))) {
            $out['codes'][] = 'vendor_mismatch';
        }
        if ($through > (float) $o['total'] + match_allowed((float) $o['total'], $tol)) {
            $out['codes'][] = 'over_order';
        }
        if ($accepted <= 0.0001) {
            $out['codes'][] = 'not_received';
        } elseif ($through > $out['received_value'] + match_allowed($out['received_value'], $tol)) {
            $out['codes'][] = 'over_receipt';
        }
        if (isset($bill['quantity']) && $bill['quantity'] !== null && (float) $bill['quantity'] > 0) {
            $price = (float) $bill['amount'] / (float) $bill['quantity'];
            $expected = (float) $o['unit_price'] * $nights;
            if (abs($price - $expected) > match_allowed($expected, ['percent' => $tol['percent'], 'amount' => 0.01])) {
                $out['codes'][] = 'price_variance';
            }
        }
    }

    $codes = implode(',', $out['codes']);
    $last = $out['codes'] ? row('SELECT c.*, u.name AS by_name FROM bill_match_clearances c LEFT JOIN users u ON u.id = c.user_id WHERE c.invoice_id = ? ORDER BY c.id DESC LIMIT 1', [(int) $bill['id']]) : null;
    $out['clearance'] = $last && $last['codes'] === $codes ? $last : null;
    $out['matched'] = ! $out['codes'];
    $out['ready'] = $bill['status'] === 'approved' && ($out['matched'] || $out['clearance'] !== null);

    return $out;
}

/** Why a bill cannot be paid yet, or null. */
function bill_payment_refusal(int $invoiceId): ?string
{
    $bill = row('SELECT * FROM vendor_invoices WHERE id = ?', [$invoiceId]);
    if (! $bill || $bill['status'] !== 'approved') {
        return null;
    }
    $m = bill_match($bill);
    if ($m['ready']) {
        return null;
    }

    return t('Bill :ref is not ready to pay: :why. Clear it under Bill matching first.',
             ['ref' => $bill['reference'], 'why' => mb_strtolower(implode('; ', array_map(fn($c) => match_labels()[$c], $m['codes'])))]);
}

/** Clear a bill's exceptions as they stand. Administrators; returns the refusal, or null. */
function bill_clear(int $invoiceId, string $reason): ?string
{
    if (! can('admin')) {
        return t('Only an administrator clears an exception.');
    }
    $bill = row('SELECT * FROM vendor_invoices WHERE id = ? FOR UPDATE', [$invoiceId]);
    if (! $bill || in_array($bill['status'], ['paid'], true)) {
        return t('That bill is not open.');
    }
    $m = bill_match($bill);
    if (! $m['codes']) {
        return t('That bill has no exception to clear.');
    }
    if ($m['clearance']) {
        return t('Those exceptions are already cleared.');
    }
    if (mb_strlen(trim($reason)) < 3) {
        return t('Say why the bill is paid despite its exceptions.');
    }
    q('INSERT INTO bill_match_clearances (invoice_id, codes, reason, user_id) VALUES (?,?,?,?)', [$invoiceId, implode(',', $m['codes']), mb_substr(trim($reason), 0, 500), uid() ?: null]);

    return null;
}

/** Every open bill against an order, with its match. */
function bills_open(): array
{
    $out = [];
    foreach (rows("SELECT v.*, j.title AS project, o.reference AS po_reference FROM vendor_invoices v JOIN jobs j ON j.id = v.job_id
                   LEFT JOIN purchase_orders o ON o.id = v.purchase_order_id
                   WHERE v.status IN ('received','approved') ORDER BY v.due_on, v.id") as $b) {
        $b['match'] = bill_match($b);
        $out[] = $b;
    }

    return $out;
}
