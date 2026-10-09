# Contracts and DocuSign staging

DocuSign is DISABLED in the default configuration. No account was connected and no envelope was sent during local tests. The internal contract workflow is usable without a signature provider.

## Internal workflow

Admin opens Contracts & signatures for the selected project, chooses Internal signature and optionally requires a signed contract before deployment. Configure exact proof document types (one per line), based on approved business/legal procedures. No I-9-before-offer requirement is inserted automatically.

Candidate receives a screening-stage portal invitation, uploads evidence under Proof uploads; office reviews the latest evidence. Required documents must be approved before preparing a draft, approving and issuing it. Required screening checks/questions must be complete at issuance and internal acceptance.

Recruiter selects an application, prepares terms and optionally attaches the original PDF. PDF upload happens in draft only; its SHA-256 is included in the signed content hash. Approve then issue; a portal notification is created. Candidate reads the terms/downloads attachments, provides typed name and explicit consent, declines or uploads a signed PDF. Upload is NOT automatic acceptance: staff must verify it and record notes.

Accepted contract is immutable, archived with signer/account/server time/content hash and consent event. The signature record is downloadable JSON; this is an internal evidence record, not a digital certificate or a PDF cryptographic signature. Original and uploaded PDFs are encrypted and scoped to the candidate or recruiting desk. Signed contract acceptance starts the existing onboarding workflow. Unsigned/expired current contracts block deployment when the project policy requires it.

Draft/approved revisions may be superseded; declined/expired/voided contracts retain history. An issued internal contract must be voided before preparing a replacement. Signed terms cannot be rewritten: amendments require a separately managed application/contract scope, not modification of the signed record. Currently one candidate signer per contract; no multi-party/countersignature engine.

## Optional DocuSign adapter — account validation still required

The adapter implements envelope creation from the attached PDF, embedded recipient view, server-verified completion, combined signed PDF and certificate retrieval. Signature coordinates are configurable per draft; recruiter must preview the PDF and verify its page/position. English titles/instructions are not automatic legal translations.

Private tenant configuration, only after approval to connect providers:

```php
'docusign' => [
    'enabled' => false, // keep false throughout current staging
    'base_url' => 'https://demo.docusign.net/restapi',
    'account_id' => '',
    'access_token' => '',
    'connect_hmac_secrets' => [],
],
```

Provision a least-privilege authorized OAuth access token privately and maintain renewal. OAuth account consent/login and automated token refresh are NOT implemented by this adapter. The token must never appear in UI, source control or reports. The production API base must match the authorized account; demo tokens/accounts are separate from production.

When activation is separately authorized, use a developer account first, HTTPS, a private encryption key and a real contract PDF. Configure Connect JSON SIM notifications to POST to the tenant HTTPS /docusign-webhook endpoint with HMAC enabled. Only valid HMAC messages for the configured account and a known envelope enter the deduplicated queue. No callback/return query parameter is trusted as proof of signature.

Schedule `php install/reconcile-contracts.php` per tenant to process queued events. The worker verifies status/recipient via REST before retrieving signed PDF/certificate and starting onboarding. It serializes workers and limits each pass to ten events; up to five failed attempts remain for manual review. Supervision of failures and OAuth renewal are required for production. Current staging exits without a provider request when disabled. Recruiter can also use Reconcile DocuSign to recover pending dispatch or check provider completion.

A durable transaction ID is created BEFORE envelope dispatch; a failed/ambiguous send remains in sending and must be reconciled, never blindly resent. DocuSign transaction lookup is available for seven days; older unknown dispatches require operator investigation. Void an issued DocuSign envelope at the provider and reconcile it; the local UI does not pretend a remote envelope was canceled.

Provider completion has not been exercised on a real developer account. Unit tests validate payload/HMAC/endpoint restrictions; they do not prove API interoperability or regulatory validity. Keep enabled=false until the separate sandbox end-to-end test passes.

## Deployment settings and tests

Set upload_max_filesize=15M or higher and post_max_size=20M or higher for contract PDFs; secure private storage, HTTPS and `public/` document root. Run upgrade.php before the new screens; contracts.sql is repeatable. Re-run the isolated tests/run_windows.py and the independent tests/docusign_unit.php, then perform employer/employee/client Chrome acceptance. Never run synthetic fixtures on customer databases.

Official references:
- [Embedded recipient signing](https://www.docusign.com/blog/developers/deep-dive-the-embedded-signing-recipient-view)
- [Connect implementation](https://developers.docusign.com/platform/webhooks/connect/implement/)
- [Connect JSON/HMAC](https://www.docusign.com/blog/developers/event-notifications-using-json-sim-and-hmac)
- [Dispatch transaction reconciliation](https://www.docusign.com/blog/developers/common-api-tasks-use-transactionid-to-find-the-envelope-you-created)
