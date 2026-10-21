# P5-M102 — The careers and application pages after the first real test

Firmin applied as "Paco Test", then applied again from the RSS demo website (rssinc.veloraweb.com). Paco arrived in
Candidates. The second application did not.

## What went wrong, and what changed

| Seen | Cause | Now |
|---|---|---|
| The second application never arrived | A second application less than 60 s after the first, in the same browser, was refused with a line of plain text on a blank page. A form left open long enough to expire ended the same way ("That form expired"). Nothing was recorded, and the applicant lost everything typed. | A refused application comes back as the form, with what was typed and one line saying why: wait a few seconds, enter a valid email, tick the consent box, or press Apply again after the refresh. The wait between two applications is now 15 s; the per-address limit of 20 an hour is unchanged. |
| The application page showed "Strike Coverage - T shirt printers" | The project's name was printed under the title. It can name the client, and strike work is confidential. | The page shows the city and state only. |
| "Travel, lodging and a daily allowance are provided" on the careers page | A blanket promise, but not every job pays the travel. | "Industrial assignments across the United States. The pay, the guaranteed hours and what is provided are on each position." |
| The application asked every applicant for their airport, and said "so we can tell you about work near you" | Written for one case. | The airport is asked only when the job pays the travel. The job's facts show the guaranteed hours, "Travel to and from site paid", "Hotel paid" and "Transport on site provided", each only when the project includes it. The city hint reads "Where you live. Optional." |
| The Back button did nothing on the careers page | Careers opens in a new tab, which has no page to go back to. | When there is no page to go back to, Back opens the page the person came from. |
| Hard to find how to close a requisition | "Close this requisition" was inside the "QR code & more" pop-up. | "Close" (or "Reopen it") is a button on the requisition's row. Closing removes the job from /careers and from the website feed (within 5 minutes on the website). |

## Tests

`tests/careers_feed_http.py` now has 17 checks. The new ones cover:

- the project name stays hidden;
- the travel terms and the airport question follow the job;
- no blanket promise and no "near you";
- a refused application comes back with its entries and the reason (no consent, an expired form, a second application
  seconds later), then goes through.

## Deploy

```bash
sudo -u veloraweb bash -c 'cd /home/veloraweb/public_html/crewvia && git pull --ff-only origin main && git log -1 --format=%h'
```

No migration.
