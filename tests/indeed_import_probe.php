<?php
/**
 * The P5-M36 test's eyes, on the test database only.
 *   person <email>   the candidate, their application and its first note
 *   batch            the last import's rows: status and note
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
[$cmd, $arg] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($cmd === 'person') {
    $c = row('SELECT id, full_name, city, state, source, discipline, resume_text FROM candidates WHERE email = ?', [$arg]);
    if ($c) {
        $c['application'] = row('SELECT id, vacancy_id, source, stage FROM applications WHERE candidate_id = ? ORDER BY id DESC LIMIT 1', [(int) $c['id']]) ?: null;
        $c['note'] = $c['application'] ? val('SELECT note FROM application_events WHERE application_id = ? ORDER BY id LIMIT 1', [(int) $c['application']['id']]) : null;
    }
    echo json_encode($c ?: null);
} elseif ($cmd === 'batch') {
    echo json_encode(rows('SELECT r.row_number, r.status, r.note FROM import_records r WHERE r.batch_id = (SELECT MAX(id) FROM import_batches) ORDER BY r.row_number'));
}
