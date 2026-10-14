<?php
/**
 * Who is deployed, and is everything in place for them.
 *
 * One row per person on the job, with the three things that can go wrong -
 * no bed, no flight, sharing a room - visible without opening anything.
 */

require_role('recruiter', 'hotels', 'payroll');
require_once __DIR__.'/../contracts.php';require_once __DIR__.'/../qualifications.php';require_once __DIR__.'/../offboarding.php';require_once __DIR__.'/../deployment.php';

$job = current_job();
$jobId = (int) ($job['id'] ?? 0);

require_once __DIR__ . '/../hr.php';

// ── how the assignment went ─────────────────────────────────────────────
// Asked when the assignment closes, because that is when somebody is
// looking at the person and still remembers. A year later it is the only
// thing that answers "is this one worth calling back".
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'review') {
    // Supervisors grade their own crew on My team; the Roster is the recruiter's.
    require_role('recruiter');

    $pid = (int) ($_POST['placement_id'] ?? 0);
    $p = row('SELECT p.*, c.full_name FROM placements p
              JOIN candidates c ON c.id = p.candidate_id
              WHERE p.id = ? AND p.job_id = ?', [$pid, $jobId]);

    if (! $p) {
        refuse(404, t('Placement unavailable.'));
    }

    if ($why = assignment_review_save($p, $_POST, uid())) {
        flash($why, 'err');
        redirect('/roster');
    }

    flash(t(':name graded :grade on this assignment.',
            ['name' => $p['full_name'], 'grade' => (string) $_POST['grade']]));

    redirect('/roster');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'status') {
    require_role('recruiter');

    $pid = (int) ($_POST['placement_id'] ?? 0);
    $st  = (string) ($_POST['status'] ?? '');
    $ok  = ['offered','confirmed','travelling','on_site','completed','cancelled'];

    if (in_array($st, $ok, true)) {
        $p=row('SELECT * FROM placements WHERE id=? AND job_id=?',[$pid,$jobId]);
        if (!$p) { refuse(404, t('Placement unavailable.')); }
        if($st==='completed'&&offboarding_pending($pid,$jobId)){flash(t('Complete approved offboarding tasks before closing the assignment.'),'err');redirect('/roster');}
        if(in_array($st,['completed','cancelled'],true) && (val('SELECT COUNT(*) FROM equipment_issues WHERE placement_id=? AND returned_at IS NULL',[$pid]) || val('SELECT COUNT(*) FROM vehicle_assignments WHERE placement_id=? AND checked_in_at IS NULL',[$pid]))) {flash(t('Return outstanding equipment before completing the assignment.'),'err');redirect('/roster');}
        if (in_array($st,['confirmed','travelling','on_site'],true)) {
            // The same conditions as before, read from the list the
            // roster itself displays, so a refusal can never name
            // something the screen was not already showing.
            $blockers = deployment_blockers($pid, (int) $p['candidate_id'], $jobId);

            if ($blockers) {
                flash(t($blockers[0]['label'], $blockers[0]['vars']), 'err');
                redirect('/roster');
            }
        }

        q('UPDATE placements SET status = ? WHERE id = ? AND job_id = ?', [$st, $pid, $jobId]);

        // Placing somebody is also a fact about the candidate, and the
        // recruiting board must not keep showing them as merely "accepted".
        if ($st === 'on_site') {
            q("UPDATE candidates c JOIN placements p ON p.candidate_id = c.id
               SET c.stage = 'placed' WHERE p.id = ?", [$pid]);
        }

        if($st==='confirmed') q("INSERT INTO notifications(user_id,message,target) SELECT id,?,? FROM users WHERE role IN ('admin','hotels') AND is_active=1",['Worker ready for hotel and travel allocation: project '.($job['title'] ?? ''),'/hotels']);
        log_activity('moved placement', 'placement', $pid, $st);
        flash(t('Status updated.'));
    }

    redirect('/roster');
}

$crew = rows(
    "SELECT p.*, c.full_name, c.discipline, c.phone, c.email, d.trade, ol.role_title AS line_role,
            h.name AS hotel, l.room_number, l.private_room, l.check_in, l.check_out,
            ti.arrive_time, ti.carrier AS in_carrier, ti.reference AS in_ref,
            to_.depart_time
     FROM placements p
     JOIN candidates c ON c.id = p.candidate_id
     LEFT JOIN assignment_details d ON d.placement_id = p.id
     LEFT JOIN job_order_lines ol ON ol.id = p.order_line_id
     LEFT JOIN lodging l ON l.placement_id = p.id AND l.status <> 'cancelled'
     LEFT JOIN hotels  h ON h.id = l.hotel_id
     LEFT JOIN travel ti ON ti.placement_id = p.id AND ti.direction = 'inbound'  AND ti.status <> 'cancelled'
     LEFT JOIN travel to_ ON to_.placement_id = p.id AND to_.direction = 'outbound' AND to_.status <> 'cancelled'
     WHERE p.job_id = ?
     ORDER BY FIELD(p.status,'on_site','travelling','confirmed','offered','completed','cancelled'),
              c.full_name", [$jobId]);

// How each closed assignment was graded, so the roster can show which
// ones nobody has said anything about yet.
// P4-M07: one query for the project's reviews (one per assignment at most),
// not one per person: a 1,000-person crew made 1,000 lookups per view.
$reviews = [];

try {
    $projectReviews = rows('SELECT r.*, u.name AS reviewer
                            FROM assignment_reviews r
                            JOIN placements p ON p.id = r.placement_id
                            LEFT JOIN users u ON u.id = r.reviewed_by
                            WHERE p.job_id = ?', [$jobId]);
} catch (Throwable $e) {
    $projectReviews = [];
}

foreach ($projectReviews as $found) {
    $found['scores'] = assignment_review_scores((int) $found['id']);
    $reviews[(int) $found['placement_id']] = $found;
}

// What stands between each person and the site, for the only status where
// it decides anything. Before this, a recruiter picked a status, lost the
// page and learned one missing item at a time.
$blockers = [];

foreach ($crew as $member) {
    if ($member['status'] === 'offered') {
        $blockers[(int) $member['id']] = deployment_blockers(
            (int) $member['id'], (int) $member['candidate_id'], $jobId);
    }
}

$pageTitle = t('Roster').' · '.$config['app_name'];
render('roster', compact('blockers', 'job','crew', 'reviews'));
