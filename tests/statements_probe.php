<?php
/** The P3-M05 statements test's eyes, on the test database only: budgets and their history. */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

echo json_encode([
    'budgets' => rows("SELECT account_key, period, amount + 0 AS amount FROM gl_budgets WHERE period LIKE '2019-%' ORDER BY account_key, period"),
    'events'  => rows("SELECT account_key, period, old_amount + 0 AS old_amount, new_amount + 0 AS new_amount, note FROM gl_budget_events WHERE period LIKE '2019-%' ORDER BY id"),
], JSON_NUMERIC_CHECK);
