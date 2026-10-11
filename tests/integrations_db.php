<?php
/**
 * P4-M04 on the isolated test database only, with a test double in place
 * of every provider: no request leaves this machine. Gmail, DocuSign and
 * Stripe are switched on for this process alone, with synthetic settings;
 * the test application's configuration keeps them off.
 *
 *   rollback, upgrade, silence on a repeat
 *   retries: what is repeated, how often, how long it waits
 *   Gmail: refresh, refusal and reconnect, scopes, revocation, one-time keys
 *   DocuSign: the JWT grant and its cache, a fresh token after 401,
 *             the webhook, the Connect queue with backoff, failure, requeue
 *   Stripe: idempotent retries, rotated secrets, duplicate, ignored, failed
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: this test only runs against rss_ops_test.\n"); exit(2); }

$results = [];
function check(string $label, bool $condition, string $detail = ''): void
{
    global $results;
    if (! $condition) { fwrite(STDERR, 'FAIL ' . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL); exit(1); }
    $results[] = $label;
    echo 'PASS ' . $label . PHP_EOL;
}
function sql_file(string $path): void
{
    foreach (preg_split('/;\s*\n/', (string) file_get_contents($path)) as $chunk) {
        $lines = array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'));
        $statement = trim(implode("\n", $lines));
        if ($statement !== '') { db()->exec($statement); }
    }
}
function run_php(string $script): array
{
    $out = []; $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}
$table = static fn(string $t): bool => (bool) val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
$col = static fn(string $t, string $c): bool => (bool) val('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
$app = __DIR__ . '/test-app';

check('P4-M04 is in place before the rollback', $table('integration_events') && $col('gmail_connections', 'status') && $col('docusign_connect_events', 'next_attempt_at'));
sql_file($app . '/install/rollback/p4-m04.sql');
check('Rollback removes the P4-M04 tables and columns, and keeps the connections and events',
      ! $table('integration_events') && ! $table('integration_tokens') && ! $table('integration_nonces') && ! $col('gmail_connections', 'status')
      && ! $col('docusign_connect_events', 'last_error') && $table('gmail_connections') && $table('docusign_connect_events'));
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback announces the integrations, every provider off', $code === 0 && str_contains($first, 'Integrations: Gmail, DocuSign and Stripe calls are audited')
      && str_contains($first, 'every provider stays off until configured'), $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P4-M04', $code === 0 && ! str_contains($second, 'Integrations:'), $second);
[$code, $out] = run_php($app . '/install/reconcile-contracts.php');
check('The Connect worker, with DocuSign off, makes no provider request', $code === 0 && str_contains($out, 'DocuSign disabled. No provider requests made.'), $out);

require_once $app . '/app/integrations.php';
require_once $app . '/app/gmail.php';
require_once $app . '/app/docusign.php';
require_once $app . '/app/saas.php';
require_once $app . '/app/contracts.php';

// ── the test double ──────────────────────────────────────────────────────
$calls = [];
$queue = [];
integration_transport(static function (array $req) use (&$calls, &$queue): array {
    $calls[] = $req;
    foreach ($queue as $i => [$match, $response]) {
        if (str_contains($req['url'], $match)) {
            array_splice($queue, $i, 1);
            return $response + ['headers' => [], 'body' => ''];
        }
    }
    return ['status' => 599, 'headers' => [], 'body' => 'no double for ' . $req['url']];
});
$waits = [];
integration_sleep(static function (float $s) use (&$waits): void { $waits[] = $s; });
$reply = static function (string $match, int $status, $body = '', array $headers = []) use (&$queue): void {
    $queue[] = [$match, ['status' => $status, 'body' => is_array($body) ? json_encode($body) : $body, 'headers' => $headers]];
};
$reset = static function () use (&$calls, &$queue, &$waits): void { $calls = []; $queue = []; $waits = []; };
$events = static fn(string $provider, string $since): array => rows('SELECT action, outcome, reference, detail FROM integration_events WHERE provider = ? AND id > ? ORDER BY id', [$provider, $since]);
$mark = static fn(): string => (string) (val('SELECT COALESCE(MAX(id), 0) FROM integration_events') ?? 0);

// ── retries ──────────────────────────────────────────────────────────────
$reset(); $m = $mark();
$reply('example.invalid/a', 503); $reply('example.invalid/a', 503); $reply('example.invalid/a', 200, '{"ok":1}');
$r = integration_request('stripe', 'GET', 'https://example.invalid/a', [], null, true);
check('A safe call failing 503 twice is tried a third time and succeeds', $r['status'] === 200 && count($calls) === 3);
check('waiting half a second, then a second, each retry recorded', $waits === [0.5, 1.0] && count(array_filter($events('stripe', $m), fn($e) => $e['outcome'] === 'retry')) === 2, json_encode($waits));
$reset();
$reply('example.invalid/b', 429, '', ['retry-after' => '2']); $reply('example.invalid/b', 200);
integration_request('stripe', 'GET', 'https://example.invalid/b', [], null, true);
check('A 429 waits what Retry-After asks', $waits === [2.0]);
$reset();
$reply('example.invalid/c', 429, '', ['retry-after' => '120']); $reply('example.invalid/c', 200);
integration_request('stripe', 'GET', 'https://example.invalid/c', [], null, true);
check('but never more than 5 seconds', $waits === [5.0]);
$reset();
$reply('example.invalid/d', 0); $reply('example.invalid/d', 200);
check('A dropped connection is retried', integration_request('stripe', 'GET', 'https://example.invalid/d', [], null, true)['status'] === 200 && count($calls) === 2);
$reset();
$reply('example.invalid/e', 503);
check('A call that is not safe to repeat is sent once', integration_request('stripe', 'POST', 'https://example.invalid/e', [], 'x', false)['status'] === 503 && count($calls) === 1 && ! $waits);
$reset();
$reply('example.invalid/f', 404);
check('A 404 is not retried', integration_request('stripe', 'GET', 'https://example.invalid/f', [], null, true)['status'] === 404 && count($calls) === 1);
$reset();
$reply('example.invalid/g', 503); $reply('example.invalid/g', 503); $reply('example.invalid/g', 503); $reply('example.invalid/g', 200);
check('Three attempts at most', integration_request('stripe', 'GET', 'https://example.invalid/g', [], null, true)['status'] === 503 && count($calls) === 3);

// ── Gmail ────────────────────────────────────────────────────────────────
$config['google_client_id'] = 'unit-client.apps.googleusercontent.com';
$config['google_client_secret'] = 'unit-client-secret';
$config['google_redirect_uri'] = 'https://unit.invalid/google-callback';
check('Gmail is on for this test only', gmail_configured());
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$user = static function (string $k, string $role) use ($password): int {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Integrations ' . $k, 'int-' . $k . '@test.invalid', $password, $role]);
    return (int) db()->lastInsertId();
};
$u1 = $user('mail', 'recruiter');
$u2 = $user('mail2', 'recruiter');
$admin = $user('admin', 'admin');
$scopes = 'openid email https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.send';
check('Read and send granted: the scopes are enough', gmail_scopes_ok($scopes));
check('Without send, they are not', ! gmail_scopes_ok('openid email https://www.googleapis.com/auth/gmail.readonly'));

$m = $mark();
gmail_store_connection($u1, 'int-mail@test.invalid', ['access_token' => 'unit-access-old', 'refresh_token' => 'unit-refresh-1', 'expires_in' => -120, 'scope' => $scopes]);
$stored = row('SELECT * FROM gmail_connections WHERE user_id = ?', [$u1]);
check('A connection is stored encrypted, active, with its scopes', $stored['status'] === 'active' && str_contains((string) $stored['scopes'], 'gmail.send')
      && ! str_contains($stored['encrypted_tokens'], 'unit-refresh-1') && token_decrypt($stored['encrypted_tokens'])['refresh_token'] === 'unit-refresh-1');
$reset();
$reply('oauth2.googleapis.com/token', 200, ['access_token' => 'unit-access-new', 'expires_in' => 3600]);
check('An expired token is refreshed', gmail_access_token($u1) === 'unit-access-new' && count($calls) === 1);
parse_str((string) $calls[0]['body'], $form);
check('with the refresh token, by the refresh grant', $form['grant_type'] === 'refresh_token' && $form['refresh_token'] === 'unit-refresh-1');
$tokens = token_decrypt((string) val('SELECT encrypted_tokens FROM gmail_connections WHERE user_id = ?', [$u1]));
check('The new token is stored encrypted, the refresh token kept', $tokens['access_token'] === 'unit-access-new' && $tokens['refresh_token'] === 'unit-refresh-1'
      && val('SELECT last_refreshed_at FROM gmail_connections WHERE user_id = ?', [$u1]) !== null);
$reset();
check('A token still valid is used without calling Google', gmail_access_token($u1) === 'unit-access-new' && ! $calls);
check('The connection and the refresh are in the audit trail', array_column($events('gmail', $m), 'action') === ['connected', 'token refresh']);

q('UPDATE gmail_connections SET encrypted_tokens = ? WHERE user_id = ?', [token_encrypt(['access_token' => 'x', 'refresh_token' => 'unit-refresh-1', 'expires_at' => time() - 10]), $u1]);
$reset(); $m = $mark();
$reply('oauth2.googleapis.com/token', 503); $reply('oauth2.googleapis.com/token', 503); $reply('oauth2.googleapis.com/token', 503);
try { gmail_access_token($u1); $threw = false; } catch (RuntimeException $e) { $threw = true; }
check('Google unavailable: the refresh is tried three times, the connection stays active', $threw && count($calls) === 3
      && val('SELECT status FROM gmail_connections WHERE user_id = ?', [$u1]) === 'active' && in_array('failed', array_column($events('gmail', $m), 'outcome'), true));
$reset(); $m = $mark();
$reply('oauth2.googleapis.com/token', 400, ['error' => 'invalid_grant']);
try { gmail_access_token($u1); $msg = ''; } catch (RuntimeException $e) { $msg = $e->getMessage(); }
check('Google refuses the refresh token: the connection is marked to reconnect', $msg === 'Reconnect Gmail to renew access.'
      && val('SELECT status FROM gmail_connections WHERE user_id = ?', [$u1]) === 'reconnect' && $events('gmail', $m)[0]['outcome'] === 'refused');
$reset();
try { gmail_access_token($u1); } catch (RuntimeException $e) {}
check('and nothing more is asked of Google until it is reconnected', ! $calls);
gmail_store_connection($u2, 'int-mail2@test.invalid', ['access_token' => 'a', 'refresh_token' => 'unit-refresh-2', 'expires_in' => 3600, 'scope' => $scopes]);
q('UPDATE gmail_connections SET encrypted_tokens = ? WHERE user_id = ?', ['bm90IGEgdG9rZW4gYXQgYWxsLCBqdXN0IGdhcmJhZ2UgZm9yIHRoZSB0ZXN0', $u2]);
try { gmail_access_token($u2); } catch (RuntimeException $e) {}
check('A token that no longer decrypts marks it to reconnect too', val('SELECT status FROM gmail_connections WHERE user_id = ?', [$u2]) === 'reconnect');
gmail_store_connection($u2, 'int-mail2@test.invalid', ['access_token' => 'a', 'refresh_token' => 'unit-refresh-2', 'expires_in' => 3600, 'scope' => $scopes]);
check('Connecting again makes it active', val('SELECT status FROM gmail_connections WHERE user_id = ?', [$u2]) === 'active');

$reset(); $m = $mark();
$reply('oauth2.googleapis.com/revoke', 200);
gmail_disconnect($u2);
parse_str((string) ($calls[0]['body'] ?? ''), $form);
check('Disconnecting revokes the token at Google, then deletes it here', count($calls) === 1 && $form['token'] === 'unit-refresh-2'
      && ! val('SELECT COUNT(*) FROM gmail_connections WHERE user_id = ?', [$u2]) && array_column($events('gmail', $m), 'outcome') === ['ok', 'ok']);
gmail_store_connection($u2, 'int-mail2@test.invalid', ['access_token' => 'a', 'refresh_token' => 'unit-refresh-3', 'expires_in' => 3600, 'scope' => $scopes]);
$reset(); $m = $mark();
$reply('oauth2.googleapis.com/revoke', 400);
gmail_disconnect($u2);
check('Google not confirming the revocation: deleted here all the same, and recorded', ! val('SELECT COUNT(*) FROM gmail_connections WHERE user_id = ?', [$u2])
      && $events('gmail', $m)[0]['action'] === 'revoke' && $events('gmail', $m)[0]['outcome'] === 'failed');
$reset();
$reply('gmail.googleapis.com/gmail/v1/users/me/messages/send', 503);
try { google_http('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', ['raw' => 'x'], 'token', true); } catch (RuntimeException $e) {}
check('A message send is never repeated blindly', count($calls) === 1);

$_SESSION['user'] = ['id' => $u1, 'name' => 'Integrations mail', 'email' => 'int-mail@test.invalid', 'role' => 'recruiter', 'locale' => 'en'];
$n = integration_nonce('gmail-send');
check('A form key is used once', integration_nonce_claim('gmail-send', $n) && ! integration_nonce_claim('gmail-send', $n));
$n2 = integration_nonce('gmail-send');
check('not for another purpose', ! integration_nonce_claim('other', $n2) && integration_nonce_claim('gmail-send', $n2));
$n3 = integration_nonce('gmail-send');
$_SESSION['user']['id'] = $u2;
check('not by another user', ! integration_nonce_claim('gmail-send', $n3));
check('and a malformed key is refused', ! integration_nonce_claim('gmail-send', 'not-a-key'));
unset($_SESSION['user']);

// ── DocuSign ─────────────────────────────────────────────────────────────
// A key pair made for this run only, never stored. On Windows PHP needs its own openssl.cnf.
$sslConf = array_values(array_filter([getenv('OPENSSL_CONF') ?: '', dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf'], 'is_file'))[0] ?? null;
$sslOpts = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + ($sslConf ? ['config' => $sslConf] : []);
$pair = openssl_pkey_new($sslOpts);
check('A throwaway RSA key pair is made for the JWT grant', $pair !== false);
openssl_pkey_export($pair, $privatePem, null, $sslConf ? ['config' => $sslConf] : []);
$publicPem = openssl_pkey_get_details($pair)['key'];
$hmac = 'unit-connect-secret-0123456789';
$config['docusign'] = ['enabled' => true, 'account_id' => 'unit-acct-1234', 'base_url' => 'https://demo.docusign.net/restapi',
    'jwt' => ['integration_key' => 'unit-ik-1234abcd', 'user_id' => 'unit-user-5678efgh', 'oauth_host' => 'account-d.docusign.com', 'private_key' => $privatePem],
    'connect_hmac_secrets' => ['unit-old-secret-0123456789', $hmac]];
$s = docusign_settings();
check('DocuSign configured by the JWT grant alone, without a static token', $s['account_id'] === 'unit-acct-1234' && empty($s['access_token']));
$reset(); $m = $mark();
$reply('account-d.docusign.com/oauth/token', 200, ['access_token' => 'unit-ds-token-1', 'expires_in' => 3600]);
check('The JWT grant obtains a token', docusign_access_token($s) === 'unit-ds-token-1' && count($calls) === 1 && $calls[0]['url'] === 'https://account-d.docusign.com/oauth/token');
parse_str((string) $calls[0]['body'], $form);
[$h, $c, $sig] = explode('.', $form['assertion']);
$dec = static fn(string $p): string => base64_decode(strtr($p, '-_', '+/') . str_repeat('=', (4 - strlen($p) % 4) % 4));
$head = json_decode($dec($h), true);
$claims = json_decode($dec($c), true);
check('The assertion is RS256 for the integration key, the user, the OAuth host, signature and impersonation, one hour',
      $form['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer' && $head['alg'] === 'RS256' && $claims['iss'] === 'unit-ik-1234abcd'
      && $claims['sub'] === 'unit-user-5678efgh' && $claims['aud'] === 'account-d.docusign.com' && $claims['scope'] === 'signature impersonation' && $claims['exp'] - $claims['iat'] === 3600, json_encode($claims));
check('and its signature verifies with the public key', openssl_verify($h . '.' . $c, $dec($sig), $publicPem, OPENSSL_ALGO_SHA256) === 1);
$reset();
check('The token is cached: no second request', docusign_access_token($s) === 'unit-ds-token-1' && ! $calls);
check('and stored encrypted', ! str_contains((string) val("SELECT encrypted_token FROM integration_tokens WHERE provider = 'docusign'"), 'unit-ds-token-1'));
q("UPDATE integration_tokens SET expires_at = DATE_ADD(NOW(), INTERVAL 2 MINUTE) WHERE provider = 'docusign'");
$reset();
$reply('account-d.docusign.com/oauth/token', 200, ['access_token' => 'unit-ds-token-2', 'expires_in' => 3600]);
check('Within 5 minutes of expiry a new one is asked for', docusign_access_token($s) === 'unit-ds-token-2' && count($calls) === 1);
$reset();
$reply('/envelopes/unit-env-1', 401); $reply('account-d.docusign.com/oauth/token', 200, ['access_token' => 'unit-ds-token-3', 'expires_in' => 3600]);
$reply('/envelopes/unit-env-1', 200, ['status' => 'sent']);
$state = docusign_request('GET', '/envelopes/unit-env-1');
check('A 401 from the API: one fresh token, and the call again with it', $state['status'] === 'sent' && count($calls) === 3
      && in_array('Authorization: Bearer unit-ds-token-3', $calls[2]['headers'], true));
$reset();
$reply('/envelopes', 503);
try { docusign_request('POST', '/envelopes', ['emailSubject' => 'x']); $threw = false; } catch (RuntimeException $e) { $threw = true; }
check('An envelope is never sent twice: a failed send is not repeated', $threw && count($calls) === 1);
q("DELETE FROM integration_tokens WHERE provider = 'docusign'");
$reset(); $m = $mark();
$reply('account-d.docusign.com/oauth/token', 400, ['error' => 'consent_required']);
try { docusign_access_token($s); $threw = false; } catch (RuntimeException $e) { $threw = true; }
check('A refused grant is an error, recorded', $threw && $events('docusign', $m)[0]['outcome'] === 'refused' && str_contains((string) $events('docusign', $m)[0]['detail'], 'consent'));

// A contract sent through DocuSign, with everything its reconciliation reads.
q("INSERT INTO clients(name) VALUES ('Integrations client')");
q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status) VALUES (?,'P4-M04 project','Synthetic.',30,50,0,0,0,'active')", [(int) db()->lastInsertId()]);
$job = (int) db()->lastInsertId();
q("INSERT INTO vacancies(job_id,title,description,is_open,openings) VALUES (?,'Signer','Synthetic.',1,1)", [$job]);
$vac = (int) db()->lastInsertId();
q("INSERT INTO candidates(full_name,email,stage) VALUES ('Sig Nature','int-signer@test.invalid','offered')");
$cand = (int) db()->lastInsertId();
$worker = $user('signer', 'worker');
q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$worker, $cand]);
q("INSERT INTO applications(candidate_id,vacancy_id,stage,source) VALUES (?,?,'offered','unit')", [$cand, $vac]);
$app = (int) db()->lastInsertId();
q("INSERT INTO employment_contracts(application_id,revision,title,terms,content_hash,method,status,expires_at,created_by,envelope_id,signature_page,signature_x,signature_y)
   VALUES (?,1,'Unit contract','Terms',?,'docusign','issued',DATE_ADD(NOW(), INTERVAL 10 DAY),?,'unit-env-1',1,40,60)", [$app, hash('sha256', 'Terms'), $admin]);
$contract = (int) db()->lastInsertId();

$body = json_encode(['event' => 'envelope-sent', 'data' => ['accountId' => 'unit-acct-1234', 'envelopeId' => 'unit-env-1']]);
$sign = static fn(string $raw, string $secret): string => base64_encode(hash_hmac('sha256', $raw, $secret, true));
$m = $mark();
check('Connect, DocuSign off: refused 503', docusign_webhook_handle($body, [$sign($body, $hmac)], ['enabled' => false]) === 503);
check('a wrong signature: 401', docusign_webhook_handle($body, [$sign($body, 'another-secret-0123456789')], $config['docusign']) === 401);
check('signed with the secret being rotated out: still accepted', docusign_webhook_handle($body, ['', $sign($body, 'unit-old-secret-0123456789')], $config['docusign']) === 200);
check('the same notification again is a duplicate, queued once', docusign_webhook_handle($body, [$sign($body, $hmac)], $config['docusign']) === 200
      && (int) val("SELECT COUNT(*) FROM docusign_connect_events WHERE envelope_id = 'unit-env-1'") === 1);
$other = json_encode(['data' => ['accountId' => 'unit-acct-1234', 'envelopeId' => 'not-ours-999']]);
check('an envelope not sent from Crewvia: 422', docusign_webhook_handle($other, [$sign($other, $hmac)], $config['docusign']) === 422);
check('not JSON: 400', docusign_webhook_handle('nope', [$sign('nope', $hmac)], $config['docusign']) === 400);
check('Each outcome is recorded', array_column($events('docusign', $m), 'outcome') === ['refused', 'refused', 'ok', 'duplicate', 'refused', 'refused'], json_encode(array_column($events('docusign', $m), 'outcome')));

$reset(); $m = $mark();
$reply('account-d.docusign.com/oauth/token', 200, ['access_token' => 'unit-ds-token-4', 'expires_in' => 3600]);
$reply('/envelopes/unit-env-1', 200, ['status' => 'sent']);
$done = docusign_process_connect_events();
check('The queue reconciles the envelope by asking DocuSign itself, and the event is processed', $done['processed'] === 1
      && val("SELECT status FROM docusign_connect_events WHERE envelope_id = 'unit-env-1'") === 'processed'
      && val("SELECT action FROM contract_events WHERE contract_id = ? ORDER BY id DESC LIMIT 1", [$contract]) === 'provider_status');
q("INSERT INTO docusign_connect_events(payload_hash,envelope_id) VALUES (?, 'unit-env-gone')", [hash('sha256', 'gone')]);
$done = docusign_process_connect_events();
$ev = row("SELECT * FROM docusign_connect_events WHERE envelope_id = 'unit-env-gone'");
check('A failure waits 2 minutes before the next attempt, with its reason', $done['retry'] === 1 && (int) $ev['attempts'] === 1 && $ev['status'] === 'pending'
      && $ev['last_error'] === 'Unknown contract' && strtotime($ev['next_attempt_at']) > time() + 60);
check('Not yet due, it is left alone', docusign_process_connect_events()['retry'] === 0);
q("UPDATE docusign_connect_events SET attempts = 4, next_attempt_at = NULL WHERE envelope_id = 'unit-env-gone'");
$done = docusign_process_connect_events();
check('After the fifth attempt it is marked failed, and recorded', $done['failed'] === 1 && val("SELECT status FROM docusign_connect_events WHERE envelope_id = 'unit-env-gone'") === 'failed'
      && in_array('failed', array_column($events('docusign', $m), 'outcome'), true));
check('A failed event is never picked up again by itself', docusign_process_connect_events()['failed'] === 0);
check('An administrator requeues it from the first attempt', docusign_connect_requeue(hash('sha256', 'gone'))
      && (int) val("SELECT attempts FROM docusign_connect_events WHERE envelope_id = 'unit-env-gone'") === 0 && ! docusign_connect_requeue(hash('sha256', 'gone')));
q("UPDATE docusign_connect_events SET status = 'failed', attempts = 5, last_error = 'Unknown contract' WHERE envelope_id = 'unit-env-gone'");

// ── Stripe ───────────────────────────────────────────────────────────────
$config['commercial_mode'] = 'subscription';
$config['tenant_id'] = 'unit-tenant';
$config['stripe_secret_key'] = 'sk_test_unitonly';
$config['stripe_price_id'] = 'price_unitonly';
$reset();
$reply('api.stripe.com/v1/customers', 500); $reply('api.stripe.com/v1/customers', 200, ['id' => 'cus_unit1']);
$cus = saas_api('POST', '/customers', ['email' => 'x@test.invalid'], 'workforce-customer-unit');
$keys = array_map(fn($c) => implode('|', array_filter($c['headers'], fn($h) => str_starts_with($h, 'Idempotency-Key:'))), $calls);
check('A Stripe write with its idempotency key is retried, with the same key', $cus['id'] === 'cus_unit1' && count($calls) === 2 && $keys[0] === 'Idempotency-Key: workforce-customer-unit' && $keys[0] === $keys[1]);
$reset();
$reply('api.stripe.com/v1/billing_portal/sessions', 500);
try { saas_api('POST', '/billing_portal/sessions', ['customer' => 'cus_unit1']); $threw = false; } catch (RuntimeException $e) { $threw = true; }
check('one without a key is sent once', $threw && count($calls) === 1);
$reset();
$reply('api.stripe.com/v1/subscriptions/sub_unit', 502); $reply('api.stripe.com/v1/subscriptions/sub_unit', 200, ['id' => 'sub_unit']);
check('a read is retried', saas_api('GET', '/subscriptions/sub_unit')['id'] === 'sub_unit' && count($calls) === 2);

$stripe = static function (array $event, string $secret, ?int $t = null): array {
    $payload = json_encode($event);
    $t ??= time();
    return [$payload, 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, $secret)];
};
$secrets = ['whsec_unit_old', 'whsec_unit_new'];
$m = $mark();
[$p, $h] = $stripe(['id' => 'evt_unitA', 'type' => 'customer.created', 'data' => ['object' => ['id' => 'cus_x']]], 'whsec_unit_new');
check('A Stripe event signed with the new secret is processed', stripe_webhook_handle($p, $h, $secrets)[0] === 200 && val("SELECT event_type FROM saas_webhook_events WHERE event_id = 'evt_unitA'") === 'customer.created');
check('the same event again is a duplicate', stripe_webhook_handle($p, $h, $secrets)[1] === '{"received":true,"duplicate":true}');
[$p, $h] = $stripe(['id' => 'evt_unitB', 'type' => 'customer.created', 'data' => ['object' => ['id' => 'cus_y']]], 'whsec_unit_old');
check('signed with the old secret during the rotation, still accepted', stripe_webhook_handle($p, $h, $secrets)[0] === 200);
[$p, $h] = $stripe(['id' => 'evt_unitC', 'type' => 'customer.created', 'data' => ['object' => ['id' => 'cus_z']]], 'whsec_someone_else');
check('signed with any other secret, refused', stripe_webhook_handle($p, $h, $secrets)[0] === 400);
[$p, $h] = $stripe(['id' => 'evt_unitD', 'type' => 'customer.created', 'data' => ['object' => ['id' => 'cus_z']]], 'whsec_unit_new', time() - 600);
check('too old, refused', stripe_webhook_handle($p, $h, $secrets)[0] === 400);
[$p, $h] = $stripe(['id' => 'evt_unitE', 'type' => 'customer.subscription.updated', 'data' => ['object' => ['id' => 'sub_other', 'metadata' => ['workspace_id' => 'another-tenant']]]], 'whsec_unit_new');
check('another workspace\'s event is ignored', stripe_webhook_handle($p, $h, $secrets)[1] === '{"received":true,"ignored":true}');
[$p, $h] = $stripe(['id' => 'evt_unitF', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_unknown', 'client_reference_id' => 'unit-tenant', 'customer' => 'cus_nobody', 'mode' => 'subscription']]], 'whsec_unit_new');
$res = stripe_webhook_handle($p, $h, $secrets);
check('one that cannot be processed answers 503 so Stripe sends it again, and is not marked done', $res[0] === 503 && ! val("SELECT COUNT(*) FROM saas_webhook_events WHERE event_id = 'evt_unitF'"));
check('Every Stripe outcome is recorded', array_column($events('stripe', $m), 'outcome') === ['ok', 'duplicate', 'ok', 'refused', 'refused', 'ignored', 'failed'], json_encode(array_column($events('stripe', $m), 'outcome')));
check('and no secret or token is ever written to the audit trail', ! val("SELECT COUNT(*) FROM integration_events WHERE detail LIKE '%whsec_%' OR detail LIKE '%unit-ds-token%' OR detail LIKE '%unit-refresh%' OR detail LIKE '%sk_test%'"));

file_put_contents(__DIR__ . '/integrations.json', json_encode(['admin' => $admin, 'reconnect_user' => $u1, 'failed_hash' => hash('sha256', 'gone')]));
file_put_contents(__DIR__ . '/int-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
