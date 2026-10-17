<?php
/**
 * P5-M85: Flight arrivals - who lands when and where, for the people who send someone to the airport (RSS: "just
 * a page that says flight arrivals... they'll send a person to go pick them up"). A person counts as arrived once
 * their W-4 or I-9 has been signed in person, which only happens in front of HR or the supervisor.
 */

require_role('hotels', 'recruiter', 'payroll');

$days = in_array((int) ($_GET['days'] ?? 14), [7, 14, 30], true) ? (int) ($_GET['days'] ?? 14) : 14;
$jobId = (int) (current_job()['id'] ?? 0);
$all = ! empty($_GET['all']);
$arrivals = rows("SELECT t.*, p.id AS placement_id, p.start_date, c.id AS candidate_id, c.full_name, c.phone, j.title AS job,
                         (SELECT h.name FROM lodging l JOIN hotels h ON h.id = l.hotel_id WHERE l.placement_id = p.id AND l.status IN ('held','booked','checked_in') ORDER BY l.id DESC LIMIT 1) AS hotel,
                         (SELECT MIN(f.signed_at) FROM worker_tax_forms f WHERE f.candidate_id = c.id AND f.signed_at >= DATE(COALESCE(t.arrive_time, t.depart_time)) AND f.status <> 'replaced') AS arrived_at
                  FROM travel t JOIN placements p ON p.id = t.placement_id JOIN candidates c ON c.id = p.candidate_id JOIN jobs j ON j.id = p.job_id
                  WHERE t.direction = 'inbound' AND t.status IN ('planned','booked','completed')
                    AND COALESCE(t.arrive_time, t.depart_time) >= DATE_SUB(CURDATE(), INTERVAL 2 DAY)
                    AND COALESCE(t.arrive_time, t.depart_time) < DATE_ADD(CURDATE(), INTERVAL ? DAY)" . ($all || ! $jobId ? '' : ' AND p.job_id = ' . $jobId) . "
                  ORDER BY COALESCE(t.arrive_time, t.depart_time), c.full_name", [$days]);
$byDay = [];
foreach ($arrivals as $a) {
    $byDay[substr((string) ($a['arrive_time'] ?? $a['depart_time']), 0, 10)][] = $a;
}
$pageTitle = t('Flight arrivals') . ' · ' . $config['app_name'];
render('arrivals', compact('byDay', 'days', 'all', 'jobId'));
