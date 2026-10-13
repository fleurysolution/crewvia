<?php
/**
 * Home pages by role (layout walkthrough, wave 2): each desk opens on what
 * its own job needs today, across the running projects, each figure leading
 * to the page where it is worked. Figures come from the same queries and
 * functions as those pages (project_figures() for the deployment problems),
 * so a home and its page never disagree.
 *
 *   recruiter  - who applied, who waits for review, offers out, requisitions
 *                still short of people, campaigns;
 *   Logistics  - who arrives this week, beds, travel, folios, purchases;
 *   payroll    - the pay week: hours and sheets to approve, lateness
 *                disputes, changes to check, bills past due.
 * The administrator's home is the Executive overview; the supervisor's is My
 * team; the worker's is their portal; the client's is the client portal.
 */

declare(strict_types=1);

require_once __DIR__ . '/projects.php';

/** Where a role lands after signing in, when no page was asked for. */
function home_for(string $role): string
{
    return match ($role) {
        'admin'      => '/overview',
        'supervisor' => '/my-team',
        'worker'     => '/portal',
        'client'     => '/client-portal',
        default      => '/home',
    };
}

/** The deployment problems added up across the running projects, as project_figures() counts them. */
function home_problems(): array
{
    $out = ['beds' => 0, 'sharing' => 0, 'blocked' => 0, 'travel' => 0, 'unapproved' => 0, 'placed' => 0, 'onsite' => 0, 'target' => 0];
    foreach (rows("SELECT * FROM jobs WHERE status <> 'closed'") as $job) {
        $f = project_figures($job);
        foreach ($out as $k => $v) {
            $out[$k] += (int) ($f[$k] ?? 0);
        }
    }

    return $out;
}

function home_recruiter(): array
{
    $open = "SELECT v.id FROM vacancies v JOIN jobs j ON j.id = v.job_id WHERE v.is_open = 1 AND j.status <> 'closed'";
    $counts = row("SELECT COALESCE(SUM(a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)), 0) AS new_week,
                          COALESCE(SUM(a.stage = 'new'), 0) AS waiting,
                          COALESCE(SUM(a.stage IN ('screening','interview')), 0) AS reviewing,
                          COALESCE(SUM(a.stage = 'offered'), 0) AS offered
                   FROM applications a WHERE a.vacancy_id IN ($open)") ?: [];
    $short = rows("SELECT v.id, v.title, j.title AS project, v.openings,
                          (SELECT COUNT(*) FROM applications a WHERE a.vacancy_id = v.id AND a.stage = 'accepted') AS hired,
                          (SELECT COUNT(*) FROM applications a WHERE a.vacancy_id = v.id AND a.stage = 'new') AS new_count
                   FROM vacancies v JOIN jobs j ON j.id = v.job_id
                   WHERE v.is_open = 1 AND j.status <> 'closed'
                   ORDER BY (CAST(v.openings AS SIGNED) - (SELECT COUNT(*) FROM applications a WHERE a.vacancy_id = v.id AND a.stage = 'accepted')) DESC, v.id DESC LIMIT 8");
    $short = array_values(array_filter($short, static fn($v) => (int) $v['openings'] > (int) $v['hired']));
    $campaigns = row("SELECT COALESCE(SUM(status = 'sending'), 0) AS sending, COALESCE(SUM(status = 'awaiting_approval'), 0) AS waiting, COALESCE(SUM(status = 'draft'), 0) AS drafts FROM email_campaigns") ?: [];

    return ['counts' => array_map('intval', $counts), 'short' => $short, 'campaigns' => array_map('intval', $campaigns),
            'never_called' => (int) val("SELECT COUNT(*) FROM candidates WHERE last_contact_at IS NULL AND stage = 'new'")];
}

function home_logistics(): array
{
    $arrivals = rows("SELECT p.id, p.start_date, p.status, c.full_name, j.title AS project,
                             EXISTS (SELECT 1 FROM lodging l WHERE l.placement_id = p.id AND l.status NOT IN ('cancelled','checked_out')) AS has_bed,
                             EXISTS (SELECT 1 FROM travel t WHERE t.placement_id = p.id AND t.status <> 'cancelled') AS has_travel
                      FROM placements p JOIN candidates c ON c.id = p.candidate_id JOIN jobs j ON j.id = p.job_id
                      WHERE p.status IN ('confirmed','travelling') AND j.status <> 'closed' AND p.start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                      ORDER BY p.start_date, c.full_name LIMIT 30");

    return ['problems' => home_problems(), 'arrivals' => $arrivals,
            'folios_open' => (int) val("SELECT COUNT(*) FROM hotel_folios WHERE status = 'open'"),
            'purchases' => (int) val("SELECT COUNT(*) FROM purchase_requests WHERE status = 'requested'")];
}

function home_payroll(): array
{
    $last = week_ending(date('Y-m-d', strtotime('-7 days')));

    return [
        'last_week' => $last,
        'hours_waiting' => (int) val("SELECT COUNT(*) FROM attendance_records WHERE status = 'submitted'"),
        'sheets_open' => (int) val("SELECT COUNT(*) FROM timesheets WHERE week_ending = ? AND status IN ('draft','submitted')", [$last]),
        'disputes' => (int) val("SELECT COUNT(*) FROM attendance_lateness WHERE status = 'disputed'"),
        'changes' => (int) val("SELECT (SELECT COUNT(*) FROM profile_change_requests WHERE status = 'pending') + (SELECT COUNT(*) FROM worker_bank_change_requests WHERE status = 'pending')"),
        'bills_due' => (int) val("SELECT COUNT(*) FROM vendor_invoices WHERE status <> 'paid' AND due_on < CURDATE()"),
        'bills_week' => (int) val("SELECT COUNT(*) FROM vendor_invoices WHERE status <> 'paid' AND due_on BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)"),
        'timeoff' => (int) val("SELECT COUNT(*) FROM time_off_requests WHERE status = 'pending'"),
    ];
}

/** The supervisor's crew today, for My team. */
function home_supervisor(int $userId): array
{
    $crew = "SELECT p.id FROM placements p JOIN assignment_details d ON d.placement_id = p.id WHERE d.supervisor_id = " . (int) $userId . " AND p.status = 'on_site'";

    return [
        'on_site' => (int) val("SELECT COUNT(*) FROM ($crew) x"),
        'marked' => (int) val("SELECT COUNT(*) FROM assignment_checkins WHERE work_date = CURDATE() AND placement_id IN ($crew)"),
        'late_week' => (int) val("SELECT COUNT(*) FROM attendance_lateness WHERE work_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND status <> 'cancelled' AND placement_id IN ($crew)"),
        'hours_waiting' => (int) val("SELECT COUNT(*) FROM attendance_records WHERE status = 'submitted' AND placement_id IN ($crew)"),
        'tasks' => (int) val("SELECT COUNT(*) FROM project_tasks WHERE assignee_id = ? AND status IN ('todo','in_progress','blocked')", [$userId]),
        'tasks_late' => (int) val("SELECT COUNT(*) FROM project_tasks WHERE assignee_id = ? AND status IN ('todo','in_progress','blocked') AND due_on < CURDATE()", [$userId]),
    ];
}
