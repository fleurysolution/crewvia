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
}

/** Whether a requirement can still be removed: nobody answered it, nobody reviewed it. */
function onboarding_requirement_removable(int $requirementId): bool
{
    return ! (int) val("SELECT COUNT(*) FROM onboarding_tasks WHERE requirement_id = ? AND (status <> 'pending' OR response IS NOT NULL OR reviewed_at IS NOT NULL)", [$requirementId]);
}
