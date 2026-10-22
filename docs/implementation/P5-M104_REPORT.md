# P5-M104 — The four emails every applicant receives

## Why

Before this milestone, Crewvia sent an applicant nothing when the application arrived, nothing while it was reviewed,
and nothing when it was rejected. The emails at the interview, offer and hire steps went only to applicants who had
created a portal. Someone who applied from the RSS website or from a job board link received nothing at all. A refusal
was recorded in Crewvia but never sent.

Firmin's decision (22 Oct 2026): this is part of the implementation, not an option.

## What

| Email | When | How |
|---|---|---|
| Application received | At once, when the application arrives (careers page, the agency's website, a job board link) | Automatic |
| Application under review | When a recruiter takes it in hand: moved to screening or interview, or sent to a hiring manager for review | Automatic, once; not after "retained" |
| Applicant retained | When the hiring manager approves the applicant, or an offer is made | Automatic, once |
| Applicant not retained | 24 hours after the application is rejected | Automatic; cancelled if the application is reopened within the 24 hours |

**Each email:**

- goes to the applicant's address, portal or not;
- is sent at most once per application;
- follows the test mode on Email delivery (test inbox until the agency switches it off);
- is written on the applicant's record (`candidate_events`, "email sent"). It is also logged in `candidate_email_log`
  (sent, scheduled or cancelled, the subject, and a fingerprint of the template version used), so a refusal is
  uniform and traceable.

**Administration → Emails to applicants** (`/candidate-emails`, owner):

- the four emails, with when each is sent, how many were sent in the last 30 days and how many refusals are waiting
  their 24 hours;
- for each, the subject and message to edit, a preview, an on/off switch and "Back to the default text";
- the language of the emails (English, French, Spanish; default texts in all three);
- the placeholders `{first_name}`, `{job_title}` and `{agency}`.

**Delivery.**

- Immediate emails go through the mail queue (`install/deliver-mail.php`, every 5 minutes, or "Send now" on Email
  delivery).
- The refusals whose 24 hours are up are written first, by the same job and by "Send now".

**Safety.** An email that cannot be written never stops the move of an application (the error is logged).

## Before going live

- The emails start with the next event. Nothing is sent retroactively to the applicants already in Crewvia.
- Every application moved from now on triggers its email. A clean-up of old applications (more than 10 rejections
  within 10 minutes) is held, never sent on its own (P5-M104b below).
- Check the mode on Email delivery. In test mode, these emails go to the test inbox. Switch to live only when the
  texts on "Emails to applicants" are approved.

## P5-M104b — No silent mass refusal

- **A burst is held.** More than 10 refusals within 10 minutes is a clean-up, not a decision per person. The whole
  burst, the first ones included, is **held**: nothing leaves, even after 24 hours.
- **The owner is told.** A notice goes to the owner. Email delivery shows the held refusals in red, and Emails to
  applicants asks **Do not send them**, or **Send them** (each 24 hours after its rejection).
- **The refusals still to come are visible.** Email delivery also shows how many refusals leave within 24 hours.
- **Rejecting a person rejects their open applications.** "Move to → Rejected" on the Candidates list (the triage
  of imported applicants) rejected the person but left their applications open, so they were never told. Their
  open applications are now rejected too, the same way, with the same 24-hour refusal, which the burst rule covers.

## Migration

- New tables: `candidate_email_templates` (the agency's texts) and `candidate_email_log`.
- New settings: `candidate_email_locale` and `candidate_email_off_<key>`.
- Rollback: `install/rollback/p5-m104.sql`.

## Tests

`tests/candidate_emails_http.py`, 20 checks:

- received at once, with no portal, and written on the record;
- under review once;
- retained on the offer;
- the refusal waits 24 hours, then goes, and an undone rejection is never told;
- the edited text is what the next applicant receives;
- switched off, nothing is sent;
- the language;
- a recruiter cannot edit the texts;
- the Candidates-list rejection, the held burst, and "Do not send them".
