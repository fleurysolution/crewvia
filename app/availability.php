<?php
/**
 * P5-M45: a candidate says when they can work, and has a portal from the
 * moment they apply.
 *
 * Availability is the candidate's own word, kept on their record: available
 * now, available from a date, or not available for now. They give it when
 * they apply (the public page a job poster's QR code opens) and change it in
 * their portal whenever it changes; the recruiter sees it in Hiring next to
 * the application, with the day it was last said, so nobody phones to ask.
 *
 * A portal from the start: right after applying, the person is offered to
 * create their portal there and then - the same activation as an invitation,
 * without an email, since they are on the page. Somebody whose email already
 * has an account is asked to sign in instead; nothing is merged.
 */

declare(strict_types=1);

/** @return array<string,string> */
function availability_choices(): array
{
    return ['available' => t('Available now'), 'from' => t('Available from a date'), 'unavailable' => t('Not available for now')];
}

/** Record what the candidate says. @return ?string an error */
function availability_set(int $candidateId, string $status, string $from): ?string
{
    if (! isset(availability_choices()[$status])) { return t('Say whether you are available.'); }
    $date = null;
    if ($status === 'from') {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! strtotime($from)) { return t('Give the date you can start.'); }
        if ($from <= date('Y-m-d')) { $status = 'available'; } else { $date = $from; }
    }
    q('UPDATE candidates SET availability = ?, available_from = ?, availability_at = NOW() WHERE id = ?', [$status, $date, $candidateId]);
    q('INSERT INTO candidate_events (candidate_id, event_type, detail) VALUES (?,?,?)', [$candidateId, 'availability', $status . ($date ? ' from ' . $date : '')]);

    return null;
}

/** What a candidate's availability reads as, for the office: '' when never said. A date that has come reads "available". */
function availability_words(?string $status, ?string $from): string
{
    return match ($status) {
        'available' => t('Available now'),
        'from' => $from !== null && $from <= date('Y-m-d') ? t('Available now') : t('Available from :date', ['date' => d((string) $from)]),
        'unavailable' => t('Not available for now'),
        default => '',
    };
}

/** The tone of the tag the office sees. */
function availability_tone(?string $status, ?string $from): string
{
    return match ($status) {
        'available' => 'green',
        'from' => $from !== null && $from <= date('Y-m-d') ? 'green' : 'amber',
        'unavailable' => 'grey',
        default => '',
    };
}

/**
 * Right after a public application: create the person's portal now.
 *
 * @return array{kind:string,url:?string} kind: activate (a private link, valid
 *         7 days), signin (an account already uses the email), portal
 *         (they are signed in), none (no valid email)
 */
function availability_portal_offer(int $candidateId): array
{
    global $config;
    if (is_worker_account()) { return ['kind' => 'portal', 'url' => '/my-jobs']; }
    $c = row('SELECT email FROM candidates WHERE id = ?', [$candidateId]);
    if (! $c || ! filter_var((string) $c['email'], FILTER_VALIDATE_EMAIL)) { return ['kind' => 'none', 'url' => null]; }
    if (row('SELECT id FROM users WHERE email = ?', [$c['email']]) || row('SELECT user_id FROM worker_accounts WHERE candidate_id = ?', [$candidateId])) {
        return ['kind' => 'signin', 'url' => '/login'];
    }
    $token = bin2hex(random_bytes(32));
    q('INSERT INTO invitations (candidate_id, token_hash, expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 7 DAY))', [$candidateId, hash('sha256', $token)]);

    return ['kind' => 'activate', 'url' => '/activate?token=' . $token];
}
