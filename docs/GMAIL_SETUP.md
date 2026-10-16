# Gmail in Crewvia — one-time setup

Each person connects their own mailbox from **Your Gmail inbox** (read and send, private to them).
Until the agency's Google OAuth client is configured on the server, the inbox says "Google setup
required" and Integrations shows Gmail **Off**. Nothing in Crewvia's screens can set it: the client
secret belongs in the server's `config.php`, never in the database or the repository.

## 1. In Google Cloud (with the agency's Google account)

1. https://console.cloud.google.com → create a project, e.g. "Crewvia RSS".
2. **APIs & Services → Library**: enable **Gmail API**.
3. **APIs & Services → OAuth consent screen**:
   - User type: **Internal** if everyone uses the agency's Google Workspace domain (e.g. `@rssinc.com`):
     no Google review needed. **External** if people use personal `@gmail.com` addresses: while the
     app is in "Testing", add each person under *Test users* (up to 100), and know that Google expires
     testing tokens after 7 days (they reconnect); going to "In production" with Gmail read access
     requires Google's verification.
   - App name "Crewvia", support email, authorized domain `bpms247.com`.
   - Scopes: `openid`, `email`, `profile`, `https://www.googleapis.com/auth/gmail.readonly`,
     `https://www.googleapis.com/auth/gmail.send` (Crewvia refuses a connection without both Gmail scopes).
4. **APIs & Services → Credentials → Create credentials → OAuth client ID**:
   - Application type: **Web application**, name "Crewvia".
   - Authorized redirect URI: `https://crewvia.bpms247.com/google-callback`
   - Copy the **Client ID** and **Client secret**.

## 2. On the server (by whoever holds the server access)

In `/home/veloraweb/public_html/crewvia/config.php`, add (or fill) these three entries inside the
returned array; `encryption_key` is already set (the encrypted mail queue uses it):

```php
'google_client_id'     => '<Client ID>',
'google_client_secret' => '<Client secret>',
'google_redirect_uri'  => 'https://crewvia.bpms247.com/google-callback',
```

No restart is needed (PHP reads the file on each request).

## 3. Check

- **Integrations** (administrator): Gmail is **On**, "0 connected mailboxes".
- Each person: **Your Gmail inbox → Connect my Gmail account**, accept read and send; the inbox lists their mail.
- Integrations then counts the connected mailboxes and any that need reconnecting.
