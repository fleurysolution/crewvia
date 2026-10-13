# P5-M20 — layout walkthrough, wave 1: one shared look

The owner's request, 13 Oct 2026, before the security clean-up: "the
dashboard, the portal, the clients, the settings, the project… all need to
be checked and upgraded for conformity and great layout… everything may be
in place but they look scattered".

His decisions:

- **Homes:** one home page per role.
- **Workers:** one home, merging My portal and My self-service.
- **Menus:** daily screens first, the rest under "More".
- **Delivery:** four waves, stopping after each:
  1. the shared look (this report);
  2. the role homes;
  3. the menus;
  4. a page-by-page pass, busiest screens first.

## The audit

An audit script opened every page in every role's menu on the preview
(admin, recruiter, Logistics, payroll, supervisor, worker, client):
**109 distinct pages**. It measured each one and took a screenshot.

**Found:**

- **Nothing broken:** every page answered, with no PHP error.
- **Three header styles:**
  - 82 pages with a bare title;
  - 22 with an older header;
  - 5 with the new one.

  Action buttons sat in different places.
- **563 inline style overrides** on 51 pages.
- **Buttons that stretched** to fill their grid cell or form row, such as
  "Save the credentials" on Integrations and "Open project" on the client
  portal.
- **The working-project bar on every page,** including Team, Settings,
  Integrations, Candidates and Campaign email, where nothing depends on it.
- **Three pages wider than a phone:**
  - Candidates by 766 px;
  - System health and Project tasks by about 100 px.
- **Every desk landing on the same project dashboard**, a worker with two
  overlapping homes, and very long menus: these are waves 2 and 3.

## What wave 1 changed

- **One page header.** Every page that opened with a bare title and
  subtitle now opens with the standard header: title and one-line purpose
  on the left, actions on the right. That is 72 views, wrapped by a script
  that only adds the container around the existing title and subtitle.
  The 22 older headers now look identical, through one stylesheet rule.
- **Every page says where it is.** Above the title, the section it belongs
  to in the menu ("RECRUITING", "PAY AND BILLING"…). It is added by the
  layout unless the page already names its own context there.
- **Buttons keep their own size,** in a grid cell or a form row.
- **The working-project bar shows only on pages that read the working
  project:** 47 routes, plus a person's and a placement's page. The list
  is worked out from the code (`workspace_project_page()` in
  `app/navigation.php`). The notifications pill still shows everywhere.
- **Nothing scrolls sideways on a phone.**
  - A wide table scrolls inside its own card.
  - On Candidates the cause was the hidden screen-reader labels in the
    table rows: positioned against the whole page, they escaped the
    table's scroll box. The scroll box is now their reference.
  - Measured at 390 px wide: Candidates 0 px of sideways scroll (was
    766), and System health and Project tasks 0.
- **Found while building:** a view can overwrite the layout's own
  variables. `devices.php` sets `$here`, which hid its section label. The
  layout now saves the page's path before the view runs.

**Measured after wave 1** (same audit, same 109 pages):

| | Before | After |
|---|---|---|
| Standard header | 5 | 68, plus 22 older ones that now look the same |
| Bare title | 82 | 19 (detail pages with a back link: wave 4) |
| Wider than a phone | 3 | 0 |
| Project bar on unrelated pages | all | none |

Styles are in `public/assets/crewvia-ui.css` (now `?v=3`). There are no
data changes, no new strings, and no migration.

## Tests

- **`tests/layout_http.py`**, for admin, worker, supervisor and client,
  page by page through each one's menu (102, 33, 22 and 1 pages):
  - every page answers;
  - every page has exactly one title;
  - every page says which section it belongs to;
  - every page opens with the standard header, except the 19 listed for
    wave 4. That list only shrinks.
  - The project bar shows on Hours, Roster, Hotels, Attendance and the
    project page, and not on Team, Settings, Integrations, Candidates,
    Campaign email or the audit log.
- **Mutation:** with the section label removed, the test failed at
  "admin: every page says which part of Crewvia it belongs to". Restored
  afterwards.
- **Phone widths and screenshots** were checked with the audit script on
  the preview. They are not in the suite, whose browser step is still
  skipped (DEFERRED_SECURITY X7).

## Left for the next waves

- **Wave 2:** a home per role, and the worker's two homes merged.
- **Wave 3:** the menus, with daily screens first and the rest under
  "More".
- **Wave 4:** the 19 detail pages' headers, and the inline styles page by
  page, busiest screens first:
  - Audit log 141;
  - Team 73;
  - Settings 41;
  - Project 36;
  - Assets 36;
  - Project dashboard 31.
