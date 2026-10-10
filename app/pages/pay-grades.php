<?php
/**
 * Pay grades and their bands (P2-M01). Administrators only: a band is a
 * term of pay. A grade is retired, never deleted - people were on it.
 */

require_once __DIR__ . '/../compensation.php';

require_role('admin');

$money = static function (string $v, float $max): array {
    $v = trim($v);
    if ($v === '') { return [null, true]; }
    if (! is_numeric($v) || (float) $v < 0 || (float) $v > $max) { return [null, false]; }
    return [round((float) $v, 2), true];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $id = (int) ($_POST['grade_id'] ?? 0);

    if ($do === 'save') {
        $label = trim((string) ($_POST['label'] ?? ''));
        $code = $id ? null : trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_');
        [$rmin, $a] = $money((string) ($_POST['rate_min'] ?? ''), 1000);
        [$rmax, $b] = $money((string) ($_POST['rate_max'] ?? ''), 1000);
        [$smin, $c] = $money((string) ($_POST['salary_min'] ?? ''), 1000000);
        [$smax, $d] = $money((string) ($_POST['salary_max'] ?? ''), 1000000);

        if (mb_strlen($label) < 2 || mb_strlen($label) > 120 || ($code !== null && ($code === '' || strlen($code) > 40))) {
            refuse(422, t('Name the grade in 2 to 120 characters.'));
        }
        if (! $a || ! $b || ! $c || ! $d) {
            refuse(422, t('Band ends are blank, or amounts: up to 1,000 an hour, up to 1,000,000 a pay period.'));
        }
        if (($rmin !== null && $rmax !== null && $rmin > $rmax) || ($smin !== null && $smax !== null && $smin > $smax)) {
            refuse(422, t('The bottom of a band cannot be above its top.'));
        }
        if ($code !== null && val('SELECT COUNT(*) FROM pay_grades WHERE code = ?', [$code])) {
            refuse(422, t('A grade with that name already exists.'));
        }
        if ($id && ! val('SELECT COUNT(*) FROM pay_grades WHERE id = ?', [$id])) {
            refuse(404, t('That grade does not exist.'));
        }

        if ($id) {
            q('UPDATE pay_grades SET label=?, rate_min=?, rate_max=?, salary_min=?, salary_max=?, sort_order=? WHERE id=?',
              [$label, $rmin, $rmax, $smin, $smax, max(0, min(999, (int) ($_POST['sort_order'] ?? 0))), $id]);
            log_activity('changed a pay grade', 'pay_grade', $id, $label . ' ' . json_encode([$rmin, $rmax, $smin, $smax]));
        } else {
            q('INSERT INTO pay_grades (code, label, rate_min, rate_max, salary_min, salary_max, sort_order, created_by) VALUES (?,?,?,?,?,?,?,?)',
              [$code, $label, $rmin, $rmax, $smin, $smax, max(0, min(999, (int) ($_POST['sort_order'] ?? 0))), uid()]);
            log_activity('created a pay grade', 'pay_grade', (int) db()->lastInsertId(), $label);
        }

        flash(t('Grade saved. Pay already set is not changed; a change outside the band now needs an administrator.'));
        redirect('/pay-grades');
    }

    if ($do === 'retire' || $do === 'restore') {
        q('UPDATE pay_grades SET is_active = ? WHERE id = ?', [$do === 'restore' ? 1 : 0, $id]);
        log_activity($do . 'd a pay grade', 'pay_grade', $id);
        redirect('/pay-grades');
    }

    refuse(422, t('Unknown action.'));
}

$grades = rows('SELECT g.*, (SELECT COUNT(*) FROM employee_profiles e WHERE e.grade_id = g.id) AS people
                FROM pay_grades g ORDER BY g.is_active DESC, g.sort_order, g.label');
$current = isset($_GET['id']) ? row('SELECT * FROM pay_grades WHERE id = ?', [(int) $_GET['id']]) : null;

$pageTitle = t('Pay grades') . ' · ' . $config['app_name'];
render('pay-grades', compact('grades', 'current'));
