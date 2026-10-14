# P5-M36 — Indeed applicants imported whole, and start dates in words

Working live, 14 Oct 2026: the requisitions on Crewvia's public page are now
the ones open on Indeed. The people who applied on Indeed (about 415) are
brought into Crewvia to be screened there, and from there to the portal.

## Found

- **Losing information on import.** Imports → Applicants took name, email,
  phone, city and state. An Indeed export gives the place as "Grand Forks, ND"
  in one column. It also carries:
  - relevant experience and education;
  - the board's status;
  - the screening questions with each answer, and whether Indeed judged it a
    match.

  All of that would have been lost.
- **Mapping by hand.** Every column had to be mapped by hand, for every file.
- **Start dates in the past.** A requisition created without a start date
  took the plant's start date (14 Sep 2026, already past). The public page
  said "starts 14 Sep 2026".
- **"1 lines on the order"** on Projects.

## What was built

**An Indeed export imports whole.** Imports → Applicants, onto a requisition.
- **Columns:** they map themselves (name, email, phone, candidate location,
  relevant experience, education, source).
- **City and state:** "City, ST" becomes a city and a state.
- **The candidate's file:** experience and education are written on it.
- **Source:** "Indeed (Sponsored Job Link)", for example. The application's
  source is "Indeed". The trade comes from the requisition.
- **The application:** it carries the board's status and every screening
  question with its answer. An answer Indeed marked as not a match is
  flagged "(does not meet)", on the application and in the import's review
  list.
- **Someone already on file** is held for review, as before. Their record is
  not changed.

**Start dates in words:**
- A start date already past reads "starts immediately", on the public page,
  in Jobs for you and in campaigns.
- The requisition form proposes today, when the plant has already started.

**"1 line on the order".**

## Not in the export, done separately

Indeed's export has no CVs, attachments or cover letters. They are attached
to each candidate's file one by one until the Indeed API is connected.

## Tests

- **`tests/indeed_import_http.py`:** a synthetic file in Indeed's format,
  three invented people. It checks:
  - the self-mapping;
  - city and state;
  - source, experience and education;
  - the application with status and answers;
  - the flagged answer;
  - the review list;
  - the duplicate held.
- **Re-run and passing:** campaigns 37, Jobs for you 32, the walkthrough chain
  with campaign texts and the Indeed import 91.
