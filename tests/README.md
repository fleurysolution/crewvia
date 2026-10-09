# Regression tests

Pure tests also run independently without MySQL/Playwright or a copied test-app:

```
php tests/saas_unit.php
php tests/recruiting_unit.php
php tests/security_unit.php
php tests/payroll_unit.php
php tests/connector_unit.php
php tests/docusign_unit.php
php tests/i18n_unit.php en
php tests/i18n_unit.php fr
php tests/i18n_unit.php es
```

The tenant unit test creates its own random temporary config directory and removes it on exit. It never writes tenants into the application. JSON evidence is written beside the test scripts.

Requirements: Python 3, PHP 8.2 (pdo_mysql, zip, openssl, curl, mbstring, DOM), MariaDB Windows binaries including mysql_install_db.exe, Node, Playwright and Chromium.

Set PHP_BIN, MYSQL_BIN_DIR, NODE_BIN, PLAYWRIGHT_MODULE (module path or package name), CHROMIUM_BIN, TEST_WORK_DIR (short writable path), TEST_OUTPUT_DIR (evidence destination). Defaults use C:/xampp for PHP/MySQL and Node from PATH. Run `python tests/run_windows.py` from a writable working directory.

The runner initializes a separate temporary MariaDB instance on port 3308, serves an isolated source copy on 8097 and refuses occupied ports. It does not copy production config.php or storage. Test encryption keys and synthetic user passwords are confined to the temporary copy. Source installer/customer data is not deployed by these tests; --blank is exercised.

Do not run fixture.php or HTTP scripts against an actual customer installation. Test fixtures mark synthetic employment evidence complete solely to exercise deployment gating; they are not legal evidence.

Evidence includes test-results.json, browser-results.json, runtime-services.log and screenshots. This is a functional regression suite, not concurrent load testing, penetration testing or certification of payroll/legal compliance.

Contracts: HTTP tests cover required proof review, revoked approvals, draft privacy, expiry, current contract deployment gate, original encrypted PDF, content-hash/consent, repeated-signature prevention, uploaded signature review, archive isolation, revisions, disabled DocuSign and FR/ES. Provider unit tests inspect payloads and endpoint restrictions; no real DocuSign account or envelope is used.
