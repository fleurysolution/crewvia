<?php
/**
 * The P5-M10 test's eyes and hands, on the test database only.
 *   read                      applications of the two requisitions and their events
 *   check <application> <s>   sets the application's screening checks to a status
 *   require <job> <on|off>    adds or removes a document the project requires before a contract
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

if (($argv[1] ?? '') === 'check') {
    q('UPDATE screening_checks SET status = ? WHERE application_id = ?', [(string) $argv[3], (int) $argv[2]]);
    exit(0);
}
if (($argv[1] ?? '') === 'require') {
    $argv[3] === 'on' ? q("INSERT IGNORE INTO contract_document_requirements(job_id,document_type) VALUES (?,'P5-M10 licence')", [(int) $argv[2]])
                      : q("DELETE FROM contract_document_requirements WHERE job_id = ? AND document_type = 'P5-M10 licence'", [(int) $argv[2]]);
    exit(0);
}
$fx = json_decode((string) file_get_contents(__DIR__ . '/hiring.json'), true);
$vacancies = (int) $fx['open'] . ',' . (int) $fx['closed'];
echo json_encode([
    'applications' => rows("SELECT id, candidate_id, vacancy_id, stage, source, interest, interest_by, interest_at FROM applications WHERE vacancy_id IN ($vacancies) ORDER BY id"),
    'events' => rows("SELECT e.application_id, e.user_id, e.stage, e.note FROM application_events e JOIN applications a ON a.id = e.application_id WHERE a.vacancy_id IN ($vacancies) ORDER BY e.id"),
]);
