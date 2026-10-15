<?php
/**
 * The P5-M53 test's eyes, on the test database only.
 *   offer <application>   the application's stage, the signature and countersignature, its open placements
 *   apps <email>          the applications of the record that has the portal, oldest first
 *   staff-notes <text>    how many staff notifications contain this text
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
[$cmd, $arg] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($cmd === 'offer') {
    $a = row('SELECT id, stage, candidate_id, vacancy_id FROM applications WHERE id = ?', [(int) $arg]);
    echo json_encode([
        'stage'      => $a['stage'] ?? null,
        'signed'     => row('SELECT signer_name, offer_text, countersigned_at, countersigner_name FROM offer_acceptances WHERE application_id = ?', [(int) $arg]),
        'placements' => $a ? (int) val("SELECT COUNT(*) FROM placements p JOIN vacancies v ON v.job_id = p.job_id WHERE p.candidate_id = ? AND v.id = ? AND p.status NOT IN ('completed','cancelled')", [(int) $a['candidate_id'], (int) $a['vacancy_id']]) : 0,
        'events'     => array_column(rows('SELECT note FROM application_events WHERE application_id = ? ORDER BY id', [(int) $arg]), 'note'),
    ]);
} elseif ($cmd === 'apps') {
    echo json_encode(rows('SELECT a.id, a.stage, a.vacancy_id, a.candidate_id FROM applications a JOIN worker_accounts w ON w.candidate_id = a.candidate_id JOIN candidates c ON c.id = a.candidate_id WHERE c.email = ? ORDER BY a.id', [$arg]));
} elseif ($cmd === 'staff-notes') {
    echo json_encode((int) val("SELECT COUNT(*) FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.role <> 'worker' AND n.message LIKE ?", ['%' . $arg . '%']));
}
