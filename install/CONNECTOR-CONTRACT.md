# Recruitment ingestion contract

This is an agency-controlled middleware interface. It is not a native Indeed,
LinkedIn or other board connector and must not be marketed as partner certification.
An authorized integration partner maps its actual API to this contract.

Configure `recruiting_webhooks[channel] = ['enabled'=>true,'secret'=>...]` privately
per tenant, with a random secret of at least 32 characters. Disabled by default.
POST JSON to `/recruiting-webhook?channel=your_partner` over HTTPS, maximum 128 KiB.
Headers: `X-Workforce-Timestamp` (Unix seconds) and `X-Workforce-Signature`
(lowercase SHA-256 HMAC of `timestamp + '.' + exact_body`). Five-minute tolerance.

```json
{"schema":"workforce.application.v1","event_id":"partner-event-123","vacancy_id":42,"name":"Example Candidate","email":"candidate@example.com","phone":"","consent":true}
```

201 creates a new candidate/application for staff review; 200 returns an existing
application for an identical repeated event; 409 rejects changed content with the
same event ID; 410 means a closed vacancy. Do not alter the body when retrying.
No automatic identity merging, decisions or employee account creation occurs on
an unverified public application. Normal recruiter screening/activation follows.
Consent must reflect the applicant's actual authorization, retained by the partner.

Public jobs: `/job-feed`; crawl discovery: `/sitemap.xml`. Configure legal employer,
HTTPS URL, publication/expiry dates and location. Submit the sitemap to Search
Console and validate JobPosting; appearance is subject to Google's acceptance.
This endpoint does not post jobs or send replies to public platforms.

## Sandbox before live (P4-M05)

Each channel has a mode, set by an administrator under Integrations:

- **sandbox**: a correctly signed, valid event is answered `202` with
  `{"sandbox":true,"valid":true,"vacancy_open":...,"created":false}`. Nothing
  is created. The first such event records the sandbox trial as passed.
- **live**: events create the candidate and application as described above.
  Live is allowed only once a sandbox trial has passed and the administrator
  has recorded who at the agency authorized it.
- **disabled**: every event is answered `401`, as for a wrong signature.

A channel configured before P4-M05 was recorded live, as it already was. A
channel added afterwards starts in sandbox.

To rotate a secret, put the new one in `secret` and the old one in
`secrets => ['...']` until the partner has switched. Remove it afterwards.
Every event outcome is kept in the integration audit trail, refusals
included.
