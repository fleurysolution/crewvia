# P5-M26 — the flow-by-flow walkthrough, role by role

The owner's request, 14 Oct 2026: walk every flow, user by user, with the open
Indeed jobs, and fix what breaks. Production was ruled out while RSS is hiring.

## How it was walked

- **The walkthrough copy:** a blank agency on this computer, with email
  delivery off.
  - Every account was created the way the agency would create it.
  - Every step went through the screens, never into the database.
  - The two open Indeed postings (CNC Machinist, Chemical Engineer) were read
    from Indeed and never changed there.
- **The full-scale copy:** RSS's real size, with 1,022 applicants and 900
  hires.
  - 3 owners, 2 VPs of operations, the VP of recruitment, 4 recruiters,
    4 coordinators, 10 PMs and 65 van supervisors.
  - Every account on a solutionsfleury+label@gmail.com address.
  - The 65 vans and 3 plants.
  - Each request was timed.
- **What was proven:** each fix was checked by its effect, then held by
  tests/walkthrough_http.py.

## Findings

Status is one of: fixed; withdrawn (not a bug); or left for the owner, which
is listed again below.

| # | Flow | Role | Finding | Status |
|---|---|---|---|---|
| 1 | First sign-in | every desk | After the forced password change, a desk landed on the project dashboard ("No active job") instead of its home | Fixed: app/pages/account.php redirects to home_for(); verified recruiter -> /home, supervisor -> /my-team |
| 2 | Team | admin | "1 accounts", and the subtitle was English in every language | Fixed: tn() + translated |
| 3 | Team | admin | Can the last administrator demote themselves? | Not a bug: refused ("That is the last person who can see everything") - verified |
| 4 | Team | admin | Choosing "Worker portal" for a staff account did nothing, silently | Fixed: says a worker signs in through the invitation from their candidate file |
| 5 | Agreement | admin | "Transport on site" covered though not ticked | Not a bug: the box is ticked by default |
| 6 | Agreement | admin | A scope line with no guarantee of its own counted 0 hours: "full week" showed only the per diem ($3,150) and "No bill rate on any line yet" though both lines had bill rates | Fixed: scope_guarantee() falls back to the agreement's guarantee; now $17,150 / $21,400 / margin $4,250, checked by hand. Same fallback added to the overview's weekly money (placements with no guarantee) - to verify once placed |
| 6b | Agreement | admin | ":people people across :lines lines" | Fixed: counts in words |
| 7 | Requisitions | recruiter | The recruiter was shown the "Create a requisition" form but every save answered "Not your desk" (admin-only since the first commit; the tests only ever used an admin) | Fixed: a recruiter raises requisitions, still capped by the agreement's line; overriding the agreed rates stays admin-only (hidden for others, refused on the server); close/reopen/remove stay admin. Verified: both Indeed requisitions raised by the recruiter, rates inherited |
| 8 | Careers / apply | public | The public pages show the agreement's description ("About the assignment") and the project's title; the agreement form asks for that text "in the words the client would recognise" | DECISION FOR THE OWNER: if client names must stay confidential in postings, these should be hidden or rewritten for candidates. Client name, order reference, bill rate and site name are NOT shown (checked) |
| 9 | Apply | public | The application asked no city, state or ZIP, so the distance filter (T8) could not place applicants ("Location not given" for all) | Fixed: optional City, State, ZIP on the application; placed at once (geo_refresh_candidate). Verified: a Pasadena TX 77506 applicant is within 25 miles of the site |
| 10 | Apply / my-jobs | public, worker | A yes/no question's label pointed at an id no radio had: screen readers did not tie the question to its Yes/No | Fixed: the label names a role=radiogroup; my_jobs_http checks the new markup |
| 11 | Screening | recruiter | A number question could not set a minimum: 1 year of setup scored the same as 6, though the requisition requires 2 | Fixed: a disqualifying answer "<2" on a number question flags anything under 2 (exact answers unchanged). Verified: 1 year flagged with the reason, 5 years 100% |
| 11b | Screening | recruiter | Changing a question's weight or minimum meant removing it, which threw away every answer received | Fixed: "Change" edits weight, disqualifying answer and required, and re-ranks with the answers kept (verified: weight 3 -> 6, answers kept); Remove now warns how many answers it discards |
| 12 | Hiring / offers | recruiter | SERIOUS: screening questions were counted across every role of the project. A required question for one role (the Chemical Engineer's sponsorship question) counted against every other role: "3/4 answers", and the offer gates (Hiring, Application screening, contracts) would refuse every CNC offer | Fixed in 8 queries: a role's questions are the project's plus its own, as screening_questions() already said. Verified: 3/3, and Gale offered |
| 13 | Operations | owner | Worker invitations (after an offer) and "forgot password" messages are queued by install/deliver-emails.php and install/deliver-account-mail.php, which are NOT in the cron table: on production they are never sent unless run by hand | docs/OPERATIONS.md now lists both every 5 minutes. THE OWNER ADDS THE TWO CRON LINES |
| 14 | Invitation email | worker | "Activate your employee portal. Your application has progressed..." named neither the agency nor the job (reads like phishing) | Fixed: names the agency (brand name) and the job, greets by first name, says to ignore it if they did not apply. Verified on the queued message |
| 15 | Portal | worker | The application's stage showed the raw word "offered" | Fixed: in words (Offered, Hired) |
| 16 | Portal / Hiring | worker, recruiter | The note typed when moving to Offered is shown to the candidate as the offer; the field read like an internal note | Fixed: the placeholder says the candidate reads it; shown as "From recruiting:" beside the terms |
| 17 | Portal | worker | SERIOUS: the worker was asked to accept "the offer terms displayed above" with nothing above but the note; what was signed (and hashed) was only the note | Fixed: offer_terms() - pay, per diem, guaranteed hours, start and shift, place, what is paid - shown and signed (verified: the signed record holds the terms and the note) |
| 18 | Placement | logistics | A placement made at acceptance had no start date, though the requisition starts 26 Oct: Logistics' arrivals depend on it | Fixed on both acceptance paths (portal, contract). Verified: Emery's placement starts 26 Oct |
| 19 | Candidates / Today | recruiter | Hiring moved the application and left the person at "new": hired people counted among "new people nobody has contacted" | Fixed: candidate_follow_application() moves the person forward at every application move; one-time upgrade sweep, reversible (install/rollback/p5-m26.sql). Verified: 7 -> 6 after a move; sweep moved Gale and Emery |
| 20 | Acceptance | logistics | Portal acceptance did not open the lodging request | WITHDRAWN: it does (my reading was cut short) |
| 21 | Apply / Hiring | recruiter | Applying again with the same email makes a second candidate record | By design (identity is a person's call, never the email's), but only Application screening said so. Fixed: Hiring's review pane warns "Possible existing candidate" by email or phone, linked. Verified |
| 22 | Placement | recruiter, logistics | SERIOUS: nothing could change a placement's start or end date once it existed (and one made at acceptance had none); the recruiter/logistics placement page also showed the raw status, "room" in English and raw directions | Fixed: Dates on the placement page (recruiting, logistics), end not before start, logged in the person's history; status, room and directions in words. Verified |
| 23 | Roster | recruiter | The trade read "Other" for someone placed on the Chemical Engineer line | Fixed: falls back to the scope line's role. Verified |
| 24 | I-9 / W-4 | worker, recruiter | Statuses printed raw ("employer_completed"), labels English only, empty dates blank | Fixed: in words, translated, "—" when empty. (Not a bug: the forms take one document; my script had read the field wrong) |
| 25 | Onboarding | recruiter | SERIOUS: a task the worker had never submitted could be approved, which opened the deployment gate (it happened in the walkthrough: Emery's untouched task was approved) | Fixed: only a submitted task can be reviewed (server and page); statuses in words. Verified: a hand-made approval of an unsubmitted task is refused; Gale's submitted task approved, Gale "Clear" and confirmed |
| 23b | Hotels, Travel, Manning, Organization | logistics, recruiter | The same "Other"/"Unassigned" trade on four more pages; Travel's column said "Engineer"; Manning's "Unassigned" was English only | Fixed everywhere: the trade, else the scope line's role; "Person"; translated. The assign form now starts with the line's role |
| 26 | Travel | logistics | The flight line showed an empty departure and no arrival | Fixed: "leaves" / "arrives" as given |
| 27 | My team | supervisor | Crew statuses raw ("on_site") | Fixed: in words |
| 28 | My team | supervisor | SERIOUS: My team counted who was not marked at roll call, but only the administrator's Deployment view (and the trusted phone) could mark it; the tile linked to Attendance | Fixed: Present / Absent on My team for the supervisor's own crew on site, logged. Verified: 1 -> 0 not marked |
| 29 | Screening questions | recruiting | At 3 plants, the same questions must be typed 3 times: no copy to another project | To build (owner's call) |
| 30 | Apply | public | MEASURED: 20 applications an hour from one connection; the 21st is refused "Please wait before submitting again" - a hiring event on one wifi stops at 20 | DECISION FOR THE OWNER (anti-spam limit, security trade-off) |
| 31 | Import | admin | An import always placed everybody as "offered" on the working project (which falls back to a default), so a job board's applicant export skipped screening, offer and signature | Fixed: the import asks what the rows become - employees on the working project (unchanged default), applicants to a chosen open requisition, or the talent pool. Verified: 1,002 applicants imported onto 6 requisitions, no placements |
| 32 | Import | recruiter | Imported people not placed on the map | Withdrawn: placed lazily on the first Hiring/Candidates visit (measured 2.9 s for 1,000, then 69 ms) |
| 33 | Screening questionnaire | recruiter | SERIOUS: answers taken on the phone were saved but never scored: "Yes, I have a full-time job" flagged nobody (measured: screened_out 0, score NULL) | Fixed: saving the questionnaire rescores the application - to verify on plants B and C |
| 34 | Vans | logistics, supervisor | MODEL GAP: a van can only be checked out to a worker on a placement with a portal account; RSS runs one supervisor per van carrying 10-15 workers, and nothing ties a van to a supervisor's crew | To build (owner's call): a van belongs to a supervisor, its passengers are that supervisor's crew |
| 35 | Portal activation | worker | MEASURED: 20 activations in 15 minutes from one connection, then "Please wait": an onboarding day on one hotel wifi stops at 20 | Fixed together with 30: the server's configuration may now set each limit ('rate_limits', config.example.php); nothing set, nothing changes. The owner decides the numbers |

## Left for the owner

- **30 / 35, the per-connection limits:**
  - 20 applications an hour;
  - 20 activations in 15 minutes.

  They can now be set on the server (`rate_limits` in the configuration).
  Suggested: `['public-application' => [200, 3600], 'activation-ip' => [200, 900]]`.
- **13, the two mail jobs:** add the two cron lines (docs/OPERATIONS.md). Until
  then, worker invitations and "forgot password" messages are queued and never
  sent.
- **8, the public pages:** the careers page shows the agreement's description
  and the application page the project's title. Decide whether client names
  may appear there.
- **29, the screening questions:** they cannot be copied from one plant to
  another.
- **34, the vans:** a van belongs to a worker's placement. RSS runs one
  supervisor per van with 10-15 workers, so a van should belong to a
  supervisor, with that supervisor's crew as its passengers.
- **The project managers:** Crewvia has no project manager desk. In the
  walkthrough they are supervisors, who see only their own crew.

## Data

One reversible migration: the one-time candidate stage sweep (finding 19).
- **The table:** install/candidate-stage-sweep.sql keeps each old stage.
- **The rollback:** install/rollback/p5-m26.sql puts them back.

## Tests

- **`tests/walkthrough_db.php`, `tests/walkthrough_http.py` and
  `tests/walkthrough_probe.php`:** 31 checks, one or more per fix, each read
  back from the database.
- **Mutation:** with the candidate-stage step taken out of Hiring, the test
  failed at "the person moves forward with their application" (the stage
  stayed "new"). Restored afterwards.
- **`tests/http_tests.py`:** the portal marker.
- **`tests/my_jobs_http.py`:** the yes/no question names its radio group.
