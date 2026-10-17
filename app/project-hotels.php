<?php
/**
 * P5-M76: the hotels of a project (RSS, 17 Oct 2026: "a project could lodge in multiple hotels… they can book
 * twenty hotels and fill them up… the system needs to capture all of those").
 *
 * A project has as many hotels as it needs. For each: the rooms held for this project, the dates the block is
 * held, the cost per night agreed with the hotel, and - when the client agreed it - the price billed per night
 * for a room there. Everything is optional: the rooms are often not known until the crew is accepted, and the
 * rate can wait until the room is booked. A booking for the project counts against the project's block.
 */

declare(strict_types=1);

/** The project's hotels, with how many of their rooms this project's crew is in now. */
function ph_hotels(int $jobId): array
{
    return rows("SELECT jh.*, h.name, h.address, h.city, h.state, h.phone, h.confirmation_contact, h.nightly_rate AS hotel_rate,
                        (SELECT COUNT(*) FROM lodging l JOIN placements p ON p.id = l.placement_id
                          WHERE l.hotel_id = jh.hotel_id AND p.job_id = jh.job_id AND l.status IN ('held','booked','checked_in')) AS taken
                 FROM job_hotels jh JOIN hotels h ON h.id = jh.hotel_id WHERE jh.job_id = ? ORDER BY h.name", [$jobId]);
}

/** Links a hotel to the project, or changes its block. Returns the refusal, or null. */
function ph_link(int $jobId, int $hotelId, array $in): ?string
{
    $held = trim((string) ($in['rooms_held'] ?? ''));
    if ($held !== '' && (! ctype_digit($held) || (int) $held > 2000)) {
        return t('The block of rooms must be a whole number up to 2000.');
    }
    $from = (string) ($in['block_starts'] ?? '');
    $to = (string) ($in['block_ends'] ?? '');
    if (($from !== '' && ! valid_date($from)) || ($to !== '' && ! valid_date($to)) || ($from !== '' && $to !== '' && $to < $from)) {
        return t('Check the dates the block is held for.');
    }
    $money = static function (string $k) use ($in): float|null|false {
        $v = trim((string) ($in[$k] ?? ''));
        return $v === '' ? null : (is_numeric($v) && (float) $v >= 0 && (float) $v <= 5000 ? round((float) $v, 2) : false);
    };
    $cost = $money('nightly_rate');
    $billed = $money('client_night_rate');
    if ($cost === false || $billed === false) {
        return t('A rate per night is in dollars, up to 5,000.');
    }
    if ($held !== '' && ($taken = (int) val("SELECT COUNT(*) FROM lodging l JOIN placements p ON p.id = l.placement_id
                                            WHERE l.hotel_id = ? AND p.job_id = ? AND l.status IN ('held','booked','checked_in')", [$hotelId, $jobId])) > (int) $held) {
        return t(':n of this project\'s crew are already in this hotel: the block cannot be smaller.', ['n' => $taken]);
    }
    q('INSERT INTO job_hotels (job_id, hotel_id, rooms_held, block_starts, block_ends, nightly_rate, client_night_rate, notes)
       VALUES (?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE rooms_held = VALUES(rooms_held), block_starts = VALUES(block_starts), block_ends = VALUES(block_ends),
                               nightly_rate = VALUES(nightly_rate), client_night_rate = VALUES(client_night_rate), notes = VALUES(notes)',
      [$jobId, $hotelId, $held !== '' ? (int) $held : null, $from ?: null, $to ?: null, $cost, $billed,
       mb_substr(trim((string) ($in['notes'] ?? '')), 0, 500) ?: null]);

    return null;
}

/** Takes a hotel off the project; refused while some of the crew are booked there. */
function ph_unlink(int $jobId, int $hotelId): ?string
{
    $taken = (int) val("SELECT COUNT(*) FROM lodging l JOIN placements p ON p.id = l.placement_id
                        WHERE l.hotel_id = ? AND p.job_id = ? AND l.status IN ('held','booked','checked_in')", [$hotelId, $jobId]);
    if ($taken) {
        return t(':n of this project\'s crew are booked in this hotel. Move them first.', ['n' => $taken]);
    }
    q('DELETE FROM job_hotels WHERE job_id = ? AND hotel_id = ?', [$jobId, $hotelId]);

    return null;
}
