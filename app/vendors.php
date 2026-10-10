<?php
/**
 * Approved vendors, approval thresholds and project purchasing allocations
 * (P3-M06), on top of procurement.
 *
 *   - an order goes only to an approved vendor, approved for the kind of
 *     thing bought, with a W-9 on file and insurance not expired
 *   - a vendor is proposed by procurement and approved by an administrator
 *     other than whoever proposed it; it can be suspended, with a reason
 *   - the order's total picks a tier: the budget owner, an administrator,
 *     or both, in which case two different people approve
 *   - an order that takes a project past a budget line (P3-M01) also needs
 *     an administrator, whatever its total
 *   - an order can be split across projects; the shares add up to its total
 *
 * Bid analysis stays out (R43): quotations remain notes on a request.
 */

declare(strict_types=1);

require_once __DIR__ . '/procurement.php';

function vendor_statuses(): array
{
    return ['pending' => t('Waiting for approval'), 'approved' => t('Approved'), 'suspended' => t('Suspended')];
}

function vendor_event(int $vendorId, string $event, ?string $detail = null): void
{
    q('INSERT INTO vendor_events (vendor_id, event, detail, user_id) VALUES (?,?,?,?)', [$vendorId, $event, $detail !== null ? mb_substr($detail, 0, 500) : null, uid() ?: null]);
}

function vendor_by_name(string $name): ?array
{
    return row('SELECT * FROM vendors WHERE name = ?', [trim($name)]) ?: null;
}

/** Why this vendor cannot be ordered from for this category, or null. */
function vendor_refusal(string $name, string $category): ?string
{
    $v = vendor_by_name($name);
    if (! $v) {
        return t('":name" is not a registered vendor. Register it under Vendors and have it approved before ordering from it.', ['name' => trim($name)]);
    }
    if ($v['status'] !== 'approved') {
        return t('":name" is :status, so it cannot be ordered from.', ['name' => $v['name'], 'status' => mb_strtolower(vendor_statuses()[$v['status']])]);
    }
    if (! in_array($category, explode(',', (string) $v['categories']), true)) {
        return t('":name" is not approved to supply :category.', ['name' => $v['name'], 'category' => mb_strtolower(procurement_categories()[$category] ?? $category)]);
    }
    if ((int) $v['w9_on_file'] !== 1) {
        return t('":name" has no W-9 on file.', ['name' => $v['name']]);
    }
    if ($v['insurance_expires'] && $v['insurance_expires'] < date('Y-m-d')) {
        return t('":name"\'s insurance expired on :date.', ['name' => $v['name'], 'date' => d((string) $v['insurance_expires'])]);
    }

    return null;
}

/** Propose a vendor, or change one's details. Returns [id, refusal]. */
function vendor_save(array $in, int $id = 0): array
{
    $name = trim((string) ($in['name'] ?? ''));
    $cats = array_values(array_intersect(array_keys(procurement_categories()), is_array($in['categories'] ?? null) ? $in['categories'] : []));
    $expires = trim((string) ($in['insurance_expires'] ?? ''));

    if (mb_strlen($name) < 2 || mb_strlen($name) > 190) {
        return [0, t('Name the vendor in 2 to 190 characters.')];
    }
    if (! $cats) {
        return [0, t('Say what the vendor supplies.')];
    }
    if ($expires !== '' && ! valid_date($expires)) {
        return [0, t('Give the insurance expiry as a date.')];
    }
    $clash = (int) val('SELECT id FROM vendors WHERE name = ?', [$name]);
    if ($clash && $clash !== $id) {
        return [0, t('A vendor with that name is already registered.')];
    }

    $fields = [$name, implode(',', $cats), ! empty($in['w9_on_file']) ? 1 : 0, $expires ?: null,
               mb_substr(trim((string) ($in['contact'] ?? '')), 0, 190) ?: null, mb_substr(trim((string) ($in['note'] ?? '')), 0, 500) ?: null];
    if ($id) {
        $old = row('SELECT * FROM vendors WHERE id = ?', [$id]);
        if (! $old) {
            return [0, t('That vendor does not exist.')];
        }
        q('UPDATE vendors SET name = ?, categories = ?, w9_on_file = ?, insurance_expires = ?, contact = ?, note = ? WHERE id = ?', [...$fields, $id]);
        vendor_event($id, 'changed', implode(',', $cats) . ' · W-9 ' . ($fields[2] ? 'yes' : 'no') . ' · insurance ' . ($expires ?: '—'));
        // Renamed: the orders and bills that used the old name keep it; new ones use the new name.
        return [$id, null];
    }

    q('INSERT INTO vendors (name, categories, w9_on_file, insurance_expires, contact, note, created_by) VALUES (?,?,?,?,?,?,?)', [...$fields, uid() ?: null]);
    $id = (int) db()->lastInsertId();
    vendor_event($id, 'proposed', implode(',', $cats));

    return [$id, null];
}

/** Approve, suspend or reinstate. Administrators; never whoever proposed it. */
function vendor_decide(int $id, string $action, string $reason): ?string
{
    $v = row('SELECT * FROM vendors WHERE id = ? FOR UPDATE', [$id]);
    if (! $v) {
        return t('That vendor does not exist.');
    }
    if (! can('admin')) {
        return t('Only an administrator approves or suspends a vendor.');
    }
    $to = ['approve' => 'approved', 'suspend' => 'suspended', 'reinstate' => 'approved'][$action] ?? null;
    $from = ['approve' => 'pending', 'suspend' => 'approved', 'reinstate' => 'suspended'][$action] ?? null;
    if (! $to || $v['status'] !== $from) {
        return t('That cannot be done to a vendor that is :status.', ['status' => mb_strtolower(vendor_statuses()[$v['status']])]);
    }
    if ($action === 'approve' && (int) $v['created_by'] === uid()) {
        return t('Whoever proposed a vendor does not approve it.');
    }
    if ($action !== 'approve' && mb_strlen(trim($reason)) < 3) {
        return t('Say why.');
    }

    q('UPDATE vendors SET status = ?, decided_by = ?, decided_at = NOW() WHERE id = ?', [$to, uid() ?: null, $id]);
    vendor_event($id, $action, trim($reason) ?: null);

    return null;
}

/** The tiers, lowest first; the open-ended one last. */
function procurement_thresholds(): array
{
    return rows('SELECT * FROM procurement_thresholds ORDER BY up_to IS NULL, up_to');
}

/** Who approves an order of this total. */
function procurement_approvers_for(float $total): string
{
    foreach (procurement_thresholds() as $t) {
        if ($t['up_to'] === null || $total <= (float) $t['up_to'] + 0.004) {
            return (string) $t['approvers'];
        }
    }

    return 'both';
}

/** Replace the tiers. Returns the refusal, or null. */
function procurement_thresholds_save(array $upTo, array $approvers): ?string
{
    $tiers = [];
    $last = 0.0;
    foreach ($upTo as $i => $limit) {
        $limit = trim((string) $limit);
        $who = (string) ($approvers[$i] ?? '');
        if ($limit === '' && $who === '') {
            continue;
        }
        if (! in_array($who, ['budget_owner', 'admin', 'both'], true)) {
            return t('Choose who approves each tier.');
        }
        if ($limit !== '' && (! is_numeric($limit) || (float) $limit <= $last || (float) $limit > 100000000)) {
            return t('Each tier\'s limit is above the one before it.');
        }
        $tiers[] = [$limit === '' ? null : round((float) $limit, 2), $who];
        if ($limit !== '') {
            $last = (float) $limit;
        }
    }
    $open = array_filter($tiers, fn($t) => $t[0] === null);
    if (count($open) !== 1 || end($tiers)[0] !== null) {
        return t('The last tier has no limit: it covers every larger order.');
    }

    q('DELETE FROM procurement_thresholds');
    foreach ($tiers as [$limit, $who]) {
        q('INSERT INTO procurement_thresholds (up_to, approvers, updated_by, updated_at) VALUES (?,?,?,NOW())', [$limit, $who, uid() ?: null]);
    }

    return null;
}

/** The budget line a procurement category spends from (P3-M01). */
function procurement_budget_line(string $category): string
{
    return ['lodging' => 'hotels', 'vehicle' => 'transportation', 'safety_equipment' => 'equipment'][$category] ?? 'other';
}

/**
 * The split of an order across projects, from the form. Empty, the whole
 * order is the project's own. Returns [[job id => amount], refusal].
 */
function procurement_allocation_input(array $jobs, array $amounts, int $ownJob, float $total): array
{
    $split = [];
    foreach ($jobs as $i => $job) {
        $job = (int) $job;
        $amount = trim((string) ($amounts[$i] ?? ''));
        if (! $job && $amount === '') {
            continue;
        }
        if (! $job || ! val('SELECT COUNT(*) FROM jobs WHERE id = ?', [$job]) || ! is_numeric($amount) || (float) $amount <= 0) {
            return [[], t('Each share names a project and an amount above 0.')];
        }
        $split[$job] = round(($split[$job] ?? 0) + (float) $amount, 2);
    }
    if (! $split) {
        return [[$ownJob => $total], null];
    }
    if (abs(array_sum($split) - $total) >= 0.005) {
        return [[], t('The shares add up to :sum; the order is :total.', ['sum' => money(array_sum($split)), 'total' => money($total)])];
    }

    return [$split, null];
}

/**
 * What a project has left on a budget line: the budget less what was spent
 * and what is ordered and not yet invoiced. Null when no budget is set.
 */
function procurement_budget_left(int $jobId, string $line): ?float
{
    require_once __DIR__ . '/project-costing.php';
    $budget = project_budget($jobId)[$line] ?? null;
    if ($budget === null) {
        return null;
    }
    $costs = project_costs($jobId);
    $committed = 0.0;
    foreach (procurement_commitments($jobId) as $o) {
        if ($o['status'] === 'approved' && procurement_budget_line((string) $o['category']) === $line && $o['category'] !== 'lodging') {
            $committed += max(0.0, (float) $o['share'] - (float) $o['invoiced_share']);
        }
    }

    return round($budget - $costs['actual'][$line] - $committed, 2);
}

/** The approvals an order still needs: 'budget_owner' and/or 'admin'. */
function procurement_still_needed(array $order): array
{
    $rule = (string) ($order['approvers'] ?: 'budget_owner');
    if ((int) ($order['over_budget'] ?? 0) === 1 && $rule === 'budget_owner') {
        $rule = 'both';
    }
    $needed = $rule === 'both' ? ['budget_owner', 'admin'] : [$rule];
    // Only this revision's approvals count: a revised order is authorised again (P3-M07).
    $given = array_column(rows('SELECT approver FROM purchase_order_approvals WHERE purchase_order_id = ? AND revision = ?', [(int) $order['id'], (int) ($order['revision'] ?? 0)]), 'approver');

    return array_values(array_diff($needed, $given));
}

/** In which capacity this person can approve now, or null. */
function procurement_approver_role(array $order): ?string
{
    if ((int) $order['created_by'] === uid()) {
        return null;
    }
    // One person, one approval: whoever approved once does not approve again.
    if (val('SELECT COUNT(*) FROM purchase_order_approvals WHERE purchase_order_id = ? AND revision = ? AND user_id = ?', [(int) $order['id'], (int) ($order['revision'] ?? 0), uid()])) {
        return null;
    }
    $owner = (int) val('SELECT budget_owner_id FROM jobs WHERE id = ?', [(int) $order['job_id']]);
    $needed = procurement_still_needed($order);
    $isOwner = $owner && $owner === uid();
    // An administrator who is not the budget owner fills the administrator's
    // place first, leaving the budget owner's to the budget owner.
    if (in_array('admin', $needed, true) && can('admin') && ! $isOwner) {
        return 'admin';
    }
    // An administrator may stand in for the budget owner, as before.
    if (in_array('budget_owner', $needed, true) && ($isOwner || can('admin'))) {
        return 'budget_owner';
    }
    if (in_array('admin', $needed, true) && can('admin')) {
        return 'admin';
    }

    return null;
}
