<?php
declare(strict_types=1);

/**
 * P5-M114: a Crewvia subscription renews until the customer cancels.
 *
 * - The price (amount, currency, month or year) is read from Stripe and kept on saas_subscription.
 * - The renewal terms approved on 19 Nov 2026 are shown before payment, in the reader's language, with an
 *   unticked checkbox the customer must tick. What was shown is kept word for word for 3 years.
 * - The customer cancels from the Subscription screen; the cancellation takes effect at the end of the
 *   paid period (cancel_at_period_end), never at once. This does not rely on the Billing Portal's
 *   account-wide setting.
 * - Each paid or failed invoice is recorded once, at OUR price x OUR seat count, never the payload's amount.
 * - A failed payment alerts the customer and Fleury Solutions (billing_report_to).
 * - A yearly plan gets a reminder 30 days before it renews. A monthly plan gets none.
 *
 * The seat count is the number the customer contracted. It is never synced from usage: that would be new
 * scope, with its own approved clause.
 */

require_once __DIR__ . '/saas.php';
require_once __DIR__ . '/notices.php';

const SAAS_CONSENT_YEARS = 3;

/** A catalogue string in a given language, whatever the request's language is. */
function saas_lt(string $loc, string $text, array $vars = []): string
{
    if ($loc !== 'en' && isset(WORKFORCE_LOCALES[$loc])) {
        $text = lang_catalogue($loc)[$text] ?? $text;
    }
    foreach ($vars as $k => $v) {
        $text = str_replace(':' . $k, (string) $v, $text);
    }

    return $text;
}

/** An amount in cents, in the convention of a language: $250.00, 250,00 $, $250,00; another currency by its code. */
function saas_money_in(int $cents, string $currency, string $loc): string
{
    $n = $cents / 100;
    $cur = strtoupper($currency);
    if ($cur !== 'USD') {
        return match ($loc) {
            'fr', 'es' => number_format($n, 2, ',', $loc === 'fr' ? ' ' : '.') . ' ' . $cur,
            default => number_format($n, 2) . ' ' . $cur,
        };
    }

    return match ($loc) {
        'fr' => number_format($n, 2, ',', ' ') . ' $',
        'es' => '$' . number_format($n, 2, ',', '.'),
        default => '$' . number_format($n, 2),
    };
}

/** A date written out in a language: November 20, 2026 / 20 novembre 2026 / 20 de noviembre de 2026. */
function saas_date_in(int $ts, string $loc): string
{
    $m = (int) gmdate('n', $ts) - 1;
    $day = gmdate('j', $ts);
    $year = gmdate('Y', $ts);

    return match ($loc) {
        'fr' => $day . ' ' . ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'][$m] . ' ' . $year,
        'es' => $day . ' de ' . ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'][$m] . ' de ' . $year,
        default => gmdate('F j, Y', $ts),
    };
}

/** One month or one year after $ts, the day clamped to the end of a shorter month, as Stripe bills (31 Jan -> 28 Feb). */
function saas_add_period(int $ts, string $interval): int
{
    $y = (int) gmdate('Y', $ts);
    $m = (int) gmdate('n', $ts);
    $d = (int) gmdate('j', $ts);
    if ($interval === 'year') {
        $y++;
    } else {
        $m++;
        if ($m > 12) { $m = 1; $y++; }
    }
    $last = (int) gmdate('t', gmmktime(0, 0, 0, $m, 1, $y));

    return gmmktime((int) gmdate('G', $ts), (int) gmdate('i', $ts), (int) gmdate('s', $ts), $m, min($d, $last), $y);
}

/**
 * The configured price, read from Stripe and kept for a day. Refused unless it is a licensed per-seat price
 * that recurs every single month or every single year: that is all the approved terms describe.
 *
 * @return array{unit_amount:int, currency:string, interval:string}
 */
function saas_price(bool $fresh = false): array
{
    global $config;
    $priceId = (string) ($config['stripe_price_id'] ?? '');
    $local = row('SELECT price_id, price_unit_amount, price_currency, price_interval, price_checked_at FROM saas_subscription WHERE id = 1');
    if (! $fresh && $local && $local['price_id'] === $priceId && $local['price_unit_amount'] !== null
        && strtotime((string) $local['price_checked_at']) > time() - 86400) {
        return ['unit_amount' => (int) $local['price_unit_amount'], 'currency' => (string) $local['price_currency'], 'interval' => (string) $local['price_interval']];
    }
    $p = saas_api('GET', '/prices/' . $priceId);
    $rec = $p['recurring'] ?? null;
    if (($p['id'] ?? '') !== $priceId || ($p['type'] ?? '') !== 'recurring' || ! is_array($rec)
        || ! in_array($rec['interval'] ?? '', ['month', 'year'], true) || (int) ($rec['interval_count'] ?? 0) !== 1
        || ($rec['usage_type'] ?? 'licensed') !== 'licensed' || ($p['billing_scheme'] ?? 'per_unit') !== 'per_unit'
        || ! is_int($p['unit_amount'] ?? null) || $p['unit_amount'] < 1 || ! preg_match('/^[a-z]{3}$/D', (string) ($p['currency'] ?? ''))) {
        throw new RuntimeException('The Stripe price must recur every month or every year, per seat.');
    }
    q('UPDATE saas_subscription SET price_id = ?, price_unit_amount = ?, price_currency = ?, price_interval = ?, price_checked_at = NOW() WHERE id = 1',
      [$priceId, $p['unit_amount'], $p['currency'], $rec['interval']]);

    return ['unit_amount' => (int) $p['unit_amount'], 'currency' => (string) $p['currency'], 'interval' => (string) $rec['interval']];
}

/** Where the customer cancels, in their language. */
function saas_cancel_place(string $loc): string
{
    return saas_lt($loc, 'Administration') . ' › ' . saas_lt($loc, 'Subscription & workspace');
}

/**
 * The approved renewal terms and the consent sentence for a number of seats, in a language.
 * {amount} is the full renewal amount: contracted seats x unit price.
 *
 * @return array{terms:string, consent:string, amount_cents:int, currency:string, period:string, first_renewal:int, hash:string}
 */
function saas_renewal_terms(int $seats, string $loc, array $price, int $now): array
{
    $cents = $seats * $price['unit_amount'];
    $first = saas_add_period($now, $price['interval']);
    $vars = ['period' => saas_lt($loc, $price['interval']), 'amount' => saas_money_in($cents, $price['currency'], $loc),
             'date' => saas_date_in($first, $loc), 'cancel' => saas_cancel_place($loc)];
    $terms = saas_lt($loc, 'Your plan renews automatically every :period at :amount until you cancel. The first renewal is on :date. You can cancel at any time in :cancel; you keep access until the end of the period you have paid for, and no further charge is made.', $vars);
    $consent = saas_lt($loc, 'I agree that my plan renews automatically at :amount every :period until I cancel.', $vars);

    return ['terms' => $terms, 'consent' => $consent, 'amount_cents' => $cents, 'currency' => $price['currency'], 'period' => $price['interval'],
            'first_renewal' => $first, 'hash' => hash('sha256', $terms . "\n" . $consent)];
}

/** Keep what the customer was shown and agreed to. Kept for 3 years; never updated except to link the Checkout session. */
function saas_record_consent(array $terms, int $seats, string $loc, ?int $userId, string $email, ?string $requestKey, ?string $ip): int
{
    q('INSERT INTO saas_renewal_consents (user_id, email, locale, terms_text, consent_text, amount_cents, currency, period, quantity, first_renewal_on, checkout_request_key, ip, keep_until)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?, DATE_ADD(CURDATE(), INTERVAL ' . SAAS_CONSENT_YEARS . ' YEAR))',
      [$userId, $email, $loc, $terms['terms'], $terms['consent'], $terms['amount_cents'], $terms['currency'], $terms['period'], $seats,
       gmdate('Y-m-d', $terms['first_renewal']), $requestKey, $ip]);

    return (int) db()->lastInsertId();
}

/**
 * Cancel the renewal at the end of the paid period, or keep the subscription after all.
 * The answer is read back from Stripe, so the screen shows what Stripe will do.
 */
function saas_set_cancel_at_period_end(bool $cancel): void
{
    $sub = row('SELECT stripe_subscription_id, status FROM saas_subscription WHERE id = 1');
    $id = (string) ($sub['stripe_subscription_id'] ?? '');
    if (! preg_match('/^sub_[a-zA-Z0-9]+$/D', $id) || in_array($sub['status'], ['canceled', 'incomplete_expired'], true)) {
        throw new RuntimeException('There is no subscription to change.');
    }
    $remote = saas_api('POST', '/subscriptions/' . $id, ['cancel_at_period_end' => $cancel ? 'true' : 'false'],
                       'workforce-renewal-' . ($cancel ? 'cancel' : 'keep') . '-' . $id . '-' . gmdate('YmdHi'));
    if (($remote['id'] ?? '') !== $id || (bool) ($remote['cancel_at_period_end'] ?? ! $cancel) !== $cancel) {
        throw new RuntimeException('Billing provider unavailable. Please retry or contact support.');
    }
    q('UPDATE saas_subscription SET cancel_at_period_end = ? WHERE id = 1', [$cancel ? 1 : 0]);
}

/** The email of the person who subscribed, and their language: the latest consent on record. */
function saas_subscriber(): ?array
{
    return row('SELECT email, locale FROM saas_renewal_consents ORDER BY id DESC LIMIT 1') ?: null;
}

function saas_subscription_link(): string
{
    global $config;

    return rtrim((string) ($config['app_url'] ?? ''), '/') . '/subscription';
}

/**
 * After a verified webhook has refreshed the subscription: confirm a sign-up, record an invoice, alert on a failure.
 * Runs inside the webhook's transaction, so a failure here makes Stripe send the event again.
 */
function saas_after_event(string $type, array $object): void
{
    global $config;
    if ($type === 'checkout.session.completed') {
        $consent = row('SELECT * FROM saas_renewal_consents WHERE checkout_session_id = ? ORDER BY id DESC LIMIT 1', [(string) ($object['id'] ?? '')]);
        if ($consent && $consent['confirmed_at'] === null) {
            q('UPDATE saas_renewal_consents SET confirmed_at = NOW() WHERE id = ?', [(int) $consent['id']]);
            $loc = (string) $consent['locale'];
            // The terms exactly as the customer was shown them, word for word, and where to cancel.
            mail_queue(null, (string) $consent['email'], saas_lt($loc, 'Your Crewvia subscription renews automatically'),
                saas_lt($loc, 'Hello,') . "\n\n" . saas_lt($loc, 'Thank you for subscribing to Crewvia. These are the terms you accepted on :date:', ['date' => saas_date_in(strtotime((string) $consent['accepted_at'] . ' UTC'), $loc)])
                . "\n\n" . $consent['terms_text'] . "\n\n" . saas_lt($loc, 'To cancel:') . ' ' . saas_subscription_link()
                . "\n\n" . 'Crewvia, ' . saas_lt($loc, 'a Fleury Solutions LLC platform'), 'billing renewal');
        }

        return;
    }
    if (! in_array($type, ['invoice.paid', 'invoice.payment_failed'], true)) {
        return;
    }
    $invoice = (string) ($object['id'] ?? '');
    if (! preg_match('/^in_[a-zA-Z0-9]+$/D', $invoice)) {
        throw new RuntimeException('Invalid invoice identity.');
    }
    $sub = row('SELECT * FROM saas_subscription WHERE id = 1');
    $price = saas_price();
    // Our price x our seat count. The payload's amount is never trusted.
    $cents = (int) $sub['quantity'] * $price['unit_amount'];
    $outcome = $type === 'invoice.paid' ? 'paid' : 'failed';
    $reason = preg_match('/^[a-z_]{1,40}$/D', (string) ($object['billing_reason'] ?? '')) ? (string) $object['billing_reason'] : null;
    $new = q('INSERT IGNORE INTO saas_invoices (stripe_invoice_id, outcome, amount_cents, currency, quantity, paid_through, billing_reason) VALUES (?,?,?,?,?,?,?)',
             [$invoice, $outcome, $cents, $price['currency'], (int) $sub['quantity'], $outcome === 'paid' ? $sub['period_end'] : null, $reason])->rowCount() > 0;
    if ($outcome !== 'failed' || ! $new) {
        return; // one alert per failed invoice, not one per retry
    }
    $who = saas_subscriber();
    if ($who) {
        $loc = (string) $who['locale'];
        mail_queue(null, (string) $who['email'], saas_lt($loc, 'Crewvia: your subscription payment did not go through'),
            saas_lt($loc, 'Hello,') . "\n\n" . saas_lt($loc, 'The payment of :amount for your Crewvia subscription did not go through. Please update your payment method in :where:', ['amount' => saas_money_in($cents, $price['currency'], $loc), 'where' => saas_cancel_place($loc)])
            . ' ' . saas_subscription_link() . "\n\n" . 'Crewvia, ' . saas_lt($loc, 'a Fleury Solutions LLC platform'), 'billing alert');
    }
    $report = (string) ($config['billing_report_to'] ?? '');
    if ($report !== '') {
        mail_queue(null, $report, 'Crewvia ' . ($config['tenant_id'] ?? '') . ': subscription payment failed',
            'Workspace: ' . ($config['tenant_id'] ?? '') . "\nInvoice: " . $invoice . "\nAmount: " . saas_money_in($cents, $price['currency'], 'en')
            . "\nSeats: " . (int) $sub['quantity'] . "\nCustomer told: " . ($who ? 'yes' : 'no subscriber email on record'), 'billing alert');
    }
}

/**
 * The reminder 30 days before a YEARLY renewal (California's window is 15 to 45 days). Sent once per renewal date;
 * a run missed by a few days still sends while at least 15 days remain. Nothing for a monthly plan or a cancelled one.
 * Returns how many reminders were queued (0 or 1).
 */
function saas_renewal_reminders(int $now): int
{
    if (! saas_configured()) {
        return 0;
    }
    $sub = row('SELECT * FROM saas_subscription WHERE id = 1');
    if (! $sub || $sub['status'] !== 'active' || (int) $sub['cancel_at_period_end'] === 1 || ! $sub['period_end']) {
        return 0;
    }
    $price = saas_price();
    $renews = strtotime((string) $sub['period_end'] . ' UTC');
    $days = ($renews - $now) / 86400;
    if ($price['interval'] !== 'year' || $days > 30 || $days < 15 || $sub['reminder_sent_for'] === $sub['period_end']) {
        return 0;
    }
    $who = saas_subscriber();
    if (! $who) {
        return 0;
    }
    $loc = (string) $who['locale'];
    $vars = ['product' => 'Crewvia', 'date' => saas_date_in($renews, $loc),
             'amount' => saas_money_in((int) $sub['quantity'] * $price['unit_amount'], $price['currency'], $loc), 'cancel' => saas_cancel_place($loc)];
    mail_queue(null, (string) $who['email'], saas_lt($loc, 'Your :product plan renews on :date', $vars),
        saas_lt($loc, 'On :date your plan renews for one year at :amount. Nothing to do if you want to keep it. To stop the renewal, cancel before that date in :cancel.', $vars)
        . ' ' . saas_subscription_link() . "\n\n" . 'Crewvia, ' . saas_lt($loc, 'a Fleury Solutions LLC platform'), 'billing reminder');
    q('UPDATE saas_subscription SET reminder_sent_for = period_end WHERE id = 1');

    return 1;
}
