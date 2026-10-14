# P5-M25 — the remaining inline styles

The owner's request, 14 Oct 2026: finish the inline styles wave 4 left.

## Found before building

Measured on the version then live (3f9d24f):

**295 inline styles in the views.** My line-by-line count said 294; the
new test found one more, spread over two lines on the project dashboard.
- **14 hold PHP.** 10 of those are widths computed from data (progress
  bars); 4 choose a colour or a tone by a condition.
- **165 are values repeated two or more times:** field widths (seven sizes
  in em and px), margins under a heading (-4 or -6px), small paddings, a
  card's top strip, a big figure.
- **115 one-offs.**

Measured on 45 pages:
- each role's daily screens;
- the most-styled pages: Project setup, Project board, Deployment view,
  Campaigns, Screening questions, Account.

On those 45 pages, 159 elements carried an inline style.

## What was built

**The converter splits each style into its declarations.**
- When every declaration has a shared class, the style becomes those
  classes.
- When one does not, the style is left as it was, so nothing a page shows
  is dropped.
- 228 styles went this way.
- **Margins:** on the existing scale (4, 8, 12, 16, 24px), with
  `pull-up` for the paragraph tucked under its heading.
- **Fields:** `in-*` caps a field's width, `fw-*` sets it (6, 8, 10 and
  12em, and `in-xl` 30em).
- **Flex:** `flex`, `flex-wrap`, `gap-*`, `items-*`.
- **Text:** `semibold`, `bold`, `t-muted` and the like.

**The 52 one-offs.**
- **Mapped by hand to a class named for what it is:**
  - `card-foot` (the strip along a card's bottom), `card-row` (one line of
    a list inside a card);
  - `stat-label`, `steps`, `check-label`, `inline-check`;
  - `progress-track` and `progress-fill`, `board-cols`, `qr-box`,
    `stage-step` (the dashboard's pipeline strip);
  - `tone-warn` and `tone-alert` (the tinted cards).
- **Close colours were merged:**
  - three pale backgrounds become `bg-soft`;
  - `#b42318` and `#9b1c1c` become the brand red.
- **Kept:** the accessible amber `#8a5600`, which keeps its own class
  (`t-amber-ink`), because the brand amber is too light for small text.
- **The 4 colours chosen by a condition** now choose a class.

**What stays inline:** 10 widths worked out from data (`width:<?= … ?>%`),
the progress bars. That is what an inline style is for.

**Measured on the same 45 pages:**
- 159 styled elements down to 6, all of them progress widths.
- 35 of the 45 pages are the same height to the pixel; the rest are within
  17px (the spacing scale).
- Nothing scrolls sideways at 390px, and no page shows a PHP error.
- **Corrected along the way:** Team's activity list first grew by 252px.
  Its rows had been given the card strip's 14px padding, so they got their
  own `card-row` at the original 9px; Team is now within 2px.

**The stylesheet link is now `crewvia-ui.css?v=5`.** It had stayed at `v=4`
since wave 3, although wave 4 and this pass both changed the stylesheet, so
a browser that kept wave 3's copy could miss the new classes. The new
number makes every browser fetch the current file.

**Where the class goes.** On a tag with no class of its own, the class takes
the style's exact place, so every other attribute keeps its position.
- **Why:** the first run put it right after the tag name, and the full suite
  failed in `rfq_http.py`, which reads the order form's
  `<input name="vendor_name"…>` by its first attribute.
- **The fix:** rather than loosen that test, the pass was redone from the
  views in git with the class in the style's place.
- **Checked:** with each tag's attributes sorted, the two passes are
  identical, so the measurements above hold.

**The logo.** The second run failed at "the logo leads home": the logo's
class had become `brand no-underline t-inherit`, and `home_http.py` reads
`class="brand"` exactly. The logo keeps `class="brand"`, and the rule
moved to `a.brand` in the stylesheet.
- **Checked:** every other class this pass extended was compared with the
  exact class strings the tests read.
- **Result:** the only other matches (`class="n"` on Today, Time off and
  Training; `class="muted"` on Project tasks) are on tags this pass did not
  touch.

**Data:** no data changes, no migration.

## Tests

- **`tests/page_pass_http.py`:**
  - The ceiling of 294 is now a rule: the only inline style a view may
    carry is a width worked out from data. The check reads the whole file,
    so a style spread over two lines is seen; that is how the dashboard's
    was found.
  - The helper-class check reads the stylesheet, including rules written
    side by side on one line, and requires every helper class the views
    use to be defined (58 in use).
- **`tests/layout_http.py`:** passes; every menu page of the four roles it
  walks opens with the standard header.
- **Mutation:** with `style="margin-top:3px"` put back on Today in the test
  copy, the test failed and named it. Restored afterwards.
