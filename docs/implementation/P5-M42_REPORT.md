# P5-M42 — Add an applicant who applied by email, with their documents; a proper Candidate documents page; job board screening as a score

Asked for by the owner after seeing `/resumes`: the page opened on a bare "Attach CVs to a
requisition's applicants" upload box, an internal import tool with no flow around it. Adding
somebody who applied by email needs their full details and what they sent, together.

## What the office can do now

1. **Hiring → a requisition → Add an applicant** (`/hiring?v=ID&tab=add`). One form in three parts:
   - *Who they are*: first name, last name, email, phone (one of the two required), city, state, ZIP.
   - *Their application*: how they applied (by email, by phone, in person, referred by someone, on a
     job board, another way), the date received, degree or trade qualification, years of
     experience, their message or cover letter (pasted).
   - *What they sent*: up to 6 documents, each marked CV, cover letter, diploma or certificate, or
     other document; PDF or Word, 5 MB each, encrypted.

   The person joins the requisition as a new applicant (stage New, source "Applied by email"…),
   their message and how they applied go on the application (shown in "From the application"),
   and the office lands on their application to review them. Every file is checked and encrypted
   before anything is written: one bad file stops the whole addition and leaves nothing behind. A
   refused form keeps what was typed. A new record every time, like a public application; Hiring
   already shows when the email or phone is on file under another record.
2. **Candidate documents** (`/resumes`) is now the list of every document with its kind, filtered by
   name or file name, kind and requisition, each person leading to their application in Hiring.
   "Add an applicant" is the page's main action. The job board bulk import is folded away at the
   foot of the page ("Import documents from a job board") with its three steps. A worker sees
   "My documents", only their own.
3. **Diploma or certificate** is a new kind of candidate document (also in Hiring's single attach).
4. **Job board screening as a score.** An applicant imported from Indeed gets the share of the
   board's requirements they meet as their screening score, so Hiring's "Best screening score" sort
   ranks them for the owner's and manager's review. Nobody is screened out for it: the office
   decides. Applicants imported before P5-M42 are scored once by the upgrade, from the note the
   import left.

Date of birth is deliberately not asked: it is not needed to review an application, and asking it
before an offer invites age discrimination claims. It belongs in onboarding (I-9 and payroll).

## Changes

- `app/hiring.php`: `hw_channels()`, `hw_add_applicant()`.
- `app/pages/hiring.php`: `do=add_applicant`; the `add` view; the refused form kept in the session.
- `app/views/hiring.php`: "Add an applicant" in the requisition header, the form, document kinds.
- `app/intake.php`: `candidate_document_kinds()`, `candidate_document_file()`.
- `app/pages/resumes.php`, `app/views/resumes.php`: the documents list, its filters, the folded import.
- `app/pages/imports.php`: the board score on import.
- `install/upgrade.php`: `candidate_resumes.kind` gains `certificate`; the one-time board score fill.
- `install/rollback/p5-m42.sql`: diplomas filed as "other" again (files kept), the enum restored,
  the board scores cleared (only Indeed applications with no answers to Crewvia's own questions).
- `app/lang/messages.tsv`: 51 FR/ES strings.

## Tests

- `tests/add_applicant_http.py` (new, 14 checks): the button and the full form; refused without a
  way to reach them, the form kept; a bad file stops everything, nothing left behind; Emma, added by
  email with CV, cover letter and diploma: her details, her message on the application, three
  documents of the right kinds with who added them, stored encrypted, the office lands on her
  application; the documents page lists before the import, the import folded away, filters by kind
  and by name, each row leading to the application.
- `tests/board_scores_db.php` (new, 8 checks): 100 and 50 on import, nobody screened out; rollback
  clears the scores and files the diploma as another document; upgrade restores both; a second
  upgrade is silent.

Measured:
- Walkthrough chain + plant setup + notices + Indeed import + add applicant: 95 PASS; board scores
  8 PASS.
- Mutation runs: no score on import (caught: 100/50 check), no backfill (caught: upgrade check),
  documents not filed (caught: "Her CV, cover letter and diploma…").
- Seen in the browser on the test server: the form and the documents page.
- Full suite on this commit: 3598 PASS, 0 FAIL (3576 before, plus the 22 new checks).
