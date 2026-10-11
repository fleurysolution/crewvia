<?php
/**
 * The P5-M03 test's eyes and hands, on the test database only.
 *   read                     items, inspections, plans, services, events
 *   plan-due <plan> <date>   moves a maintenance plan's next date, to make it overdue
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

if (($argv[1] ?? '') === 'plan-due') {
    q('UPDATE asset_maintenance_plans SET next_due = ? WHERE id = ?', [(string) $argv[3], (int) $argv[2]]);
    exit(0);
}

echo json_encode([
    'items' => rows("SELECT id, asset_tag, status, inspection_due, in_service_on, retired_on, disposed_on, disposal_method, disposal_proceeds, residual_value, useful_life_months FROM equipment WHERE asset_tag LIKE 'P5-%' ORDER BY id"),
    'categories' => rows('SELECT id, code, label, asset_class, inspection_days, useful_life_months, residual_percent, is_active FROM asset_categories ORDER BY id'),
    'inspections' => rows('SELECT equipment_id, inspected_on, result, findings, next_due FROM asset_inspections ORDER BY id'),
    'plans' => rows('SELECT id, equipment_id, task, every_days, next_due, blocks_issue, last_done_on, is_active FROM asset_maintenance_plans ORDER BY id'),
    'services' => rows('SELECT equipment_id, kind, performed_on, vendor_id, cost, downtime_days, reference, plan_id FROM asset_service_records ORDER BY id'),
    'events' => rows('SELECT equipment_id, event, detail, cost FROM asset_events ORDER BY id'),
]);
