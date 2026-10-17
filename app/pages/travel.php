<?php
/**
 * Travel - flights in and out, the airport pickup, and the scheduled runs.
 *
 * RSS promised transport from the airport to the hotel, to and from the job
 * site, and runs for food, Walmart, laundry and haircuts. Those are different
 * things: a flight belongs to one person, a shuttle belongs to a day.
 */

require_role('hotels');
require_once __DIR__ . '/../travel-details.php';

$job = current_job();
$jobId = (int) ($job['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';

    if ($do === 'book_travel') {
        $pid = (int) ($_POST['placement_id'] ?? 0);
        $dir = ($_POST['direction'] ?? 'inbound') === 'outbound' ? 'outbound' : 'inbound';

        $p = row('SELECT p.id, c.full_name FROM placements p
                  JOIN candidates c ON c.id = p.candidate_id WHERE p.id = ? AND p.job_id = ?', [$pid, $jobId]);

        if (! $p) {
            flash(t('That placement no longer exists.'), 'err');
            redirect('/travel');
        }

        // P5-M30: the form offered "Rail" and "Agency vehicle", which the table does not hold.
        $mode = array_key_exists((string) ($_POST['mode'] ?? ''), TRAVEL_MODES) ? (string) $_POST['mode'] : 'flight';
        $ticket = trim(str_replace("\r\n", "\n", (string) ($_POST['ticket_details'] ?? '')));
        if (mb_strlen($ticket) > 5000) {
            refuse(422, t('Paste at most 5000 characters of the ticket.'), '/travel');
        }
        $fields = [
            $pid, $dir,
            $mode,
            trim((string) ($_POST['carrier'] ?? '')) ?: null,
            trim((string) ($_POST['reference'] ?? '')) ?: null,
            trim((string) ($_POST['depart_from'] ?? '')) ?: null,
            trim((string) ($_POST['arrive_at'] ?? '')) ?: null,
            ($_POST['depart_time'] ?? '') !== '' ? str_replace('T', ' ', (string) $_POST['depart_time']) . ':00' : null,
            ($_POST['arrive_time'] ?? '') !== '' ? str_replace('T', ' ', (string) $_POST['arrive_time']) . ':00' : null,
            ($_POST['cost'] ?? '') !== '' ? (float) $_POST['cost'] : null,
            isset($_POST['pickup_needed']) ? 1 : 0,
            $ticket !== '' ? $ticket : null,
        ];

        $existing = row('SELECT id FROM travel WHERE placement_id = ? AND direction = ? AND status <> ?',
                        [$pid, $dir, 'cancelled']);

        if ($existing) {
            q('UPDATE travel SET mode=?, carrier=?, reference=?, depart_from=?, arrive_at=?,
               depart_time=?, arrive_time=?, cost=?, pickup_needed=?, ticket_details=?, status=? WHERE id=?',
              [...array_slice($fields, 2), 'booked', $existing['id']]);
            $travelId = (int) $existing['id'];
        } else {
            q('INSERT INTO travel (placement_id, direction, mode, carrier, reference, depart_from,
                                   arrive_at, depart_time, arrive_time, cost, pickup_needed, ticket_details, status)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [...$fields, 'booked']);
            $travelId = (int) db()->lastInsertId();
        }
        // P5-M30: the worker is told, in the portal and by email, with the ticket and the advice.
        $emailed = isset($_POST['send_itinerary']) && tp_send_itinerary($travelId);
        if (! isset($_POST['send_itinerary'])) {
            notify_candidate((int) val('SELECT candidate_id FROM placements WHERE id = ?', [$pid]), t('Your travel for :project is booked. Your itinerary is in your portal.', ['project' => $job['title']]), '/portal#trip');
        }

        // Somebody with a flight booked is travelling, not merely confirmed.


        log_activity('booked travel', 'placement', $pid, $p['full_name'] . ' ' . $dir);
        flash(t('Travel saved for :name.', ['name'=>$p['full_name']]) . ' ' . ($emailed ? t('The itinerary is on its way to them by email, and in their portal.') : t('They see it in their portal.')));
        redirect('/travel');
    }

    // P5-M30: the advice for travellers, written by the operations VP (an administrator).
    if (in_array($do, ['advice_add', 'advice_remove', 'advice_usual'], true)) {
        if (! can('admin')) {
            refuse(403, t('The operations VP writes the advice for travellers.'), '/travel');
        }
        if ($do === 'advice_add') {
            $text = trim((string) ($_POST['body'] ?? ''));
            if (mb_strlen($text) < 5 || mb_strlen($text) > 300) {
                refuse(422, t('Write the advice in 5 to 300 characters.'), '/travel');
            }
            q('INSERT INTO travel_advisories (job_id, body, sort_order, created_by) VALUES (?,?,?,?)',
              [$jobId, $text, (int) val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM travel_advisories WHERE job_id = ?', [$jobId]), uid()]);
        } elseif ($do === 'advice_remove') {
            q('DELETE FROM travel_advisories WHERE id = ? AND job_id = ?', [(int) ($_POST['advice_id'] ?? 0), $jobId]);
        } elseif (! val('SELECT COUNT(*) FROM travel_advisories WHERE job_id = ?', [$jobId])) {
            foreach (tp_usual_advice() as $i => $text) {
                q('INSERT INTO travel_advisories (job_id, body, sort_order, created_by) VALUES (?,?,?,?)', [$jobId, t($text), $i + 1, uid()]);
            }
        }
        log_activity('changed the advice for travellers', 'job', $jobId);
        flash(t('Advice for travellers saved. Every itinerary from now on carries it.'));
        redirect('/travel#advice');
    }

    if ($do === 'add_run') {
        q('INSERT INTO shuttle_runs (job_id, purpose, runs_on, depart_time, return_time, pickup_point, driver, seats, notes)
           VALUES (?,?,?,?,?,?,?,?,?)', [
            $jobId,
            (string) ($_POST['purpose'] ?? 'site'),
            (string) ($_POST['runs_on'] ?? date('Y-m-d')),
            ($_POST['depart_time'] ?? '') ?: null,
            ($_POST['return_time'] ?? '') ?: null,
            trim((string) ($_POST['pickup_point'] ?? '')) ?: null,
            trim((string) ($_POST['driver'] ?? '')) ?: null,
            ($_POST['seats'] ?? '') !== '' ? (int) $_POST['seats'] : null,
            trim((string) ($_POST['notes'] ?? '')) ?: null,
        ]);

        flash(t('Run added.'));
        redirect('/travel');
    }
}

$needTravel = rows(
    "SELECT p.id, p.candidate_id, c.full_name, c.discipline, COALESCE(NULLIF(d.trade, ''), ol.role_title) AS trade, c.city, c.state, p.status, p.start_date
     FROM placements p
     JOIN candidates c ON c.id = p.candidate_id
     LEFT JOIN assignment_details d ON d.placement_id = p.id
     LEFT JOIN job_order_lines ol ON ol.id = p.order_line_id
     LEFT JOIN travel t ON t.placement_id = p.id AND t.direction='inbound' AND t.status <> 'cancelled'
     WHERE p.job_id = ? AND p.status IN ('confirmed','travelling') AND t.id IS NULL
     ORDER BY p.start_date IS NULL, p.start_date, c.full_name", [$jobId]);

// P5-M97: sent home - the flights home still to book.
$needHome = rows(
    "SELECT t.id AS leg_id, t.depart_from, t.arrive_at, p.id, p.candidate_id, p.end_date, c.full_name
     FROM travel t JOIN placements p ON p.id = t.placement_id JOIN candidates c ON c.id = p.candidate_id
     WHERE p.job_id = ? AND t.direction = 'outbound' AND t.status = 'planned'
     ORDER BY p.end_date, c.full_name", [$jobId]);

$booked = rows(
    "SELECT t.*, c.full_name, c.discipline
     FROM travel t
     JOIN placements p ON p.id = t.placement_id
     JOIN candidates c ON c.id = p.candidate_id
     WHERE p.job_id = ? AND t.status <> 'cancelled'
     ORDER BY t.direction, t.arrive_time IS NULL, t.arrive_time", [$jobId]);

$runs = rows('SELECT * FROM shuttle_runs WHERE job_id = ? AND runs_on >= CURDATE() - INTERVAL 1 DAY
              ORDER BY runs_on, depart_time LIMIT 40', [$jobId]);

// P5-M30: what each worker entered for the booking.
foreach ($needTravel as &$n) {
    $n['profile'] = tp_profile((int) $n['candidate_id']);
    // P5-M98: the airport they gave when applying, until they fill their travel details.
    $n['home_airport'] = (string) (val('SELECT home_airport FROM candidates WHERE id = ?', [(int) $n['candidate_id']]) ?? '');
}
unset($n);
$advice = tp_advisories($jobId);
$adviceIsOwn = $advice && $advice[0]['job_id'] !== null;

$pageTitle = t('Travel').' · '.$config['app_name'];
render('travel', compact('job','needTravel','booked','runs','advice','adviceIsOwn', 'needHome'));
