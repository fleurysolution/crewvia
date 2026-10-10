<?php
/**
 * Receivables and payables (P3-M04): what clients owe RSS and what RSS
 * owes its vendors, invoice by invoice and party by party.
 *
 * One set of rules for both sides ('ar' clients, 'ap' vendors):
 *   - a payment is recorded once and applied to one or more of the same
 *     party's invoices; what is not applied stays on account
 *   - an application never exceeds what the invoice still asks or what the
 *     payment still has
 *   - a credit note lowers what an invoice asks; administrators only
 *   - an invoice's balance is what it asks less what was applied and
 *     credited, and its status follows: nothing left, it is paid; something
 *     left again after a reversal, it is back to issued (approved for a bill)
 *   - an invoice marked paid before payments were recorded is settled: it
 *     asks nothing and takes no application
 *   - nothing is deleted: payments, applications and credit notes are
 *     reversed with a reason, and stay
 */

declare(strict_types=1);

require_once __DIR__ . '/periods.php';

function bal_side(string $side): array
{
    return $side === 'ap'
        ? ['side' => 'ap', 'invoices' => 'vendor_invoices', 'total' => 'amount', 'open' => ['approved', 'paid'], 'unpaid' => 'approved',
           'payments' => 'ap_payments', 'alloc' => 'ap_allocations', 'credits' => 'ap_credits', 'party' => 'vendor_name']
        : ['side' => 'ar', 'invoices' => 'client_invoices', 'total' => 'total', 'open' => ['issued', 'paid'], 'unpaid' => 'issued',
           'payments' => 'ar_payments', 'alloc' => 'ar_allocations', 'credits' => 'ar_credits', 'party' => 'client_id'];
}

function bal_methods(): array
{
    return ['transfer' => t('Wire transfer'), 'ach' => t('ACH'), 'check' => t('Check'), 'card' => t('Card'), 'cash' => t('Cash')];
}

/**
 * Invoices with what they ask, what was applied and credited, and what is
 * left. $party narrows to one client id or vendor name.
 */
function bal_invoices(string $side, $party = null, bool $openOnly = false): array
{
    $s = bal_side($side);
    $partyExpr = $side === 'ap' ? 'i.vendor_name' : 'j.client_id';
    $labelExpr = $side === 'ap' ? 'i.vendor_name' : 'c.name';
    $issued = $side === 'ap' ? 'i.due_on' : 'DATE(COALESCE(i.issued_at, i.created_at))';
    $args = [];
    $where = "i.status IN ('" . implode("','", $s['open']) . "')";
    if ($party !== null) {
        $where .= " AND $partyExpr = ?";
        $args[] = $party;
    }

    $rows = rows("SELECT i.id, i.reference, i.status, i.{$s['total']} AS total, i.due_on, $issued AS issued_on, $partyExpr AS party, $labelExpr AS party_label,
                         j.title AS project, j.id AS job_id,
                         (SELECT COALESCE(SUM(a.amount), 0) FROM {$s['alloc']} a WHERE a.invoice_id = i.id AND a.reversed_at IS NULL) AS applied,
                         (SELECT COALESCE(SUM(x.amount), 0) FROM {$s['credits']} x WHERE x.invoice_id = i.id AND x.reversed_at IS NULL) AS credited,
                         (SELECT COUNT(*) FROM {$s['alloc']} a WHERE a.invoice_id = i.id) + (SELECT COUNT(*) FROM {$s['credits']} x WHERE x.invoice_id = i.id) AS touched
                  FROM {$s['invoices']} i JOIN jobs j ON j.id = i.job_id LEFT JOIN clients c ON c.id = j.client_id
                  WHERE $where ORDER BY i.due_on, i.id", $args);

    $out = [];
    foreach ($rows as $r) {
        $r['settled_before'] = $r['status'] === 'paid' && (int) $r['touched'] === 0;
        $r['balance'] = $r['settled_before'] ? 0.0 : round((float) $r['total'] - (float) $r['applied'] - (float) $r['credited'], 2);
        $r['days_late'] = $r['due_on'] ? (int) floor((strtotime(date('Y-m-d')) - strtotime((string) $r['due_on'])) / 86400) : 0;
        if (! $openOnly || $r['balance'] > 0.004) {
            $out[] = $r;
        }
    }

    return $out;
}

/** One invoice's figures, or null if it cannot take money. */
function bal_invoice(string $side, int $invoiceId): ?array
{
    $s = bal_side($side);
    $party = val("SELECT " . ($side === 'ap' ? 'i.vendor_name' : 'j.client_id') . " FROM {$s['invoices']} i JOIN jobs j ON j.id = i.job_id WHERE i.id = ?", [$invoiceId]);
    if ($party === null || $party === false) {
        return null;
    }
    foreach (bal_invoices($side, $party) as $r) {
        if ((int) $r['id'] === $invoiceId) {
            return $r;
        }
    }

    return null;
}

/** Payments with what was applied and what is still on account. */
function bal_payments(string $side, $party = null, bool $standingOnly = false): array
{
    $s = bal_side($side);
    $where = [];
    $args = [];
    if ($party !== null) {
        $where[] = "p.{$s['party']} = ?";
        $args[] = $party;
    }
    if ($standingOnly) {
        $where[] = 'p.reversed_at IS NULL';
    }
    $rows = rows("SELECT p.*, (SELECT COALESCE(SUM(a.amount), 0) FROM {$s['alloc']} a WHERE a.payment_id = p.id AND a.reversed_at IS NULL) AS applied
                  FROM {$s['payments']} p" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY p.received_on, p.id', $args);
    foreach ($rows as &$r) {
        $r['unapplied'] = $r['reversed_at'] ? 0.0 : round((float) $r['amount'] - (float) $r['applied'], 2);
    }
    unset($r);

    return $rows;
}

/** Put an invoice's status in line with its balance. */
function bal_sync(string $side, int $invoiceId): void
{
    $s = bal_side($side);
    $inv = bal_invoice($side, $invoiceId);
    if (! $inv || $inv['settled_before']) {
        return;
    }

    if ($inv['balance'] <= 0.004 && $inv['status'] !== 'paid') {
        $last = row("SELECT p.received_on, p.method, p.reference FROM {$s['alloc']} a JOIN {$s['payments']} p ON p.id = a.payment_id
                     WHERE a.invoice_id = ? AND a.reversed_at IS NULL ORDER BY p.received_on DESC, a.id DESC LIMIT 1", [$invoiceId]);
        $paidAt = ($last['received_on'] ?? date('Y-m-d')) . ' 12:00:00';
        if ($side === 'ap') {
            // The register shows how a bill was paid, so it keeps the last payment's method and reference.
            $method = in_array($last['method'] ?? '', ['transfer', 'check', 'cash', 'card'], true) ? $last['method'] : ($last ? 'transfer' : null);
            q("UPDATE vendor_invoices SET status = 'paid', paid_at = ?, payment_method = ?, payment_reference = ? WHERE id = ?",
              [$paidAt, $method, $last['reference'] ?? null, $invoiceId]);
        } else {
            q("UPDATE client_invoices SET status = 'paid', paid_at = ? WHERE id = ?", [$paidAt, $invoiceId]);
        }
    } elseif ($inv['balance'] > 0.004 && $inv['status'] === 'paid') {
        if ($side === 'ap') {
            q("UPDATE vendor_invoices SET status = 'approved', paid_at = NULL, payment_method = NULL, payment_reference = NULL WHERE id = ?", [$invoiceId]);
        } else {
            q("UPDATE client_invoices SET status = 'issued', paid_at = NULL WHERE id = ?", [$invoiceId]);
        }
    }
}

function bal_amount(string $v): ?float
{
    $v = trim($v);
    if ($v === '' || ! is_numeric($v) || (float) $v <= 0 || (float) $v > 100000000 || round((float) $v, 2) != (float) $v) {
        return null;
    }

    return round((float) $v, 2);
}

/** Apply part of a payment to an invoice. Returns the refusal, or null. */
function bal_apply(string $side, int $paymentId, int $invoiceId, string $amount): ?string
{
    $s = bal_side($side);
    $value = bal_amount($amount);
    $pay = row("SELECT * FROM {$s['payments']} WHERE id = ? FOR UPDATE", [$paymentId]);
    q("SELECT id FROM {$s['invoices']} WHERE id = ? FOR UPDATE", [$invoiceId]);
    $inv = bal_invoice($side, $invoiceId);

    if ($value === null) {
        return t('An amount is more than 0, in dollars and cents.');
    }
    if (! $pay || $pay['reversed_at']) {
        return t('That payment does not exist or was reversed.');
    }
    if (! $inv || (string) $inv['party'] !== (string) $pay[$s['party']]) {
        return t('Apply a payment only to the same party\'s open invoices.');
    }
    $left = round((float) $pay['amount'] - (float) val("SELECT COALESCE(SUM(amount), 0) FROM {$s['alloc']} WHERE payment_id = ? AND reversed_at IS NULL", [$paymentId]), 2);
    if ($value > $left + 0.004) {
        return t('The payment has :left left to apply.', ['left' => money($left)]);
    }
    if ($value > $inv['balance'] + 0.004) {
        return t('The invoice :ref asks only :left.', ['ref' => $inv['reference'], 'left' => money($inv['balance'])]);
    }

    q("INSERT INTO {$s['alloc']} (payment_id, invoice_id, amount, created_by) VALUES (?,?,?,?)", [$paymentId, $invoiceId, $value, uid() ?: null]);
    bal_sync($side, $invoiceId);

    return null;
}

/**
 * Record a payment, and apply it to the invoices given. All or nothing:
 * the caller holds the transaction. Returns [id, refusal].
 */
function bal_record(string $side, $party, string $date, string $amount, string $method, string $reference, string $note, array $apply): array
{
    $s = bal_side($side);
    $value = bal_amount($amount);
    if ($value === null) {
        return [0, t('An amount is more than 0, in dollars and cents.')];
    }
    if (! valid_date($date) || $date > date('Y-m-d')) {
        return [0, t('Date the payment today or earlier.')];
    }
    if ($why = period_guard($date)) {
        return [0, $why];
    }
    if (! isset(bal_methods()[$method])) {
        return [0, t('Choose how it was paid.')];
    }
    if (mb_strlen(trim($reference)) < 2 || mb_strlen(trim($reference)) > 190) {
        return [0, t('Give the payment\'s reference: the check number, the transfer reference.')];
    }
    if ($side === 'ar' ? ! val('SELECT COUNT(*) FROM clients WHERE id = ?', [(int) $party]) : ! val('SELECT COUNT(*) FROM vendor_invoices WHERE vendor_name = ?', [(string) $party])) {
        return [0, t('Choose who the payment is from or to.')];
    }
    if (val("SELECT COUNT(*) FROM {$s['payments']} WHERE {$s['party']} = ? AND reference = ? AND reversed_at IS NULL", [$party, trim($reference)])) {
        return [0, t('A payment with that reference is already recorded for them.')];
    }

    q("INSERT INTO {$s['payments']} ({$s['party']}, received_on, amount, method, reference, note, created_by) VALUES (?,?,?,?,?,?,?)",
      [$party, $date, $value, $method, trim($reference), mb_substr(trim($note), 0, 500) ?: null, uid() ?: null]);
    $id = (int) db()->lastInsertId();

    foreach ($apply as $invoiceId => $part) {
        if (trim((string) $part) === '') {
            continue;
        }
        if ($why = bal_apply($side, $id, (int) $invoiceId, (string) $part)) {
            return [0, $why];
        }
    }

    return [$id, null];
}

/** Take an application back: the money returns to the payment, on account. */
function bal_unapply(string $side, int $allocationId, string $reason): ?string
{
    $s = bal_side($side);
    $a = row("SELECT * FROM {$s['alloc']} WHERE id = ? AND reversed_at IS NULL FOR UPDATE", [$allocationId]);
    if (! $a) {
        return t('That application does not exist or was already taken back.');
    }
    if (mb_strlen(trim($reason)) < 3) {
        return t('Say why.');
    }
    q("UPDATE {$s['alloc']} SET reversed_at = NOW(), reversed_by = ?, reversal_reason = ? WHERE id = ?", [uid() ?: null, trim($reason), $allocationId]);
    bal_sync($side, (int) $a['invoice_id']);

    return null;
}

/** Reverse a payment: a bounced check, a payment recorded twice. */
function bal_reverse_payment(string $side, int $paymentId, string $reason): ?string
{
    $s = bal_side($side);
    $p = row("SELECT * FROM {$s['payments']} WHERE id = ? FOR UPDATE", [$paymentId]);
    if (! $p || $p['reversed_at']) {
        return t('That payment does not exist or was reversed.');
    }
    if (mb_strlen(trim($reason)) < 3) {
        return t('Say why.');
    }
    if ($why = period_guard(date('Y-m-d'))) {
        return $why;
    }
    $invoices = array_map('intval', array_column(rows("SELECT invoice_id FROM {$s['alloc']} WHERE payment_id = ? AND reversed_at IS NULL", [$paymentId]), 'invoice_id'));
    q("UPDATE {$s['alloc']} SET reversed_at = NOW(), reversed_by = ?, reversal_reason = ? WHERE payment_id = ? AND reversed_at IS NULL", [uid() ?: null, 'Payment reversed: ' . trim($reason), $paymentId]);
    q("UPDATE {$s['payments']} SET reversed_at = NOW(), reversed_by = ?, reversal_reason = ? WHERE id = ?", [uid() ?: null, trim($reason), $paymentId]);
    foreach (array_unique($invoices) as $inv) {
        bal_sync($side, $inv);
    }

    return null;
}

/** A credit note against an invoice. Administrators only. Returns [id, refusal]. */
function bal_credit(string $side, int $invoiceId, string $amount, string $reason, string $date): array
{
    $s = bal_side($side);
    if (! can('admin')) {
        return [0, t('Only an administrator issues a credit note.')];
    }
    $value = bal_amount($amount);
    q("SELECT id FROM {$s['invoices']} WHERE id = ? FOR UPDATE", [$invoiceId]);
    $inv = bal_invoice($side, $invoiceId);
    if ($value === null) {
        return [0, t('An amount is more than 0, in dollars and cents.')];
    }
    if (! $inv || $inv['settled_before']) {
        return [0, t('Choose an open invoice.')];
    }
    if ($value > $inv['balance'] + 0.004) {
        return [0, t('The invoice :ref asks only :left.', ['ref' => $inv['reference'], 'left' => money($inv['balance'])])];
    }
    if (mb_strlen(trim($reason)) < 3) {
        return [0, t('Say why the invoice is credited.')];
    }
    if (! valid_date($date) || $date > date('Y-m-d')) {
        return [0, t('Date the credit note today or earlier.')];
    }
    if ($why = period_guard($date)) {
        return [0, $why];
    }

    q("INSERT INTO {$s['credits']} (invoice_id, amount, issued_on, reason, created_by) VALUES (?,?,?,?,?)", [$invoiceId, $value, $date, mb_substr(trim($reason), 0, 500), uid() ?: null]);
    $id = (int) db()->lastInsertId();
    bal_sync($side, $invoiceId);

    return [$id, null];
}

function bal_reverse_credit(string $side, int $creditId, string $reason): ?string
{
    $s = bal_side($side);
    if (! can('admin')) {
        return t('Only an administrator issues a credit note.');
    }
    $c = row("SELECT * FROM {$s['credits']} WHERE id = ? AND reversed_at IS NULL FOR UPDATE", [$creditId]);
    if (! $c) {
        return t('That credit note does not exist or was reversed.');
    }
    if (mb_strlen(trim($reason)) < 3) {
        return t('Say why.');
    }
    if ($why = period_guard(date('Y-m-d'))) {
        return $why;
    }
    q("UPDATE {$s['credits']} SET reversed_at = NOW(), reversed_by = ?, reversal_reason = ? WHERE id = ?", [uid() ?: null, trim($reason), $creditId]);
    bal_sync($side, (int) $c['invoice_id']);

    return null;
}

/** Aging buckets by days past due: not yet due, 1-30, 31-60, 61-90, over 90. */
function bal_buckets(): array
{
    return ['current' => t('Not yet due'), 'd30' => t('1–30 days'), 'd60' => t('31–60 days'), 'd90' => t('61–90 days'), 'over' => t('Over 90 days')];
}

function bal_bucket(int $daysLate): string
{
    return $daysLate <= 0 ? 'current' : ($daysLate <= 30 ? 'd30' : ($daysLate <= 60 ? 'd60' : ($daysLate <= 90 ? 'd90' : 'over')));
}

/** Each party: open invoices by bucket, on-account payments, net. */
function bal_aging(string $side): array
{
    $parties = [];
    $blank = fn($label) => ['label' => $label, 'open' => 0.0, 'on_account' => 0.0, 'net' => 0.0, 'invoices' => 0] + array_fill_keys(array_keys(bal_buckets()), 0.0);
    foreach (bal_invoices($side, null, true) as $r) {
        $k = (string) $r['party'];
        $parties[$k] ??= $blank($r['party_label']);
        $parties[$k][bal_bucket($r['days_late'])] += $r['balance'];
        $parties[$k]['open'] += $r['balance'];
        $parties[$k]['invoices']++;
    }
    foreach (bal_payments($side, null, true) as $p) {
        if ($p['unapplied'] > 0.004) {
            $k = (string) $p[bal_side($side)['party']];
            $parties[$k] ??= $blank($side === 'ap' ? $k : (string) val('SELECT name FROM clients WHERE id = ?', [(int) $k]));
            $parties[$k]['on_account'] += $p['unapplied'];
        }
    }
    foreach ($parties as &$p) {
        foreach ($p as $key => $v) {
            if (is_float($v)) { $p[$key] = round($v, 2); }
        }
        $p['net'] = round($p['open'] - $p['on_account'], 2);
    }
    unset($p);
    uasort($parties, fn($a, $b) => $b['open'] <=> $a['open']);

    return $parties;
}

/** A party's statement: invoices, credit notes, payments and reversals in order, with the running balance. */
function bal_statement(string $side, $party): array
{
    $s = bal_side($side);
    $events = [];
    foreach (bal_invoices($side, $party) as $i) {
        $events[] = ['date' => (string) $i['issued_on'], 'what' => t('Invoice :ref', ['ref' => $i['reference']]), 'amount' => (float) $i['total'], 'order' => 1];
        if ($i['settled_before']) {
            $events[] = ['date' => (string) $i['issued_on'], 'what' => t('Settled before payments were recorded'), 'amount' => -(float) $i['total'], 'order' => 2];
        }
        foreach (rows("SELECT * FROM {$s['credits']} WHERE invoice_id = ?", [(int) $i['id']]) as $c) {
            $events[] = ['date' => (string) $c['issued_on'], 'what' => t('Credit note on :ref', ['ref' => $i['reference']]) . ' · ' . $c['reason'], 'amount' => -(float) $c['amount'], 'order' => 3];
            if ($c['reversed_at']) {
                $events[] = ['date' => substr((string) $c['reversed_at'], 0, 10), 'what' => t('Credit note reversed') . ' · ' . $c['reversal_reason'], 'amount' => (float) $c['amount'], 'order' => 5];
            }
        }
    }
    foreach (bal_payments($side, $party) as $p) {
        $events[] = ['date' => (string) $p['received_on'], 'what' => t('Payment :ref', ['ref' => $p['reference']]), 'amount' => -(float) $p['amount'], 'order' => 4];
        if ($p['reversed_at']) {
            $events[] = ['date' => substr((string) $p['reversed_at'], 0, 10), 'what' => t('Payment reversed') . ' · ' . $p['reversal_reason'], 'amount' => (float) $p['amount'], 'order' => 5];
        }
    }
    usort($events, fn($a, $b) => [$a['date'], $a['order']] <=> [$b['date'], $b['order']]);
    $running = 0.0;
    foreach ($events as &$e) {
        $running = round($running + $e['amount'], 2);
        $e['balance'] = $running;
    }

    return $events;
}

/** What does not add up: the checks a reconciliation starts from. */
function bal_exceptions(string $side): array
{
    $out = [];
    foreach (bal_invoices($side) as $i) {
        if ($i['settled_before']) {
            continue;
        }
        if ($i['status'] === 'paid' && $i['balance'] > 0.004) {
            $out[] = t(':ref is marked paid but :left is still open.', ['ref' => $i['reference'], 'left' => money($i['balance'])]);
        }
        if ($i['status'] !== 'paid' && $i['balance'] <= 0.004) {
            $out[] = t(':ref has nothing left to pay but is not marked paid.', ['ref' => $i['reference']]);
        }
        if ($i['balance'] < -0.004) {
            $out[] = t(':ref has been paid or credited :over more than it asks.', ['ref' => $i['reference'], 'over' => money(-$i['balance'])]);
        }
    }
    foreach (bal_payments($side, null, true) as $p) {
        if ($p['unapplied'] > 0.004 && $p['received_on'] < date('Y-m-d', strtotime('-30 days'))) {
            $out[] = t('Payment :ref has had :left on account for over 30 days.', ['ref' => $p['reference'], 'left' => money($p['unapplied'])]);
        }
    }

    return $out;
}
