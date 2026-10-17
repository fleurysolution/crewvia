<?php
/**
 * P5-M97: Send home - the end of somebody's assignment, in one step.
 *
 * RSS: "a button for termination that automatically cancels the applicant or
 * the employee, ADP and all other related items, including triggering the
 * booking of their return flight by the project coordinator."
 *
 * One step closes everything the system holds for the assignment:
 *   - the placement ends (completed if they worked, cancelled if they never started), dated;
 *   - the hotel: checked out on the last day, later bookings cancelled; the bus runs after it;
 *   - an inbound flight not yet flown is cancelled; open screenings and onboarding/travel tasks are cancelled;
 *   - the separation is recorded (why, rehire or not), and a termination locks their account;
 *   - and the people who finish it get a task each: the return flight (the project coordinator), ending the
 *     employment in ADP (payroll), the equipment or vehicle still out (the supervisor).
 * ADP has an API for terminations; until RSS's credentials are on the server, ADP is a task, as the hire is.
 */

declare(strict_types=1);

/** @return array<string,string> */
function send_home_reasons(): array
{
    require_once __DIR__ . '/hr-records.php';

    return separation_reasons();
}

/** Who may send somebody home: the owner, a manager, HR or a recruiter. */
function send_home_allowed(): bool
{
    return can('admin') || can('recruiter');
}

/**
 * @param array{reason?:string,last_day?:string,detail?:string,rehire?:string,return_flight?:string} $in
 * @return ?string an error
 */
function send_home(int $placementId, array $in, int $by): ?string
{
    require_once __DIR__ . '/hr-records.php';
    require_once __DIR__ . '/project-tasks.php';
    require_once __DIR__ . '/stage-tasks.php';
    require_once __DIR__ . '/notices.php';

    if (! send_home_allowed()) {
        return t('Only the office sends somebody home.');
    }
    $p = row('SELECT p.*, c.full_name, c.id AS cid FROM placements p JOIN candidates c ON c.id = p.candidate_id WHERE p.id = ?', [$placementId]);
    if (! $p || ! in_array($p['status'], ['offered', 'confirmed', 'travelling', 'on_site'], true)) {
        return t('That assignment is already closed.');
    }
    $reason = (string) ($in['reason'] ?? '');
    $lastDay = (string) ($in['last_day'] ?? '');
    $detail = trim((string) ($in['detail'] ?? ''));
    $rehire = (string) ($in['rehire'] ?? 'eligible');
    if (! isset(send_home_reasons()[$reason])) {
        return t('Say why the assignment ends.');
    }
    if (! in_array($rehire, ['eligible', 'review', 'ineligible'], true)) {
        return t('Say whether they can be hired again.');
    }
    if (! valid_date($lastDay) || $lastDay > date('Y-m-d', strtotime('+30 days'))) {
        return t('Give their last day: today, an earlier day, or within the next 30 days.');
    }
    if (($reason !== 'assignment_completed' || $rehire === 'ineligible') && mb_strlen($detail) < 10) {
        return t('Say what happened: at least 10 characters.');
    }
    // A termination is the owner's, a manager's or HR's call.
    if ($reason === 'terminated' && ! can('admin') && (user()['role'] ?? '') !== 'hr') {
        return t('A termination is decided by HR or a manager.');
    }

    $worked = in_array($p['status'], ['travelling', 'on_site'], true);
    $job = row('SELECT * FROM jobs WHERE id = ?', [(int) $p['job_id']]) ?: [];
    db()->beginTransaction();
    q('UPDATE placements SET status = ?, end_date = ? WHERE id = ?', [$worked ? 'completed' : 'cancelled', $lastDay, $placementId]);
    q("UPDATE lodging SET status = 'cancelled' WHERE placement_id = ? AND status IN ('held','booked') AND check_in > ?", [$placementId, $lastDay]);
    q("UPDATE lodging SET status = 'checked_out', check_out = ? WHERE placement_id = ? AND status IN ('held','booked','checked_in')", [$lastDay, $placementId]);
    q('DELETE r FROM route_passengers r JOIN shuttle_runs s ON s.id = r.run_id WHERE r.placement_id = ? AND s.runs_on > ?', [$placementId, $lastDay]);
    q("UPDATE travel SET status = 'cancelled' WHERE placement_id = ? AND direction = 'inbound' AND status IN ('planned','booked') AND (depart_time IS NULL OR depart_time > NOW())", [$placementId]);
    q("UPDATE screening_cases SET status = 'cancelled' WHERE placement_id = ? AND status NOT IN ('cleared','not_cleared','cancelled')", [$placementId]);
    try {
        q("UPDATE project_tasks t JOIN hiring_handoffs h ON (h.hr_task_id = t.id OR h.travel_task_id = t.id)
           SET t.status = 'cancelled' WHERE h.candidate_id = ? AND h.job_id = ? AND t.status NOT IN ('done','cancelled')", [(int) $p['cid'], (int) $p['job_id']]);
    } catch (Throwable $e) {
        // Before the hand-over table exists there are no such tasks.
    }
    if (! val('SELECT COUNT(*) FROM separations WHERE placement_id = ?', [$placementId])) {
        q('INSERT INTO separations (placement_id, candidate_id, separated_on, reason, voluntary, detail, rehire, case_id, recorded_by) VALUES (?,?,?,?,?,?,?,?,?)',
          [$placementId, (int) $p['cid'], min($lastDay, date('Y-m-d')), $reason, $reason === 'resigned' ? 1 : 0, $detail !== '' ? mb_substr($detail, 0, 1000) : null, $rehire, null, $by]);
    }
    q('INSERT IGNORE INTO employee_profiles (candidate_id) VALUES (?)', [(int) $p['cid']]);
    if ($rehire === 'ineligible') {
        q("UPDATE employee_profiles SET rehire_status = 'ineligible', exclusion_reason = ?, excluded_by = ?, excluded_at = NOW() WHERE candidate_id = ?",
          [mb_substr(separation_reasons()[$reason] . ' on ' . $lastDay . ' - the account is in the HR record', 0, 1000), $by, (int) $p['cid']]);
    } elseif ($rehire === 'review') {
        q("UPDATE employee_profiles SET rehire_status = 'review' WHERE candidate_id = ? AND rehire_status NOT IN ('ineligible','do_not_use')", [(int) $p['cid']]);
    }
    q('INSERT INTO candidate_events (candidate_id, user_id, event_type, detail) VALUES (?,?,?,?)',
      [(int) $p['cid'], $by, 'separation', 'Sent home: ' . separation_reasons()[$reason] . ', last day ' . $lastDay]);
    db()->commit();
    if ($reason === 'terminated') {
        hr_lock_access((int) $p['cid'], 'Terminated: sent home on ' . $lastDay);
    }

    $task = static function (string $title, string $description, ?int $who, string $link, string $due) use ($p, $by): int {
        q("INSERT INTO project_tasks (job_id, title, description, assignee_id, priority, due_on, created_by) VALUES (?,?,?,?, 'high', ?, ?)",
          [(int) $p['job_id'], $title, $description, $who, $due, $by]);
        $id = (int) db()->lastInsertId();
        pt_event($id, $by, 'created', null, $title);
        try {
            q('UPDATE project_tasks SET link = ? WHERE id = ?', [$link, $id]);
        } catch (Throwable $e) {
            // Before P5-M92 the task has no link.
        }
        if ($who) {
            pt_notify($who, $by, $title, '/my-tasks?task=' . $id);
        }

        return $id;
    };

    // The return flight: a leg home, to the airport they fly from, for the project coordinator to book.
    if ($worked && ($in['return_flight'] ?? '1') === '1') {
        $airport = (string) (val('SELECT airport FROM travel_profiles WHERE candidate_id = ?', [(int) $p['cid']]) ?: '');
        if ($airport === '') {
            try {
                $airport = (string) (val('SELECT home_airport FROM candidates WHERE id = ?', [(int) $p['cid']]) ?: '');
            } catch (Throwable $e) {
                $airport = '';
            }
        }
        if (! val("SELECT COUNT(*) FROM travel WHERE placement_id = ? AND direction = 'outbound' AND status <> 'cancelled'", [$placementId])) {
            q("INSERT INTO travel (placement_id, direction, mode, depart_from, arrive_at, status) VALUES (?, 'outbound', 'flight', ?, ?, 'planned')",
              [$placementId, trim((string) (($job['site_city'] ?? '') . ' ' . ($job['site_state'] ?? ''))) ?: null, $airport !== '' ? $airport : null]);
        }
        $task(t('Book the return flight for :name', ['name' => $p['full_name']]),
              t('Sent home, last day :date. Book the flight home:airport, then the hotel checkout is already set.', ['date' => d($lastDay), 'airport' => $airport !== '' ? ' ' . t('to :airport', ['airport' => $airport]) : '']),
              stage_task_assignee($job, 'hotels'), '/travel', $lastDay);
    }
    // ADP: the employment ends.
    $task(t('End :name\'s employment in ADP', ['name' => $p['full_name']]),
          t('Last day :date. Reason: :reason.', ['date' => d($lastDay), 'reason' => separation_reasons()[$reason]]),
          stage_task_assignee($job, 'payroll'), '/adp', $lastDay);
    // Whatever is still out.
    $out = (int) val('SELECT COUNT(*) FROM equipment_issues WHERE placement_id = ? AND returned_at IS NULL', [$placementId])
         + (int) val('SELECT COUNT(*) FROM vehicle_assignments WHERE placement_id = ? AND checked_in_at IS NULL', [$placementId]);
    $supervisor = (int) val('SELECT supervisor_id FROM assignment_details WHERE placement_id = ?', [$placementId]);
    if ($out) {
        $task(t('Get back the equipment and vehicle of :name', ['name' => $p['full_name']]),
              t('Sent home, last day :date. :n item still out.', ['date' => d($lastDay), 'n' => $out]),
              $supervisor ?: stage_task_assignee($job, 'site'), '/operations', $lastDay);
    }
    if ($supervisor) {
        notify_user($supervisor, t(':name leaves your crew: last day :date.', ['name' => $p['full_name'], 'date' => d($lastDay)]), '/my-team');
    }
    log_activity('sent somebody home', 'placement', $placementId, separation_reasons()[$reason] . ', last day ' . $lastDay);

    return null;
}
