# P5-M115 — What Crewvia promises, and the reports kept

20 Nov 2026. Firmin ordered it in this session ("oui pour la documentation, fais-la"). The hub proposed it on 19 Nov.

## `docs/PRODUCT-SCOPE.md` (new)

A plain page for whoever answers a client's question about Crewvia. It covers:
- what Crewvia is sold as, by catalogue code, and whether each line renews;
- the two ways a client runs it: a dedicated installation, as RSS does, or a Stripe subscription;
- what it does;
- what it does not promise.

Each refusal was checked against the code, not copied from the catalogue:

| Refusal | Where the code shows it |
|---|---|
| No no-show alerting | `app/clock-in.php`: nothing fires when a worker fails to clock in. The supervisor is told of a clock-in, not of its absence, and marks "not here" at roll call. |
| No payroll tax handling | `app/gross-to-net-calculation.php`: "Taxes are not computed here and never will be from this code". The provider withholds. Crewvia passes the W-4 elections to ADP. |
| No vendor management system entry (Fieldglass) | No VMS integration anywhere in the code. |
| No direct screening orders yet | `app/screening-orders.php`: HireRight and Quest Diagnostics are recorded as sent and received, by hand. |
| No E-Verify submission | `app/pages/employment.php`: the case number is recorded. The case is opened in E-Verify. |
| No Indeed integration before Indeed's approval | catalogue line `indeed_integration`. |
| No seat count synced from usage | P5-M114: the usage review is read-only. |

The first three are the ones the catalogue's pilot line already says never to promise RSS. The page carries no prices, so it never contradicts the catalogue.

## `tests/reports_check.py` (new, first step of `tests/run_windows.py`)

The check fails the suite when a commit named `P5-M<n>` (n ≥ 100) touches `app/` or `install/` and nothing in `docs/implementation/` covers it.
- **What counts as covered:** a report whose name covers n (`P5-M114_REPORT.md`, or a range such as `P5-M97-M99_REPORT.md`), or any document there that names `P5-M<n>`. For example, the staging runbook covers P5-M107.
- **Suffixed commits:** a commit such as P5-M110b is covered by its milestone's report.
- **Where the history comes from:** it reads the git history of the repository. On a copied tree without history, it says so and checks nothing.
- **Proof:**
  - 22 commits checked, all covered.
  - With `P5-M112_REPORT.md` moved aside, it fails and names commit 1b33206.

## The test stand-ins take a free port

`tests/push_http.py` and `tests/twilio_http.py` had their stand-in server hard-coded on 127.0.0.1:8094. On 20 Nov another session's test server held that port, so the pushes reached it instead of ours and the push test failed. Each stand-in now binds a free port and the test uses whatever port it got. The application already accepts any 127.0.0.1 port in debug.

## Full suite

`SKIP_BROWSER=1`: 4333 PASS, exit 0. This run includes P5-M114b (see the P5-M114 report).
