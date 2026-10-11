# P4-M04 — Gmail OAuth, DocuSign and Stripe: tokens, webhooks, retries, idempotency, audit

**What this module does not claim:** none of the three providers was
called. Gmail, DocuSign and Stripe stay disabled in production and in the
test configuration. Every behaviour below was verified against a test
double that stands in for the provider, inside the isolated test run. No
successful production integration is claimed. Each still needs its own
sandbox account, credentials and an end-to-end trial before it is
switched on.

## What existed, verified before building

| Area | Gmail | DocuSign | Stripe |
|---|---|---|---|
| Tokens | OAuth code flow with a state check (10 minutes), offline access; tokens encrypted with AES-256-GCM; refreshed when expired | a static access token from the configuration | secret key from the configuration |
| Webhooks | — | Connect: HMAC-SHA256 over the raw bytes, several secrets, constant-time comparison, size limit; account and envelope must match; queued once by payload hash | signature over timestamp and raw bytes, several `v1` signatures, 5-minute window, size limit; event id must be well formed |
| Idempotency | — | envelope created with a transaction id, found again by it rather than sent twice | idempotency keys on customer and checkout creation; each event processed once by id, inside a transaction |
| Reconciliation | — | worker asks DocuSign for the envelope's state, verifies the signer and the content hash before accepting | subscription read back from Stripe, so events out of order still leave its latest state |

These were sound and are kept. The gaps found:

| Gap | Where |
|---|---|
| No retry at all: a 429, a 5xx or a dropped connection failed the action | all three |
| A refresh Google refuses failed every page and was never recorded; the connection never showed it needed reconnecting | Gmail |
| A token that no longer decrypts (key changed) failed the same way | Gmail |
| Disconnecting deleted the token here but left it valid at Google | Gmail |
| The scopes granted were not checked: a connection without "send" was accepted and broke later | Gmail |
| A send form posted twice sent the message twice | Gmail |
| Only a static token, which DocuSign expires after hours: no JWT grant, no renewal | DocuSign |
| The Connect worker retried a failure at every run with no wait, and after five attempts left it "pending" forever, invisible | DocuSign |
| One webhook secret only: it could not be rotated without missing events | Stripe |
| Failures written only to the PHP error log; no audit trail; no screen | all three |

## What was built

**One way to call a provider** (`app/integrations.php`). Every call goes
through `integration_request()`: curl with certificate checks in
production, a double in tests. A call is repeated only when repeating is
safe:

- a read;
- a token refresh;
- a Stripe call carrying its idempotency key (Stripe answers the key's
  first result again).

It makes up to 3 attempts on a dropped connection, a 429 or a 5xx. It
waits 0.5 s then 1 s, or the provider's Retry-After capped at 5 s. A
Gmail send, a DocuSign envelope and an un-keyed Stripe write are sent
once.

**Audit trail** (`integration_events`). Every outcome is kept: ok,
duplicate, ignored, retry, failed or refused, with the provider's own
reference and a reason. A token, a secret or a message body is never
written there, and a test checks it.

Refusals at the public webhooks are recorded up to 100 an hour per sender.
Past that they are only counted, so the endpoints cannot be used to flood
the table.

**Gmail.**

- A refresh Google refuses (400 or 401), a missing refresh token, or a
  token that no longer decrypts marks the connection "reconnect", with
  the reason.
- From then on no further request is made to Google for it. The inbox
  shows a Reconnect button, and the Integrations page lists the
  connection.
- An unavailable Google is retried and does not mark the connection.
- Connecting is refused unless both read and send were granted.
- Disconnecting revokes the token at Google and then deletes it here. If
  Google cannot be reached, it is still deleted here, and that is
  recorded.
- The send form carries a one-time key, so the same form posted twice
  sends once. Keys are per user and per purpose, and expire after a day.

**DocuSign.**

- **JWT grant:**
  - The RSA private key comes from a file path (or the configuration),
    and the integration key and impersonated user are configured.
  - The RS256 assertion lasts an hour, with scope `signature
    impersonation`, against account-d (demo) or account (production)
    only.
  - The token is cached encrypted and renewed 5 minutes before expiry.
  - A 401 from the API brings one fresh token and one more attempt.
  - The static token still works where the JWT grant is not configured.
- **The Connect webhook** is a testable function, audited for each
  outcome.
- **The Connect queue:**
  - A failure waits 2, 4, 8 and then 16 minutes.
  - After the fifth attempt it is marked "failed" with its reason, and is
    not picked up again by itself.
  - An administrator requeues it from Integrations.
  - `install/reconcile-contracts.php` reports how many were processed,
    retried and failed.

**Stripe.**

- Reads and keyed writes are retried with the same idempotency key.
- Webhook secrets can be a list (`stripe_webhook_secrets`), so a secret is
  rotated without missing an event; the single `stripe_webhook_secret`
  still works.
- The webhook is a testable function. Each outcome is recorded: processed,
  duplicate, another workspace or customer ignored, or failed. A failure
  answers 503, so Stripe sends it again, and the event is not marked done.

**Integrations page** (`/integrations`, administrators only):

- whether each provider is on, without a single setting's value;
- how DocuSign authenticates;
- the Gmail connections to reconnect;
- the Connect queue, with the failed events to requeue;
- the Stripe events processed;
- the audit trail, filtered by provider and outcome.

Schema:

- new tables `integration_events`, `integration_tokens` and
  `integration_nonces`;
- `gmail_connections` gains `status`, `scopes`, `last_refreshed_at` and
  `last_error`;
- `docusign_connect_events` gains `next_attempt_at`, `last_error` and
  `processed_at`, and a `failed` status.

Rollback: `install/rollback/p4-m04.sql`.

## Tests

`tests/integrations_db.php` switches the three providers on for its own
process only, with synthetic settings, and replaces the transport with a
double. It covers:

- **Schema:** rollback (connections and events kept), upgrade, a silent
  repeat, and the Connect worker refusing to call anyone while DocuSign is
  off.
- **Retries:**
  - 503, 503, 200 succeeds on the third attempt, waiting 0.5 s then 1 s,
    each retry recorded;
  - Retry-After honoured and capped at 5 s;
  - a dropped connection is retried;
  - an unsafe call, or a 404, is sent once;
  - never more than three attempts.
- **Gmail:**
  - the connection stored encrypted, with its scopes;
  - an expired token refreshed by the refresh grant and stored encrypted,
    the refresh token kept;
  - a valid token used without a call;
  - Google unavailable: three attempts, still active, recorded;
  - `invalid_grant`: marked to reconnect, and no further call;
  - an undecryptable token: reconnect;
  - reconnecting makes it active again;
  - disconnect revokes and deletes, and deletes even when the revocation
    is not confirmed;
  - a send is never repeated;
  - one-time keys: used once, not for another purpose or another user,
    malformed ones refused.
- **DocuSign:**
  - configured by the JWT grant alone;
  - the assertion's header and claims, and its signature verified with
    the public key of a key pair made for the run (never stored);
  - the token cached and stored encrypted, renewed near its expiry;
  - a 401 answered by one fresh token;
  - a failed envelope send not repeated;
  - a refused grant recorded;
  - the webhook: off 503, wrong signature 401, rotated secret accepted,
    duplicate queued once, a foreign envelope 422, not JSON 400, each
    outcome recorded;
  - the queue: reconciled by asking DocuSign, a failure waiting 2 minutes,
    left alone until due, failed after the fifth attempt, never picked up
    again by itself, requeued.
- **Stripe:**
  - a keyed write retried with the same key, an un-keyed one sent once, a
    read retried;
  - webhooks: new and old secret accepted, any other refused, a stale one
    refused, a duplicate, another workspace ignored, a failure answered
    503 and not marked done;
  - every outcome recorded;
  - no secret or token anywhere in the audit trail.

`tests/integrations_http.py`, with every provider off as in production,
covers:

- Integrations for administrators only, the three providers off, and no
  setting's value on the page;
- the connection to reconnect and the failed event, with its reason;
- all six outcome kinds in the trail;
- requeue refused to recruiting, done by an administrator, refused for an
  event that has not failed;
- the Stripe webhook taking POST only and refusing while off;
- DocuSign Connect answering 503 while off;
- both refusals recorded with the sender;
- the inbox saying Google is not set up, and the Google start refused;
- translations.

`/integrations` is in the access matrix (administrators only) and the
CSRF sweep (`requeue`). The existing `docusign_unit.php` and
`saas_unit.php` checks still pass unchanged.

Full isolated run: **1960 PASS, 0 FAIL** (browser step skipped).

Mutation: I made every provider call retryable, safe or not. The suite failed on "A call that is not safe to repeat is sent once".

## Before switching any of them on

Each step is yours to decide:

- **Gmail:** create the Google Cloud OAuth client with the callback URL,
  enable the Gmail API, and put the client id, secret and a 32-byte
  encryption key in the server configuration. Google's verification of
  the restricted Gmail scopes applies to the app.
- **DocuSign:**
  - Create the integration key in the developer (demo) account and an RSA
    key pair. Store the private key outside the web root.
  - Grant consent for the impersonated user.
  - Set `docusign.jwt` and `connect_hmac_secrets`.
  - Add the Connect configuration pointing at `/docusign-webhook`, and a
    cron line for `install/reconcile-contracts.php`.
  - Trial on demo; production needs DocuSign's go-live review.
- **Stripe:** test-mode keys, the price, the webhook endpoint
  `/stripe-webhook` with its signing secret, then a test checkout. A
  rotation uses `stripe_webhook_secrets`.

These belong with the credential cleanup in DEFERRED_SECURITY.md: no key
or secret is in the repository.
