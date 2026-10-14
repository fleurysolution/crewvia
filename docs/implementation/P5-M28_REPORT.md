# P5-M28 — the notices along the flow, and the VP's validation of an offer

The owner's flow, 14 Oct 2026:
- **A new order:** when the owner or a VP enters it, the VP and the project
  manager are told there is work.
- **A posted requisition:** the recruiters are told how many candidates are
  needed.
- **A candidate the recruiter finds:** they go to the VP for validation. Only
  then does the candidate hear "you have been selected", and the recruiter
  hears it went through.

## Found before building

- **Approval chains** could be configured on Approval chains, including "Offer
  before it reaches the candidate", but nothing in the hiring flow ever started
  one. A chain set up there never fired.
- **Orders and requisitions** told nobody.
- **The project manager:** a plant had none.

## What was built

**Notices** (`app/notices.php`). Each is a notification row, so it rings the
bell, lists on Notifications, and reaches a phone where push is switched on.
- **An order line** added to an agreement tells the recruiting desk ("New order
  on Plant A: 200 x Welder. Raise the requisition.") and the plant's project
  manager.
- **A requisition** raised tells every other recruiter ("New requisition on
  Plant A: 50 x Welder starting 9 Nov 2026. Find the candidates."), with a link
  straight to it in Hiring. The project manager is told too.

**A plant's project manager.** The agreement names them, from the staff. They
are told when they are named, then of each order and requisition. Supervisors,
which is what the 10 PMs are for now, open notices on My team.

**The VP validates an offer.** With an "Offer before it reaches the candidate"
chain:
1. A recruiter's move to Offered waits for the chain's steps. The note is kept,
   and the candidate hears nothing.
2. The VP, or whichever desk the step names, is told and decides on Approvals.
3. **Approved:** the offer goes out with the recruiter's note and the
   invitation, as a move to Offered sends them. The recruiter is told "was
   validated; the offer is with the candidate", and the candidate, if they
   already have a portal, "You have been selected".
4. **Turned down:** the recruiter is told why, and can change the offer and
   send it again.

Without a chain, nothing changes. Hiring shows an offer waiting for validation,
and one that was turned down.

**Data:** one reversible column, `jobs.project_manager_id`
(install/rollback/p5-m28.sql).

## Tests

- **`tests/notices_http.py` and `tests/notices_probe.php`:**
  - the project manager named and told;
  - an order line reaching the recruiters and the project manager;
  - a requisition reaching the other recruiters and not the one who raised it;
  - an offer waiting for validation, the VP told, Hiring showing it, a second
    send refused;
  - approved: the offer out with the note, the recruiter told, the candidate
    invited;
  - turned down: the offer not out, the recruiter told why.
