# GAP-1002 — encryption key rotation

The last Phase 4 roadmap item ("Encryption key rotation tool, GAP-1002, for
SEC-0"). Until now the workspace key could not be changed: a new key would
have made every encrypted value and document unreadable.

## What the key protects, verified in the code and the schema

| What | Where | How stored |
|---|---|---|
| bank details, accepted and proposed | `worker_bank_details`, `worker_bank_change_requests` `.encrypted_details` | base64 of AES-256-GCM |
| two-step secrets | `account_security.encrypted_totp` | same |
| Gmail and provider OAuth tokens | `gmail_connections.encrypted_tokens`, `integration_tokens.encrypted_token` | same |
| queued mail (invitations, recovery links) | `email_outbox`, `account_mail_outbox` `.encrypted_message` | same |
| imported rows | `import_records.encrypted_source` | same |
| documents: résumés, contracts, proofs (I-9, W-4, receipts) | `storage/resumes`, `storage/contracts`, `storage/documents` | raw IV, tag and ciphertext |

Every file written there is encrypted: they have exactly three writers
(`intake.php`, `contracts.php`, `proofs.php`), and all three encrypt.

Also derived from the key:
- the backup manifest signature and key check (P4-M07);
- an import preview held in the session for a few minutes.

## What was built

- **`app/crypto.php`.** One place for the scheme:
  - `crypto_seal()` encrypts with the current key;
  - `crypto_open()` decrypts with the current key, then with
    `previous_encryption_key` if one is configured.

  The four decrypt sites now use it: tokens (`token_decrypt`), contracts,
  proofs and résumés. File and value formats are unchanged, so everything
  already stored reads as before.
- **`app/key-rotation.php`** and **`install/rotate-key.php`**:
  - **`--check`:** every encrypted value and document, counted by the key
    that opens it. The encrypted columns are found in the schema (any
    column named `encrypted_…`), so a table added later is covered. One
    item that opens with no key fails the check, and is named.
  - **`--start`:**
    1. the check: anything unreadable stops it, and nothing changes;
    2. a backup, which is mandatory;
    3. a new random key written into `config.php`, with the old one kept
       as `previous_encryption_key`;
    4. every value and document re-encrypted;
    5. the old key removed.
  - **`--resume`:** continues an interrupted rotation, or one run with
    `--limit=N`.
  - Neither key is ever printed.
- **Safety:**
  - The site keeps working throughout: it reads with both keys and writes
    with the new one.
  - Values are re-encrypted compare-and-set on their primary key, which
    may span several columns (`integration_tokens`), so a change the
    application makes meanwhile is never overwritten.
  - Files are written beside the original and renamed over it.
  - `config.php` is rewritten in place, keeping every other line and
    comment and its line endings. It is written to a temporary file,
    renamed, then read back by a separate PHP process; if anything other
    than the two key values differs, the original is put back.
  - A config with two `encryption_key` entries, or with a key not written
    as a literal (from the environment, for example), is refused and left
    untouched.
- **Backups:**
  - A backup is accepted under the current key or under
    `previous_encryption_key`.
  - A backup taken before a rotation stays restorable: set the old key back
    as `previous_encryption_key`, restore, then `--resume`.
- **Health:** System health and the cron check warn while a rotation is
  under way.
- **Runbook:** a new section, `docs/OPERATIONS.md` §8.

## Tests

`tests/key_rotation_unit.php` (15 checks, on temporary copies). Three
config layouts: single line, one entry per line with comments, and Windows
line endings with double quotes. For each:

- the new key goes in with the previous one beside it;
- every other value is kept;
- finishing removes the previous key and nothing else;
- the lines, comments and line endings are kept.

Also refused, and left exactly as they were: a config with two
`encryption_key` entries, and a key read from the environment. No
temporary file is left behind.

`tests/key_rotation_drill.py` (23 checks). It runs on the full test
database, right after the disaster-recovery drill, which at that point
holds 19 encrypted values and documents: bank details, two-step secrets,
queued mail, résumés and proofs.

1. **The check:**
   - `--check` counts everything, all under the current key, and every
     item decrypts;
   - one document nobody encrypted fails the check and is named;
   - it stops the rotation before anything changes: no backup, and
     `config.php` byte for byte the same.
2. **A limited start (`--limit=5`):**
   - the backup comes first;
   - the new key is in `config.php`, with the old one beside it;
   - 5 items are re-encrypted, and it exits 3 with "still need the previous
     key";
   - neither key appears in the output;
   - meanwhile the site serves a résumé and a proof, each byte for byte;
   - the health check warns, and a second `--start` is refused.
3. **Resume:**
   - `--resume` completes, and the previous key is gone from `config.php`;
   - every value and document decrypts to exactly what it was (SHA-256 of
     each plaintext, before and after);
   - the old key opens none of them, and the new key opens all of them;
   - `--check` agrees;
   - an administrator signs in and downloads the résumé byte for byte;
   - `--resume` with nothing under way is refused.
4. **The pre-rotation backup:** refused under the new key alone, and
   accepted once the old key is set back as `previous_encryption_key`.

Everything that runs after the drill in the suite runs under the new key:
the 10,000-worker scale test, the load test with its encrypted bank
change, and the tenant isolation tests.

One fix was needed in the drill, not in the tool. The sign-in after the
rotation was refused by the per-account limit (10 in 15 minutes). Many
suites sign in as the same administrator, and the disaster drill restores
the limiter's table from its backup with everything else. The drill now
clears that account's count before signing in, as the suite already does
for the per-address limit, and reports the page's message if a sign-in
fails. The same sign-in after a full rotation, tried on a separate copy,
works.

### Results

Full isolated run: **2256 PASS, 0 FAIL** (browser step skipped). That is
38 checks more than P5-M01's 2218: 15 unit and 23 drill.

Mutation: I made decryption ignore the previous key, the thing that keeps
the site working during a rotation. The suite failed on "Meanwhile the
site serves a resume, whichever key it is under", after 2216 passes.

## Limits

- Only the main `config.php` workspace is rotated. A workspace configured
  under `tenants/` is refused with a message.
- An import preview open in someone's session at the moment the old key is
  removed can no longer be read; that person previews the file again.
- The full path "restore a pre-rotation backup with the old key set back,
  then `--resume`" is described and follows from the code, but the drill
  tests only its first half (the backup is accepted with the old key back).
- Nothing was run on the production server. No key there was changed. The
  rotation is yours to run when you decide, after the credential clean-up
  in DEFERRED_SECURITY.md.
