<?php
/**
 * P5-M100: the billing ledger's daily job. Writes the records not yet in the ledger, then seals every finished month
 * that is not sealed yet - on the 1st, the month before. Meant for cron, once a day:
 *
 *   5 0 * * * php /path/to/crewvia/install/billing-close.php
 *
 * Running it twice writes nothing twice and seals nothing twice.
 *
 * P5-M108: it reports its own outcome through Crewvia's mailer (DKIM-signed, the path proven to reach Gmail), not
 * through cron's MAILTO. Report address: the server config's 'billing_report_to' - Fleury Solutions', never RSS's.
 *   - a month sealed: what was sealed (people, records, fingerprint, time);
 *   - a failure: what failed and the state each month is in (sealed, not sealed);
 *   - on the 1st, always, even with nothing to seal - a close that did not run must not look like one that found
 *     nothing; the other days stay silent unless something was sealed or failed.
 * The report is sent at once, not left to the 5-minute delivery job.
 *
 *   php install/billing-close.php --arm-failure-test   the NEXT run fails on purpose before sealing anything, reports
 *                                                     the failure, and disarms itself (to prove the report arrives)
 *   php install/billing-close.php --report             report even on an ordinary day
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/billing-ledger.php';
require_once __DIR__ . '/../app/notices.php';

if (in_array('--arm-failure-test', $argv, true)) {
    q("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('billing_close_fail_once', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");
    echo 'Armed: the next run of billing-close fails on purpose before sealing anything, reports it, and disarms itself.' . PHP_EOL;
    exit(0);
}

$opsStarted = microtime(true);
$due = [];
$written = 0;
$sealed = [];
$failure = null;
$check = ['ok' => false, 'checked' => 0, 'broken_at' => null];
try {
    $written = bl_sync();
    // The months waiting to be sealed, for the report whatever happens next.
    $due = rows('SELECT DISTINCT period_month FROM billing_events e WHERE e.period_month < ? AND NOT EXISTS (SELECT 1 FROM billing_closings c WHERE c.period_month = e.period_month) ORDER BY period_month', [date('Y-m')]);
    if (val("SELECT setting_value FROM platform_settings WHERE setting_key = 'billing_close_fail_once'") === '1') {
        q("DELETE FROM platform_settings WHERE setting_key = 'billing_close_fail_once'");
        throw new RuntimeException('deliberate failure test (armed with --arm-failure-test); nothing was sealed');
    }
    $sealed = bl_close_due();
    $check = bl_verify();
} catch (Throwable $e) {
    $failure = $e->getMessage();
}

$ok = $failure === null && $check['ok'];
ops_record('billing_close', $ok ? 'ok' : 'failed', ['written' => $written, 'sealed' => $sealed, 'chain_ok' => $check['ok'], 'failure' => $failure], $opsStarted);
$line = 'Billing: ' . $written . ' record(s) written; sealed: ' . ($sealed ? implode(', ', $sealed) : 'none') . '; chain '
      . ($check['ok'] ? 'intact (' . $check['checked'] . ' records)' : ($failure !== null ? 'not checked' : 'BROKEN at record ' . $check['broken_at']))
      . ($failure !== null ? '; FAILED: ' . $failure : '');
echo $line . PHP_EOL;

// The report: on a seal, on a failure, on the 1st always, or on request.
if ($sealed || ! $ok || date('j') === '1' || in_array('--report', $argv, true)) {
    $to = (string) ($config['billing_report_to'] ?? '');
    if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $body = [];
        $body[] = 'Crewvia billing close on ' . ($config['app_url'] ?? '') . ' - ' . date('Y-m-d H:i:s T');
        $body[] = '';
        $body[] = $ok ? 'Result: OK' : 'Result: FAILED' . ($failure !== null ? ' - ' . $failure : ' - the ledger chain is broken at record ' . $check['broken_at']);
        $body[] = '';
        foreach ($sealed as $m) {
            $c = bl_closing($m);
            $body[] = 'Sealed ' . $m . ': ' . (int) $c['billable_people'] . ' billable people, ' . (int) $c['event_count'] . ' records, amount '
                    . number_format(bl_amount((int) $c['billable_people']), 2) . ' USD, fingerprint ' . $c['ledger_hash'] . ', sealed at ' . $c['sealed_at'] . ' UTC.';
        }
        if (! $sealed) {
            $body[] = 'Nothing sealed in this run.';
        }
        // The state of every month that was due, whatever happened.
        foreach ($due as $d) {
            $m = (string) $d['period_month'];
            $body[] = 'State of ' . $m . ': ' . (bl_closing($m) ? 'sealed' : 'NOT sealed');
        }
        $last = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        if (! in_array($last, array_column($due, 'period_month'), true)) {
            $body[] = 'State of ' . $last . ': ' . (bl_closing($last) ? 'sealed' : 'nothing to seal (no paid person recorded that month)');
        }
        $body[] = 'Records written in this run: ' . $written . '. Chain: ' . ($check['ok'] ? 'intact, ' . $check['checked'] . ' records' : 'not confirmed') . '.';
        $now = bl_month(date('Y-m'));
        $body[] = 'This month so far (' . date('Y-m') . '): ' . $now['people'] . ' people, ' . $now['events'] . ' records.';
        mail_queue(null, $to, ($ok ? 'Crewvia billing close: OK' : 'Crewvia billing close: FAILED') . ' - ' . ($sealed ? implode(', ', $sealed) . ' sealed' : date('Y-m-d')), implode(PHP_EOL, $body), 'ops:billing-close');
        $sent = mail_deliver(5, 'ops:billing-close');   // at once, ahead of the queue
        echo 'Report to ' . $to . ': ' . ($sent['held'] !== null ? 'held - ' . $sent['held'] : $sent['sent'] . ' sent, ' . $sent['failed'] . ' failed') . PHP_EOL;
    } else {
        echo 'No report sent: set billing_report_to in the server config.' . PHP_EOL;
    }
}
exit($ok ? 0 : 1);
