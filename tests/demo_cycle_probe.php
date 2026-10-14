<?php
/**
 * The P5-M35 test's eyes, on the test database only.
 *   counts            every base table's exact row count
 *   loaded            what the demonstration holds: people per plant, accounts by role, sectors, shifts, vans, hotels, rooms missing
 *   person <label>    a demonstration person's ids, from their plus-address label (employee12...)
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
[$cmd, $arg] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($cmd === 'counts') {
    $out = [];
    foreach (rows("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name") as $r) {
        if ($r['t'] !== 'demo_records') {
            $out[$r['t']] = (int) val('SELECT COUNT(*) FROM `' . $r['t'] . '`');
        }
    }
    echo json_encode($out);
} elseif ($cmd === 'loaded') {
    $demo = static fn(string $t): string => "SELECT record_id FROM demo_records WHERE table_name = '$t'";
    echo json_encode([
        'plants' => array_column(rows('SELECT j.title, COUNT(p.id) AS n FROM jobs j JOIN placements p ON p.job_id = j.id WHERE j.id IN (' . $demo('jobs') . ') GROUP BY j.id ORDER BY j.title'), 'n', 'title'),
        'roles' => array_column(rows('SELECT role, COUNT(*) AS n FROM users WHERE id IN (' . $demo('users') . ') GROUP BY role'), 'n', 'role'),
        'trades' => array_column(rows("SELECT CONCAT(j.title, ' ', d.trade) AS k, COUNT(*) AS n FROM assignment_details d JOIN placements p ON p.id = d.placement_id JOIN jobs j ON j.id = p.job_id WHERE j.id IN (" . $demo('jobs') . ') GROUP BY k'), 'n', 'k'),
        'sectors' => (int) val('SELECT COUNT(*) FROM project_departments WHERE job_id IN (' . $demo('jobs') . ')'),
        'shifts' => array_column(rows('SELECT name, COUNT(*) AS n FROM project_shifts WHERE job_id IN (' . $demo('jobs') . ') GROUP BY name'), 'n', 'name'),
        'vans' => (int) val('SELECT COUNT(*) FROM vehicles WHERE id IN (' . $demo('vehicles') . ')'),
        'hotels' => (int) val('SELECT COUNT(*) FROM hotels WHERE id IN (' . $demo('hotels') . ')'),
        'rooms_missing' => (int) val('SELECT COUNT(*) FROM lodging l JOIN placements p ON p.id = l.placement_id WHERE p.job_id IN (' . $demo('jobs') . ') AND l.room_number IS NULL'),
        'max_crew' => (int) val('SELECT MAX(n) FROM (SELECT COUNT(*) n FROM assignment_details d JOIN placements p ON p.id = d.placement_id WHERE p.job_id IN (' . $demo('jobs') . ') GROUP BY d.supervisor_id) x'),
        'open_requisitions' => (int) val('SELECT COUNT(*) FROM vacancies WHERE is_open = 1 AND job_id IN (' . $demo('jobs') . ')'),
        'pay' => row("SELECT pay_rate, bill_rate, per_diem_rate FROM placements p JOIN assignment_details d ON d.placement_id = p.id WHERE d.trade = 'Chemical engineer' AND p.job_id IN (" . $demo('jobs') . ') LIMIT 1'),
        'policy' => row('SELECT weekly_overtime_after, guarantee_absence_rule, guarantee_overtime FROM project_pay_policies WHERE job_id IN (' . $demo('jobs') . ') LIMIT 1'),
    ]);
} elseif ($cmd === 'person') {
    $u = row('SELECT u.id AS user_id, w.candidate_id, p.id AS placement_id, d.supervisor_id FROM users u LEFT JOIN worker_accounts w ON w.user_id = u.id LEFT JOIN placements p ON p.candidate_id = w.candidate_id
              LEFT JOIN assignment_details d ON d.placement_id = p.id WHERE u.email LIKE ?', ['%+' . $arg . '@%']);
    if ($u && $u['supervisor_id']) {
        $u['supervisor_email'] = (string) val('SELECT email FROM users WHERE id = ?', [(int) $u['supervisor_id']]);
    }
    echo json_encode($u ?: null);
}
