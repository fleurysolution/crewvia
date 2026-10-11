<?php
/**
 * Asset lifecycle (P5-M03), on top of Phase 2's equipment module
 * (app/assets.php): classification, inspections with results, maintenance
 * plans, the service history, retirement and disposal, and the item's value.
 *
 * Value is a management valuation: straight-line depreciation of the
 * purchase cost down to a residual value over a useful life, from the day
 * the item went into service. It is not posted to the ledger. Equipment is
 * expensed when bought today (account 5120); capitalising it is a decision
 * for the agency's accountant, and the figures here are what it would need.
 *
 * Recording is for logistics (hotels) and administrators, as in Phase 2.
 */

declare(strict_types=1);

require_once __DIR__ . '/assets.php';

/** @return array<string,string> */
function al_classes(): array
{
    return ['safety' => t('Safety equipment'), 'tools' => t('Tools'), 'electronics' => t('Electronics'), 'vehicles_plant' => t('Vehicles and plant'), 'other' => t('Other')];
}

/** @return array<string,string> */
function al_disposal_methods(): array
{
    return ['sold' => t('Sold'), 'scrapped' => t('Scrapped'), 'returned_to_client' => t('Returned to the client'), 'donated' => t('Donated'), 'written_off' => t('Written off')];
}

/** @return array<string,string> */
function al_service_kinds(): array
{
    return ['repair' => t('Repair'), 'maintenance' => t('Maintenance'), 'calibration' => t('Calibration')];
}

function al_amount_refusal(string $v, float $max = 1000000): ?string
{
    return $v === '' || (is_numeric($v) && (float) $v >= 0 && (float) $v <= $max) ? null : t('A cost is a number from 0 to 1,000,000.');
}

function al_past_date_refusal(string $date): ?string
{
    return valid_date($date) && $date <= date('Y-m-d') && $date >= '2000-01-01' ? null : t('Give a date that is not in the future.');
}

// ── classification ──────────────────────────────────────────────────────

/** Saves a category: code, label, class, inspection interval, useful life, residual share. */
function al_category_save(array $in, int $id = 0): ?string
{
    $code = trim((string) ($in['code'] ?? ''));
    $label = trim((string) ($in['label'] ?? ''));
    $class = (string) ($in['asset_class'] ?? 'other');
    $days = trim((string) ($in['inspection_days'] ?? ''));
    $life = trim((string) ($in['useful_life_months'] ?? ''));
    $residual = trim((string) ($in['residual_percent'] ?? ''));
    if (! preg_match('/^[a-z0-9_]{2,40}$/D', $code)) { return t('A category code is 2 to 40 lowercase letters, digits or underscores.'); }
    if (mb_strlen($label) < 2 || mb_strlen($label) > 120) { return t('Give the category a name of 2 to 120 characters.'); }
    if (! isset(al_classes()[$class])) { return t('Choose a class.'); }
    if ($days !== '' && (! ctype_digit($days) || (int) $days < 1 || (int) $days > 3650)) { return t('An inspection interval is 1 to 3,650 days.'); }
    if ($life !== '' && (! ctype_digit($life) || (int) $life < 1 || (int) $life > 600)) { return t('A useful life is 1 to 600 months.'); }
    if ($residual !== '' && (! is_numeric($residual) || (float) $residual < 0 || (float) $residual > 90)) { return t('A residual value is 0 to 90% of the cost.'); }
    if (val('SELECT COUNT(*) FROM asset_categories WHERE code = ? AND id <> ?', [$code, $id])) { return t('Another category already has that code.'); }
    $values = [$code, $label, $class, $days !== '' ? (int) $days : null, $life !== '' ? (int) $life : null, $residual !== '' ? round((float) $residual, 2) : null];
    if ($id) {
        if (! row('SELECT id FROM asset_categories WHERE id = ?', [$id])) { return t('That category does not exist.'); }
        q('UPDATE asset_categories SET code = ?, label = ?, asset_class = ?, inspection_days = ?, useful_life_months = ?, residual_percent = ?, is_active = ? WHERE id = ?',
          [...$values, ! empty($in['is_active']) ? 1 : 0, $id]);
        log_activity('changed an asset category', 'asset_category', $id, $code);
        return null;
    }
    q('INSERT INTO asset_categories (code, label, asset_class, inspection_days, useful_life_months, residual_percent) VALUES (?,?,?,?,?,?)', $values);
    log_activity('added an asset category', 'asset_category', (int) db()->lastInsertId(), $code);
    return null;
}

// ── inspections ─────────────────────────────────────────────────────────

/**
 * Records an inspection. Passed: the next one is set (given, or from the
 * category). Failed: what was found is required, the item goes to repair,
 * and it is not issued until it passes again.
 */
function al_inspect(int $equipmentId, string $result, string $on, string $findings, string $nextDue): ?string
{
    $a = asset($equipmentId);
    if (! $a) { return t('That item does not exist.'); }
    if (in_array($a['state'], ['lost', 'retired', 'disposed'], true)) {
        return t('That cannot be done to an item that is :state.', ['state' => mb_strtolower(asset_statuses()[$a['state']])]);
    }
    if (! in_array($result, ['pass', 'fail'], true)) { return t('Say whether it passed or failed.'); }
    if (($why = al_past_date_refusal($on)) !== null) { return $why; }
    $findings = trim($findings);
    if (mb_strlen($findings) > 1000) { return t('Keep the findings under 1,000 characters.'); }
    if ($result === 'fail') {
        if (mb_strlen($findings) < 3) { return t('Say what failed, so the repair can be done.'); }
        if ($a['state'] === 'issued') { return t('Take the item back first.'); }
        q("UPDATE equipment SET status = 'in_repair' WHERE id = ?", [$equipmentId]);
        q('INSERT INTO asset_inspections (equipment_id, inspected_on, result, findings, inspected_by) VALUES (?,?,?,?,?)', [$equipmentId, $on, 'fail', $findings, uid() ?: null]);
        asset_event($equipmentId, 'inspection_failed', $findings);
        log_activity('recorded a failed inspection', 'equipment', $equipmentId, $findings);
        return null;
    }
    $days = $a['category_id'] ? val('SELECT inspection_days FROM asset_categories WHERE id = ?', [(int) $a['category_id']]) : null;
    $next = trim($nextDue) !== '' ? trim($nextDue) : ($days ? date('Y-m-d', strtotime($on . ' +' . (int) $days . ' days')) : '');
    if ($next !== '' && (! valid_date($next) || $next <= date('Y-m-d'))) { return t('The next inspection is a date after today.'); }
    q('UPDATE equipment SET inspection_due = ? WHERE id = ?', [$next ?: null, $equipmentId]);
    q('INSERT INTO asset_inspections (equipment_id, inspected_on, result, findings, next_due, inspected_by) VALUES (?,?,?,?,?,?)',
      [$equipmentId, $on, 'pass', $findings ?: null, $next ?: null, uid() ?: null]);
    asset_event($equipmentId, 'inspect', trim($findings . ($next ? ' · ' . t('next due :date', ['date' => d($next)]) : ''), ' ·') ?: null);
    log_activity('recorded a passed inspection', 'equipment', $equipmentId, $next ? 'next ' . $next : '');
    return null;
}

/** The latest inspection failed and no pass came after it. */
function al_failed_inspection(int $equipmentId): bool
{
    try {
        return val('SELECT result FROM asset_inspections WHERE equipment_id = ? ORDER BY inspected_on DESC, id DESC LIMIT 1', [$equipmentId]) === 'fail';
    } catch (Throwable $e) {
        return false;
    }
}

// ── maintenance ─────────────────────────────────────────────────────────

function al_plan_save(int $equipmentId, array $in): ?string
{
    $a = asset($equipmentId);
    if (! $a) { return t('That item does not exist.'); }
    if (in_array($a['state'], ['retired', 'disposed'], true)) { return t('A retired item has no maintenance plan.'); }
    $task = trim((string) ($in['task'] ?? ''));
    $every = trim((string) ($in['every_days'] ?? ''));
    $first = trim((string) ($in['next_due'] ?? ''));
    if (mb_strlen($task) < 3 || mb_strlen($task) > 190) { return t('Say what the maintenance is, in 3 to 190 characters.'); }
    if (! ctype_digit($every) || (int) $every < 1 || (int) $every > 3650) { return t('Maintenance comes round every 1 to 3,650 days.'); }
    $first = $first !== '' ? $first : date('Y-m-d', strtotime('+' . (int) $every . ' days'));
    if (! valid_date($first) || $first < date('Y-m-d')) { return t('The first maintenance is today or later.'); }
    q('INSERT INTO asset_maintenance_plans (equipment_id, task, every_days, next_due, blocks_issue, created_by) VALUES (?,?,?,?,?,?)',
      [$equipmentId, $task, (int) $every, $first, ! empty($in['blocks_issue']) ? 1 : 0, uid() ?: null]);
    asset_event($equipmentId, 'plan', $task . ' · ' . t('every :n days', ['n' => (int) $every]) . (! empty($in['blocks_issue']) ? ' · ' . t('blocks issue when overdue') : ''));
    log_activity('planned maintenance', 'equipment', $equipmentId, $task . ' every ' . (int) $every . ' days');
    return null;
}

function al_plan_stop(int $planId): ?string
{
    $p = row('SELECT * FROM asset_maintenance_plans WHERE id = ? AND is_active = 1', [$planId]);
    if (! $p) { return t('That maintenance plan does not exist or is already stopped.'); }
    q('UPDATE asset_maintenance_plans SET is_active = 0 WHERE id = ?', [$planId]);
    asset_event((int) $p['equipment_id'], 'plan_stopped', $p['task']);
    log_activity('stopped a maintenance plan', 'equipment', (int) $p['equipment_id'], $p['task']);
    return null;
}

/** An active maintenance plan that blocks issue and is past its date, or null. */
function al_blocking_plan(int $equipmentId): ?array
{
    try {
        return row('SELECT * FROM asset_maintenance_plans WHERE equipment_id = ? AND is_active = 1 AND blocks_issue = 1 AND next_due < ? ORDER BY next_due LIMIT 1',
                   [$equipmentId, date('Y-m-d')]) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

// ── service history ─────────────────────────────────────────────────────

/** Records a service: repair, maintenance or calibration. Done for a plan, the plan moves on. */
function al_service_add(int $equipmentId, array $in): ?string
{
    $a = asset($equipmentId);
    if (! $a) { return t('That item does not exist.'); }
    if ($a['state'] === 'disposed') { return t('That item has been disposed of.'); }
    $kind = (string) ($in['kind'] ?? '');
    $on = trim((string) ($in['performed_on'] ?? ''));
    $vendor = (int) ($in['vendor_id'] ?? 0);
    $by = trim((string) ($in['performed_by'] ?? ''));
    $cost = trim((string) ($in['cost'] ?? ''));
    $downtime = trim((string) ($in['downtime_days'] ?? ''));
    $reference = trim((string) ($in['reference'] ?? ''));
    $notes = trim((string) ($in['notes'] ?? ''));
    $plan = (int) ($in['plan_id'] ?? 0);
    if (! isset(al_service_kinds()[$kind])) { return t('Say what kind of service it was.'); }
    if (($why = al_past_date_refusal($on)) !== null) { return $why; }
    if ($vendor && ! row('SELECT id FROM vendors WHERE id = ?', [$vendor])) { return t('Choose a vendor from the list, or leave it empty.'); }
    if (mb_strlen($by) > 190 || mb_strlen($reference) > 120 || mb_strlen($notes) > 1000) { return t('A field is too long.'); }
    if (($why = al_amount_refusal($cost)) !== null) { return $why; }
    if ($downtime !== '' && (! ctype_digit($downtime) || (int) $downtime > 365)) { return t('Downtime is 0 to 365 days.'); }
    if ($plan) {
        $p = row('SELECT * FROM asset_maintenance_plans WHERE id = ? AND equipment_id = ? AND is_active = 1', [$plan, $equipmentId]);
        if (! $p) { return t('That maintenance plan is not this item\'s, or is stopped.'); }
    }
    if (! $vendor && $by === '') { return t('Say who did it: a vendor from the list, or a name.'); }
    q('INSERT INTO asset_service_records (equipment_id, kind, performed_on, vendor_id, performed_by, cost, downtime_days, reference, notes, plan_id, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
      [$equipmentId, $kind, $on, $vendor ?: null, $by ?: null, $cost !== '' ? round((float) $cost, 2) : null, $downtime !== '' ? (int) $downtime : null,
       $reference ?: null, $notes ?: null, $plan ?: null, uid() ?: null]);
    if ($plan) {
        $next = date('Y-m-d', strtotime($on . ' +' . (int) $p['every_days'] . ' days'));
        q('UPDATE asset_maintenance_plans SET last_done_on = ?, next_due = ? WHERE id = ?', [$on, $next, $plan]);
    }
    asset_event($equipmentId, 'serviced', al_service_kinds()[$kind] . ($plan ? ' · ' . $p['task'] : '') . ($notes !== '' ? ' · ' . $notes : ''), $cost !== '' ? round((float) $cost, 2) : null);
    log_activity('recorded a service', 'equipment', $equipmentId, $kind . ($cost !== '' ? ' ' . $cost : ''));
    return null;
}

// ── lifecycle, retirement, disposal ─────────────────────────────────────

/** Sets what valuation reads: when it went into service, its life, its residual value. */
function al_value_settings(int $equipmentId, array $in): ?string
{
    $a = asset($equipmentId);
    if (! $a) { return t('That item does not exist.'); }
    $since = trim((string) ($in['in_service_on'] ?? ''));
    $life = trim((string) ($in['useful_life_months'] ?? ''));
    $residual = trim((string) ($in['residual_value'] ?? ''));
    if ($since !== '' && (($why = al_past_date_refusal($since)) !== null || ($a['purchase_date'] && $since < $a['purchase_date']))) {
        return $why ?? t('An item goes into service on or after the day it was bought.');
    }
    if ($life !== '' && (! ctype_digit($life) || (int) $life < 1 || (int) $life > 600)) { return t('A useful life is 1 to 600 months.'); }
    if ($residual !== '' && (! is_numeric($residual) || (float) $residual < 0 || ($a['purchase_cost'] !== null && (float) $residual > (float) $a['purchase_cost']))) {
        return t('A residual value is from 0 up to the purchase cost.');
    }
    q('UPDATE equipment SET in_service_on = ?, useful_life_months = ?, residual_value = ? WHERE id = ?',
      [$since ?: null, $life !== '' ? (int) $life : null, $residual !== '' ? round((float) $residual, 2) : null, $equipmentId]);
    asset_event($equipmentId, 'valuation', trim(($since ? t('in service :date', ['date' => d($since)]) : '') . ($life !== '' ? ' · ' . t(':n months', ['n' => (int) $life]) : '')
                                               . ($residual !== '' ? ' · ' . t('residual :amount', ['amount' => money((float) $residual)]) : ''), ' ·') ?: null);
    log_activity('set an asset\'s valuation', 'equipment', $equipmentId, "in service $since, life $life, residual $residual");
    return null;
}

/** Disposes of a retired or lost item, for good: how, when, for how much. */
function al_dispose(int $equipmentId, array $in): ?string
{
    $a = asset($equipmentId);
    if (! $a) { return t('That item does not exist.'); }
    if (! in_array($a['state'], ['retired', 'lost'], true)) { return t('Retire the item, or mark it lost, before disposing of it.'); }
    $method = (string) ($in['disposal_method'] ?? '');
    $on = trim((string) ($in['disposed_on'] ?? ''));
    $proceeds = trim((string) ($in['proceeds'] ?? ''));
    $note = trim((string) ($in['note'] ?? ''));
    if (! isset(al_disposal_methods()[$method])) { return t('Say how it was disposed of.'); }
    if (($why = al_past_date_refusal($on)) !== null) { return $why; }
    if ($a['retired_on'] && $on < $a['retired_on']) { return t('An item is disposed of on or after the day it was retired.'); }
    if ($method === 'returned_to_client' && $a['owner_type'] !== 'client') { return t('Only a client\'s item is returned to the client.'); }
    if ($method === 'sold') {
        if ($proceeds === '' || ($why = al_amount_refusal($proceeds)) !== null) { return t('Give what it was sold for.'); }
    } elseif ($proceeds !== '' && (float) $proceeds > 0) {
        return t('Only a sale brings proceeds.');
    }
    if (mb_strlen($note) > 400) { return t('Keep the note under 400 characters.'); }
    q("UPDATE equipment SET status = 'disposed', disposed_on = ?, disposal_method = ?, disposal_proceeds = ? WHERE id = ?",
      [$on, $method, $method === 'sold' ? round((float) $proceeds, 2) : null, $equipmentId]);
    q('UPDATE asset_maintenance_plans SET is_active = 0 WHERE equipment_id = ?', [$equipmentId]);
    asset_event($equipmentId, 'disposed', al_disposal_methods()[$method] . ($note !== '' ? ' · ' . $note : ''), $method === 'sold' ? round((float) $proceeds, 2) : null);
    log_activity('disposed of an asset', 'equipment', $equipmentId, $method . ($method === 'sold' ? ' ' . $proceeds : ''));
    return null;
}

/** The item's life in order: bought, in service, inspections, services, retired, disposed. */
function al_lifecycle(array $a): array
{
    $out = [];
    if ($a['purchase_date']) { $out[] = [$a['purchase_date'], t('Acquired'), $a['purchase_cost'] !== null ? money((float) $a['purchase_cost']) : '']; }
    if ($a['in_service_on']) { $out[] = [$a['in_service_on'], t('In service'), '']; }
    foreach (rows('SELECT inspected_on, result, findings FROM asset_inspections WHERE equipment_id = ? ORDER BY inspected_on, id', [(int) $a['id']]) as $i) {
        $out[] = [$i['inspected_on'], $i['result'] === 'pass' ? t('Inspection passed') : t('Inspection failed'), (string) $i['findings']];
    }
    foreach (rows('SELECT performed_on, kind, cost FROM asset_service_records WHERE equipment_id = ? ORDER BY performed_on, id', [(int) $a['id']]) as $s) {
        $out[] = [$s['performed_on'], al_service_kinds()[$s['kind']], $s['cost'] !== null ? money((float) $s['cost']) : ''];
    }
    if ($a['retired_on']) { $out[] = [$a['retired_on'], t('Retired'), '']; }
    if ($a['disposed_on']) { $out[] = [$a['disposed_on'], t('Disposed of'), al_disposal_methods()[$a['disposal_method']] . ($a['disposal_proceeds'] !== null ? ' · ' . money((float) $a['disposal_proceeds']) : '')]; }
    usort($out, fn($x, $y) => strcmp($x[0], $y[0]));
    return $out;
}

// ── valuation ───────────────────────────────────────────────────────────

/** Whole months from $from to $to. */
function al_months(string $from, string $to): int
{
    if ($to <= $from) { return 0; }
    [$y1, $m1, $d1] = array_map('intval', explode('-', $from));
    [$y2, $m2, $d2] = array_map('intval', explode('-', $to));
    return max(0, ($y2 - $y1) * 12 + ($m2 - $m1) - ($d2 < $d1 ? 1 : 0));
}

/**
 * An item's value on $asOf. Straight line: (cost - residual) spread over the
 * useful life from the day it went into service (or was bought). A disposed
 * item is worth nothing after its disposal, which shows the gain or loss on
 * it; a lost one is written off on the day it was lost.
 */
function al_valuation(array $a, string $asOf): array
{
    $out = ['valued' => false, 'reason' => '', 'cost' => null, 'residual' => null, 'life' => null, 'start' => null, 'months' => 0,
            'accumulated' => null, 'book' => null, 'service_cost' => 0.0, 'tco' => null, 'gain' => null, 'ended' => null];
    $out['service_cost'] = (float) val('SELECT COALESCE(SUM(cost), 0) FROM asset_service_records WHERE equipment_id = ? AND performed_on <= ?', [(int) $a['id'], $asOf]);
    if (($a['owner_type'] ?? 'agency') === 'client') { $out['reason'] = t('Owned by the client'); return $out; }
    if ($a['purchase_cost'] === null) { $out['reason'] = t('No purchase cost'); return $out; }
    $cost = (float) $a['purchase_cost'];
    $out['cost'] = $cost;
    $out['tco'] = round($cost + $out['service_cost'], 2);
    $category = $a['category_id'] ? row('SELECT useful_life_months, residual_percent FROM asset_categories WHERE id = ?', [(int) $a['category_id']]) : null;
    $life = $a['useful_life_months'] !== null ? (int) $a['useful_life_months'] : ($category && $category['useful_life_months'] !== null ? (int) $category['useful_life_months'] : null);
    if (! $life) { $out['reason'] = t('No useful life set, on the item or its category'); return $out; }
    $start = $a['in_service_on'] ?: $a['purchase_date'];
    if (! $start) { $out['reason'] = t('No in-service or purchase date'); return $out; }
    $residual = $a['residual_value'] !== null ? (float) $a['residual_value'] : round($cost * (float) ($category['residual_percent'] ?? 0) / 100, 2);
    $ended = null;
    if ($a['disposed_on']) {
        $ended = (string) $a['disposed_on'];
    } elseif (($a['status'] ?? '') === 'lost') {
        $ended = (string) (val("SELECT DATE(MAX(created_at)) FROM asset_events WHERE equipment_id = ? AND event IN ('lost','lose')", [(int) $a['id']]) ?: $asOf);
    }
    $until = $ended !== null && $ended < $asOf ? $ended : $asOf;
    $months = min($life, al_months($start, $until));
    $accumulated = round(($cost - $residual) * $months / $life, 2);
    $book = round($cost - $accumulated, 2);
    $out = array_merge($out, ['valued' => true, 'residual' => $residual, 'life' => $life, 'start' => $start, 'months' => $months, 'accumulated' => $accumulated, 'book' => $book]);
    if ($ended !== null && $ended <= $asOf) {
        $proceeds = (float) ($a['disposal_proceeds'] ?? 0);
        $out['gain'] = round($proceeds - $book, 2);
        $out['ended'] = $ended;
        $out['book'] = 0.0;
    }
    return $out;
}

/** Every agency item's value on $asOf, with totals by class. */
function al_valuation_report(string $asOf, string $class = ''): array
{
    $items = rows('SELECT e.*, o.owner_type, o.client_id, c.label AS category, c.asset_class
                     FROM equipment e LEFT JOIN equipment_ownership o ON o.equipment_id = e.id LEFT JOIN asset_categories c ON c.id = e.category_id
                    WHERE (e.purchase_date IS NULL OR e.purchase_date <= ?)' . ($class !== '' ? ' AND c.asset_class = ?' : '') . ' ORDER BY e.asset_tag', $class !== '' ? [$asOf, $class] : [$asOf]);
    $rows = [];
    $totals = [];
    foreach ($items as $a) {
        $v = al_valuation($a, $asOf);
        $rows[] = ['item' => $a, 'value' => $v];
        $k = $a['asset_class'] ?: 'other';
        $totals[$k] ??= ['count' => 0, 'valued' => 0, 'cost' => 0.0, 'accumulated' => 0.0, 'book' => 0.0, 'service' => 0.0];
        $totals[$k]['count']++;
        $totals[$k]['service'] += $v['service_cost'];
        if ($v['valued']) {
            $totals[$k]['valued']++;
            $totals[$k]['cost'] += $v['cost'];
            $totals[$k]['accumulated'] += $v['ended'] !== null ? 0 : $v['accumulated'];
            $totals[$k]['book'] += $v['book'];
        }
    }
    return ['rows' => $rows, 'totals' => $totals];
}

/** Inspections and maintenance due within $days (or overdue), for the register. */
function al_due(int $days = 14): array
{
    $until = date('Y-m-d', strtotime("+$days days"));
    $inspections = rows("SELECT id, name, asset_tag, inspection_due FROM equipment WHERE inspection_due IS NOT NULL AND inspection_due <= ?
                          AND status NOT IN ('lost','retired','disposed') ORDER BY inspection_due LIMIT 200", [$until]);
    $plans = rows("SELECT p.*, e.name, e.asset_tag FROM asset_maintenance_plans p JOIN equipment e ON e.id = p.equipment_id
                    WHERE p.is_active = 1 AND p.next_due <= ? AND e.status NOT IN ('lost','retired','disposed') ORDER BY p.next_due LIMIT 200", [$until]);
    return ['inspections' => $inspections, 'plans' => $plans];
}
