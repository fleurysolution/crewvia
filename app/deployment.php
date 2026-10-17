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

/**
 * P5-M66: the flow carries on by itself. The moment nothing stands between an offered placement and the site
 * any more - forms accepted, Section 2 done, screening cleared, tasks approved, contract signed - the office
 * is told once to confirm the person on the roster, and the person is told their onboarding is done.
 * Called where those conditions clear, and when the office opens the roster or Onboarding (for what cleared
 * elsewhere: a contract, a qualification).
 */
function deployment_ready_notice(?int $candidateId = null, ?int $jobId = null): int
{
    require_once __DIR__ . '/notices.php';
    $where = ["p.status = 'offered'", 'p.ready_notified_at IS NULL'];
    $args = [];
    if ($candidateId) { $where[] = 'p.candidate_id = ?'; $args[] = $candidateId; }
    if ($jobId) { $where[] = 'p.job_id = ?'; $args[] = $jobId; }
    $told = 0;
    foreach (rows('SELECT p.id, p.candidate_id, p.job_id, p.start_date, c.full_name, j.title FROM placements p JOIN candidates c ON c.id = p.candidate_id JOIN jobs j ON j.id = p.job_id
                   WHERE ' . implode(' AND ', $where) . ' LIMIT 300', $args) as $p) {
        if (deployment_blockers((int) $p['id'], (int) $p['candidate_id'], (int) $p['job_id'])) {
            continue;
        }
        q('UPDATE placements SET ready_notified_at = NOW() WHERE id = ? AND ready_notified_at IS NULL', [(int) $p['id']]);
        $start = $p['start_date'] ? d((string) $p['start_date']) : t('a date to set');
        notify_desks(['recruiter'], t(':name is cleared for :project (start :start): every condition is met. Confirm them on the roster; hotel and travel are told then.',
                                      ['name' => $p['full_name'], 'project' => $p['title'], 'start' => $start]), '/roster');
        notify_candidate((int) $p['candidate_id'], t('Your onboarding for :project is complete. The office confirms your assignment and books your travel and hotel.', ['project' => $p['title']]), '/portal');
        log_activity('was cleared for deployment', 'placement', (int) $p['id']);
        $told++;
    }

    return $told;
}

/** P5-M66: a placement confirmed on the roster: hotel and travel are told who, where and when; the person too. */
function deployment_confirmed_notice(int $placementId): void
{
    require_once __DIR__ . '/notices.php';
    $p = row('SELECT p.id, p.candidate_id, p.start_date, c.full_name, j.title FROM placements p JOIN candidates c ON c.id = p.candidate_id JOIN jobs j ON j.id = p.job_id WHERE p.id = ?', [$placementId]);
    if (! $p) {
        return;
    }
    $start = $p['start_date'] ? d((string) $p['start_date']) : t('a date to set');
    $hasTravel = (bool) val('SELECT COUNT(*) FROM travel_profiles WHERE candidate_id = ?', [(int) $p['candidate_id']]);
    notify_desks(['hotels'], t(':name is confirmed for :project, starting :start: book the hotel and the travel.', ['name' => $p['full_name'], 'project' => $p['title'], 'start' => $start])
                 . ($hasTravel ? '' : ' ' . t('Their travel details are not in yet: they are asked in their portal.')), '/travel');
    notify_candidate((int) $p['candidate_id'], t('Your assignment on :project is confirmed, starting :start. Your hotel and travel are being booked; you are told here.', ['project' => $p['title'], 'start' => $start]), '/portal');
}
