<?php
/**
 * Removes the demonstration workspace (P5-M35). See app/demo.php.
 *
 *   php install/demo-flush.php              shows what would be removed, table by table; removes nothing
 *   php install/demo-flush.php --execute    removes it, in one transaction, then the demonstration's document files
 *
 * Take a backup first (php install/backup.php). Only rows that belong to a
 * demonstration record, directly or through one, are removed.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/demo.php';

$execute = in_array('--execute', $argv, true);
if (! demo_loaded()) {
    echo "No demonstration is loaded. Nothing to remove.\n";
    exit(0);
}
$plan = demo_plan();
$total = 0;
ksort($plan);
foreach ($plan as $table => $rows) {
    printf("  %-40s %7d\n", $table, count($rows));
    $total += count($rows);
}
printf("  %-40s %7d\n", 'total', $total);
// The audit log and the ledger refuse deletes: the demonstration's lines there stay, as the record.
$users = array_map('intval', array_column(rows("SELECT record_id FROM demo_records WHERE table_name = 'users'"), 'record_id'));
foreach (demo_append_only() as $table) {
    if ($users && in_array('user_id', demo_schema()[$table]['columns'] ?? [], true)
        && ($n = (int) val('SELECT COUNT(*) FROM `' . $table . '` WHERE user_id IN (' . implode(',', $users) . ')'))) {
        printf("  kept, append-only (%s): %d lines by demonstration accounts\n", $table, $n);
    }
}
// Rows that stay but name a demonstration account as who did something
// (created_by, reviewed_by...): shown, never changed.
if ($users) {
    foreach (demo_schema() as $table => $info) {
        foreach ($info['columns'] as $c) {
            if (! preg_match('/_by$/', $c)) {
                continue;
            }
            $sql = 'SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $c . '` IN (' . implode(',', $users) . ')';
            if (! empty($plan[$table]) && ($info['pk'] ?? []) === ['id']) {
                $sql .= ' AND id NOT IN (' . implode(',', array_map(static fn($r) => (int) $r['id'], $plan[$table])) . ')';
            }
            if ($n = (int) val($sql)) {
                printf("  kept, naming a demonstration account in %s.%s: %d\n", $table, $c, $n);
            }
        }
    }
}
// P5-M109: what a demonstration account wrote about real people stays (their record, their audit trail); the account
// that wrote it is retired - no sign-in, no mail, its name kept - instead of removed.
foreach (demo_users_kept($plan) as $id) {
    $u = row('SELECT name, email FROM users WHERE id = ?', [$id]);
    printf("  kept and retired, it wrote about real people: account #%d %s <%s>\n", $id, $u['name'] ?? '?', $u['email'] ?? '?');
}
// P5-M109: never with real records inside - separate them first (install/demo-separate.php).
$real = demo_real_at_risk($plan);
if ($real) {
    echo "REFUSED: the flush would also remove records of real people:\n";
    foreach ($real as $t => $n) {
        printf("  %-40s %7d\n", $t, $n);
    }
    echo "Move them out of the demonstration first (php install/demo-separate.php). Nothing was removed.\n";
    exit(1);
}
if (! $execute) {
    echo "Nothing was removed. Run again with --execute to remove these rows.\n";
    exit(0);
}

// The demonstration's encrypted document and resume files go with their rows.
global $config;
$files = [];
foreach (['worker_documents' => 'documents', 'resumes' => 'resumes'] as $table => $dir) {
    if (empty($plan[$table])) {
        continue;
    }
    $ids = array_map(static fn($r) => (int) ($r['id'] ?? 0), $plan[$table]);
    try {
        foreach (rows('SELECT storage_name FROM `' . $table . '` WHERE id IN (' . implode(',', $ids) . ')') as $f) {
            if (preg_match('/^[a-f0-9]{16,128}$/', (string) $f['storage_name'])) {
                $files[] = workforce_storage_path($config) . '/' . $dir . '/' . $f['storage_name'];
            }
        }
    } catch (Throwable $e) {
        // A table without stored files: nothing to remove on disk.
    }
}
$done = demo_flush($plan);
$removedFiles = 0;
foreach ($files as $f) {
    if (is_file($f) && unlink($f)) {
        $removedFiles++;
    }
}
printf("Demonstration removed: %d rows in %d tables, %d files.\n", array_sum($done), count($done), $removedFiles);
