<?php
/**
 * The P5-M29 test's eyes and hands, on the test database only.
 *   campaign <id>      the campaign, its recipients, and the last text each one got
 *   last               the newest campaign's id
 *   stop <candidate>   the person replies STOP
 *   twilio <mode>      Twilio's mode: disabled or sandbox
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
[$cmd, $arg] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($cmd === 'campaign') {
    $m = row('SELECT id, channel, status, subject, recipients FROM email_campaigns WHERE id = ?', [(int) $arg]);
    $m['people'] = [];
    foreach (rows('SELECT candidate_id, email, phone, status, error FROM email_campaign_recipients WHERE campaign_id = ? ORDER BY id', [(int) $arg]) as $r) {
        $r['sms'] = row("SELECT mode, status, phone, body FROM sms_messages WHERE candidate_id = ? AND direction = 'out' ORDER BY id DESC LIMIT 1", [(int) $r['candidate_id']]) ?: null;
        $m['people'][(int) $r['candidate_id']] = $r;
    }
    echo json_encode($m);
} elseif ($cmd === 'last') {
    echo json_encode((int) val('SELECT MAX(id) FROM email_campaigns'));
} elseif ($cmd === 'stop') {
    q('INSERT INTO contact_preferences (candidate_id, no_texts_since, reason) VALUES (?, NOW(), ?) ON DUPLICATE KEY UPDATE no_texts_since = NOW()', [(int) $arg, 'Replied STOP by text (Twilio)']);
    echo json_encode(true);
} elseif ($cmd === 'twilio' && in_array($arg, ['disabled', 'sandbox'], true)) {
    q("UPDATE connector_modes SET mode = ? WHERE connector = 'twilio'", [$arg]);
    echo json_encode(true);
}
