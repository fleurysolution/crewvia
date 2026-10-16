<?php
/**
 * P5-M55: who an onboarding requirement asks.
 *
 * A requirement used to become a task for every assignment on the project,
 * the crew already working there included: adding seven requirements to the
 * plants (15 Oct 2026) gave some nine hundred employees on site seven tasks
 * each, "waiting for the worker", for paperwork done before Crewvia. A
 * requirement now asks the new hires - assignments not started yet (offered,
 * confirmed, travelling) - and the crew on site only when the office says so
 * ("Also ask the crew already on site", for a new safety rule for instance).
 */

declare(strict_types=1);

/** The placements a requirement r asks, for a query joining placements p and onboarding_requirements r. */
function onboarding_scope_sql(): string
{
    return "(p.status IN ('offered','confirmed','travelling') OR (r.for_crew = 1 AND p.status = 'on_site'))";
}

/**
 * Creates the tasks that are due and missing, for the placements and requirements matching $where
 * (a condition on p and r, with its arguments). Existing tasks are kept as they are.
 */
function onboarding_sync(string $where, array $args): void
{
    q('INSERT IGNORE INTO onboarding_tasks (placement_id, requirement_id)
       SELECT p.id, r.id FROM placements p JOIN onboarding_requirements r ON r.job_id = p.job_id
       WHERE ' . onboarding_scope_sql() . ' AND ' . $where, $args);
    onboarding_form_catch_up($where, $args);
}

/** Whether a requirement can still be removed: nobody answered it, nobody reviewed it. */
function onboarding_requirement_removable(int $requirementId): bool
{
    return ! (int) val("SELECT COUNT(*) FROM onboarding_tasks WHERE requirement_id = ? AND (status <> 'pending' OR response IS NOT NULL OR reviewed_at IS NOT NULL)", [$requirementId]);
}

/**
 * P5-M64: the tasks a form answers (a requirement's form_kind: w4, i9 or bank), for this person's
 * assignments, follow the form: submitted when it is sent, approved when it is accepted, rejected with
 * the reason when it is sent back. Nobody approves them by hand (Onboarding refuses it).
 */
function onboarding_form_tasks(int $candidateId, string $kind, string $status, string $response, ?int $reviewer = null): void
{
    $from = $status === 'submitted' ? ['pending', 'rejected'] : ['pending', 'submitted', 'rejected'];
    q("UPDATE onboarding_tasks t JOIN placements p ON p.id = t.placement_id JOIN onboarding_requirements r ON r.id = t.requirement_id
       SET t.status = ?, t.response = ?, t.reviewed_by = ?, t.reviewed_at = " . ($status === 'submitted' ? 'NULL' : 'NOW()') . "
       WHERE p.candidate_id = ? AND r.form_kind = ? AND t.status IN (?,?" . (count($from) === 3 ? ',?' : '') . ") AND p.status NOT IN ('completed','cancelled')",
      [$status, mb_substr($response, 0, 2000), $reviewer, $candidateId, $kind, ...$from]);
}

/**
 * P5-M64: a new assignment's form tasks start where the forms already are: a W-4 or an I-9 accepted, bank
 * details in use, close the task; one waiting to be checked shows it as submitted.
 */
function onboarding_form_catch_up(string $where, array $args): void
{
    $done = "(r.form_kind IN ('w4','i9') AND EXISTS (SELECT 1 FROM worker_tax_forms f WHERE f.candidate_id = p.candidate_id AND f.form = r.form_kind AND f.status = 'accepted'))
             OR (r.form_kind = 'bank' AND EXISTS (SELECT 1 FROM worker_bank_details b WHERE b.candidate_id = p.candidate_id AND b.status = 'verified'))";
    $sent = "(r.form_kind IN ('w4','i9') AND EXISTS (SELECT 1 FROM worker_tax_forms f WHERE f.candidate_id = p.candidate_id AND f.form = r.form_kind AND f.status = 'submitted'))
             OR (r.form_kind = 'bank' AND EXISTS (SELECT 1 FROM worker_bank_change_requests b WHERE b.candidate_id = p.candidate_id AND b.status = 'pending'))";
    q("UPDATE onboarding_tasks t JOIN placements p ON p.id = t.placement_id JOIN onboarding_requirements r ON r.id = t.requirement_id
       SET t.status = 'approved', t.response = 'Already on file', t.reviewed_at = NOW()
       WHERE t.status = 'pending' AND r.form_kind IS NOT NULL AND ($done) AND $where", $args);
    q("UPDATE onboarding_tasks t JOIN placements p ON p.id = t.placement_id JOIN onboarding_requirements r ON r.id = t.requirement_id
       SET t.status = 'submitted', t.response = 'Sent, waiting to be checked'
       WHERE t.status = 'pending' AND r.form_kind IS NOT NULL AND ($sent) AND $where", $args);
}
