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
} elseif ($cmd === 'docs') {
    echo json_encode(rows('SELECT r.kind, r.original_name, r.attached_by, r.storage_name FROM candidate_resumes r JOIN candidates c ON c.id = r.candidate_id WHERE c.email = ? ORDER BY r.id', [$arg]));
} elseif ($cmd === 'notes') {
    echo json_encode(array_column(rows("SELECT e.note FROM application_events e JOIN applications a ON a.id = e.application_id JOIN candidates c ON c.id = a.candidate_id WHERE c.email = ? AND e.note LIKE 'Cover letter%' ORDER BY e.id", [$arg]), 'note'));
} elseif ($cmd === 'stored') {
    global $config;
    $raw = (string) @file_get_contents(workforce_storage_path($config) . '/resumes/' . basename($arg));
    echo json_encode(['size' => strlen($raw), 'plain_pdf' => str_starts_with($raw, '%PDF')]);
} elseif ($cmd === 'batch') {
    echo json_encode(rows('SELECT r.row_number, r.status, r.note FROM import_records r WHERE r.batch_id = (SELECT MAX(id) FROM import_batches) ORDER BY r.row_number'));
}
