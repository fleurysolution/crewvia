<?php
/**
 * The P5-M04 test's eyes and hands, on the test database only.
 *   read                         courses, questions, assignments, attempts, qualifications, notifications
 *   expires <assignment> <date>  moves when a completion runs out
 *   due <assignment> <date>      moves when an assignment is due
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

if (($argv[1] ?? '') === 'expires') { q('UPDATE training_assignments SET expires_on = ? WHERE id = ?', [(string) $argv[3], (int) $argv[2]]); exit(0); }
if (($argv[1] ?? '') === 'due') { q('UPDATE training_assignments SET due_on = ? WHERE id = ?', [(string) $argv[3], (int) $argv[2]]); exit(0); }

$fx = json_decode((string) file_get_contents(__DIR__ . '/training.json'), true);
echo json_encode([
    'courses' => rows('SELECT id, title, valid_months, awards_type_id, pass_mark, resource_url, is_active FROM training_courses ORDER BY id'),
    'questions' => rows('SELECT id, course_id, question, options_json, correct_index FROM training_questions ORDER BY id'),
    'plan_courses' => rows('SELECT plan_id, course_id, due_days FROM training_plan_courses ORDER BY plan_id, sort_order'),
    'requirements' => rows('SELECT id, course_id, scope, staff_role, trade, job_id, is_active FROM training_requirements ORDER BY id'),
    'assignments' => rows('SELECT id, user_id, course_id, source, requirement_id, plan_id, due_on, completed_at, score, expires_on, qualification_id FROM training_assignments ORDER BY id'),
    'attempts' => rows('SELECT assignment_id, score, passed FROM training_attempts ORDER BY id'),
    'qualifications' => rows('SELECT id, candidate_id, type_id, expires_on, evidence_reference, status FROM candidate_qualifications WHERE type_id = ? ORDER BY id', [(int) $fx['type']]),
    'qualification_events' => rows('SELECT e.qualification_id, e.action, e.note FROM qualification_events e JOIN candidate_qualifications q ON q.id = e.qualification_id WHERE q.type_id = ? ORDER BY e.id', [(int) $fx['type']]),
    'notifications' => rows("SELECT user_id, message, target FROM notifications WHERE target LIKE '/my-training%' ORDER BY id"),
]);
