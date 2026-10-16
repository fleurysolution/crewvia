# P5-M63 — Bank details come with a proof that payroll compares before using them

The owner, 16 Oct 2026: employees often give bank details that are not accurate - a wrong digit in the
account number - and their pay goes to someone else's account or is delayed. During onboarding, the
employee should give the account and routing numbers and attach a photo of a voided cheque or the
bank's direct deposit form, so the person who checks can make sure the numbers match before accepting.

## What existed

Bank details were already entered by the employee (My details) or by payroll on their behalf, encrypted,
the routing number checked by its ABA checksum (a transposed digit is refused), and accepted on Change
requests by a second person, never the one who typed them; every look at the full numbers recorded.
Missing: anything to compare the numbers with, and a second typing of the account number.

## What changes

- **The account number is typed twice**; two that differ are refused ("Type it again, digit by digit").
- **A proof is required**: a voided cheque, or the bank's direct deposit form or letter, as a photo
  (JPEG, PNG - a phone can take it on the spot) or a PDF, up to 10 MB. It is encrypted at rest in its own
  folder (`storage/bank-proofs`), outside the general document store, so recruiters never see it.
- **Payroll compares**: on Change requests, "Show the full numbers" shows the proof beside them (a photo
  inline, a PDF one click away); every look at the proof is recorded. Accepting needs the box
  "I compared the numbers with the proof". Details sent before this change say that no proof came with
  them.
- The same applies when payroll records bank details for somebody (from the paper they were given).

## Data

`worker_bank_change_requests` gains `proof_storage_name`, `proof_mime`, `proof_hash` (`install/upgrade.php`,
reversed by `install/rollback/p5-m63.sql`). The nightly backup already takes the whole storage folder.

## Tests

`tests/bank_proof_http.py` (9), with `tests/bank_proof_probe.php`: without a proof nothing is sent; two
account numbers that differ, nothing is sent; a file that is not a photo or a PDF is refused; with the
proof they wait for payroll, the file encrypted on disk; payroll sees the numbers and the proof beside
them; the proof opens as it was sent; a recruiter cannot open it; accepting without the box is refused;
ticked, accepted, and the looks at the proof recorded.

Updated: `tests/self_service_http.py` (sends a proof and the second typing; gains "without a proof" and
"two numbers that differ"), `tests/security_review_http.py` (payroll records details with a proof; the
second person ticks the box), `tests/load_http.py` (the box).

Mutation: the proof no longer required (caught: "Without a proof attached, nothing is sent").

Full suite: 3807 PASS, 0 FAIL (3796 before, plus the 9 new checks and 2 in self_service_http.py).
