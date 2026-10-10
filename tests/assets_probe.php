<?php
/** Read-only: the assets test items, their issues and history, as JSON. */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$tag = (string) ($argv[1] ?? 'none');

echo json_encode([
    'items'  => rows("SELECT e.id, e.asset_tag, e.name, e.status, e.category_id, e.purchase_cost, e.purchase_order_id, e.inspection_due,
                             (SELECT COUNT(*) FROM equipment_issues i WHERE i.equipment_id = e.id AND i.returned_at IS NULL) AS out_now
                      FROM equipment e WHERE e.asset_tag LIKE ? ORDER BY e.id", [$tag . '%']),
    'issues' => rows("SELECT i.id, i.equipment_id, i.placement_id, i.issue_condition, i.return_condition, i.return_note, i.issued_by, i.returned_by, i.returned_at IS NOT NULL AS returned
                      FROM equipment_issues i JOIN equipment e ON e.id = i.equipment_id WHERE e.asset_tag LIKE ? ORDER BY i.id", [$tag . '%']),
    'events' => rows("SELECT a.equipment_id, a.event, a.detail, a.cost, a.user_id FROM asset_events a JOIN equipment e ON e.id = a.equipment_id
                      WHERE e.asset_tag LIKE ? ORDER BY a.id", [$tag . '%']),
]);
