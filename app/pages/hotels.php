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

        log_activity('added a hotel', 'hotel', (int) db()->lastInsertId(), $name);
        flash(t(':name added.', ['name'=>$name]));
        redirect('/hotels');
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

        if (!valid_date($in) || !valid_date($out) || $out <= $in || !$priv || $room === '') {
            flash(t('A private room, room number, and valid check-in/out dates are required.'), 'err'); redirect('/hotels');
        }
        db()->beginTransaction();
        q('SELECT id FROM hotels WHERE id=? FOR UPDATE', [$hid]);

        // Checked inside the lock: two recruiters booking the last room at
        // the same moment would otherwise both be told there was one.
        $heldBlock = val('SELECT rooms_held FROM hotels WHERE id = ?', [$hid]);

        if ($heldBlock !== null) {
            $taken = (int) val('SELECT COUNT(*) FROM lodging
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
    "SELECT p.id, c.full_name, c.discipline, d.trade, p.status, p.start_date, p.end_date
     FROM placements p
     JOIN candidates c ON c.id = p.candidate_id
     LEFT JOIN assignment_details d ON d.placement_id = p.id
     LEFT JOIN lodging l ON l.placement_id = p.id AND l.status IN ('held','booked','checked_in')
     WHERE p.job_id = ? AND p.status IN ('confirmed','travelling','on_site') AND l.id IS NULL
     ORDER BY p.start_date IS NULL, p.start_date, c.full_name", [$jobId]);

$sharing = array_values(array_filter($booked, static fn ($b) => (int) $b['private_room'] === 0));

$pageTitle = t('Hotels').' · '.$config['app_name'];
render('hotels', compact('job','hotels','booked','needBed','sharing'));
