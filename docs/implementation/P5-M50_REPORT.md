# P5-M50 — Read a CV in the browser; invite or move applicants together

The owner, on 15 Oct 2026:
- "when they see the resume, they have to download it to view it. They should be able to click
  and see inside and download it if they want to";
- "are the system able to select … and do a bulk submit … to send the invite or the notification
  to the candidates that they have in the system".

## What changes

1. **View a PDF in the browser.** Every PDF on a candidate's file now opens in a new tab (the
   browser's own viewer), with a download link beside it: in Hiring (Background), Candidate reviews
   (the manager's dossier) and Candidate documents ("View" and "Download"). The file keeps its own
   name in both cases. A Word file still downloads: browsers do not display it.
   Served with `Content-Type: application/pdf`, `Content-Disposition: inline`, `nosniff` and
   `Cache-Control: private, no-store`; the same access rules as the download (a supervisor sees only
   the applicants given to them, P5-M44). Each view is logged ("viewed a candidate document").
2. **Several applicants at once in Hiring.** A box on every applicant, "Select all shown", and:
   - **Invite them to their portal**: the portal invitation, the stage unchanged. Somebody who
     already has a portal, or an invitation waiting, is counted as "already had it", never emailed
     twice; somebody without a valid email is listed as not done, with the reason.
   - **Move them to Screening (invites them too)**: through the same rules as one by one.
   Only applicants of the requisition on screen are touched; up to 300 at a time. The bar says that
   invitations go out through Email delivery (in test mode, to the test inbox) and points to
   Campaign email for a message of one's own.

How emails actually leave: the delivery script must run on a schedule (cron, every 5 minutes) on
the server; until then they wait in the queue (Email delivery).

## Tests

- `tests/bulk_view_http.py` (10): a PDF inline under its own name; Download as an attachment;
  View links in Hiring and Candidate documents; the bulk bar; nothing selected refused; two invited
  together (one email each, stage unchanged); invited again, no second email; moved to Screening
  together; an applicant of another requisition untouched.
- In the browser on the test server: Select all shown checks the 13 applicants and enables the
  button. (The test browser pane has no PDF viewer, so the inline PDF was checked by its headers;
  a regular browser displays it.)

Full suite: 3704 PASS, 0 FAIL (3694 before, plus the 10 new checks).
