# Deferred security clean-up

Decided by the owner on 9 October 2026: these are known and accepted while
the HR feature set is built. All of them are dealt with in one pass at the
end of the project, before go-live. Nothing new is added to this list by
creating a new exposure; the list only records what already exists.

| # | Item | Clean-up at the end |
|---|---|---|
| X1 | `config.php` with the production database password and encryption key is tracked in the repository and in its history (since `3ce5942`). | Change the database password; `git rm --cached config.php`; purge it from history; rotate the encryption key with a re-encryption script (not yet written) so stored bank details, contracts and résumés stay readable. |
| X2 | The repository was public for a period while X1 was in it. | Treat every value in X1 as known to others. |
| X3 | The server's git remote URL embeds a GitHub personal access token, stored in `.git/config` on the VPS and pasted into a working session. | Revoke the token on GitHub; `git remote set-url origin` to a URL without it; use a read-only deploy key if the repository stays private. |
| X4 | Deployment is a manual `git pull` in the live folder (cPanel Git™ Version Control points at it). A pull would also write `config.php` from the repository. | Decide the deploy route (cPanel pull, cron, or CI over SSH); keep server-only files out of the repository first. |
| X5 | Real first names appear in code comments (`app/pages/dashboard.php`, `app/pages/activity.php`, `app/pages/hours.php`). | Replace with the role. |
| X6 | Login throttle (BACKLOG Q1): 10 attempts per 15 minutes per email, counting successful sign-ins. | Owner decision: raise it, or add an administrator unlock. |
| X7 | Playwright is not installed on the build machine, so the browser step of the test runner is skipped (`SKIP_BROWSER=1`). | Install it and run the full suite once before go-live. |
