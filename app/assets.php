<?php
/**
 * Assets: the equipment the agency issues to its crews - protective
 * equipment, harnesses, gas detectors, radios, tools - with a category, a
 * status, its purchase, the condition it goes out and comes back in, its
 * repairs and inspections, and a history of every step.
 *
 * Built on equipment and equipment_issues, which Operations already uses;
 * both screens issue and return through the functions here, so the rules
 * are the same wherever it happens:
 *   - only an available item is issued, and never one past its inspection
 *   - a client's item is issued only on that client's project
 *   - an item returned lost is marked lost; returned damaged, it goes to
 *     repair
 *   - "issued" is never stored: it is an open line in equipment_issues
 */

declare(strict_types=1);

function asset_statuses(): array
{
    return ['available' => t('Available'), 'issued' => t('Issued'), 'in_repair' => t('In repair'), 'lost' => t('Lost'), 'retired' => t('Retired')];
}

function asset_conditions(): array
{
    return ['good' => t('Good'), 'worn' => t('Worn'), 'damaged' => t('Damaged'), 'lost' => t('Lost')];
}

function asset_event_labels(): array
{
    return ['registered' => t('Registered'), 'issued' => t('Issued'), 'returned' => t('Returned'), 'lost' => t('Lost'),
            'repair' => t('Sent for repair'), 'repaired' => t('Back from repair'), 'inspect' => t('Inspected'),
            'lose' => t('Lost'), 'retire' => t('Retired'), 'restore' => t('Found or put back in service')];
}

function asset_categories(bool $activeOnly = true): array
{
    try {
        return rows('SELECT * FROM asset_categories' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY label');
    } catch (Throwable $e) {
        return [];
    }
}

function asset_event(int $equipmentId, string $event, ?string $detail = null, ?float $cost = null): void
{
    q('INSERT INTO asset_events (equipment_id, event, detail, cost, user_id) VALUES (?,?,?,?,?)',
      [$equipmentId, $event, $detail !== null ? mb_substr($detail, 0, 500) : null, $cost, uid() ?: null]);
}

/** The item, with its derived state ('issued' when it is out). */
function asset(int $equipmentId): ?array
{
    $a = row('SELECT e.*, o.owner_type, o.client_id, o.serial_number, c.label AS category,
                     i.id AS issue_id, i.placement_id AS held_by_placement
              FROM equipment e LEFT JOIN equipment_ownership o ON o.equipment_id = e.id
              LEFT JOIN asset_categories c ON c.id = e.category_id
              LEFT JOIN equipment_issues i ON i.equipment_id = e.id AND i.returned_at IS NULL
              WHERE e.id = ?', [$equipmentId]);

    if ($a) {
        $a['state'] = $a['issue_id'] ? 'issued' : (string) $a['status'];
    }

    return $a ?: null;
}

/** Register an item. Returns [id, refusal]. */
function asset_register(array $in, ?int $purchaseOrderId = null, ?float $cost = null): array
{
    $name = trim((string) ($in['name'] ?? ''));
    $tag = trim((string) ($in['asset_tag'] ?? ''));
    $owner = (string) ($in['owner_type'] ?? 'agency');
    $client = (int) ($in['owner_client_id'] ?? 0);
    $category = (int) ($in['category_id'] ?? 0);
    $bought = trim((string) ($in['purchase_date'] ?? ''));
    $price = $cost ?? (trim((string) ($in['purchase_cost'] ?? '')) !== '' ? (float) $in['purchase_cost'] : null);

    if (mb_strlen($name) < 2 || mb_strlen($name) > 190) {
        return [0, t('Name the item in 2 to 190 characters.')];
    }
    if ($tag === '' || mb_strlen($tag) > 120) {
        return [0, t('Give the asset tag written on the item.')];
    }
    if (val('SELECT COUNT(*) FROM equipment WHERE asset_tag = ?', [$tag])) {
        return [0, t('That asset tag is already on another item.')];
    }
    if (! in_array($owner, ['agency', 'client'], true) || ($owner === 'client' && ! val('SELECT COUNT(*) FROM clients WHERE id = ?', [$client]))) {
        return [0, t('Select a valid equipment owner.')];
    }
    if ($category && ! val('SELECT COUNT(*) FROM asset_categories WHERE id = ? AND is_active = 1', [$category])) {
        return [0, t('Choose a category from the list.')];
    }
    if (($bought !== '' && ! valid_date($bought)) || ($price !== null && (! is_finite($price) || $price < 0 || $price > 1000000))) {
        return [0, t('Check the purchase date and cost.')];
    }

    $days = $category ? val('SELECT inspection_days FROM asset_categories WHERE id = ?', [$category]) : null;
    $due = trim((string) ($in['inspection_due'] ?? ''));
    $due = $due !== '' && valid_date($due) ? $due : ($days ? date('Y-m-d', strtotime('+' . (int) $days . ' days')) : null);

    q('INSERT INTO equipment (name, asset_tag, category_id, purchase_date, purchase_cost, purchase_order_id, inspection_due) VALUES (?,?,?,?,?,?,?)',
      [$name, $tag, $category ?: null, $bought ?: null, $price, $purchaseOrderId, $due]);
    $id = (int) db()->lastInsertId();
    q('INSERT INTO equipment_ownership (equipment_id, owner_type, client_id, serial_number) VALUES (?,?,?,?)',
      [$id, $owner, $owner === 'client' ? $client : null, mb_substr(trim((string) ($in['serial_number'] ?? '')), 0, 190) ?: null]);
    asset_event($id, 'registered', $purchaseOrderId ? 'From purchase order #' . $purchaseOrderId : null, $price);

    return [$id, null];
}

/** How many more items a purchase order may still register: received less already registered. */
function asset_registrable_from(int $purchaseOrderId): int
{
    $received = (float) val('SELECT COALESCE(SUM(quantity), 0) FROM purchase_receipts WHERE purchase_order_id = ?', [$purchaseOrderId]);
    $registered = (int) val('SELECT COUNT(*) FROM equipment WHERE purchase_order_id = ?', [$purchaseOrderId]);

    return max(0, (int) floor($received) - $registered);
}

/** Issue an item to a placement on the project. Returns the refusal, or null. */
function asset_issue(int $equipmentId, int $placementId, int $jobId, string $condition): ?string
{
    $a = row('SELECT e.*, o.owner_type, o.client_id FROM equipment e LEFT JOIN equipment_ownership o ON o.equipment_id = e.id
              WHERE e.id = ? FOR UPDATE', [$equipmentId]);
    $p = row("SELECT p.id, c.full_name FROM placements p JOIN candidates c ON c.id = p.candidate_id
              WHERE p.id = ? AND p.job_id = ? AND p.status NOT IN ('completed','cancelled')", [$placementId, $jobId]);

    if (! $a || ! $p) {
        return t('Equipment is unavailable.');
    }
    if ($a['owner_type'] === 'client' && (int) $a['client_id'] !== (int) val('SELECT client_id FROM jobs WHERE id = ?', [$jobId])) {
        return t('That item belongs to another client.');
    }
    if (($a['status'] ?? 'available') !== 'available' || val('SELECT COUNT(*) FROM equipment_issues WHERE equipment_id = ? AND returned_at IS NULL', [$equipmentId])) {
        return t('Equipment is unavailable.');
    }
    if (! empty($a['inspection_due']) && $a['inspection_due'] < date('Y-m-d')) {
        return t('That item is past its inspection date (:date). Inspect it before it is issued.', ['date' => d((string) $a['inspection_due'])]);
    }
    if (! in_array($condition, ['good', 'worn', 'damaged'], true)) {
        $condition = 'good';
    }

    q('INSERT INTO equipment_issues (equipment_id, placement_id, issue_condition, issued_by) VALUES (?,?,?,?)', [$equipmentId, $placementId, $condition, uid() ?: null]);
    asset_event($equipmentId, 'issued', $p['full_name'] . ' · ' . $condition);

    return null;
}

/** Take an item back. Returns the refusal, or null. */
function asset_return(int $issueId, int $jobId, string $condition, string $note): ?string
{
    $i = row('SELECT i.* FROM equipment_issues i JOIN placements p ON p.id = i.placement_id
              WHERE i.id = ? AND p.job_id = ? AND i.returned_at IS NULL FOR UPDATE', [$issueId, $jobId]);

    if (! $i) {
        return t('That item is not out on this project.');
    }
    if (! array_key_exists($condition, asset_conditions())) {
        $condition = 'good';
    }
    if (in_array($condition, ['damaged', 'lost'], true) && mb_strlen(trim($note)) < 3) {
        return t('Say what happened to it.');
    }

    q('UPDATE equipment_issues SET returned_at = NOW(), return_note = ?, return_condition = ?, returned_by = ? WHERE id = ?',
      [trim($note) ?: null, $condition, uid() ?: null, $issueId]);

    if ($condition === 'lost') {
        q("UPDATE equipment SET status = 'lost' WHERE id = ?", [(int) $i['equipment_id']]);
    } elseif ($condition === 'damaged') {
        q("UPDATE equipment SET status = 'in_repair' WHERE id = ?", [(int) $i['equipment_id']]);
    }

    asset_event((int) $i['equipment_id'], $condition === 'lost' ? 'lost' : 'returned', $condition . (trim($note) !== '' ? ' · ' . trim($note) : ''));

    return null;
}

/**
 * Repairs, inspections, loss, retirement, restoring. Returns the refusal,
 * or null. Which moves are allowed from which state is listed here, once.
 */
function asset_action(int $equipmentId, string $action, string $note, string $cost, string $nextDue): ?string
{
    $a = asset($equipmentId);

    if (! $a) {
        return t('That item does not exist.');
    }

    $allowed = [
        'repair'   => ['available'],
        'repaired' => ['in_repair'],
        'inspect'  => ['available', 'in_repair', 'issued'],
        'lose'     => ['available', 'in_repair'],
        'retire'   => ['available', 'in_repair', 'lost'],
        'restore'  => ['lost', 'retired'],
    ];

    if (! isset($allowed[$action])) {
        return t('Unknown action.');
    }
    if (! in_array($a['state'], $allowed[$action], true)) {
        return $a['state'] === 'issued' ? t('Take the item back first.') : t('That cannot be done to an item that is :state.', ['state' => mb_strtolower(asset_statuses()[$a['state']])]);
    }
    if (in_array($action, ['repair', 'lose', 'retire'], true) && mb_strlen(trim($note)) < 3) {
        return t('Say why.');
    }

    $amount = trim($cost) !== '' ? (float) $cost : null;
    if ($amount !== null && (! is_finite($amount) || $amount < 0 || $amount > 1000000)) {
        return t('A cost is a number from 0 to 1,000,000.');
    }

    if ($action === 'inspect') {
        $days = $a['category_id'] ? val('SELECT inspection_days FROM asset_categories WHERE id = ?', [(int) $a['category_id']]) : null;
        $next = trim($nextDue) !== '' ? trim($nextDue) : ($days ? date('Y-m-d', strtotime('+' . (int) $days . ' days')) : '');
        if ($next !== '' && (! valid_date($next) || $next <= date('Y-m-d'))) {
            return t('The next inspection is a date after today.');
        }
        q('UPDATE equipment SET inspection_due = ? WHERE id = ?', [$next ?: null, $equipmentId]);
        $note = trim($note . ($next ? ' · ' . t('next due :date', ['date' => d($next)]) : ''), ' ·');
    } else {
        $status = ['repair' => 'in_repair', 'repaired' => 'available', 'lose' => 'lost', 'retire' => 'retired', 'restore' => 'available'][$action];
        q('UPDATE equipment SET status = ? WHERE id = ?', [$status, $equipmentId]);
    }

    asset_event($equipmentId, $action, trim($note) ?: null, $amount);

    return null;
}
