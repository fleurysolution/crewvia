<?php
/**
 * P5-M28: telling the people whose turn it is. Each notice is a row in
 * notifications, so it rings the bell, lists on Notifications and, where push is
 * switched on, reaches their phone like every other notification.
 */

declare(strict_types=1);

/** Everybody active on these desks (an administrator sees every desk's work). */
function notify_desks(array $roles, string $message, string $target, ?int $except = null): int
{
    $roles = array_values(array_unique(array_merge($roles, ['admin'])));
    $in = implode(',', array_fill(0, count($roles), '?'));
    $users = rows("SELECT id FROM users WHERE is_active = 1 AND role IN ($in)" . ($except ? ' AND id <> ?' : ''), $except ? [...$roles, $except] : $roles);
    foreach ($users as $u) {
        notify_user((int) $u['id'], $message, $target);
    }

    return count($users);
}

/** One person. */
function notify_user(int $userId, string $message, string $target): void
{
    if ($userId <= 0 || notices_cross_demo($userId)) {
        return;
    }
    q('INSERT INTO notifications (user_id, message, target) VALUES (?, ?, ?)', [$userId, mb_substr($message, 0, 250), mb_substr($target, 0, 190)]);
}

/** The worker's own account, if their crew file has one. */
function notify_candidate(int $candidateId, string $message, string $target = '/portal'): bool
{
    $user = (int) val('SELECT user_id FROM worker_accounts WHERE candidate_id = ?', [$candidateId]);
    if ($user) {
        // P5-M62: the person concerned is told about their own file whoever acts on it. The demonstration
        // guard (notices_cross_demo) kept a real candidate's notice from them when a demonstration account
        // (Owner Number 1, recruiter1) worked on their application, while their email still went out.
        q('INSERT INTO notifications (user_id, message, target) VALUES (?, ?, ?)', [$user, mb_substr($message, 0, 250), mb_substr($target, 0, 190)]);
    }

    return $user > 0;
}

/**
 * P5-M52: tell a candidate who has a portal what changed for them - in the
 * app (the bell, Notifications) and by email with the link to their portal,
 * signed by the agency. Somebody without a portal yet is reached by their
 * portal invitation instead. Returns whether they have a portal.
 */
function candidate_tell(int $candidateId, string $subject, string $message, string $target = '/portal'): bool
{
    global $config;
    if (! notify_candidate($candidateId, $message, $target)) {
        return false;
    }
    $c = row('SELECT full_name, email FROM candidates WHERE id = ?', [$candidateId]) ?: [];
    $first = trim((string) strtok((string) ($c['full_name'] ?? ''), ' '));
    $agency = agency_name();
    $url = rtrim((string) ($config['app_url'] ?? ''), '/') . $target;
    mail_queue($candidateId, (string) ($c['email'] ?? ''), $agency . ': ' . $subject,
               'Hello ' . ($first !== '' ? $first : 'there') . ",

" . $message . "

Your portal: " . $url . "

" . $agency, 'candidate notice');

    return true;
}

/**
 * P5-M30: an email to a worker (an itinerary, a notice), queued and sealed
 * with the workspace key; install/deliver-mail.php sends it through the
 * configured relay. Returns whether it was queued.
 */
function mail_queue(?int $candidateId, string $to, string $subject, string $body, string $purpose): bool
{
    if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    require_once __DIR__ . '/crypto.php';
    q('INSERT INTO mail_outbox (candidate_id, purpose, recipient, encrypted_message) VALUES (?,?,?,?)',
      [$candidateId, mb_substr($purpose, 0, 40), $to, base64_encode(crypto_seal(json_encode(['subject' => $subject, 'body' => $body], JSON_THROW_ON_ERROR)))]);

    return true;
}

/**
 * The delivery run: up to $max queued emails, each tried at most 5 times.
 * Held, with the reason, while email is not switched on for this installation.
 * @return array{sent:int, failed:int, held:?string}
 */
function mail_deliver(int $max = 50): array
{
    global $config;
    require_once __DIR__ . '/campaigns.php';
    require_once __DIR__ . '/crypto.php';
    $done = ['sent' => 0, 'failed' => 0, 'held' => null];
    if (! cm_capture() && (empty($config['email_delivery_enabled']) || ! filter_var((string) ($config['email_from'] ?? ''), FILTER_VALIDATE_EMAIL))) {
        $done['held'] = 'email is not switched on for this installation (email_delivery_enabled and email_from)';
        return $done;
    }
    for ($i = 0; $i < $max; $i++) {
        db()->beginTransaction();
        $m = row("SELECT * FROM mail_outbox WHERE (status = 'pending' OR (status = 'sending' AND lease_until < NOW())) AND attempts < 5 ORDER BY id LIMIT 1 FOR UPDATE");
        if (! $m) {
            db()->commit();
            break;
        }
        q("UPDATE mail_outbox SET status = 'sending', lease_until = DATE_ADD(NOW(), INTERVAL 5 MINUTE), attempts = attempts + 1 WHERE id = ?", [(int) $m['id']]);
        db()->commit();
        $raw = base64_decode((string) $m['encrypted_message'], true);
        $content = $raw !== false ? json_decode((string) crypto_open($raw), true) : null;
        $ok = is_array($content) && cm_mail((string) $m['recipient'], (string) $content['subject'], (string) $content['body'],
                                            ['From' => (string) (($config['email_from'] ?? '') ?: 'capture@test.invalid'), 'Content-Type' => 'text/plain; charset=UTF-8']);
        q('UPDATE mail_outbox SET status = ?, sent_at = ?, lease_until = NULL WHERE id = ?', [$ok ? 'sent' : ((int) $m['attempts'] + 1 >= 5 ? 'failed' : 'pending'), $ok ? date('Y-m-d H:i:s') : null, (int) $m['id']]);
        $done[$ok ? 'sent' : 'failed']++;
    }

    return $done;
}

/**
 * P5-M35: while a demonstration is loaded beside real work, a notice from a
 * demonstration account never reaches a real one, nor the other way round.
 */
function notices_cross_demo(int $recipient): bool
{
    static $demo = null;
    if ($demo === null) {
        $demo = [];   // no demonstration has ever been loaded here
        if (val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'demo_records'")) {
            $demo = array_flip(array_map('intval', array_column(rows("SELECT record_id FROM demo_records WHERE table_name = 'users'"), 'record_id')));
        }
    }
    if (! $demo || ! uid()) {
        return false;
    }

    return isset($demo[uid()]) !== isset($demo[$recipient]);
}
