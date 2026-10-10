<?php
/**
 * Read-only: pay items, the M05 people's items, weekly sheets of the M05
 * projects with their frozen gross to net, and their advances, as JSON.
 *
 *   php gross_to_net_probe.php
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/hr.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$sheets = [];
foreach (rows("SELECT t.id, t.placement_id, t.status, s.result_json FROM timesheets t
               JOIN placements p ON p.id = t.placement_id JOIN jobs j ON j.id = p.job_id
               LEFT JOIN pay_snapshots s ON s.timesheet_id = t.id
               WHERE j.title LIKE 'M05 %' ORDER BY t.placement_id") as $r) {
    $r['net'] = $r['result_json'] !== null ? (json_decode((string) $r['result_json'], true)['gross_to_net'] ?? null) : null;
    unset($r['result_json']);
    $sheets[(string) $r['placement_id']] = $r;
}

$advances = [];
foreach (rows("SELECT a.* FROM wage_advances a JOIN candidates c ON c.id = a.candidate_id WHERE c.email LIKE 'm05-%'") as $a) {
    $advances[(string) $a['id']] = ['status' => $a['status'], 'balance' => advance_balance($a),
        'payments' => rows('SELECT amount, timesheet_id FROM wage_advance_payments WHERE advance_id = ? ORDER BY id', [(int) $a['id']])];
}

echo json_encode([
    'items'    => rows('SELECT id, code, label, side, method, provider_code, is_active FROM pay_items ORDER BY id'),
    'assigned' => rows("SELECT e.candidate_id, i.code, e.amount, e.starts_on, e.ends_on FROM employee_pay_items e
                        JOIN pay_items i ON i.id = e.pay_item_id JOIN candidates c ON c.id = e.candidate_id
                        WHERE c.email LIKE 'm05-%' ORDER BY e.id"),
    'sheets'   => $sheets,
    'advances' => $advances,
]);
