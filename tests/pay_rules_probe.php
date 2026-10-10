<?php
/**
 * Read-only: rule sets, the project's choice, and its weekly sheets with
 * their frozen figures, as JSON, for pay_rules_http.py.
 *
 *   php pay_rules_probe.php <job_id>
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$job = (int) ($argv[1] ?? 0);
$sheets = [];
foreach (rows('SELECT t.placement_id, t.week_ending, t.hours_worked, t.status, s.result_json
               FROM timesheets t JOIN placements p ON p.id = t.placement_id
               LEFT JOIN pay_snapshots s ON s.timesheet_id = t.id
               WHERE p.job_id = ? ORDER BY t.week_ending, t.placement_id', [$job]) as $r) {
    $r['snapshot'] = $r['result_json'] !== null ? json_decode((string) $r['result_json'], true) : null;
    unset($r['result_json']);
    $sheets[] = $r;
}

echo json_encode([
    'sets'     => rows('SELECT id, name, status, confirmed_by FROM pay_rule_sets ORDER BY id'),
    'project'  => val('SELECT pay_rule_set_id FROM jobs WHERE id = ?', [$job]),
    'holidays' => rows('SELECT rule_set_id, holiday_date, name FROM pay_rule_holidays ORDER BY id'),
    'premiums' => rows('SELECT rule_set_id, shift_label, amount_per_hour FROM pay_rule_shift_premiums ORDER BY id'),
    'sheets'   => $sheets,
]);
