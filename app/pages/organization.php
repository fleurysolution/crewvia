<?php
/**
 * Organization: who answers to whom on this job.
 *
 * It used to be a list of cards grouped by supervisor name, with no way to
 * change anything and no sign of the office above them - so the one question
 * it should answer, "who is carrying this person", was only half answered and
 * the fix lived on a different screen.
 *
 * The chart now runs office -> supervisor -> crew, says how loaded each
 * supervisor is, puts the unassigned crew first because they are the problem,
 * and assigns from here. The write itself still belongs to Operations: one
 * handler, two ways in, so the rules cannot drift apart.
 */

require_role('recruiter', 'hotels', 'payroll');

$job   = current_job();
$jobId = (int) ($job['id'] ?? 0);

/** Anybody who could be given a crew. Workers and clients never can. */
$possibleSupervisors = rows("SELECT id, name, role FROM users
                             WHERE is_active = 1 AND role NOT IN ('worker','client')
                             ORDER BY FIELD(role,'supervisor','admin','recruiter','hotels','payroll'), name");

$crew = rows("SELECT p.id, p.status, c.full_name, c.discipline, c.phone,
                     d.trade, d.shift_label, d.shift_id, u.id AS supervisor_id, u.name AS supervisor,
                     (SELECT ol.role_title FROM job_order_lines ol WHERE ol.id = p.order_line_id) AS line_role
              FROM placements p
              JOIN candidates c ON c.id = p.candidate_id
              LEFT JOIN assignment_details d ON d.placement_id = p.id
              LEFT JOIN users u ON u.id = d.supervisor_id
              WHERE p.job_id = ? AND p.status NOT IN ('cancelled','completed')
              ORDER BY c.full_name", [$jobId]);

// Unassigned first: a person nobody is carrying is the thing to fix.
$unassigned = array_values(array_filter($crew, static fn ($m) => ! $m['supervisor_id']));

$teams = [];

foreach ($crew as $member) {
    if ($member['supervisor_id']) {
        $at = (int) $member['supervisor_id'];
        $teams[$at]['supervisor'] = $member['supervisor'];
        $teams[$at]['members'][]  = $member;
    }
}

uasort($teams, static fn ($a, $b) => count($b['members']) <=> count($a['members']));

// The desks above the site. A crew with nobody at a desk has nobody to call.
$office = [];

foreach (rows("SELECT role, COUNT(*) n, GROUP_CONCAT(name ORDER BY name SEPARATOR ', ') who
               FROM users WHERE is_active = 1 AND role NOT IN ('worker','client')
               GROUP BY role") as $desk) {
    $office[$desk['role']] = $desk;
}

$onSite = count(array_filter($crew, static fn ($m) => $m['status'] === 'on_site'));

// The one person being reassigned, if any. The supervisor list is drawn for
// that person only: drawn for everybody, the page grew with people x staff
// and a 1,000-person project made a page the server dropped under load.
$assignId = (int) ($_GET['assign'] ?? 0);
if (! in_array($assignId, array_map(static fn ($m) => (int) $m['id'], $crew), true)) {
    $assignId = 0;
}

$pageTitle = t('Organization') . ' · ' . $config['app_name'];

require_once __DIR__ . '/../shifts.php';
$shifts = $job ? shifts_for((int) $job['id']) : [];
render('organization', compact('job', 'teams', 'unassigned', 'crew', 'office', 'shifts',
                               'onSite', 'possibleSupervisors', 'assignId'));
