<?php
/**
 * Pay items: the deductions that can be taken from pay and the
 * contributions the employer pays on top. Payroll only.
 *
 * An item is retired, never deleted: weeks already approved name it in
 * their frozen figures. The provider code is what the payroll provider's
 * import calls it; without one, the ADP draft export refuses the item.
 */

require_once __DIR__ . '/../gross-to-net.php';

require_role('payroll');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $id = (int) ($_POST['pay_item_id'] ?? 0);
    $item = $id ? row('SELECT * FROM pay_items WHERE id = ?', [$id]) : null;
    $code = trim((string) ($_POST['provider_code'] ?? ''));

    if ($code !== '' && ! preg_match('/^[A-Za-z0-9_-]{1,40}$/', $code)) {
        refuse(422, t('A provider code is letters, digits, dash or underscore, up to 40.'));
    }

    if ($do === 'create') {
        $label = trim((string) ($_POST['label'] ?? ''));
        $side = (string) ($_POST['side'] ?? '');
        $method = (string) ($_POST['method'] ?? '');
        $slug = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_');

        if (mb_strlen($label) < 2 || mb_strlen($label) > 120 || $slug === '' || strlen($slug) > 40) {
            refuse(422, t('Name the pay item in 2 to 120 characters.'));
        }

        if (! array_key_exists($side, pay_item_sides()) || ! in_array($method, ['fixed', 'percent_of_gross'], true)) {
            refuse(422, t('Choose whether it is taken from pay or paid by the employer, and how it is worked out.'));
        }

        if (val('SELECT COUNT(*) FROM pay_items WHERE code = ?', [$slug])) {
            refuse(422, t('A pay item with that name already exists.'));
        }

        q('INSERT INTO pay_items (code, label, side, method, pre_tax, provider_code, sort_order, created_by) VALUES (?,?,?,?,?,?,?,?)',
          [$slug, $label, $side, $method, $side === 'deduction' && ! empty($_POST['pre_tax']) ? 1 : 0, $code ?: null,
           max(0, min(999, (int) ($_POST['sort_order'] ?? 0))), uid()]);
        log_activity('created a pay item', 'pay_item', (int) db()->lastInsertId(), $slug);
        flash(t('Pay item saved. Give it to people from their employee folder.'));
        redirect('/pay-items');
    }

    if (! $item) {
        refuse(404, t('That pay item does not exist.'));
    }

    if ($do === 'code') {
        q('UPDATE pay_items SET provider_code = ? WHERE id = ?', [$code ?: null, $id]);
        log_activity('set a pay item provider code', 'pay_item', $id, $item['code'] . ' = ' . $code);
        redirect('/pay-items');
    }

    if ($do === 'retire' || $do === 'restore') {
        q('UPDATE pay_items SET is_active = ? WHERE id = ?', [$do === 'restore' ? 1 : 0, $id]);
        log_activity($do . 'd a pay item', 'pay_item', $id, $item['code']);
        flash($do === 'restore' ? t('Restored: it is taken again from weeks not yet approved.') : t('Retired: weeks not yet approved no longer take it. Approved weeks keep it.'));
        redirect('/pay-items');
    }

    refuse(422, t('Unknown action.'));
}

$items = rows('SELECT i.*, (SELECT COUNT(*) FROM employee_pay_items e WHERE e.pay_item_id = i.id
                            AND (e.ends_on IS NULL OR e.ends_on >= CURDATE())) AS people
               FROM pay_items i ORDER BY i.is_active DESC, i.side, i.sort_order, i.label');
$sides = pay_item_sides();
$methods = pay_item_methods();

$pageTitle = t('Pay items') . ' · ' . $config['app_name'];
render('pay-items', compact('items', 'sides', 'methods'));
