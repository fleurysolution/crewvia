<?php
/**
 * P5-M81: moving a project to its next stage gives the team its work (RSS, 17 Oct 2026: "after you push it, it
 * should take you to mobilisation... send the tasks to the other guys, what they need to do: the recruiter
 * creates the job description, or fixes it, and starts recruiting").
 *
 * Each stage has its tasks, each for a desk: recruiting, hotels and travel, payroll, the site. They become project
 * tasks (P5-M01), assigned to the person on that desk with the fewest open tasks (the project's manager for the
 * site), with a due date, and the assignee is notified. A task already on the project is not made twice.
 */

declare(strict_types=1);

require_once __DIR__ . '/project-tasks.php';

/** The tasks each stage hands out: [title, description, role, days before start (negative) or after advancing]. */
function stage_task_plan(): array
{
    return [
        'mobilising' => [
            ['Check the job descriptions and open the requisitions', 'Each trade on the scope has a requisition open to applicants, with its description, pay and dates.', 'recruiter', 0],
            ['Recruit and send the offers', 'Screen the applicants, interview, and send the offers for every trade on the scope.', 'recruiter', 3],
            ['Book the hotel rooms for the crew', 'Hold the rooms on the project\'s hotels and book each person as they are confirmed.', 'hotels', 5],
            ['Book the travel to the site', 'Flights or drives for each confirmed person, arriving before their first shift.', 'hotels', 5],
            ['Check the tax forms, the direct deposit and ADP', 'W-4, I-9 and direct deposit accepted for each new hire, and each one created in ADP.', 'hr', 5],
            ['Prepare the site: shifts, buses and clock-in', 'Shift times, bus departures, the site position for the clock-in, and the supervisors of each crew.', 'site', 2],
        ],
        'on_site' => [
            ['Approve the first week\'s hours', 'Check the timesheets of the first week against the roll calls and approve them.', 'payroll', 7],
            ['Invoice the client for the first week', 'Generate the first invoice once the week is approved.', 'admin', 8],
        ],
        'demobilising' => [
            ['Book the travel home', 'Return travel for each person leaving the site.', 'hotels', 2],
            ['Check out of the hotels', 'Close every booking and check the hotel charges.', 'hotels', 3],
            ['Get the equipment and vehicles back', 'Everything issued is returned or recorded.', 'site', 3],
            ['Pay the last week and close the timesheets', 'Last hours approved, final pay run, per diem settled.', 'payroll', 7],
        ],
    ];
}

/** The person to give a desk's task to: the project's manager for the site, else whoever on that desk has the fewest open tasks. */
function stage_task_assignee(array $job, string $role): ?int
{
    if ($role === 'site') {
        $pm = (int) ($job['project_manager_id'] ?? 0);
        if ($pm && val('SELECT is_active FROM users WHERE id = ?', [$pm])) {
            return $pm;
        }
        $role = 'supervisor';
    }
    if ($role === 'hr' && ! val("SELECT id FROM users WHERE is_active = 1 AND role = 'hr' LIMIT 1")) {
        $role = 'payroll';
    }
    $id = val("SELECT u.id FROM users u
               WHERE u.is_active = 1 AND u.role = ? AND NOT EXISTS (SELECT 1 FROM worker_accounts w WHERE w.user_id = u.id)
               ORDER BY (SELECT COUNT(*) FROM project_tasks t WHERE t.assignee_id = u.id AND t.status IN ('todo','in_progress','blocked')), u.id LIMIT 1", [$role]);

    return $id ? (int) $id : null;
}

/** Hands out the tasks of the stage the project has just reached. Returns how many were made. */
function stage_tasks_create(int $jobId, string $stage, int $by): int
{
    $job = row('SELECT * FROM jobs WHERE id = ?', [$jobId]);
    if (! $job) {
        return 0;
    }
    $made = 0;
    foreach (stage_task_plan()[$stage] ?? [] as [$title, $description, $role, $days]) {
        if (val('SELECT COUNT(*) FROM project_tasks WHERE job_id = ? AND title = ?', [$jobId, t($title)])) {
            continue;
        }
        $due = date('Y-m-d', strtotime('+' . max(0, (int) $days) . ' days'));
        if ($stage === 'mobilising' && ! empty($job['starts_on']) && $job['starts_on'] > date('Y-m-d') && $due > $job['starts_on']) {
            $due = $job['starts_on'];
        }
        [$why, $id] = pt_create($jobId, ['title' => t($title), 'description' => t($description), 'priority' => 'high', 'due_on' => $due,
                                         'assignee_id' => stage_task_assignee($job, $role) ?? 0], $by);
        $made += $why === null && $id ? 1 : 0;
    }

    return $made;
}
