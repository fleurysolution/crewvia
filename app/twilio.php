<?php
/**
 * Twilio (P5-M08): texts and calls from Crewvia itself, prepared and not
 * live. It is the connector "twilio" (P4-M05 rules):
 *
 *   disabled  nothing is offered.
 *   sandbox   the default. "Send from Crewvia" and "Call through Crewvia"
 *             record what would have gone, marked sandbox; nothing leaves
 *             Crewvia and nothing is logged as a contact. Webhooks are
 *             verified and answered, and change nothing.
 *   live      texts and calls go through the agency's Twilio account.
 *             Needs the credentials, a check of the account that passed
 *             (the sandbox trial) and who at RSS authorized it.
 *
 * Credentials (account SID, auth token, the sending number and/or a
 * messaging service) are entered by an administrator on Integrations and
 * stored sealed. Changing them puts the connector back in sandbox.
 *
 * What it does live:
 *   - a text: POST Messages.json, never retried blindly (a send is not
 *     safe to repeat); logged as "texted" in the contact register;
 *     Twilio's status reports (queued, sent, delivered, failed) update it.
 *   - a reply: matched to the person by number, logged as "replied by
 *     text", their recruiter told. STOP (and Twilio's other opt-out words)
 *     records "asked not to be texted"; START lifts what STOP recorded.
 *   - a call: Twilio rings the staff member's own phone, then connects the
 *     person, showing the agency's number. The outcome is logged by hand,
 *     as for any call.
 * Every webhook is checked against Twilio's signature, computed with the
 * auth token over the agency's own public address (config app_url), never
 * the Host header a caller sends.
 */

declare(strict_types=1);

require_once __DIR__ . '/integrations.php';
require_once __DIR__ . '/connectors.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/messaging.php';

const TW_API = 'https://api.twilio.com/2010-04-01';
const TW_STOP = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'REVOKE', 'OPTOUT'];
const TW_START = ['START', 'UNSTOP', 'YES'];
const TW_STOP_REASON = 'Replied STOP by text (Twilio)';

/**
 * Twilio's API address. The test suite's stand-in on this machine replaces
 * it, in debug only and only on 127.0.0.1; production always calls Twilio.
 */
function tw_api(): string
{
    global $config;
    if (! empty($config['debug'])) {
        $test = (string) val("SELECT setting_value FROM platform_settings WHERE setting_key = 'twilio_test_api_base'");
        if (preg_match('#^http://127\.0\.0\.1:\d+$#', $test)) {
            return $test . '/2010-04-01';
        }
    }

    return TW_API;
}

/** The stored credentials, the token opened; null when none are entered. */
function tw_credentials(): ?array
{
    $r = row("SELECT * FROM provider_credentials WHERE provider = 'twilio'");
    if (! $r) {
        return null;
    }
    $public = json_decode((string) $r['public_json'], true) ?: [];
    $token = crypto_open((string) base64_decode((string) $r['encrypted_secret'], true));

    return $token === null ? null : $public + ['auth_token' => $token];
}

function tw_configured(): bool
{
    if (outbound_disabled()) {
        return false;   // P5-M107: this installation sends nothing
    }
    return tw_credentials() !== null;
}

/** disabled, sandbox or live. */
function tw_mode(): string
{
    return connector_mode('twilio');
}

/** What may be shown about the credentials: never the token. */
function tw_summary(): array
{
    $c = tw_credentials();

    return ['configured' => $c !== null, 'sid_end' => $c ? substr((string) $c['account_sid'], -4) : null, 'from' => $c['from_number'] ?? null,
            'service' => ! empty($c['messaging_service_sid']), 'mode' => tw_mode()];
}

/** An administrator enters or replaces the credentials. A blank token keeps the one stored. */
function tw_credentials_save(array $in, int $by): ?string
{
    $sid = trim((string) ($in['account_sid'] ?? ''));
    $token = trim((string) ($in['auth_token'] ?? ''));
    $from = trim((string) ($in['from_number'] ?? ''));
    $service = trim((string) ($in['messaging_service_sid'] ?? ''));
    $old = tw_credentials();
    if (! preg_match('/^AC[0-9a-f]{32}$/', $sid)) { return t('The account SID starts with AC, followed by 32 letters and digits.'); }
    if ($token === '' && $old) { $token = (string) $old['auth_token']; }
    if (! preg_match('/^[0-9a-f]{32}$/', $token)) { return t('The auth token is 32 letters and digits.'); }
    $fromNumber = $from === '' ? null : msg_phone($from);
    if ($from !== '' && ($fromNumber === null || ! str_starts_with($fromNumber, '+'))) { return t('Give the Twilio number with its country code, for example +15551234567.'); }
    if ($service !== '' && ! preg_match('/^MG[0-9a-f]{32}$/', $service)) { return t('A messaging service SID starts with MG, followed by 32 letters and digits.'); }
    if ($fromNumber === null && $service === '') { return t('Give the Twilio number, a messaging service, or both.'); }
    $public = ['account_sid' => $sid, 'from_number' => $fromNumber, 'messaging_service_sid' => $service ?: null];
    q('INSERT INTO provider_credentials (provider, public_json, encrypted_secret, updated_by) VALUES (?,?,?,?)
       ON DUPLICATE KEY UPDATE public_json = VALUES(public_json), encrypted_secret = VALUES(encrypted_secret), updated_by = VALUES(updated_by)',
      ['twilio', json_encode($public), base64_encode(crypto_seal($token)), $by]);
    // New credentials are tried again before anything goes live.
    q("INSERT INTO connector_modes (connector, kind, mode) VALUES ('twilio', 'messaging', 'sandbox')
       ON DUPLICATE KEY UPDATE mode = IF(mode = 'live', 'sandbox', mode), sandbox_passed_at = NULL, sandbox_note = NULL, updated_by = ?, updated_at = NOW()", [$by]);
    integration_event('twilio', 'credentials', 'ok', 'AC…' . substr($sid, -4), 'Credentials saved; back in sandbox until the account is checked again.');

    return null;
}

function tw_auth(array $c): string
{
    return 'Authorization: Basic ' . base64_encode($c['account_sid'] . ':' . $c['auth_token']);
}

/** The sandbox trial: read the account (no text, no call). Passes when Twilio says it is active. */
function tw_check_account(): ?string
{
    $c = tw_credentials();
    if (! $c) { return t('Enter the Twilio credentials first.'); }
    $r = integration_request('twilio', 'GET', tw_api() . '/Accounts/' . $c['account_sid'] . '.json', [tw_auth($c), 'Accept: application/json'], null, true, 15);
    $body = json_decode((string) $r['body'], true) ?: [];
    if ((int) $r['status'] !== 200 || ($body['status'] ?? '') !== 'active') {
        integration_event('twilio', 'check', 'failed', 'AC…' . substr($c['account_sid'], -4), 'HTTP ' . (int) $r['status'] . ', status ' . (string) ($body['status'] ?? 'unknown'));
        return t('Twilio did not confirm an active account (HTTP :status). Check the SID and token.', ['status' => (int) $r['status']]);
    }
    q("INSERT INTO connector_modes (connector, kind, mode) VALUES ('twilio', 'messaging', 'sandbox') ON DUPLICATE KEY UPDATE kind = kind");
    connector_sandbox_passed('twilio', 'Account checked: active');

    return null;
}

/** The agency's public address for Twilio to call back, from the configuration. */
function tw_public_url(string $query): string
{
    global $config;

    return rtrim((string) ($config['app_url'] ?? ''), '/') . '/twilio-webhook?' . $query;
}

/** Twilio's signature: HMAC-SHA1 over the address and the sorted parameters, with the auth token. */
function tw_signature(string $url, array $params, string $token): string
{
    ksort($params, SORT_STRING);
    $data = $url;
    foreach ($params as $k => $v) {
        $data .= $k . $v;
    }

    return base64_encode(hash_hmac('sha1', $data, $token, true));
}

// ── sending ─────────────────────────────────────────────────────────────

/**
 * A text from Crewvia. In sandbox it is recorded and not sent.
 *
 * @return array{0:?string,1:string} refusal or null, and what happened (sandbox, sent)
 */
function tw_send_text(int $candidateId, int $templateId, string $body, int $by): array
{
    $mode = tw_mode();
    $body = trim($body);
    if ($mode === 'disabled' || ! tw_configured()) { return [t('Texting through Crewvia is not set up.'), '']; }
    $c = row('SELECT id, phone, stage FROM candidates WHERE id = ?', [$candidateId]);
    if (! $c) { return [t('That person does not exist.'), '']; }
    $to = msg_phone($c['phone']);
    if ($to === null || ! str_starts_with($to, '+')) { return [t('No telephone number on file'), '']; }
    if (msg_preferences($candidateId)['no_texts_since'] !== null) { return [t('This person asked not to be texted.'), '']; }
    if (mb_strlen($body) < 1 || mb_strlen($body) > 1600) { return [t('A text is 1 to 1600 characters.'), '']; }
    if ($mode === 'sandbox') {
        q("INSERT INTO sms_messages (candidate_id, user_id, direction, phone, body, template_id, mode, status) VALUES (?,?,'out',?,?,?,'sandbox','sandbox')",
          [$candidateId, $by, $to, $body, $templateId ?: null]);
        integration_event('twilio', 'send', 'ok', 'sandbox', 'Sandbox: recorded, not sent.');

        return [null, 'sandbox'];
    }
    $cred = tw_credentials();
    $fields = ['To' => $to, 'Body' => $body, 'StatusCallback' => tw_public_url('kind=status')];
    if (! empty($cred['messaging_service_sid'])) { $fields['MessagingServiceSid'] = $cred['messaging_service_sid']; } else { $fields['From'] = $cred['from_number']; }
    // A send is never repeated blindly: one attempt.
    $r = integration_request('twilio', 'POST', tw_api() . '/Accounts/' . $cred['account_sid'] . '/Messages.json',
                             [tw_auth($cred), 'Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], http_build_query($fields), false, 20);
    $out = json_decode((string) $r['body'], true) ?: [];
    if ((int) $r['status'] !== 201 || empty($out['sid'])) {
        q("INSERT INTO sms_messages (candidate_id, user_id, direction, phone, body, template_id, mode, status, error_code) VALUES (?,?,'out',?,?,?,'live','failed',?)",
          [$candidateId, $by, $to, $body, $templateId ?: null, isset($out['code']) ? (string) $out['code'] : (string) $r['status']]);
        integration_event('twilio', 'send', 'failed', null, 'HTTP ' . (int) $r['status'] . (isset($out['code']) ? ', Twilio error ' . $out['code'] : ''));

        return [t('Twilio did not take the text (:why). Nothing was sent.', ['why' => isset($out['code']) ? 'error ' . $out['code'] : 'HTTP ' . (int) $r['status']]), ''];
    }
    q("INSERT INTO sms_messages (candidate_id, user_id, direction, phone, body, template_id, mode, provider_sid, status) VALUES (?,?,'out',?,?,?,'live',?,?)",
      [$candidateId, $by, $to, $body, $templateId ?: null, (string) $out['sid'], (string) ($out['status'] ?? 'queued')]);
    integration_event('twilio', 'send', 'ok', (string) $out['sid'], 'Queued');
    msg_log_text($candidateId, $templateId, 'Sent from Crewvia: ' . $body, $by);

    return [null, 'sent'];
}

/**
 * A call through Crewvia: Twilio rings the staff member's phone first, then
 * connects the person. In sandbox it is recorded and not placed.
 *
 * @return array{0:?string,1:string}
 */
function tw_call(int $candidateId, int $by): array
{
    $mode = tw_mode();
    $cred = outbound_disabled() ? null : tw_credentials();
    if ($mode === 'disabled' || ! $cred) { return [t('Calling through Crewvia is not set up.'), '']; }
    if (empty($cred['from_number'])) { return [t('Calls need the Twilio number, not only a messaging service.'), '']; }
    $c = row('SELECT id, phone FROM candidates WHERE id = ?', [$candidateId]);
    if (! $c) { return [t('That person does not exist.'), '']; }
    $to = msg_phone($c['phone']);
    if ($to === null || ! str_starts_with($to, '+')) { return [t('No telephone number on file'), '']; }
    if (msg_preferences($candidateId)['no_calls_since'] !== null) { return [t('This person asked not to be called.'), '']; }
    $staff = msg_phone((string) val('SELECT phone FROM users WHERE id = ?', [$by]));
    if ($staff === null || ! str_starts_with($staff, '+')) { return [t('Add your own phone number to your account first: Twilio rings you, then connects the call.'), '']; }
    $token = bin2hex(random_bytes(16));
    q('INSERT INTO voice_calls (candidate_id, user_id, staff_phone, candidate_phone, token, mode, status) VALUES (?,?,?,?,?,?,?)',
      [$candidateId, $by, $staff, $to, $token, $mode, $mode === 'sandbox' ? 'sandbox' : 'requested']);
    $id = (int) db()->lastInsertId();
    if ($mode === 'sandbox') {
        integration_event('twilio', 'call', 'ok', 'sandbox', 'Sandbox: recorded, not placed.');

        return [null, 'sandbox'];
    }
    $fields = ['To' => $staff, 'From' => $cred['from_number'], 'Url' => tw_public_url('kind=voice&call=' . $token), 'StatusCallback' => tw_public_url('kind=call')];
    $r = integration_request('twilio', 'POST', tw_api() . '/Accounts/' . $cred['account_sid'] . '/Calls.json',
                             [tw_auth($cred), 'Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], http_build_query($fields), false, 20);
    $out = json_decode((string) $r['body'], true) ?: [];
    if ((int) $r['status'] !== 201 || empty($out['sid'])) {
        q("UPDATE voice_calls SET status = 'failed' WHERE id = ?", [$id]);
        integration_event('twilio', 'call', 'failed', null, 'HTTP ' . (int) $r['status'] . (isset($out['code']) ? ', Twilio error ' . $out['code'] : ''));

        return [t('Twilio did not place the call (:why).', ['why' => isset($out['code']) ? 'error ' . $out['code'] : 'HTTP ' . (int) $r['status']]), ''];
    }
    q('UPDATE voice_calls SET provider_sid = ?, status = ? WHERE id = ?', [(string) $out['sid'], (string) ($out['status'] ?? 'queued'), $id]);
    integration_event('twilio', 'call', 'ok', (string) $out['sid'], 'Ringing the staff phone first');

    return [null, 'ringing'];
}

// ── what Twilio sends back ──────────────────────────────────────────────

/** The person a number belongs to: the most recently contacted when several share it. */
function tw_candidate_by_phone(string $e164): ?int
{
    $digits = preg_replace('/\D/', '', $e164);
    if (strlen($digits) < 7) {
        return null;
    }
    foreach (rows('SELECT id, phone FROM candidates WHERE phone LIKE ? ORDER BY last_contact_at IS NULL, last_contact_at DESC, id DESC LIMIT 50', ['%' . substr($digits, -4)]) as $c) {
        if (msg_phone($c['phone']) === $e164) {
            return (int) $c['id'];
        }
    }

    return null;
}

/** A reply. In sandbox nothing is recorded. */
function tw_inbound(array $p): void
{
    $from = msg_phone((string) ($p['From'] ?? ''));
    $body = mb_substr(trim((string) ($p['Body'] ?? '')), 0, 1600);
    $sid = (string) ($p['MessageSid'] ?? '');
    if ($from === null || $sid === '' || val('SELECT COUNT(*) FROM sms_messages WHERE provider_sid = ?', [$sid])) {
        return;
    }
    $cid = tw_candidate_by_phone($from);
    q("INSERT INTO sms_messages (candidate_id, direction, phone, body, mode, provider_sid, status) VALUES (?,'in',?,?,'live',?,'received')", [$cid, $from, $body, $sid]);
    if (! $cid) {
        integration_event('twilio', 'inbound', 'ignored', $sid, 'From a number that is nobody on file.');
        return;
    }
    $word = strtoupper(trim(preg_replace('/[^A-Za-z]/', '', $body)));
    $owner = (int) val('SELECT owner_id FROM candidates WHERE id = ?', [$cid]);
    $pref = msg_preferences($cid);
    if (in_array($word, TW_STOP, true)) {
        msg_preferences_save($cid, true, $pref['no_calls_since'] !== null, TW_STOP_REASON, $owner ?: (int) val("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1"));
    } elseif (in_array($word, TW_START, true) && $pref['no_texts_since'] !== null && $pref['reason'] === TW_STOP_REASON) {
        msg_preferences_save($cid, false, $pref['no_calls_since'] !== null, $pref['no_calls_since'] !== null ? (string) $pref['reason'] : '', $owner ?: (int) val("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1"));
    }
    q("INSERT INTO candidate_calls (candidate_id, user_id, outcome, note) VALUES (?,?,'replied_text',?)",
      [$cid, $owner ?: (int) val("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1"), mb_substr('Reply: ' . $body, 0, 2000)]);
    q('UPDATE candidates SET last_contact_at = NOW() WHERE id = ?', [$cid]);
    if ($owner) {
        q('INSERT INTO notifications (user_id, message, target) VALUES (?,?,?)', [$owner, mb_substr(t('Text reply: :body', ['body' => $body]), 0, 255), '/candidates/' . $cid . '#contact']);
    }
    integration_event('twilio', 'inbound', 'ok', $sid, in_array($word, TW_STOP, true) ? 'STOP recorded' : 'Reply recorded');
}

/**
 * One webhook from Twilio. Returns the HTTP status and the TwiML to answer.
 *
 * @return array{0:int,1:string}
 */
function tw_webhook(string $kind, array $params, string $signature, string $url): array
{
    $empty = '<?xml version="1.0" encoding="UTF-8"?><Response/>';
    $cred = tw_credentials();
    $mode = tw_mode();
    if (! $cred || $mode === 'disabled') {
        integration_refusal('twilio', 'webhook', $kind, 'Not set up');
        return [403, $empty];
    }
    if (! hash_equals(tw_signature($url, $params, (string) $cred['auth_token']), $signature)) {
        integration_refusal('twilio', 'webhook', $kind, 'Signature does not match');
        return [403, $empty];
    }
    if ($mode === 'sandbox') {
        integration_event('twilio', 'webhook', 'ignored', $kind, 'Sandbox: verified, nothing changed.');
        return [200, $empty];
    }
    if ($kind === 'status' && ! empty($params['MessageSid'])) {
        q('UPDATE sms_messages SET status = ?, error_code = ? WHERE provider_sid = ?',
          [mb_substr((string) ($params['MessageStatus'] ?? 'unknown'), 0, 20), ($params['ErrorCode'] ?? '') !== '' ? mb_substr((string) $params['ErrorCode'], 0, 20) : null, (string) $params['MessageSid']]);
        return [200, $empty];
    }
    if ($kind === 'inbound') {
        tw_inbound($params);
        return [200, $empty];
    }
    if ($kind === 'call' && ! empty($params['CallSid'])) {
        q('UPDATE voice_calls SET status = ? WHERE provider_sid = ?', [mb_substr((string) ($params['CallStatus'] ?? 'unknown'), 0, 20), (string) $params['CallSid']]);
        return [200, $empty];
    }
    if ($kind === 'voice') {
        $call = row("SELECT * FROM voice_calls WHERE token = ? AND mode = 'live' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)", [(string) ($_GET['call'] ?? '')]);
        if (! $call) {
            return [200, '<?xml version="1.0" encoding="UTF-8"?><Response><Hangup/></Response>'];
        }
        $x = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return [200, '<?xml version="1.0" encoding="UTF-8"?><Response><Say>' . $x(t('Connecting your call.')) . '</Say><Dial callerId="' . $x((string) $cred['from_number']) . '">'
                     . $x((string) $call['candidate_phone']) . '</Dial></Response>'];
    }

    return [200, $empty];
}
