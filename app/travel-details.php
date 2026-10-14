<?php
/**
 * P5-M30: the worker's trip, from both ends.
 *
 *  - The worker enters what the coordinator needs to book: the name exactly
 *    as on their ID, their home address (sealed with the workspace key) and
 *    the airport they fly from.
 *  - The coordinator books on Travel, pastes the ticket and its cost.
 *  - The worker is told at once in the portal, and by email
 *    (mail_outbox, sent by install/deliver-mail.php): the legs, the ticket,
 *    and the advice the operations VP or the project's manager wrote for
 *    travellers (bring your ID, be early, bag fees, a card, not cash).
 */

declare(strict_types=1);

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/notices.php';

const TRAVEL_MODES = ['flight' => 'Flight', 'drive' => 'Drives themselves', 'bus' => 'Bus', 'train' => 'Train'];

/** The advice a project starts from, until someone writes their own. */
function tp_usual_advice(): array
{
    return [
        'Bring a government photo ID. The name on it must match the name on your ticket.',
        'Be at the airport two hours before your flight leaves.',
        'Airlines charge for checked bags. Check what your ticket includes before you pack.',
        'Bring a debit or credit card. Hotels and airlines often do not take cash.',
    ];
}

/** What the worker entered, the address opened; null when nothing is entered. */
function tp_profile(int $candidateId): ?array
{
    $p = row('SELECT * FROM travel_profiles WHERE candidate_id = ?', [$candidateId]);
    if (! $p) {
        return null;
    }
    $raw = base64_decode((string) $p['encrypted_address'], true);
    $p['address'] = $raw !== false ? (crypto_open($raw) ?? '') : '';
    unset($p['encrypted_address']);

    return $p;
}

/** The worker saves their travel details. Returns the refusal, or null. */
function tp_save(int $candidateId, array $in, int $by): ?string
{
    $name = trim((string) ($in['legal_name'] ?? ''));
    $address = trim(str_replace("\r\n", "\n", (string) ($in['address'] ?? '')));
    $airport = trim((string) ($in['airport'] ?? ''));
    $notes = trim((string) ($in['travel_notes'] ?? ''));
    if (mb_strlen($name) < 3 || mb_strlen($name) > 190) {
        return t('Enter your name exactly as it is on your ID.');
    }
    if (mb_strlen($address) < 10 || mb_strlen($address) > 300) {
        return t('Enter your home address: street, city, state and ZIP code.');
    }
    if (mb_strlen($airport) < 3 || mb_strlen($airport) > 120) {
        return t('Enter the airport you would fly from, for example IAH or Houston Bush.');
    }
    if (mb_strlen($notes) > 500) {
        return t('Keep the note under 500 characters.');
    }
    $had = (bool) val('SELECT 1 FROM travel_profiles WHERE candidate_id = ?', [$candidateId]);
    q('INSERT INTO travel_profiles (candidate_id, legal_name, encrypted_address, airport, notes, updated_by) VALUES (?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE legal_name = VALUES(legal_name), encrypted_address = VALUES(encrypted_address), airport = VALUES(airport), notes = VALUES(notes), updated_by = VALUES(updated_by)',
      [$candidateId, $name, base64_encode(crypto_seal($address)), $airport, $notes !== '' ? $notes : null, $by]);
    q('INSERT INTO candidate_events (candidate_id, event_type, detail) VALUES (?,?,?)', [$candidateId, 'travel details', ($had ? 'Changed' : 'Entered') . ' by the worker; flies from ' . mb_substr($airport, 0, 120)]);
    $who = (string) val('SELECT full_name FROM candidates WHERE id = ?', [$candidateId]);
    notify_desks(['hotels'], t(':name entered their travel details: flies from :airport.', ['name' => $who, 'airport' => $airport]), '/travel');

    return null;
}

/** The advice for travellers on a project: its own, else the agency's. */
function tp_advisories(int $jobId): array
{
    $own = rows('SELECT id, job_id, body FROM travel_advisories WHERE job_id = ? ORDER BY sort_order, id', [$jobId]);

    return $own ?: rows('SELECT id, job_id, body FROM travel_advisories WHERE job_id IS NULL ORDER BY sort_order, id');
}

/** One leg in words, for the portal and the email. */
function tp_leg_lines(array $leg): array
{
    $when = static fn($v) => $v ? date('D j M Y, g:i a', strtotime((string) $v)) : null;
    $lines = [];
    $lines[] = t($leg['direction'] === 'outbound' ? 'Going home' : 'Coming to the job') . ': ' . t(TRAVEL_MODES[$leg['mode']] ?? 'Flight')
             . ($leg['carrier'] ? ' · ' . $leg['carrier'] : '') . ($leg['reference'] ? ' · ' . t('confirmation :ref', ['ref' => $leg['reference']]) : '');
    $from = trim((string) $leg['depart_from'] . ($when($leg['depart_time']) ? ', ' . $when($leg['depart_time']) : ''), ', ');
    $to = trim((string) $leg['arrive_at'] . ($when($leg['arrive_time']) ? ', ' . $when($leg['arrive_time']) : ''), ', ');
    if ($from !== '' || $to !== '') {
        $lines[] = t('Leaves :from, arrives :to', ['from' => $from !== '' ? $from : '—', 'to' => $to !== '' ? $to : '—']);
    }
    if ($leg['direction'] !== 'outbound') {
        $lines[] = (int) $leg['pickup_needed'] ? t('Someone from the agency picks you up at the airport.') : t('No airport pickup: make your own way to the hotel.');
    }

    return $lines;
}

/** Tells the worker their trip is booked: in the portal, and by email. Returns whether an email was queued. */
function tp_send_itinerary(int $travelId): bool
{
    global $config;
    $leg = row('SELECT t.*, p.candidate_id, p.job_id, c.full_name, c.email, j.title AS project FROM travel t JOIN placements p ON p.id = t.placement_id
                JOIN candidates c ON c.id = p.candidate_id JOIN jobs j ON j.id = p.job_id WHERE t.id = ?', [$travelId]);
    if (! $leg) {
        return false;
    }
    notify_candidate((int) $leg['candidate_id'], t('Your travel for :project is booked. Your itinerary is in your portal.', ['project' => $leg['project']]), '/portal#trip');
    $email = trim((string) $leg['email']);
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $first = trim(strtok((string) $leg['full_name'], ' ') ?: (string) $leg['full_name']);
    $body = t('Hello :name,', ['name' => $first]) . "\n\n" . t('Your travel for :project is booked.', ['project' => $leg['project']]) . "\n\n"
          . implode("\n", tp_leg_lines($leg)) . "\n";
    if (trim((string) $leg['ticket_details']) !== '') {
        $body .= "\n" . t('Your ticket') . ":\n" . trim((string) $leg['ticket_details']) . "\n";
    }
    $advice = tp_advisories((int) $leg['job_id']);
    if ($advice) {
        $body .= "\n" . t('Before you travel') . ":\n" . implode("\n", array_map(static fn($a) => '- ' . $a['body'], $advice)) . "\n";
    }
    $body .= "\n" . t('The same details are in your portal: :url', ['url' => rtrim((string) ($config['app_url'] ?? ''), '/') . '/portal']) . "\n";

    return mail_queue((int) $leg['candidate_id'], $email, t('Your travel for :project', ['project' => $leg['project']]), $body, 'itinerary');
}
