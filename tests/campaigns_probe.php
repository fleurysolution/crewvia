<?php
/**
 * The P5-M18 test's eyes and hands, on the test database only.
 *   read                     campaigns, recipients, opt-outs, events, notifications
 *   cap / uncap              fills, then clears, 500 sends in the last 24 hours
 *   close <vacancy>          closes a requisition
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$fx = json_decode((string) file_get_contents(__DIR__ . '/campaigns.json'), true);
switch ($argv[1] ?? 'read') {
    case 'cap':
        // Ten past campaigns of 50 each: a person appears once per campaign.
        for ($c = 0; $c < 10; $c++) {
            q("INSERT INTO email_campaigns(vacancy_id,subject,body,filters_json,status,recipients,created_by) VALUES (?,'cap','cap','{}','sent',50,?)", [(int) $fx['vacancy'], (int) $fx['ids']['admin']]);
            $id = (int) db()->lastInsertId();
            for ($i = 0; $i < 50; $i++) {
                q("INSERT INTO email_campaign_recipients(campaign_id,candidate_id,email,token,status,sent_at) VALUES (?,?,?,?,'sent',NOW())", [$id, (int) $fx['bulk'][$i], 'cap@test.invalid', bin2hex(random_bytes(32))]);
            }
        }
        exit(0);
    case 'uncap':
        q("DELETE r FROM email_campaign_recipients r JOIN email_campaigns m ON m.id = r.campaign_id WHERE m.subject = 'cap'");
        q("DELETE FROM email_campaigns WHERE subject = 'cap'");
        exit(0);
    case 'close':
        q('UPDATE vacancies SET is_open = 0 WHERE id = ?', [(int) $argv[2]]);
        exit(0);
}
$cands = implode(',', array_map('intval', array_merge(array_values($fx['cands']))));
echo json_encode([
    'campaigns' => rows("SELECT id, vacancy_id, status, recipients, reply_to, approved_by, language FROM email_campaigns WHERE subject <> 'cap' AND vacancy_id IN (?,?,?) ORDER BY id", [(int) $fx['vacancy'], (int) $fx['electric'], (int) $fx['gone']]),
    'recipients' => rows("SELECT r.campaign_id, r.candidate_id, r.email, r.token, r.status, r.error FROM email_campaign_recipients r JOIN email_campaigns m ON m.id = r.campaign_id WHERE m.subject <> 'cap' AND m.vacancy_id IN (?,?) ORDER BY r.id", [(int) $fx['vacancy'], (int) $fx['electric']]),
    'opted_out' => array_map('intval', array_column(rows("SELECT candidate_id FROM contact_preferences WHERE no_email_since IS NOT NULL AND candidate_id IN ($cands)"), 'candidate_id')),
    'events' => rows("SELECT candidate_id, event_type, detail FROM candidate_events WHERE candidate_id IN ($cands) AND event_type IN ('campaign email','contact_preference') ORDER BY id"),
    'notifications' => rows("SELECT user_id, message, target FROM notifications WHERE target LIKE '/campaigns%' ORDER BY id"),
]);
