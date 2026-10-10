<?php
/**
 * Pay rules: the overtime, double-time, holiday and shift-premium rules a
 * project is paid under.
 *
 * Payroll writes drafts and chooses the project's set. An administrator
 * activates a draft, naming who confirmed the values; nothing is applied
 * to anybody's pay before that. See app/pay-rules.php for the life cycle.
 */

require_once __DIR__ . '/../pay-rules.php';

require_role('payroll');

$job   = current_job();
$jobId = (int) ($job['id'] ?? 0);
$back  = static fn(?int $id = null) => redirect('/pay-rules' . ($id ? '?id=' . $id : ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $id = (int) ($_POST['rule_set_id'] ?? 0);
    $set = pay_rule_set($id);

    if ($do === 'create' || $do === 'update') {
        if ($do === 'update' && (! $set || $set['status'] !== 'draft')) {
            refuse(422, t('Only a draft can be changed. Copy an active rule set to change it.'));
        }

        [$v, $refusal] = pay_rule_set_input($_POST, $do === 'update' ? $id : null);

        if ($refusal !== null) {
            refuse(422, $refusal);
        }

        if ($do === 'create') {
            q('INSERT INTO pay_rule_sets (name, jurisdiction, weekly_overtime_after, weekly_overtime_multiplier,
                 daily_overtime_after, daily_overtime_multiplier, daily_double_after, daily_double_multiplier,
                 holiday_multiplier, notes, status, created_by)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
              [...array_values($v), 'draft', uid()]);
            $id = (int) db()->lastInsertId();
            log_activity('created a pay rule set', 'pay_rule_set', $id, $v['name']);
        } else {
            q('UPDATE pay_rule_sets SET name=?, jurisdiction=?, weekly_overtime_after=?, weekly_overtime_multiplier=?,
                 daily_overtime_after=?, daily_overtime_multiplier=?, daily_double_after=?, daily_double_multiplier=?,
                 holiday_multiplier=?, notes=? WHERE id=? AND status=\'draft\'',
              [...array_values($v), $id]);
            log_activity('changed a draft pay rule set', 'pay_rule_set', $id, $v['name']);
        }

        flash(t('Draft saved. It is applied to nobody until an administrator activates it.'));
        $back($id);
    }

    if (! $set) {
        refuse(404, t('That rule set does not exist.'));
    }

    if ($do === 'copy') {
        $name = mb_substr($set['name'], 0, 100) . ' (' . t('copy') . ')';
        for ($n = 2; val('SELECT COUNT(*) FROM pay_rule_sets WHERE name = ?', [$name]); $n++) {
            $name = mb_substr($set['name'], 0, 100) . ' (' . t('copy') . ' ' . $n . ')';
        }

        db()->beginTransaction();
        q('INSERT INTO pay_rule_sets (name, jurisdiction, weekly_overtime_after, weekly_overtime_multiplier,
             daily_overtime_after, daily_overtime_multiplier, daily_double_after, daily_double_multiplier,
             holiday_multiplier, notes, status, created_by)
           SELECT ?, jurisdiction, weekly_overtime_after, weekly_overtime_multiplier, daily_overtime_after,
                  daily_overtime_multiplier, daily_double_after, daily_double_multiplier, holiday_multiplier,
                  notes, \'draft\', ? FROM pay_rule_sets WHERE id = ?', [$name, uid(), $id]);
        $copy = (int) db()->lastInsertId();
        q('INSERT INTO pay_rule_holidays (rule_set_id, holiday_date, name) SELECT ?, holiday_date, name FROM pay_rule_holidays WHERE rule_set_id = ?', [$copy, $id]);
        q('INSERT INTO pay_rule_shift_premiums (rule_set_id, shift_label, amount_per_hour) SELECT ?, shift_label, amount_per_hour FROM pay_rule_shift_premiums WHERE rule_set_id = ?', [$copy, $id]);
        db()->commit();
        log_activity('copied a pay rule set', 'pay_rule_set', $copy, $set['name'] . ' -> ' . $name);
        flash(t('Copied into a new draft. Change it, then ask an administrator to activate it.'));
        $back($copy);
    }

    if ($do === 'activate') {
        require_role('admin');
        $who = trim((string) ($_POST['confirmed_by'] ?? ''));

        if ($set['status'] !== 'draft') {
            refuse(422, t('Only a draft is activated.'));
        }

        if (mb_strlen($who) < 3 || mb_strlen($who) > 190) {
            refuse(422, t('Say who confirmed these values for the jurisdiction - the payroll provider, counsel - before activating.'));
        }

        q("UPDATE pay_rule_sets SET status='active', confirmed_by=?, confirmed_at=NOW() WHERE id=? AND status='draft'", [$who, $id]);
        log_activity('activated a pay rule set', 'pay_rule_set', $id, $set['name'] . ' - confirmed by ' . $who);
        flash(t('Activated. Projects can now be paid under it.'));
        $back($id);
    }

    if ($do === 'retire') {
        require_role('admin');

        if ($set['status'] !== 'active') {
            refuse(422, t('Only an active rule set is retired.'));
        }

        q("UPDATE pay_rule_sets SET status='retired' WHERE id=?", [$id]);
        log_activity('retired a pay rule set', 'pay_rule_set', $id, $set['name']);
        flash(t('Retired. Projects still using it keep it until they are moved to another.'));
        $back($id);
    }

    if ($do === 'holiday_add' || $do === 'holiday_remove') {
        if ($set['status'] === 'retired') {
            refuse(422, t('A retired rule set is not changed.'));
        }

        if ($do === 'holiday_add') {
            $date = (string) ($_POST['holiday_date'] ?? '');
            $name = trim((string) ($_POST['holiday_name'] ?? ''));

            if (! valid_date($date) || mb_strlen($name) < 2 || mb_strlen($name) > 120) {
                refuse(422, t('A holiday needs a date and a name.'));
            }

            if (val('SELECT COUNT(*) FROM pay_rule_holidays WHERE rule_set_id = ? AND holiday_date = ?', [$id, $date])) {
                refuse(422, t('That date is already a holiday in this rule set.'));
            }

            q('INSERT INTO pay_rule_holidays (rule_set_id, holiday_date, name) VALUES (?,?,?)', [$id, $date, $name]);
            log_activity('added a holiday to a pay rule set', 'pay_rule_set', $id, $date . ' ' . $name);
        } else {
            $hid = (int) ($_POST['holiday_id'] ?? 0);
            $gone = row('SELECT holiday_date, name FROM pay_rule_holidays WHERE id = ? AND rule_set_id = ?', [$hid, $id]);

            if (! $gone) {
                refuse(404, t('That holiday is not in this rule set.'));
            }

            q('DELETE FROM pay_rule_holidays WHERE id = ?', [$hid]);
            log_activity('removed a holiday from a pay rule set', 'pay_rule_set', $id, $gone['holiday_date'] . ' ' . $gone['name']);
        }

        $back($id);
    }

    if ($do === 'shift_add' || $do === 'shift_remove') {
        if ($set['status'] !== 'draft') {
            refuse(422, t('Only a draft can be changed. Copy an active rule set to change it.'));
        }

        if ($do === 'shift_add') {
            $label = trim((string) ($_POST['shift_label'] ?? ''));
            $amount = trim((string) ($_POST['amount_per_hour'] ?? ''));

            if ($label === '' || mb_strlen($label) > 190 || ! is_numeric($amount) || (float) $amount <= 0 || (float) $amount > 100) {
                refuse(422, t('A shift premium needs the shift name, as written on assignments, and an amount per hour up to 100.'));
            }

            if (val('SELECT COUNT(*) FROM pay_rule_shift_premiums WHERE rule_set_id = ? AND LOWER(TRIM(shift_label)) = LOWER(?)', [$id, $label])) {
                refuse(422, t('That shift already has a premium in this rule set.'));
            }

            q('INSERT INTO pay_rule_shift_premiums (rule_set_id, shift_label, amount_per_hour) VALUES (?,?,?)', [$id, $label, round((float) $amount, 2)]);
        } else {
            q('DELETE FROM pay_rule_shift_premiums WHERE id = ? AND rule_set_id = ?', [(int) ($_POST['premium_id'] ?? 0), $id]);
        }

        $back($id);
    }

    if ($do === 'assign') {
        if (! $jobId) {
            refuse(422, t('Open a project first.'));
        }

        if ($set['status'] !== 'active') {
            refuse(422, t('Only an active rule set is given to a project. A draft is applied to nobody until it is activated.'));
        }

        q('UPDATE jobs SET pay_rule_set_id = ? WHERE id = ?', [$id, $jobId]);
        log_activity('chose the pay rule set for a project', 'project', $jobId, $set['name']);
        flash(t('This project is now paid under :name. Weeks already approved keep their figures.', ['name' => $set['name']]));
        $back($id);
    }

    refuse(422, t('Unknown action.'));
}

$sets = pay_rule_sets();
$current = pay_rule_set((int) ($_GET['id'] ?? 0)) ?? ($sets[0] ?? null);
$holidays = $current ? rows('SELECT * FROM pay_rule_holidays WHERE rule_set_id = ? ORDER BY holiday_date', [(int) $current['id']]) : [];
$premiums = $current ? rows('SELECT * FROM pay_rule_shift_premiums WHERE rule_set_id = ? ORDER BY shift_label', [(int) $current['id']]) : [];
$projectSet = pay_rule_set((int) ($job['pay_rule_set_id'] ?? 0));
$statuses = pay_rule_statuses();

$pageTitle = t('Pay rules') . ' · ' . $config['app_name'];
render('pay-rules', compact('job', 'sets', 'current', 'holidays', 'premiums', 'projectSet', 'statuses'));
