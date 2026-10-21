# P5-M101 — The open positions for the agency's own website

## Why

RSS's public website (rssinc.com) only offers a resume upload, and Crewvia's `/job-feed` (the Indeed feed) lists only
published requisitions with an employer name configured. In production it was empty, while `/careers` showed six
positions. The rebuilt RSS website (VeloraWeb, template Local Crew) needs the positions Crewvia shows, with their terms,
and an apply link that brings every applicant into Crewvia.

## What

- **`GET /careers-feed`.** Public JSON (`crewvia-careers-v1`), readable from any origin (CORS `*`), cached 5 minutes,
  GET only (405 otherwise).
  - It lists exactly the positions `/careers` shows: open requisitions on running projects, not expired.
  - For each position: title, discipline, city, state, shift, start, openings, summary, years of experience, degree
    and requirements.
  - The terms: pay per hour, guaranteed hours a week, per diem, hotel, travel and transport on site. These are the
    terms actually agreed for that trade: the requisition's own number, or the one on its line of the scope of work.
  - Its `apply_url` (`/apply?id=`).
  - Nothing about the client, because strike work is confidential.
- **`app/job-publication.php`.** It now holds the shared query (`public_openings()`) and the terms
  (`public_opening_terms()`), so `/careers` and the feed cannot drift apart.
- **`/job-feed`** is unchanged: it remains the Indeed feed.

## Tests

`tests/careers_feed_http.py` (7 checks) covers:

- the format and the CORS header;
- the same positions as `/careers`;
- the terms and the apply link, and that the apply link opens that position;
- no client names;
- GET only.

## Deploy

```bash
cd /home/veloraweb/public_html/crewvia && git pull --ff-only origin main && git log -1 --format=%h
```

No migration.
