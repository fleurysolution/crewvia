# P5-M114 — A subscription renews until cancelled

20 Nov 2026. This applies Firmin's rule of 19 Nov: "renew unless cancelled". The scope came through the hub (`INBOX/2026-11-19_from-hub_auto-renewal-rule.md`). The wording was approved by Firmin on 19 Nov (`INBOX/2026-11-19_from-hub_auto-renewal-terms-APPROVED.md`, "Approve terms"), and the seat clause was confirmed with "Approve addendum". Firmin ordered the build in this session on 20 Nov.

**Who it applies to.** Only an installation sold as a Stripe subscription (`commercial_mode = 'subscription'`). The RSS installation is dedicated, quoted at a fixed price and has no Stripe key, so nothing here charges RSS. `crewvia_pilot` is `one_time` and does not renew.

## What a reader needs to know

- **The renewal amount is contracted seats × unit price.**
  - The customer types the number of seats before Checkout. Stripe bills that number every period.
  - The seat count is never synced from usage. The monthly usage review on the same screen is read-only and says so.
  - An automatic sync of seats from usage would be new scope. It would need its own clause in the catalogue and Firmin's approval. The approved per-unit addendum (§3b) does not cover contracted seats.
- **What the consent record stores:**
  - Table: `saas_renewal_consents`.
  - Contents: who (user and email), when, the language, the exact terms text shown, the exact checkbox sentence, the amount in cents, the currency, the period, the seats, the first renewal date, the IP, and the Checkout session it led to.
  - It is kept 3 years (`keep_until`) and never updated, except to link the Checkout session and mark the confirmation.
  - The amount stored is the one shown at sign-up. If the customer later changes the seats, the renewal amount differs from it. That is expected: the record proves what was shown.
- **Cancellation takes effect at the end of the paid period**, through `cancel_at_period_end` sent by Crewvia itself.
  - The Stripe Billing Portal is also set to "at the end of the billing period". The hub read that setting on 19 Nov.
  - But that setting is account-wide: it governs Crewvia, BPMS247, VCardHub and VeloraWeb at once, and anyone with dashboard access can change it.
  - We deliberately do not rely on it. The in-app buttons make the behaviour ours, and the tests can see it.

## What changed

- **`install/upgrade.php`:**
  - New columns on `saas_subscription`: `cancel_at_period_end`, plus the price kept from Stripe (`price_id`, `price_unit_amount`, `price_currency`, `price_interval`, `price_checked_at`) and `reminder_sent_for`.
  - New tables `saas_renewal_consents` and `saas_invoices`.
  - The migration is guarded and silent on a repeat. Rollback: `install/rollback/p5-m114.sql` (export the consents before using it).
- **`app/saas-renewal.php` (new):**
  - The price is read with `GET /prices/{id}` and kept for a day. It is refused unless it recurs every single month or year, per seat.
  - The approved terms are built in the reader's language, with amount, date and currency formatted for that language.
  - It records the consent, handles cancel and keep, runs the webhook follow-up, and sends the yearly reminder.
- **`app/saas.php`:**
  - `prices` is added to the allowed Stripe endpoints.
  - The subscription refresh keeps Stripe's `cancel_at_period_end`, so a cancellation made in the portal shows here too.
  - Every verified event then goes through `saas_after_event()`.
- **`app/pages/subscription.php` and its view:**
  - **Before payment:** a "Review the terms" step shows the approved `renewTerms` with an unticked checkbox (`renewConsent`) that is required to continue to Stripe. The server rebuilds the text and checks its fingerprint, so the text agreed to is the text that was shown. The consent is recorded before Stripe is called.
  - **After sign-up:** the screen shows "Renews automatically on {date} at {amount}", with "Cancel the renewal". Once cancelled, it shows "Access continues until {date}. No further charge is made", with "Keep my subscription".
  - The Payments list shows every invoice recorded.
  - Only administrators can use the screen, as before.
- **Webhook follow-up, inside the webhook's transaction:**
  - `checkout.session.completed`: the confirmation email repeats the terms word for word and gives the link to cancel.
  - `invoice.paid`: the invoice is recorded once, at our price × our seats. The payload's amount is never used.
  - `invoice.payment_failed`: the invoice is recorded and one alert goes out per failed invoice (not one per retry), to the subscriber and to `billing_report_to`.
  - `customer.subscription.created` and `customer.subscription.deleted` were already handled by the `customer.subscription.*` prefix. They are now tested.
- **`install/renewal-reminders.php` (new, daily cron):**
  - It sends a reminder 30 days before a **yearly** renewal, once per renewal date. If a run is missed it still sends, as long as at least 15 days remain.
  - It sends nothing for a monthly plan or a cancelled one, and does nothing on a dedicated installation.
  - The cron line is in `docs/OPERATIONS.md`.
- **Wording:** the strings are in `app/lang/messages.tsv`. The approved EN/FR/ES sentences are used unchanged, with `:x` in place of `{x}`.
  - `{cancel}` reads "Administration › Subscription & workspace", in the reader's language.
  - The period words are month/mois/mes and year/an/año.

## Not settled, not designed around

- The account-wide Billing settings (Smart Retries, dunning emails, what happens after the last retry) are Firmin's decision. The failure alert promises no retry behaviour.
- A renewal has not been proven on live Stripe. The dashboard will not send a test event to a live endpoint. It needs a real invoice or the Stripe CLI against a test key, once a Crewvia key exists.
  - No Crewvia key exists today.
  - Scopes it would need: Customers W, Checkout Sessions W, Subscriptions W, Invoices R, Billing Portal Sessions W, Prices R.
  - The hub creates it, restricted to those scopes and to the Crewvia host.

## Tests

- `tests/saas_renewal_db.php` (new, in `tests/run_windows.py`) uses a Stripe test double, so no request leaves the machine. It covers:
  - rollback, upgrade, and silence on a repeat;
  - the price read and kept, and one-time or weekly prices refused;
  - the terms in EN/FR/ES, word for word;
  - the 31 Jan → 28 Feb date;
  - the consent record and its 3 years;
  - the confirmation email;
  - an invoice at our amount despite a different payload amount;
  - the failure alert sent once, to both recipients;
  - cancel and keep, including the case where Stripe does not confirm the cancel;
  - a cancellation made in the portal;
  - the yearly reminder: once, never for a monthly or cancelled plan, and inside the window;
  - a subscription deleted.
- Full suite with `SKIP_BROWSER=1`: 4329 PASS, exit 0.
