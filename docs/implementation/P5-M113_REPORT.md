# P5-M113 — The side menu grouped by trade

20 Nov 2026. The change was written by Codex (handoff `INBOX/2026-11-20_from-codex_menu-lateral.md`). Claude reviewed it, tested it and committed it.

## What changed

- **`app/navigation.php`, `workspace_sections()`.** "More" now has one section per trade:
  - Overview, Projects, Recruiting, Hiring, Deployment, HR, Safety and training, Communications, Payroll, Clients, Billing, Administration.
  - "Running the job" and "Pay and billing" are split up.
  - Recruiting starts with requisitions, candidates and screening.
  - The set of screens is unchanged: the same paths are listed before and after, checked by diff.
- **`app/views/_layout.php`:**
  - Permissions are filtered once, before the menu is built. The daily shortcuts, the sections, the bookmarks and Recent all use the same filtered list, so a bookmark to a screen the person can no longer open is hidden.
  - Bookmarks and Recent move below the main menu and start folded. The "Bookmark this screen" command now sits inside Bookmarks.
  - "Find a screen" searches only the main menu.
    - It shows only when there is more than one screen.
    - Enter opens the first match with the link's own behaviour, so Careers still opens in a new tab.
    - Escape clears the search and puts the sections back the way they were before typing.
  - The Activity counter is now separated from its label by a space.
- The section titles reuse translations that already exist in FR and ES. No new strings.

## Tests

- `tests/page_pass_http.py`: the eyebrow on /projects is now "Projects", its new section, instead of "Overview".
- `tests/walkthrough_http.py`: this test would have started failing on its own once the date passed. The code moves a requisition start date that is already past to today (`offer_start_date`). The test now expects `max(2026-11-02, today)`.
- Full suite (`SKIP_BROWSER=1`): 4292 PASS, exit 0.

## Not covered

- No browser or mobile pass ran locally, because the suite skips the browser. The menu is checked on production after the deploy.
