# Crewvia — Fleury Solutions staffing platform

PHP 8.2 / MariaDB or MySQL. No framework, no composer, no build step: it
runs on cPanel shared hosting, and that constraint is deliberate. English
is the default; French and Spanish are selectable. Crewvia is a working
product name. RSS Inc. is one prospective subscriber, not the product
owner.

**Live:** `https://crewvia.bpms247.com`

---

## Start here

| | |
|---|---|
| **What was asked, and by whom** | [docs/REQUIREMENTS.md](docs/REQUIREMENTS.md) |
| **What we are doing about it, one at a time** | [docs/BACKLOG.md](docs/BACKLOG.md) |
| How the code is laid out | [below](#how-it-is-laid-out) |
| How to run it locally | [below](#running-it-locally) |

---

## Two rules that are not negotiable

**No secrets in the repository.** `config.php` is ignored; copy
`config.example.php` and generate your own key with
`php -r 'echo base64_encode(random_bytes(32));'`. Never reuse a key from
another product.

**No real people in the repository.** `install/data/` and
`install/seed-rss.php` carry the names, addresses and telephone numbers of
real applicants and real RSS staff. Both are ignored and must stay that
way — the point of this repository is that other people can read it. For
anything you need to click through, use `install/demo-crewvia.php`, where
everybody is fictitious.

---

## Running it locally

```bash
cp config.example.php config.php          # then edit it
php -r 'echo base64_encode(random_bytes(32));'   # the encryption_key

mysql -uroot -e 'CREATE DATABASE crewvia_dev CHARACTER SET utf8mb4'

WORKFORCE_ADMIN_EMAIL=you@example.invalid php install/install.php --blank
php install/upgrade.php
php install/demo-crewvia.php              # one agreement, four trades, ten people

php -S 127.0.0.1:8097 -t public public/index.php
```

`install/demo-crewvia.php --remove` takes the demonstration out again and
leaves nothing behind.

---

## How it is laid out

```
public/index.php     the only entry point; an explicit route allow-list
app/pages/<name>.php a screen's handler: decides, writes, redirects
app/views/<name>.php the same screen's markup
app/*.php            the shared model: scope, approvals, lifecycle, security
app/lang/{fr,es}.php the catalogues; the English sentence is the key
install/*.sql        schema, loaded by upgrade.php - never run by hand
install/upgrade.php  guarded, idempotent, one printed line per change
tests/               the regression suite
```

A screen exists because it is named in the route list in
`public/index.php`. Adding a file is not enough, and that is on purpose.

### Conventions worth knowing before you change anything

- **One vocabulary per concept, in one function.** `candidate_stages()`,
  `placement_word()`, `disciplines()`, `skills_list()`, `rehire_states()`.
  A second hand-kept copy is how two screens come to disagree, and it has
  happened here more than once.
- **Translate everything a person reads.** `t()` and `te()`; the English
  sentence is the key. `missing_strings` must come back at zero.
- **Never show a stored value.** `new`, `on_site`, `uploaded_review` are
  database words. People read words.
- **Widen an ENUM before offering a new value.** MySQL stores what it will
  not accept as an empty string rather than refusing it, so the record is
  silently emptied.
- **An empty list says what the emptiness means.** A blank card is
  indistinguishable from a broken screen.

---

## Verification

```bash
python tests/run_windows.py
```

Isolated database on port 3308, isolated source copy on 8097, synthetic
fixtures. It refuses to start if either port is in use, and it never
touches a customer database. Never run the fixtures against real data.

The browser step needs Playwright; without it the run stops there and the
remaining steps have to be run by hand.

---

## Install or upgrade on a server

1. Back up the database, the source, private storage and the encryption
   key, and verify the restore. Leave every sibling project untouched.
2. PHP extensions: pdo_mysql, openssl, curl, mbstring, fileinfo, zip,
   DOM/libxml. Serve only `public/` over HTTPS. Keep `config.php`,
   `storage/`, `tenants/`, `install/` and `tests/` outside the document
   root.
3. Copy `config.example.php` to `config.php`. Dedicated database, HTTPS
   `app_url`, `debug=false`, and a unique `encryption_key`.
4. Existing installation: `php install/upgrade.php`. DDL is not
   transactional — rehearse on a staging database. The upgrade is
   repeatable and prints nothing on a second run.
5. New installation: set `WORKFORCE_ADMIN_EMAIL` and run
   `php install/install.php --blank`. It always creates an empty
   commercial installation. `--reset` is refused. Keep the one-time
   temporary administrator password and change it at first sign-in.
6. Make private storage writable by the service. Then configure projects,
   approved instructions, users, client grants, rates, qualification
   requirements and resources. Agency setup provides starter templates,
   not approved legal instructions.
7. Schedule `php install/run-workflows.php` per tenant for follow-up and
   renewal notifications. The email relay stays disabled while
   `email_delivery_enabled=false`.

---

## What is deliberately switched off

Gmail OAuth, AI review, Stripe checkout and webhooks, and a signed generic
application-ingestion contract all exist in code and are **off**. Keep
`commercial_mode=demo`, `email_delivery_enabled=false`, `ai_enabled=false`,
`recruiting_webhooks=[]`, and no Google or Stripe secrets. Native Indeed
and LinkedIn connections are not implemented by the generic webhook.
Structured job data does not guarantee Google indexing.

## What this is not

**ADP export** is a configurable draft, not a confirmed customer import
and not a payment engine. Weekly overtime, salary preparation, taxes,
state daily rules and cross-project aggregation are not a certified
payroll engine. Cash, cheque and transfer entries record evidence of
payment; they do not move money.

**I-9, W-4 and screening** use private forms, evidence and reviewer
records. This is not a certified electronic I-9, E-Verify, laboratory or
FMCSA compliance system. Signature timestamps and hashes do not by
themselves establish regulatory compliance.

**The mobile shell** is an installable web app and needs connectivity.
There are no store binaries, no offline data and no background push.
Gmail attachment synchronisation, telephony, SCORM and automated travel
purchasing are not included. No training certificates are generated.

**Imports** map identities and selected assignment and payroll fields,
with duplicate review. Hotel, vehicle and historical financial migration
need the customer's own files and an explicit mapping.

---

## Contracts and signatures

`/contracts` carries document prerequisites, draft, approval, issuance,
immutable signed records, revisions, refusal and expiry, private storage
of the original and signed PDFs, internal electronic consent, and
verified uploaded signatures. DocuSign envelope, embedded signing, Connect
verification and archival code exist and are disabled; OAuth provisioning
and a real developer account are still required. See
[install/DOCUSIGN-STAGING.md](install/DOCUSIGN-STAGING.md).
