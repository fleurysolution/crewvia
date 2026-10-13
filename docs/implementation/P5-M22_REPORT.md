# P5-M22 — layout walkthrough, wave 3: the menus

The owner's decision, 13 Oct 2026: menus with the daily screens first, the
rest under a collapsed "More".

## Found before building

- **Long menus, all the same weight.** Every screen a role may open sat in
  one of up to eight folding sections:
  - administrator: 103 entries;
  - recruiter: 59;
  - payroll: 50;
  - worker: 33.

  Only the section holding the current page was open, so a screen used
  every day was one click away, behind a section heading.
- **On a phone, the open menu was painted over by the page.** The shell
  stylesheet fixed the header at 56px on every screen size. The menu opens
  inside the header, so it spilled out below, and the page's text and
  buttons were drawn on top of it. This was already the case on the live
  version (seen on abae873).

## What was built

**Daily screens.** `workspace_daily()` in `app/navigation.php` lists each
role's daily screens, in the order of its day. They stay in view at the top
of the menu:

| Role | Daily screens |
|---|---|
| Administrator | Executive overview, Activity, Approvals, Project dashboard, Projects, Hiring, Hours, Billing, Team, Settings |
| Recruiter | Today, Activity, Hiring, Candidates, Requisitions, Application screening, Onboarding, Contracts & signatures, Client requests, Approvals |
| Logistics | Today, Activity, Hotels, Travel, Hotel folios, Manning, Operations, Procurement, Approvals |
| Payroll | Today, Activity, Hours, Week check, Lateness, Pay periods, Payroll export, Billing, Client invoices, Vendor payments, Approvals |
| Supervisor | My team, Activity, My attendance, Lateness, My tasks, Project tasks, Procurement, Site safety plan, Notifications |
| Worker | My portal, Jobs for you, My attendance, Time off, My pay statements, Notifications, Activity |

Activity is on every desk's list, so its queue count stays in view.

**More.**
- Every other screen the role may open is folded under "More", in its
  section, as before.
- More is closed on a daily screen. It opens by itself on a screen kept
  under it, with that screen's section unfolded.
- **Measured** on the preview, at 1440px:
  - none of the screens under More can be clicked while it is closed;
  - on Roster, More is open with Deployment unfolded and Roster marked.

**Access is unchanged.**
- The daily list only picks from what the menu already offers the
  person, so it never widens access.
- A client keeps their one screen and no fold.
- A role with no daily list keeps its sections as they were.

**The phone.**
- The header grows with the open menu (`.top { height: auto }` up to
  720px).
- **Measured** at 390px with the menu open, for a worker and an
  administrator: nothing from the page paints inside it.
- Nothing scrolls sideways for any of the seven roles.

**Also:**
- "More" in French (Plus) and Spanish (Más).
- **Data:** no data changes, no migration.

## Tests

- **`tests/menus_http.py`** (new), for each of the six desk and crew roles:
  - the daily screens, in order;
  - every other screen under More, nothing lost or listed twice;
  - More folded on a daily screen;
  - every daily screen opens for that person.
- **Also in `menus_http.py`:**
  - More open on Roster, with Deployment unfolded and Roster marked;
  - a worker's papers still under "My papers";
  - the client's single entry and no fold;
  - the phone header rule;
  - Spanish.
- **`tests/review_fixes_http.py`:** a worker's pay statements and Jobs for
  you, and a supervisor's My team, are now among the daily screens rather
  than inside a section. The checks say so; the rest of the worker's menu
  is still checked section by section.
- **Mutation:** with `workspace_daily()` returning nothing, the test failed
  at the first check ("admin: the daily screens first"). Restored
  afterwards.

## Next

- **Wave 4:** the page-by-page pass, busiest pages first:
  - the 18 detail pages still on the pending list in `layout_http.py`;
  - the inline styles;
  - wording such as "1 people out" on the Executive overview.
