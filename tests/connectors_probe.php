<?php
/**
 * The P4-M05 test's eyes and hands, on the test database only.
 *   read                     connector modes, payroll files, candidates by email
 *   tamper <export id>       changes a kept payroll file
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

if (($argv[1] ?? '') === 'tamper') {
    q("UPDATE payroll_exports SET csv = CONCAT(csv, 'P4CO,W,x\n') WHERE id = ?", [(int) $argv[2]]);
    exit(0);
}

echo json_encode([
    'modes'   => array_column(rows('SELECT connector, mode, authorization_ref, authorized_by, sandbox_passed_at FROM connector_modes'), null, 'connector'),
    'exports' => rows("SELECT id, kind, sha256, row_count, employee_count, import_result, adp_batch_ref FROM payroll_exports WHERE week_ending = '2015-06-13' ORDER BY id"),
    'partner' => rows("SELECT c.id, c.email, c.source FROM candidates c WHERE c.email LIKE 'partner-%@test.invalid' ORDER BY c.id"),
]);
