<?php
/**
 * P5-M29 at the database level, on the isolated test database only:
 * rollback (email campaigns kept, text campaigns and the new columns gone)
 * and upgrade, silence on a repeat; then the fixture for
 * campaign_texts_http.py, on the walkthrough fixture: Twilio in sandbox
 * with test credentials, and five mechanical people in Cheyenne, WY: three
 * who can be texted, one who replied STOP, one with no phone.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/campaigns.php';
require_once __DIR__ . '/test-app/app/twilio.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: this test only runs against rss_ops_test.\n"); exit(2); }

$results = [];
function check(string $label, bool $condition, string $detail = ''): void
{
    global $results;
    if (! $condition) { fwrite(STDERR, 'FAIL ' . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL); exit(1); }
    $results[] = $label;
    echo 'PASS ' . $label . PHP_EOL;
}
function run_php(string $script): array
{
    $out = []; $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}
$columns = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = 'email_campaigns' AND column_name = 'channel') OR (table_name = 'email_campaign_recipients' AND column_name = 'phone'))");
$app = __DIR__ . '/test-app';
$wk = json_decode((string) file_get_contents(__DIR__ . '/walkthrough.json'), true);

check('P5-M29 is in place before the rollback', $columns() === 2);
$emailCampaigns = (int) val("SELECT COUNT(*) FROM email_campaigns WHERE channel = 'email'");
q("INSERT INTO email_campaigns (vacancy_id, channel, subject, body, filters_json, recipients, created_by) VALUES (?, 'text', 'Rollback text', 'A text that the rollback removes.', '{}', 0, ?)", [$wk['vA'], $wk['ids']['rec']]);
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m29.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes text campaigns and the new columns, and keeps every email campaign', $columns() === 0 && (int) val('SELECT COUNT(*) FROM email_campaigns') === $emailCampaigns);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade announces campaign texts and that Twilio must be set up', $code === 0 && str_contains($first, 'Campaign text: a campaign can go by text message through Twilio') && $columns() === 2, $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M29', $code === 0 && ! str_contains($second, 'Campaign text:'), $second);
check('Existing email campaigns stay email', (int) val("SELECT COUNT(*) FROM email_campaigns WHERE channel = 'email'") === $emailCampaigns);

// Twilio in sandbox, with test credentials (nothing ever reaches Twilio in sandbox).
// An earlier suite may have left Twilio switched off; saving credentials keeps that, so sandbox is set here.
$saved = tw_credentials_save(['account_sid' => 'AC' . str_repeat('0', 32), 'auth_token' => str_repeat('0', 32), 'from_number' => '+15550001111'], (int) $wk['ids']['admin']);
q("INSERT INTO connector_modes (connector, kind, mode) VALUES ('twilio', 'messaging', 'sandbox') ON DUPLICATE KEY UPDATE mode = 'sandbox'");
check('Twilio test credentials are accepted, in sandbox', $saved === null && tw_mode() === 'sandbox', (string) $saved);

$vacancy = cm_vacancy((int) $wk['vA']);
$filters = cm_filters(['discipline' => 'mechanical', 'state' => 'WY']);
check('Nobody in the test data matches the filters yet', $vacancy !== null && cm_audience($vacancy, $filters, 'text') === [] && cm_audience($vacancy, $filters) === []);
$people = [];
foreach (['t1' => '(307) 555-0101', 't2' => '307-555-0102', 't3' => '+1 307 555 0103', 'stop' => '(307) 555-0104', 'nophone' => null] as $k => $phone) {
    q("INSERT INTO candidates (full_name, email, phone, discipline, city, state, stage) VALUES (?,?,?,'mechanical','Cheyenne','WY','new')", ['Aaa Text ' . $k, 'ct-' . $k . '@test.invalid', $phone]);
    $people[$k] = (int) db()->lastInsertId();
}
q('INSERT INTO contact_preferences (candidate_id, no_texts_since, reason) VALUES (?, NOW(), ?)', [$people['stop'], 'Replied STOP by text (Twilio)']);
check('The fixture: three people to text, five to email', count(cm_audience($vacancy, $filters, 'text')) === 3 && count(cm_audience($vacancy, $filters)) === 5);
file_put_contents(__DIR__ . '/campaign_texts.json', json_encode(['people' => $people]));
file_put_contents(__DIR__ . '/ct-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
