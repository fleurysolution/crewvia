<?php
/**
 * Test runner only, on the isolated test database: forget the per-address
 * login count for 127.0.0.1. Every suite signs in from the same address,
 * and together they pass the limit of 100 sign-ins in 15 minutes that
 * protects the real login. The per-email limit is left alone.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

q('DELETE FROM auth_rate_limits WHERE bucket IN (?, ?)', [hash('sha256', 'login-ip|127.0.0.1'), hash('sha256', 'login-ip|::1')]);
echo 'PASS Login count for 127.0.0.1 reset between suites' . PHP_EOL;
