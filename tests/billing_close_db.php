<?php
/**
 * P5-M108 on the test database: billing-close reports its own outcome through Crewvia's mailer, success and failure.
 *  - armed, the next run fails on purpose before sealing anything, mails "FAILED" with the state of each month, and
 *    disarms itself;
 *  - the next run seals what is due and mails what it sealed (people, records, fingerprint, time);
 *  - without a report address, nothing is mailed and the run says so.
 * Runs after billing_ledger_db.php (February 2024 sealed there).
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
$check = static function (string $label, bool $ok, $detail = ''): void {
    if (! $ok) { fwrite(STDERR, "FAIL $label " . json_encode($detail) . "\n"); exit(1); }
    echo "PASS $label\n";
};
$capture = __DIR__ . '/billing-close-mail.jsonl';
@unlink($capture);
q("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('mail_capture_path', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$capture]);
$job = __DIR__ . '/test-app/install/billing-close.php';
$run = static function (string ...$args) use ($job): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($job) . ($args ? ' ' . implode(' ', array_map('escapeshellarg', $args)) : '') . ' 2>&1';
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out)];
};
$mails = static function () use ($capture): array {
    return is_file($capture) ? array_map(static fn($l) => json_decode($l, true), file($capture, FILE_IGNORE_NEW_LINES)) : [];
};

// A month due: a validated day in March 2024 (not sealed yet).
$plant = (int) val("SELECT id FROM jobs WHERE title = 'Billing plant A'");
q("INSERT INTO candidates (full_name, email, stage) VALUES ('Close Report Person', 'close-report@test.invalid', 'placed')");
$cid = (int) db()->lastInsertId();
q("INSERT INTO placements (candidate_id, job_id, status, start_date, pay_rate, bill_rate) VALUES (?, ?, 'on_site', '2024-03-01', 30, 45)", [$cid, $plant]);
q("INSERT INTO clock_ins (placement_id, work_date, clocked_in_at, validate_by, status) VALUES (?, '2024-03-04', '2024-03-04 07:00:00', '2024-03-04 12:00:00', 'validated')", [(int) db()->lastInsertId()]);

[$code, $out] = $run('--arm-failure-test');
$check('Armed for a failure test', $code === 0 && str_contains($out, 'Armed'), $out);
[$code, $out] = $run();
$m = $mails();
$check('Armed, the run fails on purpose, seals nothing, and exits 1', $code === 1 && str_contains($out, 'FAILED') && ! val("SELECT id FROM billing_closings WHERE period_month = '2024-03'"), $out);
$check('and mails FAILED with the state of the month', count($m) === 1 && str_contains($m[0]['subject'], 'FAILED') && str_contains($m[0]['body'], 'deliberate failure test')
    && str_contains($m[0]['body'], 'State of 2024-03: NOT sealed') && $m[0]['to'] === 'solutionsfleury+billing@gmail.com', $m);
$check('The arm is spent: it does not fail twice', val("SELECT setting_value FROM platform_settings WHERE setting_key = 'billing_close_fail_once'") === null);

[$code, $out] = $run();
$m = $mails();
$c = row("SELECT * FROM billing_closings WHERE period_month = '2024-03'");
$check('The next run seals the month and exits 0', $code === 0 && $c !== null, $out);
$check('and mails OK with what it sealed: people, records, fingerprint, time', count($m) === 2 && str_contains($m[1]['subject'], 'OK') && str_contains($m[1]['body'], 'Sealed 2024-03: 1 billable people')
    && str_contains($m[1]['body'], (string) $c['ledger_hash']) && str_contains($m[1]['body'], 'State of 2024-03: sealed'), $m[1] ?? null);

[$code, $out] = $run();
$check('An ordinary day with nothing to seal stays silent (the 1st always reports)', $code === 0 && count($mails()) === (date('j') === '1' ? 3 : 2), $out);
[$code, $out] = $run('--report');
$all = $mails();
$check('--report mails even on an ordinary day', count($all) === (date('j') === '1' ? 4 : 3) && str_contains(end($all)['body'], 'Nothing sealed in this run'));
// The report address is delivered as addressed, by rule, even during a rehearsal and whatever it is.
require_once __DIR__ . '/test-app/app/mail-routing.php';
foreach (['candidate_mail_test' => '1', 'office_mail_test' => '1'] as $k => $v) { q('INSERT INTO platform_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$k, $v]); }
$GLOBALS['config']['billing_report_to'] = 'accounts@fleury.invalid';
$route = mail_route('accounts@fleury.invalid');
$other = mail_route('someone-else@fleury.invalid');
$check('The report address is never rewritten by test mode or a rehearsal, whatever its spelling', ! $route['redirected'] && $route['to'] === 'accounts@fleury.invalid' && $other['redirected'], [$route, $other]);
q("DELETE FROM platform_settings WHERE setting_key IN ('office_mail_test')");
q("DELETE FROM platform_settings WHERE setting_key = 'mail_capture_path'");
@unlink($capture);
