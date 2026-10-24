<?php
/**
 * Push notifications (P5-M06c). Off until an administrator turns them on.
 *
 * A push carries no content. Crewvia sends an empty push, signed with the
 * agency's VAPID key, to the push service of each phone that asked for it.
 * The phone then shows "New in Crewvia" in its owner's language and opens
 * the notifications page when tapped. Google, Apple, Mozilla and Microsoft
 * never see a name or a message, and no message body has to be encrypted.
 *
 *   - The key pair is made once on the server: php install/push-keys.php.
 *     The private key is sealed with the encryption key (and rotated with
 *     it, GAP-1002).
 *   - Each person turns push on, per phone, and chooses for what: tasks,
 *     training, surveys, approvals and requests, recognition and feedback,
 *     everything else.
 *   - Sending is done by the scheduled workflows and by
 *     install/push-dispatch.php (every minute from cron, once it is on):
 *     one push per phone for everything new since the last pass, never one
 *     per notification. A test push can be sent from Trusted phones.
 *   - A push goes only to the push services' own addresses, over HTTPS:
 *     Crewvia is never made to call any other address.
 *   - A subscription ends when its owner removes it or signs out on that
 *     phone, when the account's sign-in version moves (password change,
 *     "sign out everywhere"), when the push service says it is gone, or
 *     after 5 failures in a row.
 */

declare(strict_types=1);

require_once __DIR__ . '/crypto.php';

const PUSH_COOKIE = 'crewvia_push';
const PUSH_MAX_FAILURES = 5;
const PUSH_HOSTS = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'];
const PUSH_HOST_SUFFIXES = ['.push.apple.com', '.notify.windows.com', '.push.services.mozilla.com'];

/** @return array<string,string> */
function push_kinds(): array
{
    return ['tasks' => t('Tasks'), 'training' => t('Training'), 'surveys' => t('Surveys'), 'approvals' => t('Approvals and requests'),
            'recognition' => t('Recognition and feedback'), 'other' => t('Everything else')];
}

/** Which kind a notification is, from where it leads. */
function push_kind_of(string $target): string
{
    $path = (string) parse_url($target, PHP_URL_PATH);
    foreach (['tasks' => ['/my-tasks', '/project-tasks', '/project-board', '/workload'], 'training' => ['/my-training', '/training', '/learning'],
              'surveys' => ['/surveys'], 'approvals' => ['/approvals', '/activity', '/timeoff', '/attendance', '/hr-requests', '/expenses', '/appraisals'],
              'recognition' => ['/recognition', '/feedback', '/hr-records']] as $kind => $paths) {
        if (in_array($path, $paths, true)) {
            return $kind;
        }
    }

    return 'other';
}

function push_b64u(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function push_enabled(): bool
{
    return (string) val("SELECT setting_value FROM platform_settings WHERE setting_key = 'push_enabled'") === '1';
}

function push_setting(string $key, string $value): void
{
    q('INSERT INTO platform_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, $value]);
}

function push_public_key(): ?string
{
    $k = val('SELECT public_key FROM push_keys WHERE id = 1');

    return $k ? (string) $k : null;
}

/** Make the agency's key pair (once). Returns the public key, or null if one already exists. */
function push_keys_make(): ?string
{
    if (push_public_key()) {
        return null;
    }
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (! $key || ! openssl_pkey_export($key, $pem)) {
        throw new RuntimeException('OpenSSL could not make a P-256 key. On Windows, set OPENSSL_CONF to an openssl.cnf.');
    }
    $d = openssl_pkey_get_details($key);
    $public = push_b64u("\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT));
    q('INSERT INTO push_keys (id, public_key, encrypted_private_key) VALUES (1,?,?)', [$public, base64_encode(crypto_seal($pem))]);

    return $public;
}

/** Turn push on or off. On needs the key pair; what is already waiting is not pushed. */
function push_switch(bool $on, int $by): ?string
{
    if ($on && ! push_public_key()) {
        return t('Make the key pair on the server first: php install/push-keys.php');
    }
    if ($on && ! push_enabled()) {
        push_setting('push_cursor', (string) (int) val('SELECT COALESCE(MAX(id), 0) FROM notifications'));
    }
    push_setting('push_enabled', $on ? '1' : '0');
    log_activity($on ? 'turned push notifications on' : 'turned push notifications off', 'platform_settings', 0, '');

    return null;
}

/** Is this a push service's address? Only those are ever called. */
function push_endpoint_allowed(string $url): bool
{
    global $config;
    $p = parse_url($url);
    if (! is_array($p) || empty($p['host']) || strlen($url) > 1000) {
        return false;
    }
    $host = strtolower((string) $p['host']);
    // The test suite's stand-in push service, on this machine, in debug only.
    if (! empty($config['debug']) && ($p['scheme'] ?? '') === 'http' && $host === '127.0.0.1') {
        return true;
    }
    if (($p['scheme'] ?? '') !== 'https' || isset($p['port']) || isset($p['user']) || isset($p['pass'])) {
        return false;
    }
    if (in_array($host, PUSH_HOSTS, true)) {
        return true;
    }
    foreach (PUSH_HOST_SUFFIXES as $suffix) {
        if (str_ends_with($host, $suffix)) {
            return true;
        }
    }

    return false;
}

function push_set_cookie(string $value, int $expires): void
{
    require_once __DIR__ . '/offline-sync.php';
    setcookie(PUSH_COOKIE, $value, ['expires' => $expires, 'path' => '/', 'secure' => os_secure(), 'httponly' => true, 'samesite' => 'Strict']);
}

/** @return array{0:?string,1:int} */
function push_subscribe(array $u, string $endpoint, array $kinds, string $userAgent): array
{
    if (! push_enabled()) { return [t('Push notifications are off.'), 0]; }
    if (($u['role'] ?? '') === 'client') { return [t('Not your desk'), 0]; }
    if (! push_endpoint_allowed($endpoint)) { return [t('That is not a push service this browser should use.'), 0]; }
    $kinds = array_values(array_intersect(array_keys(push_kinds()), array_map('strval', $kinds)));
    if (! $kinds) { return [t('Choose at least one kind of notification.'), 0]; }
    require_once __DIR__ . '/offline-sync.php';
    $hash = hash('sha256', $endpoint);
    $label = mb_substr(trim(preg_replace('/\s+/', ' ', $userAgent)) ?: 'Phone', 0, 120);
    q('INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, kinds, label, session_version) VALUES (?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), kinds = VALUES(kinds), label = VALUES(label), session_version = VALUES(session_version),
                               ended_at = NULL, end_reason = NULL, failures = 0',
      [(int) $u['id'], $endpoint, $hash, implode(',', $kinds), $label, os_session_version((int) $u['id'])]);
    $id = (int) val('SELECT id FROM push_subscriptions WHERE endpoint_hash = ?', [$hash]);
    push_set_cookie($hash, time() + 400 * 86400);
    log_activity('turned push notifications on for a phone', 'push_subscription', $id, $label);

    return [null, $id];
}

function push_end(int $id, string $reason): bool
{
    return q('UPDATE push_subscriptions SET ended_at = NOW(), end_reason = ? WHERE id = ? AND ended_at IS NULL', [mb_substr($reason, 0, 40), $id])->rowCount() > 0;
}

/** End the subscription this request's cookie names, and clear the cookie. */
function push_end_cookie(string $reason): bool
{
    $hash = (string) ($_COOKIE[PUSH_COOKIE] ?? '');
    if (! preg_match('/^[a-f0-9]{64}$/', $hash)) {
        return false;
    }
    push_set_cookie('', time() - 3600);
    unset($_COOKIE[PUSH_COOKIE]);
    $id = (int) val('SELECT id FROM push_subscriptions WHERE endpoint_hash = ?', [$hash]);

    return $id && push_end($id, $reason);
}

function push_current_subscription(int $userId): ?array
{
    $hash = (string) ($_COOKIE[PUSH_COOKIE] ?? '');

    return preg_match('/^[a-f0-9]{64}$/', $hash)
        ? row('SELECT * FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ? AND ended_at IS NULL', [$hash, $userId]) : null;
}

// ── signing and sending ─────────────────────────────────────────────────

/** An ECDSA signature from OpenSSL (DER) as the 64 raw bytes JWT wants. */
function push_der_to_raw(string $der): string
{
    $pos = 2;
    if (ord($der[1]) & 0x80) {
        $pos += ord($der[1]) & 0x7f;
    }
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = ltrim(substr($der, $pos + 2, $len), "\0");
        $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }

    return $out;
}

/** The VAPID header for one push service's origin. */
function push_vapid(string $endpoint): string
{
    global $config;
    $sealed = (string) val('SELECT encrypted_private_key FROM push_keys WHERE id = 1');
    $pem = $sealed !== '' ? crypto_open((string) base64_decode($sealed, true)) : null;
    $key = $pem ? openssl_pkey_get_private($pem) : false;
    if (! $key) {
        throw new RuntimeException('The push key cannot be opened with the configured encryption key.');
    }
    $p = parse_url($endpoint);
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $sub = (string) ($config['app_url'] ?? '') ?: 'https://crewvia.invalid';
    $data = push_b64u('{"typ":"JWT","alg":"ES256"}') . '.' . push_b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $sub], JSON_UNESCAPED_SLASHES));
    if (! openssl_sign($data, $der, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('The push could not be signed.');
    }

    return 'vapid t=' . $data . '.' . push_b64u(push_der_to_raw($der)) . ', k=' . push_public_key();
}

/** One empty push. Returns the push service's status, or 0 when it could not be reached. */
function push_send(array $sub): array
{
    if (outbound_disabled()) {
        return [0, 'outbound disabled on this installation'];
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0,
        'header' => "TTL: 86400\r\nUrgency: normal\r\nContent-Length: 0\r\nAuthorization: " . push_vapid((string) $sub['endpoint']) . "\r\n", 'content' => '']]);
    $http_response_header = [];
    $body = @file_get_contents((string) $sub['endpoint'], false, $ctx);
    $status = preg_match('#^HTTP/\S+\s+(\d{3})#', (string) ($http_response_header[0] ?? ''), $m) ? (int) $m[1] : 0;

    return [$status, $status === 0 ? 'unreachable' : ($status >= 400 ? mb_substr((string) $body, 0, 255) : null)];
}

/** Record the answer: gone ends it, repeated failure ends it, success resets the count. */
function push_record(array $sub, string $reason, ?int $upTo, int $status, ?string $error): void
{
    q('INSERT INTO push_deliveries (subscription_id, reason, up_to_notification, status, error) VALUES (?,?,?,?,?)', [(int) $sub['id'], $reason, $upTo, $status ?: null, $error]);
    if ($status >= 200 && $status < 300) {
        q('UPDATE push_subscriptions SET last_sent_at = NOW(), last_status = ?, failures = 0 WHERE id = ?', [$status, (int) $sub['id']]);
        return;
    }
    q('UPDATE push_subscriptions SET last_sent_at = NOW(), last_status = ?, failures = failures + 1 WHERE id = ?', [$status ?: null, (int) $sub['id']]);
    if (in_array($status, [404, 410], true)) {
        push_end((int) $sub['id'], 'gone');
    } elseif ((int) $sub['failures'] + 1 >= PUSH_MAX_FAILURES) {
        push_end((int) $sub['id'], 'failing');
    }
}

/** A subscription whose owner's sign-in moved on ends here. */
function push_still_signed_in(array $sub): bool
{
    require_once __DIR__ . '/offline-sync.php';
    if ((int) $sub['session_version'] !== os_session_version((int) $sub['user_id'])
        || ! val("SELECT COUNT(*) FROM users WHERE id = ? AND is_active = 1 AND role <> 'client'", [(int) $sub['user_id']])) {
        push_end((int) $sub['id'], 'signed_out');
        return false;
    }

    return true;
}

/**
 * Push everything new since the last pass: one push per phone whose owner
 * has something new of a kind they chose. Nothing at all while push is off.
 *
 * @return array{sent:int,ended:int,notifications:int}
 */
function push_dispatch(): array
{
    $out = ['sent' => 0, 'ended' => 0, 'notifications' => 0];
    if (! push_enabled() || ! push_public_key()) {
        return $out;
    }
    $cursor = (int) val("SELECT setting_value FROM platform_settings WHERE setting_key = 'push_cursor'");
    $new = rows('SELECT id, user_id, target FROM notifications WHERE id > ? AND held = 0 ORDER BY id LIMIT 5000', [$cursor]);
    if (! $new) {
        return $out;
    }
    $out['notifications'] = count($new);
    $upTo = (int) end($new)['id'];
    // Moved first: a pass that fails half way does not push the same news twice.
    push_setting('push_cursor', (string) $upTo);
    $wanted = [];
    foreach ($new as $n) {
        $wanted[(int) $n['user_id']][push_kind_of((string) $n['target'])] = true;
    }
    $in = implode(',', array_map('intval', array_keys($wanted)));
    foreach (rows("SELECT * FROM push_subscriptions WHERE ended_at IS NULL AND user_id IN ($in) ORDER BY id") as $sub) {
        if (! push_still_signed_in($sub)) {
            $out['ended']++;
            continue;
        }
        if (! array_intersect(explode(',', (string) $sub['kinds']), array_keys($wanted[(int) $sub['user_id']]))) {
            continue;
        }
        [$status, $error] = push_send($sub);
        push_record($sub, 'notification', $upTo, $status, $error);
        $out['sent']++;
    }

    return $out;
}

/** A test push to the signed-in person's own phones. */
function push_test(int $userId): array
{
    if (! push_enabled()) {
        return [t('Push notifications are off.'), 0];
    }
    $n = 0;
    foreach (rows('SELECT * FROM push_subscriptions WHERE user_id = ? AND ended_at IS NULL', [$userId]) as $sub) {
        if (push_still_signed_in($sub)) {
            [$status, $error] = push_send($sub);
            push_record($sub, 'test', null, $status, $error);
            $n++;
        }
    }

    return [$n ? null : t('No phone of yours has push notifications on.'), $n];
}
