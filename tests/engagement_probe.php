<?php
/**
 * The P5-M05 test's eyes and hands, on the test database only.
 *   read                          everything P5-M05 writes, for the fixture's people
 *   run <week_ending> <status>    a pay period, printed as its id
 *   leave <candidate> <slug>      leave_balance() for this year
 *   closes <survey> <date>        moves a survey's closing date
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$fx = json_decode((string) file_get_contents(__DIR__ . '/engagement.json'), true);
$cmd = $argv[1] ?? 'read';

if ($cmd === 'run') {
    q('INSERT INTO payroll_runs (week_ending, opened_by, status) VALUES (?,?,?)', [(string) $argv[2], (int) $fx['ids']['pay'], (string) $argv[3]]);
    echo json_encode((int) db()->lastInsertId());
    exit(0);
}
if ($cmd === 'leave') {
    require_once __DIR__ . '/test-app/app/hr.php';
    echo json_encode(leave_balance((int) $argv[2], (string) $argv[3]));
    exit(0);
}
if ($cmd === 'closes') {
    q('UPDATE surveys SET closes_on = ? WHERE id = ?', [(string) $argv[3], (int) $argv[2]]);
    exit(0);
}

$users = implode(',', array_map('intval', $fx['ids']));
$cands = implode(',', array_map('intval', $fx['cands']));
echo json_encode([
    'kudos' => rows("SELECT id, from_user_id, to_user_id, message, is_public, points FROM kudos WHERE from_user_id IN ($users) ORDER BY id"),
    'programs' => rows('SELECT id, name, period, eligible, nominated_by, points, is_active FROM award_programs ORDER BY id'),
    'nominations' => rows("SELECT id, program_id, period_key, nominee_user_id, nominated_by, status, decided_by, recognition_id FROM award_nominations WHERE nominated_by IN ($users) ORDER BY id"),
    'points' => rows("SELECT user_id, points, source, source_id, note FROM reward_points WHERE user_id IN ($users) ORDER BY id"),
    'balances' => array_column(rows("SELECT user_id, SUM(points) AS b FROM reward_points WHERE user_id IN ($users) GROUP BY user_id"), 'b', 'user_id'),
    'catalog' => rows('SELECT id, name, kind, points_cost, cash_amount, leave_type, leave_days, eligible, stock, is_active FROM reward_catalog ORDER BY id'),
    'redemptions' => rows("SELECT id, user_id, reward_id, points, kind, cash_amount, leave_type, leave_days, status, decided_by, decision_note, fulfilled_at, payroll_adjustment_id FROM reward_redemptions WHERE user_id IN ($users) ORDER BY id"),
    'recognitions' => rows("SELECT id, candidate_id, kind, title, description, created_by FROM recognitions WHERE candidate_id IN ($cands) ORDER BY id"),
    'candidate_events' => rows("SELECT candidate_id, event_type, detail FROM candidate_events WHERE candidate_id IN ($cands) AND event_type = 'recognition' ORDER BY id"),
    'adjustments' => rows("SELECT id, run_id, candidate_id, kind, amount, reason, created_by FROM payroll_adjustments WHERE candidate_id IN ($cands) ORDER BY id"),
    'feedback' => rows("SELECT id, about_user_id, from_user_id, request_id, kind, is_shared FROM feedback_entries WHERE from_user_id IN ($users) ORDER BY id"),
    'requests' => rows("SELECT r.id, r.about_user_id, r.asked_by, p.user_id, p.answered_at FROM feedback_requests r JOIN feedback_request_people p ON p.request_id = r.id WHERE r.asked_by IN ($users) ORDER BY r.id, p.user_id"),
    'surveys' => rows('SELECT id, title, audience, is_anonymous, min_group, closes_on, status FROM surveys ORDER BY id'),
    'questions' => rows('SELECT id, survey_id, kind, prompt, options_json, is_required FROM survey_questions ORDER BY id'),
    'invitations' => rows("SELECT survey_id, user_id, responded_on FROM survey_invitations WHERE user_id IN ($users) ORDER BY survey_id, user_id"),
    'responses' => rows('SELECT id, survey_id, user_id, segment FROM survey_responses ORDER BY survey_id'),
    'answers' => rows('SELECT response_id, question_id, score, choice_index, answer_text FROM survey_answers'),
    'notifications' => rows("SELECT user_id, message, target FROM notifications WHERE user_id IN ($users) ORDER BY id"),
    'settings' => array_column(rows("SELECT setting_key, setting_value FROM platform_settings WHERE setting_key IN ('kudos_points','kudos_daily_limit')"), 'setting_value', 'setting_key'),
]);
