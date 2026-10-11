<?php
/**
 * What Gmail, DocuSign and Stripe share (P4-M04): one way to call a
 * provider, retries where a retry is safe, and the audit trail.
 *
 *   transport   every provider call goes through integration_request().
 *               In production it is curl with certificate checks; tests
 *               put a double in its place, so no test reaches a provider.
 *   retries     only a call that is safe to repeat is repeated: a read, a
 *               token refresh, or a Stripe call carrying its idempotency
 *               key. Up to 3 attempts on a dropped connection, 429 or 5xx,
 *               waiting 0.5 s then 1 s, or the provider's Retry-After up
 *               to 5 s. A send, an envelope or a message is never repeated
 *               blindly: those are reconciled or keyed instead.
 *   audit       integration_events keeps each outcome: ok, duplicate,
 *               ignored, retry, failed, refused. Never a token, a secret
 *               or a message body.
 */

declare(strict_types=1);

const INTEGRATION_ATTEMPTS = 3;

/** The transport in use, or set one (tests only). */
function integration_transport(?callable $set = null): callable
{
    static $transport = null;
    if ($set !== null) {
        $transport = $set;
    }

    return $transport ??= static function (array $req): array {
        $ch = curl_init($req['url']);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $req['method'], CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => (int) ($req['timeout'] ?? 30), CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => $req['headers']]);
        if ($req['body'] !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $req['body']);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if ($raw === false) {
            return ['status' => 0, 'headers' => [], 'body' => ''];
        }
        $headers = [];
        foreach (explode("\r\n", substr((string) $raw, 0, $size)) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }

        return ['status' => $status, 'headers' => $headers, 'body' => substr((string) $raw, $size)];
    };
}

/** How long to wait before another attempt; tests count instead of sleeping. */
function integration_sleep(?callable $set = null): callable
{
    static $sleep = null;
    if ($set !== null) {
        $sleep = $set;
    }

    return $sleep ??= static fn(float $seconds) => usleep((int) ($seconds * 1000000));
}

/**
 * One provider call. Returns the response; repeats it only when $retryable
 * says repeating is safe, and records each retry.
 */
function integration_request(string $provider, string $method, string $url, array $headers, ?string $body, bool $retryable, int $timeout = 30): array
{
    $response = ['status' => 0, 'headers' => [], 'body' => ''];
    for ($attempt = 1; $attempt <= ($retryable ? INTEGRATION_ATTEMPTS : 1); $attempt++) {
        $response = (integration_transport())(['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout]);
        $status = (int) $response['status'];
        if ($status !== 0 && $status !== 429 && $status < 500) {
            break;
        }
        if ($attempt < ($retryable ? INTEGRATION_ATTEMPTS : 1)) {
            $after = $response['headers']['retry-after'] ?? null;
            $wait = is_numeric($after) ? min(5.0, max(0.0, (float) $after)) : 0.5 * (2 ** ($attempt - 1));
            integration_event($provider, 'retry', 'retry', parse_url($url, PHP_URL_HOST) . parse_url($url, PHP_URL_PATH), 'HTTP ' . $status . ', attempt ' . $attempt . ', waiting ' . $wait . ' s');
            (integration_sleep())($wait);
        }
    }

    return $response;
}

/** Keep one outcome. Never pass a token, a secret or a message body. */
function integration_event(string $provider, string $action, string $outcome, ?string $reference = null, ?string $detail = null, ?int $userId = null): void
{
    try {
        q('INSERT INTO integration_events (provider, action, outcome, reference, detail, user_id) VALUES (?,?,?,?,?,?)',
          [$provider, mb_substr($action, 0, 60), $outcome, $reference === null ? null : mb_substr($reference, 0, 190), $detail === null ? null : mb_substr($detail, 0, 500), $userId ?? (uid() ?: null)]);
    } catch (Throwable $e) {
        error_log('[crewvia integrations] Audit write failed for ' . $provider . ' ' . $action);
    }
}

/** A one-time key for a form that reaches a provider. */
function integration_nonce(string $purpose): string
{
    $nonce = bin2hex(random_bytes(32));
    q('INSERT INTO integration_nonces (nonce, purpose, user_id) VALUES (?,?,?)', [$nonce, $purpose, uid()]);
    q('DELETE FROM integration_nonces WHERE user_id = ? AND purpose = ? AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)', [uid(), $purpose]);

    return $nonce;
}

/** Use a one-time key: true the first time only, for its own user and purpose. */
function integration_nonce_claim(string $purpose, string $nonce): bool
{
    if (! preg_match('/^[a-f0-9]{64}$/', $nonce)) {
        return false;
    }

    return q('UPDATE integration_nonces SET used_at = NOW() WHERE nonce = ? AND purpose = ? AND user_id = ? AND used_at IS NULL AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)',
             [$nonce, $purpose, uid()])->rowCount() === 1;
}

/** Whether each provider is switched on, without a single setting's value. */
function integration_status(): array
{
    global $config;
    require_once __DIR__ . '/gmail.php';
    require_once __DIR__ . '/saas.php';
    $ds = $config['docusign'] ?? [];

    return [
        'gmail'    => ['enabled' => gmail_configured(), 'what' => 'Google OAuth for each user\'s mailbox: read and send.'],
        'docusign' => ['enabled' => ! empty($ds['enabled']), 'what' => 'Contracts signed through DocuSign envelopes, confirmed by Connect.',
                       'auth' => ! empty($ds['jwt']['integration_key']) ? 'JWT grant' : (! empty($ds['access_token']) ? 'access token' : 'none')],
        'stripe'   => ['enabled' => saas_configured(), 'what' => 'The workspace subscription, checkout and billing portal.'],
    ];
}

/**
 * A refused webhook, recorded. The endpoints are public, so a sender's
 * refusals are recorded up to 100 an hour; past that they are only counted.
 */
function integration_refusal(string $provider, string $action, ?string $reference, string $detail): void
{
    require_once __DIR__ . '/security.php';
    $sender = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    if (security_rate_limit('integration-refusal', $provider . '|' . $sender, 100, 3600)) {
        integration_event($provider, $action, 'refused', $reference, $detail . ' (from ' . $sender . ')');
    }
}
