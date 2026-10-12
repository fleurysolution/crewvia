<?php
/**
 * Recognition (P5-M05): thanks between colleagues, awards and their
 * nominations, points and rewards. Everyone signed in except clients takes
 * part; recruiters and administrators run awards and rewards. See
 * app/engagement.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../engagement.php';

if (! eng_person(uid())) {
    refuse(403, t('Not your desk'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $managerOnly = ['settings_save', 'program_save', 'award', 'reward_save', 'decide', 'fulfil', 'grant'];
    if (in_array($do, $managerOnly, true) && ! eng_manager()) {
        refuse(403, t('Recruiters and administrators run awards and rewards.'));
    }
    $back = '/recognition';
    $why = null;
    switch ($do) {
        case 'kudos':
            [$why] = eng_kudos_give(uid(), (int) ($_POST['to_user_id'] ?? 0), (string) ($_POST['message'] ?? ''), ! empty($_POST['is_public']));
            if ($why === null) { flash(t('Thanks sent.')); }
            break;
        case 'nominate':
            [$why] = eng_nominate((int) ($_POST['program_id'] ?? 0), (int) ($_POST['nominee_user_id'] ?? 0), (string) ($_POST['reason'] ?? ''), uid());
            $back = '/recognition#awards';
            if ($why === null) { flash(t('Nominated.')); }
            break;
        case 'withdraw':
            $why = eng_nomination_withdraw((int) ($_POST['nomination_id'] ?? 0), uid());
            $back = '/recognition#awards';
            break;
        case 'redeem':
            [$why] = eng_redeem(uid(), (int) ($_POST['reward_id'] ?? 0), (string) ($_POST['note'] ?? ''));
            $back = '/recognition#rewards';
            if ($why === null) { flash(t('Asked for. The points are held until it is decided.')); }
            break;
        case 'cancel':
            $why = eng_redemption_cancel((int) ($_POST['redemption_id'] ?? 0), uid());
            $back = '/recognition#rewards';
            if ($why === null) { flash(t('Cancelled, and your points given back.')); }
            break;
        case 'settings_save':
            $why = eng_settings_save($_POST);
            $back = '/recognition#manage';
            break;
        case 'program_save':
            [$why, $pid] = eng_program_save($_POST, uid(), (int) ($_POST['program_id'] ?? 0));
            $back = '/recognition#manage';
            break;
        case 'award':
            $why = eng_award((int) ($_POST['nomination_id'] ?? 0), uid());
            $back = '/recognition#manage';
            if ($why === null) { flash(t('Awarded.')); }
            break;
        case 'reward_save':
            [$why] = eng_reward_save($_POST, uid(), (int) ($_POST['reward_id'] ?? 0));
            $back = '/recognition#manage';
            break;
        case 'decide':
            $why = eng_redemption_decide((int) ($_POST['redemption_id'] ?? 0), (string) ($_POST['decision'] ?? ''), (string) ($_POST['note'] ?? ''), uid());
            $back = '/recognition#manage';
            break;
        case 'fulfil':
            $why = eng_redemption_fulfil((int) ($_POST['redemption_id'] ?? 0), (string) ($_POST['note'] ?? ''), uid());
            $back = '/recognition#manage';
            break;
        case 'grant':
            $why = eng_points_grant((int) ($_POST['user_id'] ?? 0), trim((string) ($_POST['points'] ?? '')), (string) ($_POST['reason'] ?? ''), uid());
            $back = '/recognition#manage';
            break;
        default:
            refuse(400, t('Unknown action.'));
    }
    if ($why !== null) { refuse(422, $why); }
    if (! isset($_SESSION['flash'])) { flash(t('Saved.')); }
    redirect($back);
}

$me = uid();
$colleagues = eng_colleagues($me);
$wall = eng_wall($me);
$programs = rows('SELECT * FROM award_programs WHERE is_active = 1 ORDER BY name');
foreach ($programs as &$pr) {
    $pr['may_nominate'] = eng_may_nominate($pr, $me);
    $pr['period_key'] = eng_period_key((string) $pr['period']);
    $pr['nominees'] = array_filter($colleagues, fn($name, $id) => eng_eligible((string) $pr['eligible'], (int) $id), ARRAY_FILTER_USE_BOTH);
}
unset($pr);
$myNominations = rows('SELECT n.*, p.name AS program, u.name FROM award_nominations n JOIN award_programs p ON p.id = n.program_id JOIN users u ON u.id = n.nominee_user_id
                       WHERE n.nominated_by = ? ORDER BY n.id DESC LIMIT 50', [$me]);
$winners = eng_winners();
$balance = eng_balance($me);
$ledger = rows('SELECT * FROM reward_points WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$me]);
$rewards = eng_rewards_for($me);
$myRedemptions = rows('SELECT r.*, c.name AS reward FROM reward_redemptions r JOIN reward_catalog c ON c.id = r.reward_id WHERE r.user_id = ? ORDER BY r.id DESC LIMIT 50', [$me]);

$manage = [];
if (eng_manager()) {
    $manage = [
        'settings'    => ['kudos_points' => eng_setting('kudos_points', 0), 'kudos_daily_limit' => eng_setting('kudos_daily_limit', ENG_DEFAULT_DAILY_KUDOS)],
        'programs'    => rows('SELECT p.*, (SELECT COUNT(*) FROM award_nominations n WHERE n.program_id = p.id AND n.status = \'nominated\') AS waiting FROM award_programs p ORDER BY p.is_active DESC, p.name'),
        'nominations' => rows("SELECT n.*, p.name AS program, u.name AS nominee, b.name AS by_name FROM award_nominations n JOIN award_programs p ON p.id = n.program_id
                               JOIN users u ON u.id = n.nominee_user_id JOIN users b ON b.id = n.nominated_by WHERE n.status = 'nominated' ORDER BY p.name, n.period_key, u.name"),
        'catalog'     => rows('SELECT * FROM reward_catalog ORDER BY is_active DESC, points_cost, name'),
        'queue'       => rows("SELECT r.*, c.name AS reward, u.name FROM reward_redemptions r JOIN reward_catalog c ON c.id = r.reward_id JOIN users u ON u.id = r.user_id
                               WHERE r.status IN ('requested','approved') ORDER BY r.status = 'requested' DESC, r.id"),
        'people'      => rows("SELECT id, name, role FROM users WHERE is_active = 1 AND role <> 'client' ORDER BY name"),
        'leave_types' => rows('SELECT slug, label FROM leave_types WHERE is_active = 1 AND days_allowed IS NOT NULL ORDER BY sort_order'),
    ];
    if (isset($_GET['program'])) {
        $manage['program'] = row('SELECT * FROM award_programs WHERE id = ?', [(int) $_GET['program']]);
    }
    if (isset($_GET['reward'])) {
        $manage['reward'] = row('SELECT * FROM reward_catalog WHERE id = ?', [(int) $_GET['reward']]);
    }
}

render('recognition', compact('colleagues', 'wall', 'programs', 'myNominations', 'winners', 'balance', 'ledger', 'rewards', 'myRedemptions', 'manage'));
