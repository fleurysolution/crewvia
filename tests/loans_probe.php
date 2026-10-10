<?php
/**
 * The P2-M05 test's eyes and hands, on the test database only.
 *   read <candidate>                         advances, pauses, events
 *   g2n <candidate> <placement> <week>       what gross to net takes from a 1,000 week
 *   backdate <advance> <first week>          moves an advance's first week into the past
 *   taken <advance> <week> <amount>          records what payroll took that week
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/gross-to-net.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$mode = (string) ($argv[1] ?? 'read');
if ($mode === 'g2n') {
    $r = gross_to_net_for_sheet(['candidate_id' => (int) $argv[2], 'placement_id' => (int) $argv[3], 'week_ending' => (string) $argv[4]],
                                ['labour_cost' => 1000, 'leave_pay' => 0, 'per_diem' => 0, 'expenses' => 0]);
    echo json_encode(array_map(fn($l) => [(int) ($l['advance_id'] ?? 0), (float) $l['amount']], array_values(array_filter($r['deductions'], fn($l) => ! empty($l['advance_id'])))));
    exit(0);
}
if ($mode === 'backdate') {
    q('UPDATE wage_advances SET first_week = ? WHERE id = ?', [(string) $argv[3], (int) $argv[2]]);
    exit(0);
}
if ($mode === 'taken') {
    q("INSERT INTO wage_advance_payments (advance_id, amount, paid_on, note) VALUES (?,?,?,'Deducted (test)')", [(int) $argv[2], (float) $argv[4], (string) $argv[3]]);
    exit(0);
}

$cid = (int) ($argv[2] ?? 0);
echo json_encode([
    'advances' => array_map(static fn($a) => ['id' => (int) $a['id'], 'kind' => $a['kind'], 'status' => $a['status'], 'amount' => (float) $a['amount'], 'weekly' => (float) $a['weekly_repayment'],
                                              'first_week' => $a['first_week'], 'requested_by' => (int) $a['requested_by'], 'approved_by' => (int) $a['approved_by'], 'written_off_amount' => $a['written_off_amount'] !== null ? (float) $a['written_off_amount'] : null],
                         rows('SELECT * FROM wage_advances WHERE candidate_id = ? ORDER BY id', [$cid])),
    'pauses'   => rows('SELECT p.advance_id, p.from_week, p.until_week, p.reason FROM advance_pauses p JOIN wage_advances a ON a.id = p.advance_id WHERE a.candidate_id = ? ORDER BY p.id', [$cid]),
    'events'   => rows('SELECT e.advance_id, e.event FROM advance_events e JOIN wage_advances a ON a.id = e.advance_id WHERE a.candidate_id = ? ORDER BY e.id', [$cid]),
]);
