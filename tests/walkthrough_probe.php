<?php
/**
 * The P5-M26 test's eyes, on the test database only: what the screens did, read back.
 *   state            JSON of the records the test checks
 *   ratelimit        a configured per-purpose limit replaces the default (1 attempt, then refused)
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
$fx = json_decode((string) file_get_contents(__DIR__ . '/walkthrough.json'), true);

if (($argv[1] ?? '') === 'ratelimit') {
    require_once __DIR__ . '/test-app/app/security.php';
    global $config;
    $config['rate_limits'] = ['walk-limit-' . bin2hex(random_bytes(3)) => null];
    $purpose = array_key_first($config['rate_limits']);
    $config['rate_limits'][$purpose] = [1, 600];
    $first = security_rate_limit($purpose, 'one');
    $second = security_rate_limit($purpose, 'one');
    $d = 'walk-default-' . bin2hex(random_bytes(3));
    $default = security_rate_limit($d, 'one', 2, 600) && security_rate_limit($d, 'one', 2, 600) && ! security_rate_limit($d, 'one', 2, 600);
    echo json_encode(['first' => $first, 'second' => $second, 'default_unchanged' => $default]);
    exit;
}

$app = static fn(int $id) => row('SELECT stage, screening_score, screened_out FROM applications WHERE id = ?', [$id]);
echo json_encode([
    'app1' => $app((int) $fx['app1']), 'app2' => $app((int) $fx['app2']), 'app3' => $app((int) $fx['app3']),
    'cand1_stage' => val('SELECT stage FROM candidates WHERE id = ?', [(int) $fx['c1']]),
    'acceptance' => val('SELECT offer_text FROM offer_acceptances WHERE application_id = ? ORDER BY signed_at DESC LIMIT 1', [(int) $fx['app3']]),
    'accepted_start' => val('SELECT start_date FROM placements WHERE candidate_id = ? ORDER BY id DESC LIMIT 1', [(int) $fx['c3']]),
    'placement_dates' => row('SELECT start_date, end_date FROM placements WHERE id = ?', [(int) $fx['pw']]),
    'tasks' => rows('SELECT id, status FROM onboarding_tasks WHERE placement_id = ? ORDER BY id', [(int) $fx['pw']]),
    'checkin' => row('SELECT present, marked_by FROM assignment_checkins WHERE placement_id = ? AND work_date = CURDATE()', [(int) $fx['pw']]),
    'num_answers' => (int) val('SELECT COUNT(*) FROM screening_answers WHERE question_id = ?', [(int) $fx['qNum']]),
    'num_weight' => (int) val('SELECT weight FROM screening_questions WHERE id = ?', [(int) $fx['qNum']]),
    'vacancies_on_lineB' => (int) val('SELECT COUNT(*) FROM vacancies WHERE order_line_id = ?', [(int) $fx['lineB']]),
    'imported' => rows("SELECT c.email, a.vacancy_id, (SELECT COUNT(*) FROM placements p WHERE p.candidate_id = c.id) placements
                        FROM candidates c LEFT JOIN applications a ON a.candidate_id = c.id WHERE c.email LIKE 'wk-import-%' ORDER BY c.email"),
    'applied' => rows("SELECT c.email, c.geo_from, a.screened_out FROM candidates c JOIN applications a ON a.candidate_id = c.id WHERE c.email LIKE 'wk-apply-%' ORDER BY c.email"),
]);
