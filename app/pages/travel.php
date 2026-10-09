<?php
/**
 * Travel - flights in and out, the airport pickup, and the scheduled runs.
 *
 * RSS promised transport from the airport to the hotel, to and from the job
 * site, and runs for food, Walmart, laundry and haircuts. Those are different
 * things: a flight belongs to one person, a shuttle belongs to a day.
 */

require_role('hotels');

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

        $fields = [
            $pid, $dir,
            (string) ($_POST['mode'] ?? 'flight'),
            trim((string) ($_POST['carrier'] ?? '')) ?: null,
            trim((string) ($_POST['reference'] ?? '')) ?: null,
            trim((string) ($_POST['depart_from'] ?? '')) ?: null,
            trim((string) ($_POST['arrive_at'] ?? '')) ?: null,
            ($_POST['depart_time'] ?? '') !== '' ? str_replace('T', ' ', (string) $_POST['depart_time']) . ':00' : null,
            ($_POST['arrive_time'] ?? '') !== '' ? str_replace('T', ' ', (string) $_POST['arrive_time']) . ':00' : null,
            ($_POST['cost'] ?? '') !== '' ? (float) $_POST['cost'] : null,
            isset($_POST['pickup_needed']) ? 1 : 0,
        ];

        $existing = row('SELECT id FROM travel WHERE placement_id = ? AND direction = ? AND status <> ?',
                        [$pid, $dir, 'cancelled']);

        if ($existing) {
            q('UPDATE travel SET mode=?, carrier=?, reference=?, depart_from=?, arrive_at=?,
               depart_time=?, arrive_time=?, cost=?, pickup_needed=?, status=? WHERE id=?',
              [...array_slice($fields, 2), 'booked', $existing['id']]);
        } else {
            q('INSERT INTO travel (placement_id, direction, mode, carrier, reference, depart_from,
                                   arrive_at, depart_time, arrive_time, cost, pickup_needed, status)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [...$fields, 'booked']);
        }

        // Somebody with a flight booked is travelling, not merely confirmed.


        log_activity('booked travel', 'placement', $pid, $p['full_name'] . ' ' . $dir);
        flash(t('Travel saved for :name.', ['name'=>$p['full_name']]));
        redirect('/travel');
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
    "SELECT p.id, c.full_name, c.discipline, c.city, c.state, p.status, p.start_date
     FROM placements p
     JOIN candidates c ON c.id = p.candidate_id
     LEFT JOIN travel t ON t.placement_id = p.id AND t.direction='inbound' AND t.status <> 'cancelled'
     WHERE p.job_id = ? AND p.status IN ('confirmed','travelling') AND t.id IS NULL
     ORDER BY p.start_date IS NULL, p.start_date, c.full_name", [$jobId]);

$booked = rows(
    "SELECT t.*, c.full_name, c.discipline
     FROM travel t
     JOIN placements p ON p.id = t.placement_id
     JOIN candidates c ON c.id = p.candidate_id
     WHERE p.job_id = ? AND t.status <> 'cancelled'
     ORDER BY t.direction, t.arrive_time IS NULL, t.arrive_time", [$jobId]);

$runs = rows('SELECT * FROM shuttle_runs WHERE job_id = ? AND runs_on >= CURDATE() - INTERVAL 1 DAY
              ORDER BY runs_on, depart_time LIMIT 40', [$jobId]);

$pageTitle = t('Travel').' · '.$config['app_name'];
render('travel', compact('job','needTravel','booked','runs'));
