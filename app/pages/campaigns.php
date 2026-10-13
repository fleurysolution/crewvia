<?php
/**
 * Campaign email (T7): draft one email about one requisition to a filtered
 * list, review who it goes to, test it, send it. See app/campaigns.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../campaigns.php';

require_role('recruiter');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $back = '/campaigns';
    if ($do === 'create') {
        [$why, $id] = cm_create((int) ($_POST['vacancy_id'] ?? 0), $_POST, uid());
        $back = $id ? '/campaigns?c=' . $id : '/campaigns';
    } elseif ($do === 'address') {
        if (! can('admin')) {
            refuse(403, t('An administrator enters the postal address.'), '/campaigns');
        }
        $address = trim(str_replace("\r\n", "\n", (string) ($_POST['address'] ?? '')));
        $why = mb_strlen($address) < 10 || mb_strlen($address) > 300 ? t('Enter the full postal address, 10 to 300 characters.') : null;
        if (! $why) {
            q("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('campaign_postal_address', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$address]);
            log_activity('set the postal address for campaign email', 'setting', 0, 'campaign_postal_address');
        }
    } else {
        $campaign = cm_campaign((int) ($_POST['campaign_id'] ?? 0));
        if (! $campaign) {
            refuse(404, t('That campaign does not exist.'), '/campaigns');
        }
        $back = '/campaigns?c=' . (int) $campaign['id'];
        $why = match ($do) {
            'send'    => cm_send($campaign, uid()),
            'approve' => can('admin') ? cm_approve($campaign, uid()) : t('An administrator approves campaigns to more than :n people.', ['n' => CAMPAIGN_DIRECT_LIMIT]),
            'cancel'  => cm_cancel($campaign),
            'test'    => cm_test($campaign, uid()),
            default   => t('Unknown action.'),
        };
    }
    if ($why !== null) {
        refuse(422, $why, $back);
    }
    flash(match ($do) {
        'create'  => t('Draft ready. Check who it goes to and the sample, send yourself a test, then send it.'),
        'send'    => t('On its way. It goes out :n at a time, every few minutes; an administrator approves it first when it is for more than :limit people.', ['n' => CAMPAIGN_PER_RUN, 'limit' => CAMPAIGN_DIRECT_LIMIT]),
        'test'    => t('Test sent to your own email.'),
        default   => t('Saved.'),
    });
    redirect($back);
}

$cmCampaign = isset($_GET['c']) ? cm_campaign((int) $_GET['c']) : null;
if (isset($_GET['c']) && ! $cmCampaign) {
    refuse(404, t('That campaign does not exist.'), '/campaigns');
}
$cmVacancies = cm_open_vacancies();
$cmNotReady = cm_not_ready();
$cmAddress = cm_postal_address();
$cmPreview = null;
$cmForm = [];
$cmSample = null;
$cmCounts = null;
$cmRecipients = [];
if ($cmCampaign) {
    $cmCounts = cm_counts((int) $cmCampaign['id']);
    $cmRecipients = rows('SELECT r.status, r.sent_at, r.error, c.id AS candidate_id, c.full_name, r.email FROM email_campaign_recipients r JOIN candidates c ON c.id = r.candidate_id
                          WHERE r.campaign_id = ? ORDER BY r.id LIMIT 200', [(int) $cmCampaign['id']]);
    $first = row('SELECT r.token, c.full_name, w.user_id FROM email_campaign_recipients r JOIN candidates c ON c.id = r.candidate_id LEFT JOIN worker_accounts w ON w.candidate_id = c.id
                  WHERE r.campaign_id = ? ORDER BY r.id LIMIT 1', [(int) $cmCampaign['id']]);
    $vacancy = cm_vacancy((int) $cmCampaign['vacancy_id']);
    if ($first && $vacancy) {
        $cmSample = cm_render($cmCampaign, $vacancy, $first, str_repeat('0', 64));
    }
} else {
    $language = ($_GET['language'] ?? 'en') === 'es' ? 'es' : 'en';
    $cmForm = ['vacancy_id' => (int) ($_GET['vacancy_id'] ?? 0), 'language' => $language] + cm_default($language) + cm_filters($_GET);
    if (isset($_GET['subject'])) { $cmForm['subject'] = (string) $_GET['subject']; }
    if (isset($_GET['body'])) { $cmForm['body'] = (string) $_GET['body']; }
    if (isset($_GET['preview']) && ($vacancy = cm_vacancy($cmForm['vacancy_id']))) {
        $people = cm_audience($vacancy, cm_filters($_GET));
        $cmPreview = ['count' => count($people), 'people' => array_slice($people, 0, 25), 'site' => geo_site($vacancy) !== null];
    }
}
$cmList = rows('SELECT m.id, m.status, m.subject, m.recipients, m.created_at, v.title AS vacancy_title, u.name AS created_by_name,
                       (SELECT COUNT(*) FROM email_campaign_recipients r WHERE r.campaign_id = m.id AND r.status = \'sent\') AS sent
                FROM email_campaigns m JOIN vacancies v ON v.id = m.vacancy_id JOIN users u ON u.id = m.created_by ORDER BY m.id DESC LIMIT 100');
$pageTitle = t('Campaign email') . ' · ' . $config['app_name'];
render('campaigns', compact('cmCampaign', 'cmVacancies', 'cmNotReady', 'cmAddress', 'cmPreview', 'cmForm', 'cmSample', 'cmCounts', 'cmRecipients', 'cmList'));
