<?php
/**
 * Weekly hotel folio reconciliation (T20, R30): "She goes through the hotel
 * folios, because that's got to be turned in every week."
 *
 * A folio is one hotel's statement for one project and one week (Sunday to
 * Saturday, the agency's pay week). Opening it fills one room line per
 * guest from the bookings already in Crewvia - nights that week and the
 * booked rate - so the desk only corrects what the hotel actually billed
 * and adds its taxes, its incidentals, and any guest Crewvia did not book.
 *
 * Every room line is checked against its booking: more or fewer nights,
 * another rate, a guest who is not ours. Each such difference needs a note
 * before the folio is reconciled. A room billed on nights the worker was
 * marked absent at roll call is pointed out too, for the per diem question.
 * Each incidental is charged to the agency, the client (to rebill) or the
 * worker; a worker's charge is shown on their portal with its description,
 * and payroll recovers it by an adjustment they approve - never on its own.
 *
 * A reconciled folio becomes the hotel's bill in Payables in one step, and
 * only once.
 */

declare(strict_types=1);

/** The seven days of the pay week ending on $weekEnding. @return string[] */
function fo_week_days(string $weekEnding): array
{
    return array_map(static fn(int $i) => date('Y-m-d', strtotime($weekEnding . ' -' . (6 - $i) . ' days')), range(0, 6));
}

/** The nights of a stay inside the week: the dates slept there, check-out day excluded. @return string[] */
function fo_nights(array $lodging, string $weekEnding): array
{
    if (empty($lodging['check_in'])) {
        return [];
    }
    $out = (string) ($lodging['check_out'] ?: date('Y-m-d', strtotime($weekEnding . ' +1 day')));

    return array_values(array_filter(fo_week_days($weekEnding), static fn(string $d) => $d >= $lodging['check_in'] && $d < $out));
}

/** The bookings at a hotel for a project that touch the week, with their nights and rate. */
function fo_bookings(int $jobId, int $hotelId, string $weekEnding): array
{
    $days = fo_week_days($weekEnding);
    $out = [];
    foreach (rows("SELECT l.*, c.full_name, COALESCE(l.nightly_rate, h.nightly_rate) AS rate_booked
                   FROM lodging l JOIN placements p ON p.id = l.placement_id JOIN candidates c ON c.id = p.candidate_id JOIN hotels h ON h.id = l.hotel_id
                   WHERE p.job_id = ? AND l.hotel_id = ? AND l.status <> 'cancelled' AND l.check_in IS NOT NULL
                     AND l.check_in <= ? AND (l.check_out IS NULL OR l.check_out > ?)
                   ORDER BY c.full_name, l.id", [$jobId, $hotelId, $days[6], $days[0]]) as $l) {
        $l['nights_booked'] = fo_nights($l, $weekEnding);
        if ($l['nights_booked']) {
            $out[] = $l;
        }
    }

    return $out;
}

function fo_folio(int $id, int $jobId): ?array
{
    return row('SELECT f.*, h.name AS hotel, v.reference AS bill_reference, v.status AS bill_status FROM hotel_folios f JOIN hotels h ON h.id = f.hotel_id
                LEFT JOIN vendor_invoices v ON v.id = f.vendor_invoice_id WHERE f.id = ? AND f.job_id = ?', [$id, $jobId]) ?: null;
}

/** Opens a folio and fills its room lines from the bookings. @return array{0:?string,1:int} refusal, folio id */
function fo_open(int $jobId, int $hotelId, string $week, string $reference, string $dueOn, int $by): array
{
    $reference = trim($reference);
    if (! $jobId) {
        return [t('Choose a working project first.'), 0];
    }
    if (! row('SELECT id FROM hotels WHERE id = ?', [$hotelId])) {
        return [t('Choose a hotel.'), 0];
    }
    if (! valid_date($week)) {
        return [t('Choose the week.'), 0];
    }
    $weekEnding = week_ending($week);
    if ($weekEnding > week_ending(date('Y-m-d'))) {
        return [t('A folio is for a week that has started.'), 0];
    }
    if ($reference === '' || mb_strlen($reference) > 190) {
        return [t('Enter the folio number the hotel printed on it.'), 0];
    }
    if ($dueOn !== '' && ! valid_date($dueOn)) {
        return [t('The due date is not a date.'), 0];
    }
    if ($id = (int) val('SELECT id FROM hotel_folios WHERE job_id = ? AND hotel_id = ? AND week_ending = ?', [$jobId, $hotelId, $weekEnding])) {
        return [t('This hotel already has a folio for that week on this project.'), $id];
    }
    db()->beginTransaction();
    try {
        q('INSERT INTO hotel_folios (job_id, hotel_id, week_ending, reference, due_on, created_by) VALUES (?,?,?,?,?,?)', [$jobId, $hotelId, $weekEnding, $reference, $dueOn ?: null, $by]);
        $id = (int) db()->lastInsertId();
        foreach (fo_bookings($jobId, $hotelId, $weekEnding) as $b) {
            $nights = count($b['nights_booked']);
            $rate = $b['rate_booked'] !== null ? (float) $b['rate_booked'] : 0.0;
            q("INSERT INTO hotel_folio_lines (folio_id, kind, lodging_id, placement_id, guest_name, room_number, nights, rate, amount, created_by) VALUES (?,'room',?,?,?,?,?,?,?,?)",
              [$id, (int) $b['id'], (int) $b['placement_id'], $b['full_name'], $b['room_number'], $nights, $rate, round($nights * $rate, 2), $by]);
        }
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) { db()->rollBack(); }
        throw $error;
    }
    log_activity('opened a hotel folio', 'folio', $id, $reference);

    return [null, $id];
}

/**
 * The folio's lines, each with what its booking says and what differs.
 * Flags that need a note: nights_more, nights_fewer, rate, not_ours. Shown only: absent.
 */
function fo_lines(array $folio): array
{
    $lines = rows('SELECT l.*, c.full_name AS charged_name FROM hotel_folio_lines l LEFT JOIN placements p ON p.id = l.placement_id LEFT JOIN candidates c ON c.id = p.candidate_id
                   WHERE l.folio_id = ? ORDER BY FIELD(l.kind, \'room\', \'tax\', \'incidental\'), l.id', [(int) $folio['id']]);
    foreach ($lines as &$line) {
        $line['flags'] = [];
        $line['booked_nights'] = null;
        $line['booked_rate'] = null;
        $line['absent_nights'] = 0;
        if ($line['kind'] === 'room') {
            $booking = $line['lodging_id'] ? row('SELECT l.*, COALESCE(l.nightly_rate, h.nightly_rate) AS rate_booked FROM lodging l JOIN hotels h ON h.id = l.hotel_id WHERE l.id = ?', [(int) $line['lodging_id']]) : null;
            if (! $booking) {
                $line['flags'][] = 'not_ours';
            } else {
                $nights = fo_nights($booking, (string) $folio['week_ending']);
                $line['booked_nights'] = count($nights);
                $line['booked_rate'] = $booking['rate_booked'] !== null ? (float) $booking['rate_booked'] : null;
                if ((int) $line['nights'] > count($nights)) { $line['flags'][] = 'nights_more'; }
                if ((int) $line['nights'] < count($nights)) { $line['flags'][] = 'nights_fewer'; }
                if ($line['booked_rate'] !== null && abs((float) $line['rate'] - $line['booked_rate']) > 0.004) { $line['flags'][] = 'rate'; }
                if ($nights) {
                    $line['absent_nights'] = (int) val('SELECT COUNT(*) FROM assignment_checkins WHERE placement_id = ? AND present = 0 AND work_date IN (' . implode(',', array_fill(0, count($nights), '?')) . ')',
                                                       array_merge([(int) $booking['placement_id']], $nights));
                    if ($line['absent_nights']) { $line['flags'][] = 'absent'; }
                }
            }
        }
        $line['needs_note'] = (bool) array_intersect($line['flags'], ['nights_more', 'nights_fewer', 'rate', 'not_ours']) && trim((string) $line['accepted_note']) === '';
        $line['needs_charge'] = $line['kind'] === 'incidental' && ($line['charge_to'] === null || ($line['charge_to'] === 'worker' && ! $line['placement_id']));
    }
    unset($line);

    return $lines;
}

/** @return array<string,string> */
function fo_flags(): array
{
    return ['nights_more' => t('More nights than booked'), 'nights_fewer' => t('Fewer nights than booked'), 'rate' => t('Rate differs from the booking'),
            'not_ours' => t('No booking in Crewvia for this guest'), 'absent' => t('Billed on night(s) the worker was absent at roll call')];
}

/** @return array{billed:float, booked:float, agency:float, client:float, worker:float} */
function fo_totals(array $lines): array
{
    $t = ['billed' => 0.0, 'booked' => 0.0, 'agency' => 0.0, 'client' => 0.0, 'worker' => 0.0];
    foreach ($lines as $l) {
        $t['billed'] += (float) $l['amount'];
        if ($l['kind'] === 'room' && $l['booked_nights'] !== null && $l['booked_rate'] !== null) {
            $t['booked'] += $l['booked_nights'] * $l['booked_rate'];
        }
        $who = $l['kind'] === 'incidental' ? ($l['charge_to'] ?? 'agency') : 'agency';
        $t[$who] += (float) $l['amount'];
    }

    return array_map(static fn($v) => round($v, 2), $t);
}

/** Guests on this folio's rooms, to charge an incidental to. @return array<int,string> placement id => name */
function fo_guests(array $folio): array
{
    $out = [];
    foreach (rows("SELECT DISTINCT p.id, c.full_name FROM hotel_folio_lines l JOIN placements p ON p.id = l.placement_id JOIN candidates c ON c.id = p.candidate_id
                   WHERE l.folio_id = ? AND l.kind = 'room' ORDER BY c.full_name", [(int) $folio['id']]) as $g) {
        $out[(int) $g['id']] = (string) $g['full_name'];
    }

    return $out;
}

/** Validates and writes one line from a form. Returns the refusal, or null. */
function fo_line_write(array $folio, ?array $line, string $kind, array $in, int $by): ?string
{
    if ($folio['status'] !== 'open') {
        return t('This folio is reconciled. Reopen it to change a line.');
    }
    $note = trim((string) ($in['accepted_note'] ?? ''));
    if (mb_strlen($note) > 500) {
        return t('A note is at most 500 characters.');
    }
    if ($kind === 'room') {
        $nights = (string) ($in['nights'] ?? '');
        $rate = (string) ($in['rate'] ?? '');
        if (! ctype_digit($nights) || (int) $nights > 7 || ! is_numeric($rate) || (float) $rate < 0 || (float) $rate > 5000) {
            return t('Nights are 0 to 7, and the nightly rate a number.');
        }
        $guest = trim((string) ($in['guest_name'] ?? ($line['guest_name'] ?? '')));
        if (! $line && ($guest === '' || mb_strlen($guest) > 190)) {
            return t('Give the guest\'s name as the hotel printed it.');
        }
        $fields = ['nights' => (int) $nights, 'rate' => round((float) $rate, 2), 'amount' => round((int) $nights * (float) $rate, 2), 'accepted_note' => $note ?: null];
        if (! $line) {
            $fields += ['guest_name' => $guest, 'room_number' => mb_substr(trim((string) ($in['room_number'] ?? '')), 0, 30) ?: null];
        }
    } else {
        $amount = (string) ($in['amount'] ?? '');
        $description = trim((string) ($in['description'] ?? ''));
        if (! is_numeric($amount) || (float) $amount <= 0 || (float) $amount > 100000) {
            return t('An amount above 0.');
        }
        if ($description === '' || mb_strlen($description) > 255) {
            return t('Describe the charge as the hotel printed it.');
        }
        $fields = ['amount' => round((float) $amount, 2), 'description' => $description, 'accepted_note' => $note ?: null];
        if ($kind === 'incidental') {
            $charge = (string) ($in['charge_to'] ?? '');
            if (! in_array($charge, ['agency', 'client', 'worker'], true)) {
                return t('Say who pays it: the agency, the client, or the worker.');
            }
            $placement = (int) ($in['placement_id'] ?? 0);
            if ($charge === 'worker' && ! isset(fo_guests($folio)[$placement])) {
                return t('Choose which guest pays it.');
            }
            $fields += ['charge_to' => $charge, 'placement_id' => $charge === 'worker' ? $placement : null];
        }
    }
    if ($line) {
        q('UPDATE hotel_folio_lines SET ' . implode(', ', array_map(static fn($k) => "`$k` = ?", array_keys($fields))) . ' WHERE id = ?', array_merge(array_values($fields), [(int) $line['id']]));
    } else {
        $fields += ['folio_id' => (int) $folio['id'], 'kind' => $kind, 'created_by' => $by];
        q('INSERT INTO hotel_folio_lines (`' . implode('`, `', array_keys($fields)) . '`) VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')', array_values($fields));
    }

    return null;
}

function fo_line_delete(array $folio, array $line): ?string
{
    if ($folio['status'] !== 'open') {
        return t('This folio is reconciled. Reopen it to change a line.');
    }
    q('DELETE FROM hotel_folio_lines WHERE id = ? AND folio_id = ?', [(int) $line['id'], (int) $folio['id']]);

    return null;
}

/** Reconciles a folio once every difference has its note and every incidental its payer. Returns the refusal, or null. */
function fo_reconcile(array $folio, int $by): ?string
{
    if ($folio['status'] !== 'open') {
        return t('This folio is already reconciled.');
    }
    $lines = fo_lines($folio);
    if (! $lines || fo_totals($lines)['billed'] <= 0) {
        return t('A folio with nothing billed cannot be reconciled.');
    }
    $open = count(array_filter($lines, static fn($l) => $l['needs_note'] || $l['needs_charge']));
    if ($open) {
        return t(':n line(s) still need a note for the difference, or who pays.', ['n' => $open]);
    }
    q("UPDATE hotel_folios SET status = 'reconciled', reconciled_by = ?, reconciled_at = NOW() WHERE id = ?", [$by, (int) $folio['id']]);
    log_activity('reconciled a hotel folio', 'folio', (int) $folio['id'], (string) $folio['reference']);

    return null;
}

function fo_reopen(array $folio): ?string
{
    if ($folio['status'] !== 'reconciled') {
        return t('This folio is open already.');
    }
    if ($folio['vendor_invoice_id']) {
        return t('This folio is already a bill in Payables. Correct the bill there.');
    }
    q("UPDATE hotel_folios SET status = 'open', reconciled_by = NULL, reconciled_at = NULL WHERE id = ?", [(int) $folio['id']]);
    log_activity('reopened a hotel folio', 'folio', (int) $folio['id']);

    return null;
}

/** Turns a reconciled folio into the hotel's bill in Payables, once. Returns the refusal, or null. */
function fo_bill(array $folio, string $dueOn, int $by): ?string
{
    if ($folio['status'] !== 'reconciled') {
        return t('Reconcile the folio first.');
    }
    if ($folio['vendor_invoice_id']) {
        return t('This folio is already a bill in Payables.');
    }
    $due = $dueOn !== '' ? $dueOn : (string) ($folio['due_on'] ?? '');
    if (! valid_date($due)) {
        return t('Give the bill its due date.');
    }
    if (val('SELECT COUNT(*) FROM vendor_invoices WHERE job_id = ? AND vendor_name = ? AND reference = ?', [(int) $folio['job_id'], (string) $folio['hotel'], (string) $folio['reference']])) {
        return t('A bill from this hotel with this reference is already in Payables.');
    }
    $total = fo_totals(fo_lines($folio))['billed'];
    db()->beginTransaction();
    try {
        q('INSERT INTO vendor_invoices (job_id, hotel_id, vendor_name, reference, amount, due_on) VALUES (?,?,?,?,?,?)',
          [(int) $folio['job_id'], (int) $folio['hotel_id'], (string) $folio['hotel'], (string) $folio['reference'], $total, $due]);
        $bill = (int) db()->lastInsertId();
        q('UPDATE hotel_folios SET vendor_invoice_id = ?, due_on = ? WHERE id = ?', [$bill, $due, (int) $folio['id']]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) { db()->rollBack(); }
        throw $error;
    }
    log_activity('turned a hotel folio into a bill', 'folio', (int) $folio['id'], money($total));

    return null;
}

/** Hotel charges a worker pays, from reconciled folios, for their portal. */
function fo_worker_charges(int $candidateId): array
{
    return rows("SELECT l.amount, l.description, l.accepted_note, f.week_ending, h.name AS hotel
                 FROM hotel_folio_lines l JOIN hotel_folios f ON f.id = l.folio_id JOIN hotels h ON h.id = f.hotel_id JOIN placements p ON p.id = l.placement_id
                 WHERE l.kind = 'incidental' AND l.charge_to = 'worker' AND f.status = 'reconciled' AND p.candidate_id = ?
                 ORDER BY f.week_ending DESC, l.id DESC", [$candidateId]);
}
