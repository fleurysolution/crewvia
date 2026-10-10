<?php
/**
 * Leave types: the kinds of time off, how each is earned, who may take it.
 *
 * Administrators only - an allowance is a term of employment. A type is
 * retired, never deleted: requests already made name it, and a balance
 * from last year still carries over under its rules.
 */

require_once __DIR__ . '/../hr.php';
require_once __DIR__ . '/../classification.php';

require_role('admin');

$employment = employment_types();

$input = static function (array $in, bool $creating) use ($employment): array {
    $txt = static fn(string $k): string => trim((string) ($in[$k] ?? ''));
    $int = static function (string $k, int $max, bool $blankIsNull) use ($txt) {
        $v = $txt($k);
        if ($v === '') { return [$blankIsNull ? null : 0, true]; }
        if (! ctype_digit($v) || (int) $v > $max) { return [null, false]; }
        return [(int) $v, true];
    };

    $label = $txt('label');
    if (mb_strlen($label) < 2 || mb_strlen($label) > 90) {
        return [[], t('Name the kind of leave in 2 to 90 characters.')];
    }

    $slug = $creating ? trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_') : null;
    if ($creating && ($slug === '' || strlen($slug) > 40)) {
        return [[], t('Name the kind of leave in 2 to 90 characters.')];
    }
    if ($creating && val('SELECT COUNT(*) FROM leave_types WHERE slug = ?', [$slug])) {
        return [[], t('A kind of leave with that name already exists.')];
    }

    $method = $txt('accrual_method') === 'hours_worked' ? 'hours_worked' : 'annual';
    [$days, $ok1] = $int('days_allowed', 366, true);
    [$cap, $ok2] = $int('accrual_cap_days', 366, true);
    [$carry, $ok3] = $int('carryover_max_days', 366, false);
    [$wait, $ok4] = $int('eligible_after_days', 3650, false);
    [$order, $ok5] = $int('sort_order', 999, false);

    if (! $ok1 || ! $ok2 || ! $ok3 || ! $ok4 || ! $ok5) {
        return [[], t('Days are whole numbers: up to 366 for an allowance, cap or carryover, up to 3650 for a waiting period.')];
    }

    $per = $txt('accrual_hours_per_day');
    if ($method === 'hours_worked') {
        if (! is_numeric($per) || (float) $per < 1 || (float) $per > 2000) {
            return [[], t('Leave earned from hours worked needs the hours that earn one day, from 1 to 2000.')];
        }
        $days = null;
    } else {
        $per = null;
    }

    $hpd = $txt('hours_per_day');
    if (! is_numeric($hpd) || (float) $hpd <= 0 || (float) $hpd > 24) {
        return [[], t('A day of leave is paid as a number of hours from 0.25 to 24.')];
    }

    $allowed = array_values(array_intersect(array_keys($employment), (array) ($in['eligible_employment_types'] ?? [])));
    if (($in['eligible_employment_types'] ?? null) !== null && ! $allowed) {
        return [[], t('Choose at least one kind of employment, or leave them all unticked for everybody.')];
    }

    return [[
        'slug' => $slug, 'label' => $label, 'days_allowed' => $days, 'accrual_method' => $method,
        'accrual_hours_per_day' => $per !== null ? round((float) $per, 2) : null, 'accrual_cap_days' => $cap,
        'carryover_max_days' => $carry, 'eligible_after_days' => $wait,
        'eligible_employment_types' => $allowed && count($allowed) < count($employment) ? implode(',', $allowed) : null,
        'is_paid' => ! empty($in['is_paid']) ? 1 : 0, 'hours_per_day' => round((float) $hpd, 2), 'sort_order' => $order,
    ], null];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $slug = (string) ($_POST['slug'] ?? '');
    $existing = $slug !== '' ? row('SELECT * FROM leave_types WHERE slug = ?', [$slug]) : null;

    if ($do === 'create' || $do === 'update') {
        if ($do === 'update' && ! $existing) {
            refuse(404, t('That kind of leave does not exist.'));
        }

        [$v, $refusal] = $input($_POST, $do === 'create');

        if ($refusal !== null) {
            refuse(422, $refusal);
        }

        if ($do === 'create') {
            q('INSERT INTO leave_types (slug, label, days_allowed, accrual_method, accrual_hours_per_day, accrual_cap_days,
                 carryover_max_days, eligible_after_days, eligible_employment_types, is_paid, hours_per_day, sort_order)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', array_values($v));
            log_activity('created a kind of leave', 'leave_type', 0, $v['slug']);
            $slug = $v['slug'];
        } else {
            unset($v['slug']);
            q('UPDATE leave_types SET label=?, days_allowed=?, accrual_method=?, accrual_hours_per_day=?, accrual_cap_days=?,
                 carryover_max_days=?, eligible_after_days=?, eligible_employment_types=?, is_paid=?, hours_per_day=?, sort_order=?
               WHERE slug=?', [...array_values($v), $slug]);
            log_activity('changed a kind of leave', 'leave_type', 0, $slug . ': ' . json_encode($v));
        }

        flash(t('Saved. Balances are worked out again from these rules the next time anybody looks.'));
        redirect('/leave-types?slug=' . rawurlencode($slug));
    }

    if ($do === 'retire' || $do === 'restore') {
        if (! $existing) {
            refuse(404, t('That kind of leave does not exist.'));
        }

        q('UPDATE leave_types SET is_active = ? WHERE slug = ?', [$do === 'restore' ? 1 : 0, $slug]);
        log_activity($do === 'restore' ? 'restored a kind of leave' : 'retired a kind of leave', 'leave_type', 0, $slug);
        flash($do === 'restore' ? t('Restored: it can be asked for again.') : t('Retired: nobody can ask for it any more. Requests already made keep it.'));
        redirect('/leave-types');
    }

    refuse(422, t('Unknown action.'));
}

$types = rows('SELECT t.*, (SELECT COUNT(*) FROM time_off_requests r WHERE r.leave_type = t.slug) AS requests
               FROM leave_types t ORDER BY t.is_active DESC, t.sort_order, t.label');
$current = isset($_GET['slug']) ? row('SELECT * FROM leave_types WHERE slug = ?', [(string) $_GET['slug']]) : null;

$pageTitle = t('Leave types') . ' · ' . $config['app_name'];
render('leave-types', compact('types', 'current', 'employment'));
