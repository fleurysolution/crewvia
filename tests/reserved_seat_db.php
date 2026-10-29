<?php
/**
 * P5-M111: a seat reserved by the hiring manager's approval is not a hire.
 * Walk of 29 Oct 2026: Sample Applicant, approved but never offered, had the employee portal (pay, attendance, HR),
 * the W-4, the I-9 and the direct deposit tasks, and the project's headcount on Activity.
 *  - an offered placement while the application is in screening: still a candidate, no onboarding task;
 *  - the same once the application is accepted (offer countersigned): an employee, the tasks come;
 *  - an offered placement the approval did not make (an import, a transfer, an older hire): an employee, as before.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/onboarding-tasks.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
$check = static function (string $label, bool $ok, $detail = ''): void {
    if (! $ok) { fwrite(STDERR, "FAIL $label " . json_encode($detail) . "\n"); exit(1); }
    echo "PASS $label\n";
};

q("INSERT INTO clients (name) VALUES ('Reserved seat client')");
q("INSERT INTO jobs (client_id, title, status, site_city, site_state) VALUES (?, 'Reserved seat plant', 'active', 'Baytown', 'TX')", [(int) db()->lastInsertId()]);
$job = (int) db()->lastInsertId();
q("INSERT INTO vacancies (job_id, title, description) VALUES (?, 'Reserved seat welder', 'test')", [$job]);
$vac = (int) db()->lastInsertId();
q("INSERT INTO onboarding_requirements (job_id, title) VALUES (?, 'Reserved seat emergency contact')", [$job]);
$req = (int) db()->lastInsertId();
$person = static function (string $name): int {
    q("INSERT INTO candidates (full_name, email, stage) VALUES (?, ?, 'screening')", [$name, strtolower(str_replace(' ', '.', $name)) . '@test.invalid']);
    return (int) db()->lastInsertId();
};
$tasks = static fn(int $cid): int => (int) val('SELECT COUNT(*) FROM onboarding_tasks t JOIN placements p ON p.id = t.placement_id WHERE p.candidate_id = ?', [$cid]);

// Approved by the hiring manager: the seat is reserved, the application still in screening.
$sample = $person('Reserved Seat Sample');
q("INSERT INTO applications (candidate_id, vacancy_id, stage) VALUES (?, ?, 'screening')", [$sample, $vac]);
$app = (int) db()->lastInsertId();
$boss = (int) val("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
q("INSERT INTO application_reviews (application_id, requested_by, reviewer_id, rating, recommendation, status, decided_at) VALUES (?, ?, ?, 4, 'test', 'validated', NOW())", [$app, $boss, $boss]);
q("INSERT INTO placements (candidate_id, job_id, vacancy_id, status) VALUES (?, ?, ?, 'offered')", [$sample, $job, $vac]);
onboarding_sync('p.candidate_id = ?', [$sample]);
$check('A seat reserved before the offer leaves the person a candidate', ! candidate_is_employee($sample));
$check('and creates no onboarding task (no W-4, I-9 or bank form before the hire)', $tasks($sample) === 0, $tasks($sample));

// Offered, then countersigned: hired.
q("UPDATE applications SET stage = 'offered' WHERE id = ?", [$app]);
$check('An offer not yet countersigned is still not a hire', ! candidate_is_employee($sample));
q("UPDATE applications SET stage = 'accepted' WHERE id = ?", [$app]);
onboarding_sync('p.candidate_id = ?', [$sample]);
$check('Countersigned (application accepted): an employee', candidate_is_employee($sample));
$check('and the onboarding tasks come', $tasks($sample) === 1, $tasks($sample));

// An import or a transfer: an offered placement and no application on the project.
$import = $person('Reserved Seat Import');
q("INSERT INTO placements (candidate_id, job_id, status) VALUES (?, ?, 'offered')", [$import, $job]);
onboarding_sync('p.candidate_id = ?', [$import]);
$check('An offered placement on no requisition (import, transfer) is a hire, as before', candidate_is_employee($import) && $tasks($import) === 1);

// A hire before the offer flow: offered, linked to a requisition whose application never moved, and never approved.
$old = $person('Reserved Seat Old Hire');
q("INSERT INTO applications (candidate_id, vacancy_id, stage) VALUES (?, ?, 'screening')", [$old, $vac]);
q("INSERT INTO placements (candidate_id, job_id, vacancy_id, status) VALUES (?, ?, ?, 'offered')", [$old, $job, $vac]);
$check('An offered placement the approval did not make (an older hire) stays a hire', candidate_is_employee($old));

// Confirmed on the roster: a hire whatever the application says.
$roster = $person('Reserved Seat Roster');
q("INSERT INTO applications (candidate_id, vacancy_id, stage) VALUES (?, ?, 'screening')", [$roster, $vac]);
q("INSERT INTO placements (candidate_id, job_id, vacancy_id, status) VALUES (?, ?, ?, 'confirmed')", [$roster, $job, $vac]);
$check('A confirmed placement is a hire', candidate_is_employee($roster));

q('DELETE t FROM onboarding_tasks t JOIN placements p ON p.id = t.placement_id WHERE p.job_id = ?', [$job]);
q('DELETE FROM placements WHERE job_id = ?', [$job]);
q('DELETE r FROM application_reviews r JOIN applications a ON a.id = r.application_id WHERE a.vacancy_id = ?', [$vac]);
q('DELETE FROM applications WHERE vacancy_id = ?', [$vac]);
q('DELETE FROM onboarding_requirements WHERE id = ?', [$req]);
q('DELETE FROM vacancies WHERE id = ?', [$vac]);
q("DELETE FROM candidates WHERE full_name LIKE 'Reserved Seat %'");
