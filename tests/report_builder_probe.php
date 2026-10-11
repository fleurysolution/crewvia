<?php
/**
 * The P4-M03 test's eyes and hands, on the test database only.
 *   read                      definitions, schedules, deliveries, recipients, exports, notifications
 *   due <schedule id> [slot]  makes a schedule due (its next run in the past, at that slot)
 *   role <user id> <role>     changes a user's role
 *   tamper <delivery id> / untamper <delivery id>
 *   expire <delivery id>      moves a delivery past its 30 days
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$mode = (string) ($argv[1] ?? 'read');
$a = (int) ($argv[2] ?? 0);
switch ($mode) {
    case 'due':
        q('UPDATE report_schedules SET next_run_at = ? WHERE id = ?', [(string) ($argv[3] ?? '2017-03-06 06:00:00'), $a]);
        exit(0);
    case 'role':
        q('UPDATE users SET role = ? WHERE id = ?', [(string) $argv[3], $a]);
        exit(0);
    case 'tamper':
        q("UPDATE report_deliveries SET csv = CONCAT(csv, 'extra,row\n') WHERE id = ?", [$a]);
        exit(0);
    case 'untamper':
        q("UPDATE report_deliveries SET csv = LEFT(csv, CHAR_LENGTH(csv) - CHAR_LENGTH('extra,row\n')) WHERE id = ?", [$a]);
        exit(0);
    case 'expire':
        q("UPDATE report_deliveries SET expires_at = '2017-04-01 00:00:00' WHERE id = ?", [$a]);
        exit(0);
}

$ints = static fn(array $rows, array $keys): array => array_map(static function (array $r) use ($keys): array {
    foreach ($keys as $k) {
        if (isset($r[$k])) { $r[$k] = (int) $r[$k]; }
    }
    return $r;
}, $rows);

echo json_encode([
    'definitions' => $ints(rows('SELECT id, name, source, owner_id, shared, archived_at FROM report_definitions ORDER BY id'), ['id', 'owner_id', 'shared']),
    'schedules'   => $ints(rows('SELECT id, definition_id, frequency, weekday, monthday, run_hour, recipients_json, active, next_run_at, last_run_at FROM report_schedules ORDER BY id'),
                           ['id', 'definition_id', 'weekday', 'monthday', 'run_hour', 'active']),
    'deliveries'  => $ints(rows('SELECT id, schedule_id, run_for, period_label, status, csv, sha256, row_count, expires_at FROM report_deliveries ORDER BY id'), ['id', 'schedule_id', 'row_count']),
    'recipients'  => $ints(rows('SELECT delivery_id, user_id, skipped_reason, notified_at, downloaded_at FROM report_delivery_recipients ORDER BY id'), ['delivery_id', 'user_id']),
    'exports'     => $ints(rows('SELECT kind, source, definition_id, delivery_id, row_count, sha256, user_id FROM report_exports ORDER BY id'), ['definition_id', 'delivery_id', 'row_count', 'user_id']),
    'notified'    => $ints(rows("SELECT user_id, message, target FROM notifications WHERE target LIKE '/report-builder%' ORDER BY id"), ['user_id']),
]);
