<?php
/**
 * Settings: everything that governs the workspace, in one place.
 *
 * This screen used to be a single form plus a permission control that granted
 * one role one desk per submission, and printed the result as a list of
 * "recruiter -> hotels" lines. An administrator could not see the shape of who
 * could reach what, which on a system holding payroll and background checks is
 * the first thing they need to see.
 *
 * Permissions are now a grid saved in one go, each desk named with the screens
 * it actually opens, taken from the menu itself so the description cannot
 * drift away from the grant.
 */

require_role('admin');
require_once __DIR__ . '/../navigation.php';

/** Staff desks. Workers, supervisors and clients are not desks and never appear here. */
const SETTINGS_DESKS = ['recruiter', 'hotels', 'payroll'];

/** Text settings this screen owns, each one read somewhere in the product. */
const SETTINGS_TEXT = [
    'brand_name'        => 190,
    'legal_contact'     => 190,
    'adp_company_code'  => 40,
    'adp_hours_code'    => 40,
    'adp_perdiem_code'  => 40,
    'adp_overtime_code' => 40,
];

function settings_put(string $key, string $value): void
{
    q('INSERT INTO platform_settings(setting_key, setting_value) VALUES (?,?)
       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
      [$key, $value]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');

    // ── the permission grid, saved as a whole ────────────────────────────
    //
    // One submission carries the complete intended state, so a box nobody
    // ticked means revoked. Saving a pair at a time made a revocation
    // indistinguishable from a form that simply never mentioned the pair.
    if ($do === 'permissions') {
        $wanted = (array) ($_POST['grant'] ?? []);

        db()->beginTransaction();
        q('DELETE FROM role_permissions');

        foreach (SETTINGS_DESKS as $role) {
            foreach (SETTINGS_DESKS as $desk) {
                if ($role === $desk) {
                    continue;   // a desk already holds itself
                }

                if (! empty($wanted[$role][$desk])) {
                    q('INSERT IGNORE INTO role_permissions(role_name, desk) VALUES (?,?)',
                      [$role, $desk]);
                }
            }
        }

        db()->commit();
        log_activity('changed role permissions');
        flash(t('Permissions saved.'));
        redirect('/settings#permissions');
    }

    // ── identity, payroll codes and the commercial basis ─────────────────
    // ── what people can actually do ─────────────────────────────────
    if ($do === 'add_skill') {
        $label = trim((string) ($_POST['label'] ?? ''));

        if ($label === '' || mb_strlen($label) > 90) {
            flash(t('A skill needs a name.'), 'err');
            redirect('/settings#skills');
        }

        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $label), '_'));

        if ($slug === '' || mb_strlen($slug) > 40) {
            flash(t('That name cannot be turned into a usable key. Use letters and numbers.'), 'err');
            redirect('/settings#skills');
        }

        if (val('SELECT COUNT(*) FROM skills WHERE slug = ?', [$slug])) {
            q('UPDATE skills SET is_active = 1, label = ? WHERE slug = ?', [$label, $slug]);
            flash(t(':skill is back on the list.', ['skill' => $label]));
            redirect('/settings#skills');
        }

        $next = (int) val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM skills');
        q('INSERT INTO skills (slug, label, sort_order) VALUES (?,?,?)', [$slug, $label, $next]);

        log_activity('added a skill', 'skill', 0, $label);
        flash(t(':skill added. Recruiters can now search for it.', ['skill' => $label]));
        redirect('/settings#skills');
    }

    if ($do === 'retire_skill' || $do === 'restore_skill') {
        $slug  = (string) ($_POST['slug'] ?? '');
        $skill = row('SELECT * FROM skills WHERE slug = ?', [$slug]);

        if (! $skill) {
            flash(t('That skill is not on the list.'), 'err');
            redirect('/settings#skills');
        }

        $retire = $do === 'retire_skill';
        q('UPDATE skills SET is_active = ? WHERE slug = ?', [$retire ? 0 : 1, $slug]);

        log_activity($retire ? 'retired a skill' : 'restored a skill',
                     'skill', 0, (string) $skill['label']);

        flash($retire
            ? t(':skill is retired. People already recorded with it keep it.',
                ['skill' => $skill['label']])
            : t(':skill is back on the list.', ['skill' => $skill['label']]));

        redirect('/settings#skills');
    }

    // ── the trades this agency staffs ───────────────────────────────
    if ($do === 'add_trade') {
        $label = trim((string) ($_POST['label'] ?? ''));

        if ($label === '' || mb_strlen($label) > 90) {
            flash(t('A trade needs a name.'), 'err');
            redirect('/settings#trades');
        }

        // The slug is what every existing record is filed under, so it is
        // derived once and never changes afterwards.
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $label), '_'));

        if ($slug === '' || mb_strlen($slug) > 40) {
            flash(t('That name cannot be turned into a usable key. Use letters and numbers.'), 'err');
            redirect('/settings#trades');
        }

        if (val('SELECT COUNT(*) FROM disciplines WHERE slug = ?', [$slug])) {
            // Re-adding a retired trade brings it back rather than failing,
            // which is what somebody typing it again actually means.
            q('UPDATE disciplines SET is_active = 1, label = ? WHERE slug = ?', [$label, $slug]);
            flash(t(':trade is back on the list.', ['trade' => $label]));
            redirect('/settings#trades');
        }

        $next = (int) val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM disciplines
                           WHERE sort_order < 90');

        q('INSERT INTO disciplines (slug, label, sort_order) VALUES (?,?,?)',
          [$slug, $label, $next]);

        log_activity('added a trade', 'discipline', 0, $label);
        flash(t(':trade added. It can now be asked for on a scope of work.', ['trade' => $label]));
        redirect('/settings#trades');
    }

    if ($do === 'retire_trade' || $do === 'restore_trade') {
        $slug  = (string) ($_POST['slug'] ?? '');
        $trade = row('SELECT * FROM disciplines WHERE slug = ?', [$slug]);

        if (! $trade) {
            flash(t('That trade is not on the list.'), 'err');
            redirect('/settings#trades');
        }

        $retire = $do === 'retire_trade';

        if ($retire && $slug === 'other') {
            flash(t('Other is what anything unclassified falls back to, so it stays.'), 'err');
            redirect('/settings#trades');
        }

        q('UPDATE disciplines SET is_active = ? WHERE slug = ?', [$retire ? 0 : 1, $slug]);

        log_activity($retire ? 'retired a trade' : 'restored a trade',
                     'discipline', 0, (string) $trade['label']);

        flash($retire
            ? t(':trade is retired. Records already filed under it keep it; it is no longer offered.',
                ['trade' => $trade['label']])
            : t(':trade is back on the list.', ['trade' => $trade['label']]));

        redirect('/settings#trades');
    }

    if ($do === 'workspace') {
        foreach (SETTINGS_TEXT as $key => $limit) {
            if (array_key_exists($key, $_POST)) {
                settings_put($key, mb_substr(trim((string) $_POST[$key]), 0, $limit));
            }
        }

        if (array_key_exists('employee_monthly_price', $_POST)) {
            $price = trim((string) $_POST['employee_monthly_price']);

            if (! is_numeric($price) || (float) $price < 0 || (float) $price > 100000) {
                refuse(422, t('Enter a price per employee between 0 and 100000.'));
            }

            settings_put('employee_monthly_price', (string) (float) $price);
        }

        if (array_key_exists('billing_basis', $_POST)) {
            if (! in_array($_POST['billing_basis'], ['active_workers', 'deployed_workers'], true)) {
                refuse(422, t('Choose how subscribed workers are counted.'));
            }

            settings_put('billing_basis', (string) $_POST['billing_basis']);
        }

        log_activity('updated platform settings');
        flash(t('Settings saved.'));
        redirect('/settings');
    }

    refuse(422, t('Unknown settings action.'));
}

$settings = array_column(rows('SELECT * FROM platform_settings'), 'setting_value', 'setting_key');

$active   = (int) val('SELECT COUNT(DISTINCT w.candidate_id) FROM worker_accounts w
                       JOIN users u ON u.id = w.user_id WHERE u.is_active = 1');
$deployed = (int) val("SELECT COUNT(DISTINCT candidate_id) FROM placements
                       WHERE status IN ('confirmed','travelling','on_site')");

// The grid: [role][desk] => granted
$granted = [];

foreach (rows('SELECT role_name, desk FROM role_permissions') as $p) {
    $granted[$p['role_name']][$p['desk']] = true;
}

// What each desk opens, and who sits at it right now.
$desks = [];

foreach (SETTINGS_DESKS as $desk) {
    $desks[$desk] = [
        'screens' => workspace_desk_screens($desk),
        'people'  => (int) val('SELECT COUNT(*) FROM users WHERE role = ? AND is_active = 1', [$desk]),
    ];
}

$roleCounts = array_column(
    rows('SELECT role, COUNT(*) n FROM users WHERE is_active = 1 GROUP BY role'), 'n', 'role');

$recentChanges = rows("SELECT a.created_at, a.action, a.detail, u.name
                       FROM activity a LEFT JOIN users u ON u.id = a.user_id
                       WHERE a.action IN ('changed role permissions','updated platform settings')
                       ORDER BY a.id DESC LIMIT 8");

$pageTitle = t('Settings') . ' · ' . $config['app_name'];

// Retired trades included: an administrator has to be able to see what was
// taken off the list in order to put it back.
$trades = [];

foreach (disciplines(true) as $slug => $label) {
    $trades[] = [
        'slug'   => $slug,
        'label'  => $label,
        'active' => (int) val('SELECT is_active FROM disciplines WHERE slug = ?', [$slug]),
        'in_use' => (int) val('SELECT COUNT(*) FROM candidates WHERE discipline = ?', [$slug])
                  + (int) val('SELECT COUNT(*) FROM vacancies WHERE discipline = ?', [$slug])
                  + (int) val('SELECT COUNT(*) FROM job_order_lines WHERE discipline = ?', [$slug]),
    ];
}

$skillRows = [];

foreach (skills_list(true) as $slug => $label) {
    $skillRows[] = [
        'slug'   => $slug,
        'label'  => $label,
        'active' => (int) val('SELECT is_active FROM skills WHERE slug = ?', [$slug]),
        'people' => (int) val('SELECT COUNT(*) FROM candidate_skills WHERE skill_slug = ?', [$slug]),
    ];
}

render('settings', compact('settings', 'active', 'deployed', 'granted', 'desks',
                           'roleCounts', 'recentChanges', 'trades', 'skillRows'));
