<?php
/**
 * P5-M58, test database only: a Hotels & Travel and a Payroll & Billing account for menu_crawl_http.py,
 * the walkthrough fixture having none.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: this test only runs against rss_ops_test.\n"); exit(2); }
$hash = password_hash('TestPassword123!', PASSWORD_DEFAULT);
// P5-M59: and a project manager on the Supervisor desk, who carries no crew.
foreach (['hotels' => 'mc-hotels@test.invalid', 'payroll' => 'mc-payroll@test.invalid', 'supervisor' => 'mc-pm@test.invalid'] as $role => $email) {
    if (! val('SELECT id FROM users WHERE email = ?', [$email])) {
        q('INSERT INTO users (name, email, password_hash, role, must_change_pw) VALUES (?,?,?,?,0)', ['Menu crawl ' . $role, $email, $hash, $role]);
    }
}
echo "menu crawl accounts ready\n";
