<?php
/**
 * P5-M114 on the isolated test database only, with a test double in place of Stripe: no request leaves this machine.
 *
 *   rollback, upgrade, silence on a repeat
 *   the price read from Stripe and kept; a price that does not recur monthly or yearly per seat is refused
 *   the approved renewal terms in en/fr/es: amount = contracted seats x unit price, first renewal date, where to cancel
 *   the consent record: the exact text, the amount, kept 3 years
 *   the webhook: the sign-up confirmed by email with the terms word for word; an invoice recorded at OUR amount;
 *     a failed payment alerts the customer and Fleury once per invoice; a deleted subscription is recorded
 *   cancel at the end of the period, and keep it after all
 *   the reminder 30 days before a yearly renewal, once; none for a monthly plan or a cancelled one
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

// ── the migration ────────────────────────────────────────────────────────
check('P5-M114 is in place before the rollback', $table('saas_renewal_consents') && $table('saas_invoices') && $col('saas_subscription', 'cancel_at_period_end'));
sql_file($app . '/install/rollback/p5-m114.sql');
check('Rollback removes the consent record, the invoices and the new columns, and keeps the subscription',
      ! $table('saas_renewal_consents') && ! $table('saas_invoices') && ! $col('saas_subscription', 'price_interval') && $table('saas_subscription'));
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback announces the renewal', $code === 0 && str_contains($first, 'Subscription: renews until cancelled') && $table('saas_renewal_consents') && $table('saas_invoices'), $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M114', $code === 0 && ! str_contains($second, 'Subscription: renews'), $second);
[$code, $out] = run_php($app . '/install/renewal-reminders.php');
check('The reminder job on a dedicated installation does nothing and says so', $code === 0 && str_contains($out, 'No Stripe subscription on this installation'), $out);

require_once $app . '/app/integrations.php';
require_once $app . '/app/saas-renewal.php';
require_once $app . '/app/crypto.php';

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
integration_sleep(static function (float $s): void {});
$reply = static function (string $match, int $status, $body = '') use (&$queue): void {
    $queue[] = [$match, ['status' => $status, 'body' => is_array($body) ? json_encode($body) : $body]];
};
$reset = static function () use (&$calls, &$queue): void { $calls = []; $queue = []; };
$mails = static function (int $since): array {
    $out = [];
    foreach (rows('SELECT id, recipient, purpose, encrypted_message FROM mail_outbox WHERE id > ? ORDER BY id', [$since]) as $m) {
        $c = json_decode((string) crypto_open(base64_decode((string) $m['encrypted_message'], true)), true);
        $out[] = ['to' => $m['recipient'], 'purpose' => $m['purpose'], 'subject' => $c['subject'] ?? '', 'body' => $c['body'] ?? ''];
    }
    return $out;
};
$mailMark = static fn(): int => (int) val('SELECT COALESCE(MAX(id), 0) FROM mail_outbox');

$config['commercial_mode'] = 'subscription';
$config['tenant_id'] = 'unit-tenant';
$config['stripe_secret_key'] = 'sk_test_unitonly';
$config['stripe_price_id'] = 'price_unitm';
$config['app_url'] = 'https://unit.test';
$config['billing_report_to'] = 'billing-report@test.invalid';
q("UPDATE saas_subscription SET stripe_customer_id = NULL, stripe_subscription_id = NULL, status = 'not_configured', quantity = 0, period_end = NULL,
   last_checkout_id = NULL, cancel_at_period_end = 0, price_id = NULL, price_unit_amount = NULL, reminder_sent_for = NULL WHERE id = 1");
$monthly = ['id' => 'price_unitm', 'type' => 'recurring', 'recurring' => ['interval' => 'month', 'interval_count' => 1, 'usage_type' => 'licensed'],
            'billing_scheme' => 'per_unit', 'unit_amount' => 1000, 'currency' => 'usd'];

// ── the price ────────────────────────────────────────────────────────────
$reset(); $reply('api.stripe.com/v1/prices/price_unitm', 200, $monthly);
$price = saas_price(true);
check('The price is read from Stripe: $10.00 a seat, every month', $price === ['unit_amount' => 1000, 'currency' => 'usd', 'interval' => 'month'] && count($calls) === 1, json_encode($price));
$reset();
check('and kept: the next read asks Stripe nothing', saas_price() === $price && count($calls) === 0);
$config['stripe_price_id'] = 'price_unitonce';
$reset(); $reply('api.stripe.com/v1/prices/price_unitonce', 200, ['id' => 'price_unitonce', 'type' => 'one_time', 'unit_amount' => 1000, 'currency' => 'usd']);
try { saas_price(true); $refused = false; } catch (RuntimeException $e) { $refused = str_contains($e->getMessage(), 'every month or every year'); }
check('A one-time price is refused: the renewal terms would not describe it', $refused);
$reset(); $reply('api.stripe.com/v1/prices/price_unitonce', 200, ['recurring' => ['interval' => 'week', 'interval_count' => 1], 'type' => 'recurring', 'id' => 'price_unitonce', 'unit_amount' => 1000, 'currency' => 'usd']);
try { saas_price(true); $refused = false; } catch (RuntimeException $e) { $refused = true; }
check('and so is a weekly one', $refused);
$config['stripe_price_id'] = 'price_unitm';
$reset(); $reply('api.stripe.com/v1/prices/price_unitm', 200, $monthly);
saas_price(true);

// ── the terms ────────────────────────────────────────────────────────────
$now = gmmktime(12, 0, 0, 1, 31, 2027);
$en = saas_renewal_terms(25, 'en', $price, $now);
check('The approved terms in English, with seats x unit price and the first renewal (31 Jan -> 28 Feb, as Stripe bills)',
      $en['terms'] === 'Your plan renews automatically every month at $250.00 until you cancel. The first renewal is on February 28, 2027. You can cancel at any time in Administration › Subscription & workspace; you keep access until the end of the period you have paid for, and no further charge is made.'
      && $en['consent'] === 'I agree that my plan renews automatically at $250.00 every month until I cancel.' && $en['amount_cents'] === 25000, $en['terms']);
$fr = saas_renewal_terms(25, 'fr', $price, $now);
check('in French, word for word as approved',
      $fr['terms'] === "Votre forfait se renouvelle automatiquement chaque mois au prix de 250,00 \$ jusqu'à ce que vous l'annuliez. Le premier renouvellement aura lieu le 28 février 2027. Vous pouvez annuler à tout moment dans Administration › Abonnement et espace ; vous gardez l'accès jusqu'à la fin de la période déjà payée, et aucun autre prélèvement n'est effectué."
      && $fr['consent'] === "J'accepte que mon forfait se renouvelle automatiquement au prix de 250,00 \$ chaque mois jusqu'à ce que je l'annule.", $fr['terms']);
$es = saas_renewal_terms(25, 'es', $price, $now);
check('in Spanish, word for word as approved',
      str_starts_with($es['terms'], 'Su plan se renueva automáticamente cada mes por $250,00 hasta que lo cancele. La primera renovación será el 28 de febrero de 2027. Puede cancelar en cualquier momento en Administración › Suscripción y espacio;')
      && $es['consent'] === 'Acepto que mi plan se renueve automáticamente por $250,00 cada mes hasta que lo cancele.', $es['terms']);
$yr = saas_renewal_terms(3, 'fr', ['unit_amount' => 12000, 'currency' => 'usd', 'interval' => 'year'], $now);
check('A yearly price renews "chaque an", a year later', str_contains($yr['terms'], 'chaque an au prix de 360,00 $') && str_contains($yr['terms'], 'le 31 janvier 2028'), $yr['terms']);
check('The terms carry a fingerprint, so what is agreed is what was shown', $en['hash'] !== $fr['hash'] && $en['hash'] === saas_renewal_terms(25, 'en', $price, $now)['hash']);

// ── the consent record ───────────────────────────────────────────────────
$admin = (int) val("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
$terms = saas_renewal_terms(25, 'fr', $price, time());
$cid = saas_record_consent($terms, 25, 'fr', $admin, 'subscriber@test.invalid', str_repeat('a', 32), '127.0.0.1');
$c = row('SELECT * FROM saas_renewal_consents WHERE id = ?', [$cid]);
check('The consent keeps who, when, the exact text shown, the amount and the seats', $c['terms_text'] === $terms['terms'] && $c['consent_text'] === $terms['consent']
      && (int) $c['amount_cents'] === 25000 && $c['currency'] === 'usd' && $c['period'] === 'month' && (int) $c['quantity'] === 25 && $c['email'] === 'subscriber@test.invalid' && (int) $c['user_id'] === $admin);
check('and is kept 3 years', $c['keep_until'] === date('Y-m-d', strtotime('+3 years', strtotime(date('Y-m-d')))), (string) $c['keep_until']);

// ── the webhook ──────────────────────────────────────────────────────────
q("UPDATE saas_subscription SET stripe_customer_id = 'cus_r1', last_checkout_id = 'cs_r1' WHERE id = 1");
q("UPDATE saas_renewal_consents SET checkout_session_id = 'cs_r1' WHERE id = ?", [$cid]);
$end = time() + 30 * 86400;
$remote = static fn(string $status = 'active', bool $cancel = false): array => ['id' => 'sub_r1', 'customer' => 'cus_r1', 'status' => $status, 'metadata' => ['workspace_id' => 'unit-tenant'],
    'current_period_end' => $end, 'cancel_at_period_end' => $cancel, 'items' => ['data' => [['price' => ['id' => 'price_unitm'], 'quantity' => 25]]]];
$secrets = ['whsec_unit_renewal'];
$stripe = static function (array $event) use ($secrets): array {
    $payload = json_encode($event); $t = time();
    return [$payload, 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, $secrets[0])];
};
$m = $mailMark();
$reset(); $reply('api.stripe.com/v1/subscriptions/sub_r1', 200, $remote());
[$p, $h] = $stripe(['id' => 'evt_r1', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_r1', 'client_reference_id' => 'unit-tenant', 'customer' => 'cus_r1', 'mode' => 'subscription', 'subscription' => 'sub_r1']]]);
check('The sign-up is processed', stripe_webhook_handle($p, $h, $secrets)[0] === 200 && val("SELECT status FROM saas_subscription WHERE id = 1") === 'active');
$sent = $mails($m);
check('and confirmed by email, in the subscriber\'s language, the terms repeated word for word with where to cancel',
      count($sent) === 1 && $sent[0]['to'] === 'subscriber@test.invalid' && $sent[0]['subject'] === 'Votre abonnement Crewvia se renouvelle automatiquement'
      && str_contains($sent[0]['body'], $terms['terms']) && str_contains($sent[0]['body'], 'Pour annuler : https://unit.test/subscription')
      && val('SELECT confirmed_at FROM saas_renewal_consents WHERE id = ?', [$cid]) !== null, json_encode($sent));
check('The same event again sends nothing more', stripe_webhook_handle($p, $h, $secrets)[1] === '{"received":true,"duplicate":true}' && count($mails($m)) === 1);

$m = $mailMark();
$reset(); $reply('api.stripe.com/v1/subscriptions/sub_r1', 200, $remote());
[$p, $h] = $stripe(['id' => 'evt_r2', 'type' => 'invoice.paid', 'data' => ['object' => ['id' => 'in_r1', 'customer' => 'cus_r1', 'subscription' => 'sub_r1', 'amount_paid' => 999999, 'billing_reason' => 'subscription_cycle']]]);
check('A renewal paid is recorded', stripe_webhook_handle($p, $h, $secrets)[0] === 200);
$inv = row("SELECT * FROM saas_invoices WHERE stripe_invoice_id = 'in_r1'");
check('at our price x our seats ($250.00), never the amount in the event', $inv && $inv['outcome'] === 'paid' && (int) $inv['amount_cents'] === 25000 && (int) $inv['quantity'] === 25
      && $inv['billing_reason'] === 'subscription_cycle' && $inv['paid_through'] !== null && ! $mails($m), json_encode($inv));

$reset(); $reply('api.stripe.com/v1/subscriptions/sub_r1', 200, $remote('past_due'));
[$p, $h] = $stripe(['id' => 'evt_r3', 'type' => 'invoice.payment_failed', 'data' => ['object' => ['id' => 'in_r2', 'customer' => 'cus_r1', 'subscription' => 'sub_r1']]]);
check('A failed payment is recorded', stripe_webhook_handle($p, $h, $secrets)[0] === 200 && val("SELECT outcome FROM saas_invoices WHERE stripe_invoice_id = 'in_r2'") === 'failed');
$sent = $mails($m);
$to = array_column($sent, 'to');
check('and alerts the customer, in their language, and Fleury Solutions', count($sent) === 2 && in_array('subscriber@test.invalid', $to, true) && in_array('billing-report@test.invalid', $to, true)
      && str_contains($sent[0]['subject'] . $sent[1]['subject'], "le paiement de votre abonnement n'a pas abouti") && str_contains($sent[0]['body'] . $sent[1]['body'], 'Invoice: in_r2'), json_encode($sent));
$reset(); $reply('api.stripe.com/v1/subscriptions/sub_r1', 200, $remote('past_due'));
[$p, $h] = $stripe(['id' => 'evt_r4', 'type' => 'invoice.payment_failed', 'data' => ['object' => ['id' => 'in_r2', 'customer' => 'cus_r1', 'subscription' => 'sub_r1']]]);
check('A retry that fails again on the same invoice alerts nobody a second time', stripe_webhook_handle($p, $h, $secrets)[0] === 200 && count($mails($m)) === 2);

// ── cancel at the end of the period ──────────────────────────────────────
$reset(); $reply('api.stripe.com/v1/subscriptions/sub_r1', 200, $remote('active', true));
saas_set_cancel_at_period_end(true);
$sentBody = (string) ($calls[0]['body'] ?? '');
check('Cancel asks Stripe to stop at the end of the period, not now, with an idempotency key',
      $calls[0]['method'] === 'POST' && $sentBody === 'cancel_at_period_end=true' && (bool) array_filter($calls[0]['headers'], fn($x) => str_starts_with($x, 'Idempotency-Key: workforce-renewal-cancel-sub_r1'))
      && (int) val('SELECT cancel_at_period_end FROM saas_subscription WHERE id = 1') === 1, $sentBody);
$reset(); $reply('api.stripe.com/v1/subscriptions/sub_r1', 200, $remote());
saas_set_cancel_at_period_end(false);
check('and the customer can keep it after all', (string) ($calls[0]['body'] ?? '') === 'cancel_at_period_end=false' && (int) val('SELECT cancel_at_period_end FROM saas_subscription WHERE id = 1') === 0);
$reset(); $reply('api.stripe.com/v1/subscriptions/sub_r1', 200, ['id' => 'sub_r1', 'cancel_at_period_end' => false]);
try { saas_set_cancel_at_period_end(true); $held = false; } catch (RuntimeException $e) { $held = true; }
check('If Stripe does not confirm the cancellation, the screen does not claim it', $held && (int) val('SELECT cancel_at_period_end FROM saas_subscription WHERE id = 1') === 0);
$reset(); $reply('api.stripe.com/v1/subscriptions/sub_r1', 200, $remote('active', true));
[$p, $h] = $stripe(['id' => 'evt_r5', 'type' => 'customer.subscription.updated', 'data' => ['object' => ['id' => 'sub_r1', 'metadata' => ['workspace_id' => 'unit-tenant']]]]);
check('A cancellation made in the Stripe portal is recorded here too', stripe_webhook_handle($p, $h, $secrets)[0] === 200 && (int) val('SELECT cancel_at_period_end FROM saas_subscription WHERE id = 1') === 1);

// ── the yearly reminder ──────────────────────────────────────────────────
q("UPDATE saas_subscription SET status = 'active', cancel_at_period_end = 0, period_end = ?, price_interval = 'month', price_checked_at = NOW(), reminder_sent_for = NULL WHERE id = 1",
  [gmdate('Y-m-d H:i:s', time() + 30 * 86400)]);
$m = $mailMark();
check('A monthly plan gets no reminder', saas_renewal_reminders(time()) === 0 && ! $mails($m));
q("UPDATE saas_subscription SET price_interval = 'year' WHERE id = 1");
check('A yearly plan 30 days before its renewal gets one', saas_renewal_reminders(time()) === 1);
$sent = $mails($m);
check('in the subscriber\'s language, with the date, the amount and where to cancel', count($sent) === 1 && $sent[0]['to'] === 'subscriber@test.invalid'
      && str_starts_with($sent[0]['subject'], 'Votre forfait Crewvia se renouvelle le ') && str_contains($sent[0]['body'], 'renouvelé pour un an au prix de 250,00 $')
      && str_contains($sent[0]['body'], 'Administration › Abonnement et espace'), json_encode($sent));
check('once: the next run sends nothing', saas_renewal_reminders(time()) === 0 && count($mails($m)) === 1);
q('UPDATE saas_subscription SET reminder_sent_for = NULL, cancel_at_period_end = 1 WHERE id = 1');
check('A cancelled plan gets no reminder', saas_renewal_reminders(time()) === 0);
q('UPDATE saas_subscription SET cancel_at_period_end = 0, period_end = ? WHERE id = 1', [gmdate('Y-m-d H:i:s', time() + 60 * 86400)]);
check('nor one still 60 days away', saas_renewal_reminders(time()) === 0);
q('UPDATE saas_subscription SET period_end = ? WHERE id = 1', [gmdate('Y-m-d H:i:s', time() + 20 * 86400)]);
check('A run missed by ten days still sends, inside the 15-45 day window', saas_renewal_reminders(time()) === 1);

// ── the subscription deleted ─────────────────────────────────────────────
$reset(); $reply('api.stripe.com/v1/subscriptions/sub_r1', 200, $remote('canceled'));
[$p, $h] = $stripe(['id' => 'evt_r6', 'type' => 'customer.subscription.deleted', 'data' => ['object' => ['id' => 'sub_r1', 'metadata' => ['workspace_id' => 'unit-tenant']]]]);
check('A subscription deleted at the end of its period is recorded as ended', stripe_webhook_handle($p, $h, $secrets)[0] === 200 && val('SELECT status FROM saas_subscription WHERE id = 1') === 'canceled');
check('and gets no reminder', saas_renewal_reminders(time()) === 0);

// Leave the test database as the other tests expect it.
q("UPDATE saas_subscription SET stripe_customer_id = NULL, stripe_subscription_id = NULL, status = 'not_configured', quantity = 0, period_end = NULL,
   last_checkout_id = NULL, cancel_at_period_end = 0, price_id = NULL, price_unit_amount = NULL, price_currency = NULL, price_interval = NULL, reminder_sent_for = NULL WHERE id = 1");
file_put_contents(__DIR__ . '/renewal-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
