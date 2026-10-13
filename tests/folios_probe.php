<?php
/**
 * The P5-M15 test's eyes, on the test database only: the fixture's folios
 * with their lines, flags and totals as the screens compute them, and the
 * bills in Payables.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/folios.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$fx = json_decode((string) file_get_contents(__DIR__ . '/folios.json'), true);
$folios = [];
foreach (rows('SELECT f.*, h.name AS hotel FROM hotel_folios f JOIN hotels h ON h.id = f.hotel_id WHERE f.job_id = ? ORDER BY f.id', [(int) $fx['job']]) as $f) {
    $lines = fo_lines($f);
    $f['lines'] = array_map(static fn($l) => ['id' => (int) $l['id'], 'kind' => $l['kind'], 'guest' => $l['guest_name'], 'placement_id' => $l['placement_id'] === null ? null : (int) $l['placement_id'],
                                               'nights' => $l['nights'] === null ? null : (int) $l['nights'], 'rate' => $l['rate'] === null ? null : (float) $l['rate'], 'amount' => (float) $l['amount'],
                                               'charge_to' => $l['charge_to'], 'flags' => $l['flags'], 'needs_note' => $l['needs_note'], 'needs_charge' => $l['needs_charge'], 'absent' => $l['absent_nights']], $lines);
    $f['totals'] = fo_totals($lines);
    $folios[] = $f;
}
echo json_encode([
    'folios' => $folios,
    'bills' => rows('SELECT id, hotel_id, vendor_name, reference, amount, due_on, status FROM vendor_invoices WHERE job_id = ? ORDER BY id', [(int) $fx['job']]),
]);
