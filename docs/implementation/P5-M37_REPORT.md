# P5-M37 — the office attaches a candidate's CV, cover letter or other document

Working live, 14 Oct 2026: the 424 Indeed applicants are in Crewvia, but their
CVs, cover letters and attachments are not. Indeed's export has none of them,
and the API comes later.

## Found

A CV reached Crewvia only one way: the candidate attaching it to a public
application. The office had no way to attach a document to a candidate's
file.

## What was built

**Hiring → the applicant's Background → "Attach a document".**
- **What it is:** CV, cover letter or other document.
- **File:** PDF or Word, up to 5 MB, checked as the public application checks
  it (the type, and a Word file's contents).
- **Storage:** encrypted with the workspace key, like every CV. The document
  shows on the applicant labelled with what it is, and is recorded on their
  history with who attached it.
- **The candidate's portal:** they see it there too (Résumés shows the
  person their own files).

**Data:** one reversible migration (install/rollback/p5-m37.sql):
`candidate_resumes.kind` and `attached_by`.

## Tests

- **In `tests/indeed_import_http.py`:**
  - the form on the applicant;
  - a CV and a cover letter attached, with their type and who attached them;
  - stored encrypted, not as the PDF;
  - listed in Hiring;
  - a text file refused.
- **The walkthrough chain:** 71 checks pass.

## P5-M38 — CVs in bulk

Resumes → "Attach CVs to a requisition's applicants":
- **Batch size:** up to 15 files at a time.
- **Matching:** each file goes to the applicant whose name is in its file
  name, as Indeed names them (ResumeFirstLast.pdf). A file named
  CoverLetter… is filed as a cover letter.
- **No guessing:** a file that matches nobody, or more than one applicant on
  the requisition, is attached to nobody and listed.
- **Repeats:** the same file sent twice is not attached twice.

Tested in `tests/indeed_import_http.py`:
- the form;
- a CV and a cover letter to the right person;
- an unknown name listed;
- a second CV beside the first;
- a repeat refused.

The walkthrough chain passes 76 checks. The full suite passed on M37 (3,563).

## P5-M39 — cover letters and other attachments

The bulk attach also takes:
- **A cover letter typed on the job board**, sent as CoverLetterFirstLast.txt.
  It goes word for word on the application in Hiring, where the recruiter
  reads it.
- **Another file the applicant sent**, as Attachment__FirstLast__original.pdf.
  It is filed as "Other document" under its original name.

Neither is added twice. Tested in indeed_import_http.py (79 checks).
