<?php
/** The P4-M01 test's eyes, on the test database only: what one user's report reads left in the activity log. */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

echo json_encode(rows("SELECT action, detail FROM activity WHERE user_id = ? AND entity = 'report' ORDER BY id", [(int) ($argv[1] ?? 0)]));
