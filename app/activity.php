<?php
/**
 * What is waiting on you, right now.
 *
 * Every desk has a queue, and until now each one lived on its own screen: you
 * had to open Application screening, then Contracts, then Hotels, then Hours
 * to discover whether anything needed you. People kept that list in their
 * head, or in a spreadsheet.
 *
 * This is the same work, counted in one place. Each line is a real count of
 * records in a state that needs a person, and it links to the screen that
 * clears it - so the number goes down because the work was done, never
 * because somebody ticked it off here.
 *
 * Only the desks a person actually works are counted: a recruiter is never
 * shown the payroll queue, and a worker only ever sees their own file.
 */

declare(strict_types=1);

/**
 * @return list<array{count:int,label:string,where:string,tone:string}>
 */
function activity_items(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $u = user();

    if (! $u) {
        return $cache = [];
    }

    $job   = (int) (current_job()['id'] ?? 0);
    $items = [];

    $add = static function (int $n, string $label, string $where,
                            string $tone = 'blue', string $source = '') use (&$items): void {
        if ($n > 0) {
            $items[] = ['count' => $n, 'label' => $label, 'where' => $where,
                        'tone' => $tone, 'source' => $source];
        }
    };

    $count = static fn (string $sql, array $args = []): int => (int) val($sql, $args);

    // ── the crew's own file ──────────────────────────────────────────────
    if (is_worker_account()) {
        $me = (int) val('SELECT candidate_id FROM worker_accounts WHERE user_id = ?', [uid()]);

        $add($count("SELECT COUNT(*) FROM employment_contracts c
                     JOIN applications a ON a.id = c.application_id
                     WHERE a.candidate_id = ? AND c.status IN ('issued','viewed')", [$me]),
             'Contract to read and sign', '/contracts', 'red',
             'Contracts issued to you and not yet signed');

        $add($count("SELECT COUNT(*) FROM applications
                     WHERE candidate_id = ? AND stage = 'offered'", [$me]),
             'Offer waiting for your answer', '/portal', 'red',
             'Applications of yours at the offered stage');

        $add($count("SELECT COUNT(*) FROM onboarding_tasks t
                     JOIN placements p ON p.id = t.placement_id
                     WHERE p.candidate_id = ? AND t.status IN ('pending','rejected')", [$me]),
             'Onboarding task to complete', '/portal', 'amber',
             'Onboarding tasks on your assignment, not yet done');

        $add($count("SELECT COUNT(*) FROM worker_documents
                     WHERE candidate_id = ? AND status = 'rejected'", [$me]),
             'Document sent back to you', '/proofs', 'amber',
             'Documents you uploaded that were rejected');

        $add($count("SELECT COUNT(*) FROM offboarding_tasks t
                     JOIN placements p ON p.id = t.placement_id
                     WHERE p.candidate_id = ? AND t.status IN ('pending','rejected')", [$me]),
             'Closeout task to complete', '/offboarding', 'blue',
             'Offboarding tasks on your assignment, not yet done');

        return $cache = $items;
    }

    // ── recruiting ───────────────────────────────────────────────────────
    if (can('recruiter') && $job) {
        $add($count("SELECT COUNT(*) FROM applications a
                     JOIN vacancies v ON v.id = a.vacancy_id
                     WHERE v.job_id = ? AND a.stage = 'new'", [$job]),
             'New application nobody has read', '/recruitment', 'red',
             'Applications on this project still at "new"');

        $add($count("SELECT COUNT(*) FROM candidates
                     WHERE stage = 'new' AND last_contact_at IS NULL"),
             'Candidate nobody has called', '/candidates', 'amber',
             'Candidates at "new" with no call ever logged');

        $add($count("SELECT COUNT(*) FROM employment_contracts c
                     JOIN applications a ON a.id = c.application_id
                     JOIN vacancies v ON v.id = a.vacancy_id
                     WHERE v.job_id = ? AND c.status IN ('draft','approved','uploaded_review')", [$job]),
             'Contract waiting on you', '/contracts', 'amber',
             'Contracts in draft, approved or awaiting signature verification');

        $add($count("SELECT COUNT(*) FROM onboarding_tasks t
                     JOIN placements p ON p.id = t.placement_id
                     WHERE p.job_id = ? AND t.status = 'submitted'", [$job]),
             'Onboarding task to review', '/onboarding', 'amber',
             'Onboarding tasks a worker has submitted');

        $add($count("SELECT COUNT(*) FROM worker_documents d
                     JOIN placements p ON p.candidate_id = d.candidate_id
                     WHERE p.job_id = ? AND d.status = 'submitted'", [$job]),
             'Document proof to verify', '/proofs', 'amber',
             'Uploaded documents still marked submitted');

        $add($count("SELECT COUNT(*) FROM screening_cases s
                     JOIN placements p ON p.id = s.placement_id
                     WHERE p.job_id = ? AND s.status IN ('pending','scheduled','completed')", [$job]),
             'Background or drug check open', '/checks', 'amber',
             'Screening cases not yet cleared or cancelled');

        $add($count("SELECT COUNT(*) FROM candidate_qualifications q
                     JOIN placements p ON p.candidate_id = q.candidate_id
                     WHERE p.job_id = ? AND q.status = 'pending'", [$job]),
             'Qualification to verify', '/qualifications', 'amber',
             'Candidate qualifications still pending');

        $add($count("SELECT COUNT(*) FROM time_off_requests r
                     JOIN placements p ON p.id = r.placement_id
                     WHERE p.job_id = ? AND r.status = 'pending'", [$job]),
             'Time-off request to decide', '/timeoff', 'blue',
             'Time-off requests still pending');
    }

    // ── hotels and travel ────────────────────────────────────────────────
    if (can('hotels') && $job) {
        $add($count("SELECT COUNT(*) FROM placements p
                     LEFT JOIN lodging l ON l.placement_id = p.id
                          AND l.status IN ('held','booked','checked_in')
                     WHERE p.job_id = ? AND p.status IN ('confirmed','travelling','on_site')
                       AND l.id IS NULL", [$job]),
             'Person on the job with no bed', '/hotels', 'red',
             'Deployed placements with no active lodging row');

        $add($count("SELECT COUNT(*) FROM lodging l
                     JOIN placements p ON p.id = l.placement_id
                     WHERE p.job_id = ? AND l.private_room = 0
                       AND l.status <> 'cancelled'", [$job]),
             'Room shared against what was promised', '/hotels', 'red',
             'Lodging rows marked as not a private room');

        $add($count("SELECT COUNT(*) FROM placements p
                     LEFT JOIN travel t ON t.placement_id = p.id
                          AND t.direction = 'inbound' AND t.status <> 'cancelled'
                     WHERE p.job_id = ? AND p.status IN ('confirmed','travelling')
                       AND t.id IS NULL", [$job]),
             'Travel still to book', '/travel', 'amber',
             'Confirmed or travelling placements with no inbound travel');
    }

    // ── pay and billing ──────────────────────────────────────────────────
    if (can('payroll') && $job) {
        $add($count("SELECT COUNT(*) FROM attendance_records a
                     JOIN placements p ON p.id = a.placement_id
                     WHERE p.job_id = ? AND a.status = 'submitted'", [$job]),
             'Attendance to approve', '/attendance', 'amber',
             'Attendance records a worker has submitted');

        $add($count("SELECT COUNT(*) FROM timesheets t
                     JOIN placements p ON p.id = t.placement_id
                     WHERE p.job_id = ? AND t.status IN ('draft','submitted')", [$job]),
             'Timesheet not yet approved', '/hours', 'amber',
             'Timesheets still in draft or submitted');

        $add($count("SELECT COUNT(*) FROM expense_claims e
                     JOIN placements p ON p.id = e.placement_id
                     WHERE p.job_id = ? AND e.status = 'submitted'", [$job]),
             'Reimbursement to decide', '/expenses', 'blue',
             'Expense claims still submitted');

        $add($count("SELECT COUNT(*) FROM expense_claims e
                     JOIN placements p ON p.id = e.placement_id
                     WHERE p.job_id = ? AND e.status = 'approved'", [$job]),
             'Approved reimbursement still to pay', '/expenses', 'blue',
             'Expense claims approved but not yet paid');
    }

    // ── the whole project ────────────────────────────────────────────────
    if (can('admin') && $job) {
        $target = (int) val('SELECT headcount_target FROM jobs WHERE id = ?', [$job]);
        $filled = $count("SELECT COUNT(*) FROM placements
                          WHERE job_id = ? AND status IN ('confirmed','travelling','on_site')", [$job]);

        if ($target > $filled) {
            $add($target - $filled, 'Still to place on this project', '/roster', 'red',
             'The headcount asked for, less those confirmed or on site');
        }
    }

    // Approval steps this person can decide now. The chain decides who is
    // asked; this only counts what has reached them.
    require_once __DIR__ . '/approvals.php';

    if (approvals_available()) {
        $add(count(approval_queue()), 'Approval waiting on your decision', '/approvals', 'red',
             'Approval steps your desk can decide right now');
    }

    // Notifications are messages, not work, so they come last.
    $add($count('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [uid()]),
         'Unread notification', '/notifications', 'grey',
             'Notifications sent to you and not yet opened');

    return $cache = $items;
}

/** One number for the sidebar badge. */
function activity_total(): int
{
    $total = 0;

    foreach (activity_items() as $item) {
        $total += $item['count'];
    }

    return $total;
}
