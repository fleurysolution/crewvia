# Crewvia: what it is sold as, and what it does not promise

Crewvia is a staffing and placement operations platform by Fleury Solutions LLC. It is written for whoever answers a client's question about it, and that person will not be reading the sales catalogue.

Every line below was checked against the code on 20 Nov 2026, not copied from the catalogue. If the code changes what a line says, change the line in the same commit.

Prices are not written here, so this page never contradicts the catalogue. The figures are in the hub's catalogue under the codes below, one version in force at a time.

## What it is sold as

| Catalogue code | What it is | Renews? |
|---|---|---|
| `implementation_hour` | Setting Crewvia up for a client: discovery, configuration, data import, training and go-live support. Fixed hours, agreed before the work starts. | No, one time |
| `subscription_employee` / `subscription_minimum` | Hosting, backups, security updates, support and new releases. Billed monthly, with a monthly minimum. | Yes, monthly until cancelled |
| `crewvia_pilot` | One open role run end to end in Crewvia for 60 days, credited against the setup if the client continues. | No, one time |
| `job_board_apply_links` (+ `_extra`) | Each requisition's own application link and QR code, set up on the client's job boards, so applicants apply in Crewvia. | No, one time |
| `applicant_import_format` | Existing applicants brought in from a job-board export or a spreadsheet, without duplicates, one price per source format. | No, one time |
| `indeed_integration` | Posting to Indeed and receiving Indeed Apply applications automatically. **It can only start once Indeed approves Fleury Solutions as an ATS partner.** That is Indeed's decision, it is not guaranteed, and nothing is billed until it is granted. | No, one time |

**Two ways a client runs it:**
- **Dedicated installation.** RSS runs this way: its own server, quoted and invoiced at a fixed price, with no Stripe subscription. The Subscription screen shows "Model: demo · Stripe: Not configured". That is normal, not a fault.
- **Stripe subscription.** The client pays by card. Since P5-M114, the subscription renews automatically until the client cancels, under the terms approved on 19 Nov 2026.
  - The client agrees to those terms before paying.
  - The client cancels from the Subscription screen, and access continues until the end of the period already paid for.
  - The amount is the number of seats the client contracted, multiplied by the unit price. The seat count is never synced from usage.

## What it does

- **Recruiting:**
  - requisitions and their application links;
  - applicants arriving in the system instead of an inbox;
  - screening questions, reviews and interviews, with who decided what and when.
- **Hiring:**
  - the offer, signed by the worker and countersigned by the office;
  - the hire paperwork (W-4, I-9, direct deposit with bank proof);
  - authorisations;
  - the background check and drug test, recorded as sent and received.
- **Deployment:**
  - the roster;
  - travel (flights, hotels, the room number);
  - arrival on site;
  - the clock-in, validated by the supervisor;
  - the roll call;
  - lateness, with the reason the worker sees;
  - expenses paid back on the next pay.
- **Pay preparation:**
  - hours and weekly sheets;
  - the agreed deductions and employer contributions;
  - advances;
  - new hires sent to ADP, by file or through ADP's API.

## What it does not promise

Do not promise any of these to a client, RSS included. Each one is a fact about the code, not a plan.

1. **No no-show alerting.** When a worker fails to clock in at shift start, Crewvia sends no alert on its own.
   - What exists: the supervisor is told when a worker **does** clock in, and marks them present or "not here" at roll call. A worker marked "not here" is told to call their supervisor.
   - A missing clock-in is seen by a person looking at the roll call, not by an automatic alert (`app/clock-in.php`).
2. **No payroll tax handling.** Crewvia computes gross pay and the agreed deductions **before tax**. It never computes or withholds federal, state or local taxes, and it files no payroll tax returns.
   - The payroll provider does that (`app/gross-to-net-calculation.php`: "Taxes are not computed here and never will be from this code").
   - Crewvia collects the W-4 elections and passes them to ADP. It does not apply them.
3. **No vendor management system entry.** Crewvia does not enter workers, hours or invoices into a client's VMS, such as SAP Fieldglass.
   - It has no integration with any VMS.
   - If a client works through one, entering data there stays a manual step.
4. **No direct ordering of background checks or drug tests yet.** HireRight and Quest Diagnostics orders are recorded in Crewvia as sent and as received, by hand.
   - Crewvia places no order through their APIs. The integration waits for their credentials and documentation (`app/screening-orders.php`).
5. **No E-Verify submission.** When the employer uses E-Verify, the I-9 asks for the Social Security number and the case number is recorded in Crewvia. The case itself is opened in E-Verify, outside Crewvia.
6. **No automatic Indeed integration before Indeed's approval.** Until Indeed approves Fleury Solutions as an ATS partner, two things work instead:
   - posting on Indeed by hand with the Crewvia application link;
   - importing Indeed's own applicant export.
7. **No seat count synced from usage.** The monthly usage review on the Subscription screen is read-only. It never changes what a Stripe subscriber pays. Syncing seats from usage would be new scope, with its own approved clause.

## When this page must change

- A milestone that adds one of the capabilities above removes it from "What it does not promise" in the same commit, and its report says so.
- A new catalogue line for Crewvia is added to the table, with its code and whether it renews.
