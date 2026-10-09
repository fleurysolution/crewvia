<?php
/**
 * What still stands between a placement and the site.
 *
 * Six conditions must hold before somebody can be confirmed,
 * travelling or on site - a signed contract, verified qualifications, cleared
 * screening, reviewed I-9 and W-4, real onboarding instructions, and every
 * required onboarding task approved. The roster used to reveal them one at a
 * time: pick a status, lose the page, read one sentence, fix it, pick again.
 * On a forty-person mobilisation that is a lot of reloads to discover a list
 * the application already knows.
 *
 * The list is computed once here, so the screen that shows it and the gate
 * that enforces it cannot disagree.
 */

declare(strict_types=1);

require_once __DIR__ . '/contracts.php';
require_once __DIR__ . '/qualifications.php';

/**
 * @return list<array{label:string,vars:array,where:string}> empty when ready
 */
function deployment_blockers(int $placementId, int $candidateId, int $jobId): array
{
    $blockers = [];

    if (contract_deployment_pending($candidateId, $jobId)) {
        $blockers[] = ['label' => 'A signed current contract is required before deployment.', 'vars' => [],
                       'where' => '/contracts'];
    }

    if (qualification_gaps($candidateId, $jobId)) {
        $blockers[] = ['label' => 'Current verified project qualifications are required before deployment.', 'vars' => [],
                       'where' => '/qualifications'];
    }

    if ((int) val("SELECT COUNT(*) FROM screening_cases
                   WHERE placement_id = ? AND status NOT IN ('cleared','cancelled')",
                  [$placementId])) {
        $blockers[] = ['label' => 'Complete required background/drug screening before deployment.', 'vars' => [],
                       'where' => '/checks'];
    }

    $clearance = row('SELECT i9_status, w4_status FROM employment_clearance WHERE candidate_id = ?',
                     [$candidateId]);

    if (! $clearance
        || $clearance['i9_status'] !== 'employer_completed'
        || $clearance['w4_status'] !== 'reviewed') {
        $blockers[] = ['label' => 'Complete the employment paperwork review before deployment.', 'vars' => [],
                       'where' => '/employment'];
    }

    // A requirement still carrying the template sentence has not been read by
    // anybody, so approving tasks against it would mean nothing.
    if (val("SELECT COUNT(*) FROM onboarding_requirements
             WHERE job_id = ? AND required = 1
               AND (TRIM(instructions) = ''
                    OR instructions = 'Agency must provide approved project-specific instructions.')",
            [$jobId])) {
        $blockers[] = ['label' => 'Replace template instructions with approved project instructions before deployment.', 'vars' => [],
                       'where' => '/onboarding'];
    }

    $required = (int) val('SELECT COUNT(*) FROM onboarding_requirements
                           WHERE job_id = ? AND required = 1', [$jobId]);
    $approved = (int) val("SELECT COUNT(*) FROM onboarding_tasks t
                           JOIN onboarding_requirements r ON r.id = t.requirement_id
                           WHERE t.placement_id = ? AND r.job_id = ? AND r.required = 1
                             AND t.status = 'approved'", [$placementId, $jobId]);

    if (! $required) {
        $blockers[] = ['label' => 'Configure and approve required onboarding tasks before deployment.',
                       'vars' => [], 'where' => '/onboarding'];
    } elseif ($approved !== $required) {
        $blockers[] = ['label' => ':approved of :required onboarding tasks approved.',
                       'vars' => ['approved' => $approved, 'required' => $required],
                       'where' => '/onboarding'];
    }

    return $blockers;
}
