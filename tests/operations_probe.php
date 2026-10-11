<?php
/** The P4-M07 test's eyes on the test database: the record of runs. */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

echo json_encode(['runs' => rows('SELECT kind, status, seconds, detail FROM ops_runs ORDER BY id DESC LIMIT 30')]);
