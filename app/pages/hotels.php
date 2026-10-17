<?php
/**
 * Hotels - Sharesa's desk.
 *
 * RSS promised every engineer their own room, in writing, in the email that
 * recruited them. So this screen leads with who has no bed and who is sharing
 * one, because those are the two ways that promise gets broken.
 */

require_role('hotels');

$job = current_job();
$jobId = (int) ($job['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';

    if ($do === 'add_hotel') {
        $name = trim((string) ($_POST['name'] ?? ''));

        if ($name === '') {
            flash(t('A hotel needs a name.'), 'err');
            redirect('/hotels');
        }

        $held = trim((string) ($_POST['rooms_held'] ?? ''));

        if ($held !== '' && (! ctype_digit($held) || (int) $held > 2000)) {
            flash(t('The block of rooms must be a whole number up to 2000.'), 'err');
            redirect('/hotels');
        }

        $blockStarts = (string) ($_POST['block_starts'] ?? '');
        $blockEnds   = (string) ($_POST['block_ends'] ?? '');

        if (($blockStarts !== '' && ! valid_date($blockStarts))
            || ($blockEnds !== '' && ! valid_date($blockEnds))
            || ($blockStarts !== '' && $blockEnds !== '' && $blockEnds < $blockStarts)) {
            flash(t('Check the dates the block is held for.'), 'err');
            redirect('/hotels');
        }

        q('INSERT INTO hotels (name, address, city, state, phone, nightly_rate,
                               confirmation_contact, rooms_held, block_starts, block_ends)
           VALUES (?,?,?,?,?,?,?,?,?,?)', [
            $name,
            trim((string) ($_POST['address'] ?? '')) ?: null,
            trim((string) ($_POST['city'] ?? '')) ?: null,
            trim((string) ($_POST['state'] ?? '')) ?: null,
            trim((string) ($_POST['phone'] ?? '')) ?: null,
            ($_POST['nightly_rate'] ?? '') !== '' ? (float) $_POST['nightly_rate'] : null,
            trim((string) ($_POST['confirmation_contact'] ?? '')) ?: null,
            $held !== '' ? (int) $held : null,
            $blockStarts ?: null,
            $blockEnds ?: null,
        ]);

        $newHotel = (int) db()->lastInsertId();
        log_activity('added a hotel', 'hotel', $newHotel, $name);
        // P5-M76: a hotel added while a project is open is that project's, with the block typed.
        if ($jobId) {
            require_once __DIR__ . '/../project-hotels.php';
            ph_link($jobId, $newHotel, $_POST);
        }
        flash(t(':name added.', ['name'=>$name]));
        redirect('/hotels#project-hotels');
    }

    // ── P5-M76: the project's hotels - several, each with its own block, dates, cost and price to the client ──
    if (in_array($do, ['link_hotel', 'update_link', 'unlink_hotel'], true)) {
        require_once __DIR__ . '/../project-hotels.php';
        $hid = (int) ($_POST['hotel_id'] ?? 0);
        if (! $jobId || ! val('SELECT id FROM hotels WHERE id = ?', [$hid])) {
            refuse(422, t('Choose a hotel, with a project open.'), '/hotels#project-hotels');
        }
        if ($do === 'unlink_hotel') {
            $why = ph_unlink($jobId, $hid);
        } else {
            $why = ph_link($jobId, $hid, $_POST);
        }
        if ($why) {
            refuse(422, $why, '/hotels#project-hotels');
        }
        log_activity($do === 'unlink_hotel' ? 'removed a hotel from the project' : 'set a hotel of the project', 'job', $jobId, (string) val('SELECT name FROM hotels WHERE id = ?', [$hid]));
        flash($do === 'unlink_hotel' ? t('Hotel removed from this project.') : t('Saved: the hotel of this project and its block.'));
        redirect('/hotels#project-hotels');
    }

    if ($do === 'book') {
        $pid   = (int) ($_POST['placement_id'] ?? 0);
        $hid   = (int) ($_POST['hotel_id'] ?? 0);
        $room  = trim((string) ($_POST['room_number'] ?? ''));
        $in    = (string) ($_POST['check_in'] ?? '');
        $out   = (string) ($_POST['check_out'] ?? '');
        $priv  = isset($_POST['private_room']) ? 1 : 0;
        $conf  = trim((string) ($_POST['confirmation'] ?? ''));

        $p = row('SELECT p.id, c.full_name FROM placements p
                  JOIN candidates c ON c.id = p.candidate_id WHERE p.id = ? AND p.job_id = ?', [$pid, $jobId]);
        $h = row('SELECT id, name, nightly_rate FROM hotels WHERE id = ?', [$hid]);

        if (! $p || ! $h) {
            flash(t('Pick both a person and a hotel.'), 'err');
            redirect('/hotels');
        }

        // P5-M32: the room number is optional here; the hotel gives it at check-in and the worker enters it.
        if (!valid_date($in) || !valid_date($out) || $out <= $in || !$priv || ($room !== '' && ! preg_match('/^[A-Za-z0-9 -]{1,12}$/D', $room))) {
            flash(t('A private room and valid check-in/out dates are required; a room number is letters and digits.'), 'err'); redirect('/hotels');
        }
        db()->beginTransaction();
        q('SELECT id FROM hotels WHERE id=? FOR UPDATE', [$hid]);

        // Checked inside the lock: two recruiters booking the last room at
        // the same moment would otherwise both be told there was one.
        // P5-M76: the block held for this project at this hotel when it has one; the hotel's own otherwise.
        $link = row('SELECT rooms_held, nightly_rate FROM job_hotels WHERE job_id = ? AND hotel_id = ?', [$jobId, $hid]);
        if ($link && $link['nightly_rate'] !== null) {
            $h['nightly_rate'] = $link['nightly_rate'];
        }
        $heldBlock = $link ? $link['rooms_held'] : val('SELECT rooms_held FROM hotels WHERE id = ?', [$hid]);

        if ($heldBlock !== null) {
            $taken = $link
                ? (int) val("SELECT COUNT(*) FROM lodging l JOIN placements p ON p.id = l.placement_id
                             WHERE l.hotel_id = ? AND p.job_id = ? AND l.placement_id <> ? AND l.status IN ('held','booked','checked_in')", [$hid, $jobId, $pid])
                : (int) val('SELECT COUNT(*) FROM lodging
                                WHERE hotel_id = ? AND status IN (?,?,?)',
                               [$hid, 'held', 'booked', 'checked_in']);

            if ($taken >= (int) $heldBlock) {
                db()->rollBack();
                flash(t('All :n rooms held at :hotel are taken. Hold more rooms there, or book somewhere else.',
                        ['n' => (int) $heldBlock, 'hotel' => $h['name']]), 'err');
                redirect('/hotels');
            }
        }
        // Two people in one room is allowed to happen - sometimes there is no
        // choice - but it is recorded as a broken promise, not hidden.
        if ($room !== '') {
            $clash = row(
                "SELECT l.id, c.full_name FROM lodging l
                 JOIN placements p2 ON p2.id = l.placement_id
                 JOIN candidates c  ON c.id = p2.candidate_id
                 WHERE l.hotel_id = ? AND l.room_number = ? AND l.placement_id <> ?
                   AND l.status IN ('held','booked','checked_in')
                   AND (l.check_in IS NULL OR l.check_in < ?) AND (l.check_out IS NULL OR l.check_out > ?)",
                [$hid, $room, $pid, $out, $in]);

            if ($clash) {
                db()->rollBack();
                flash(t('Room :room already has :name during these dates. Choose another room.', ['room'=>$room,'name'=>$clash['full_name']]), 'err');
                redirect('/hotels');
            }
        }

        $existing = row("SELECT id FROM lodging WHERE placement_id = ? AND status IN ('held','booked','checked_in') ORDER BY id DESC LIMIT 1", [$pid]);

        if ($existing) {
            q('UPDATE lodging SET hotel_id=?, room_number=?, check_in=?, check_out=?,
               private_room=?, confirmation=?, nightly_rate=? WHERE id=?',
              [$hid, $room ?: null, $in ?: null, $out ?: null, $priv, $conf ?: null,
               $h['nightly_rate'], $existing['id']]);
        } else {
            q('INSERT INTO lodging (placement_id, hotel_id, room_number, check_in, check_out,
                                    private_room, confirmation, nightly_rate, status)
               VALUES (?,?,?,?,?,?,?,?,?)',
              [$pid, $hid, $room ?: null, $in ?: null, $out ?: null, $priv, $conf ?: null,
               $h['nightly_rate'], 'booked']);
        }

        // P5-M32: the worker is told their hotel; without a room number they cannot clock in.
        q('UPDATE lodging SET room_told_at = NOW() WHERE placement_id = ? AND status IN (\'held\',\'booked\',\'checked_in\')', [$pid]);
        require_once __DIR__ . '/../notices.php';
        notify_candidate((int) val('SELECT candidate_id FROM placements WHERE id = ?', [$pid]), $room !== ''
            ? t('Your hotel: :hotel, room :room, from :date.', ['hotel' => $h['name'], 'room' => $room, 'date' => $in ? d($in) : '—'])
            : t('Your hotel: :hotel, from :date. When you check in, enter your room number in your portal within 4 hours: you cannot clock in without it.', ['hotel' => $h['name'], 'date' => $in ? d($in) : '—']), '/portal#clock');
        require_once __DIR__ . '/../procurement.php';
        procurement_lodging_booked($pid);
        db()->commit();
        log_activity('booked a room', 'placement', $pid, $p['full_name'] . ' at ' . $h['name'] . ' room ' . $room);
        flash(t($room!==''?':name booked into :hotel, room :room.':':name booked into :hotel.', ['name'=>$p['full_name'],'hotel'=>$h['name'],'room'=>$room]));
        redirect('/hotels');
    }
}

$hotels = rows('SELECT h.*,
                (SELECT COUNT(*) FROM lodging l
                  WHERE l.hotel_id = h.id
                    AND l.status IN ("held","booked","checked_in")) AS rooms
                FROM hotels h ORDER BY h.name');

// How much of each block is left, worked out once so the board, the
// booking form and the refusal all quote the same number.
foreach ($hotels as $i => $h) {
    $hotels[$i]['left'] = $h['rooms_held'] === null
        ? null
        : max(0, (int) $h['rooms_held'] - (int) $h['rooms']);
}

$booked = rows(
    "SELECT l.*, c.full_name, c.discipline, h.name AS hotel, p.status AS placement_status
     FROM lodging l
     JOIN placements p ON p.id = l.placement_id
     JOIN candidates c ON c.id = p.candidate_id
     JOIN hotels h     ON h.id = l.hotel_id
     WHERE p.job_id = ? AND l.status IN ('held','booked','checked_in')
     ORDER BY h.name, l.room_number + 0, l.room_number", [$jobId]);

// Anybody who is coming, or already here, and has nowhere to sleep.
$needBed = rows(
    "SELECT p.id, c.full_name, c.discipline, COALESCE(NULLIF(d.trade, ''), ol.role_title) AS trade, p.status, p.start_date, p.end_date
     FROM placements p
     JOIN candidates c ON c.id = p.candidate_id
     LEFT JOIN assignment_details d ON d.placement_id = p.id
     LEFT JOIN job_order_lines ol ON ol.id = p.order_line_id
     LEFT JOIN lodging l ON l.placement_id = p.id AND l.status IN ('held','booked','checked_in')
     WHERE p.job_id = ? AND p.status IN ('confirmed','travelling','on_site') AND l.id IS NULL
     ORDER BY p.start_date IS NULL, p.start_date, c.full_name", [$jobId]);

$sharing = array_values(array_filter($booked, static fn ($b) => (int) $b['private_room'] === 0));

// P5-M76: the hotels of this project, each with its block and how much of it is taken by this project's crew.
require_once __DIR__ . '/../project-hotels.php';
$projectHotels = $jobId ? ph_hotels($jobId) : [];
$linkedIds = array_map('intval', array_column($projectHotels, 'hotel_id'));
$otherHotels = array_values(array_filter($hotels, static fn ($h) => ! in_array((int) $h['id'], $linkedIds, true)));

$pageTitle = t('Hotels').' · '.$config['app_name'];
render('hotels', compact('job','hotels','booked','needBed','sharing','projectHotels','otherHotels'));
