<?php
/**
 * A pay statement for one person and one pay period, ready to print.
 *
 * Payroll sees anybody's, and the view is logged. A worker sees their own
 * only, and only once the period is approved - a statement for a week
 * still being decided would be a promise nobody made.
 *
 * It shows pay before taxes. The payroll provider withholds taxes and its
 * statement is the official one; this one says so.
 */

require_once __DIR__ . '/../pay-periods.php';

require_login();

$own = is_worker_account();
$run = payroll_run((int) ($_GET['run'] ?? 0));

if ($own) {
    $candidateId = (int) val('SELECT candidate_id FROM worker_accounts WHERE user_id = ?', [uid()]);

    if (! $run || ! in_array($run['status'], ['approved', 'locked'], true)) {
        refuse(404, t('That pay statement is not available.'));
    }
} else {
    require_role('payroll');
    $candidateId = (int) ($_GET['candidate'] ?? 0);
}

$slip = $run ? payslip_data($run, $candidateId) : null;

if (! $slip) {
    refuse(404, t('That pay statement is not available.'));
}

if (! $own) {
    log_activity('opened a pay statement', 'payroll_run', (int) $run['id'], '#' . $candidateId);
}

$hours = static function (array $snap): array {
    return array_filter([
        t('Regular')        => (float) ($snap['regular_hours'] ?? $snap['paid_hours'] ?? 0),
        t('Overtime')       => (float) ($snap['overtime_hours'] ?? 0),
        t('Double time')    => (float) ($snap['double_hours'] ?? 0),
        t('Holiday')        => (float) ($snap['holiday_hours'] ?? 0),
        t('Paid leave')     => (float) ($snap['paid_leave_hours'] ?? 0),
    ], fn($h) => $h > 0);
};
$kinds = payroll_adjustment_kinds();
$name = (string) $config['app_name'];
?><!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= te('Pay statement') ?> · <?= e($slip['person']['full_name']) ?> · <?= e(d($run['week_ending'])) ?></title>
<style>
@page { size: Letter; margin: 0; }
* { box-sizing: border-box; }
body { margin: 0; padding: 16mm 18mm; font: 10.5pt/1.45 "Segoe UI", Arial, sans-serif; color: #000; background: #fff; }
header { display: flex; align-items: center; gap: 12px; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 12px; }
header img { width: 40px; height: 40px; }
h1 { font-size: 15pt; margin: 0; }
h2 { font-size: 11pt; margin: 14px 0 4px; border-bottom: 1px solid #000; padding-bottom: 2px; }
table { width: 100%; border-collapse: collapse; }
td, th { padding: 3px 4px; text-align: left; vertical-align: top; }
th { font-weight: 600; border-bottom: 1px solid #999; }
.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.total td { border-top: 1px solid #000; font-weight: 700; }
.note { font-size: 9pt; margin-top: 14px; border-top: 1px solid #999; padding-top: 6px; }
.meta td { padding: 1px 4px; }
.bar { margin: 0 0 12px; }
@media print { .bar { display: none; } body { padding: 14mm 16mm; } }
@media (max-width: 640px) { body { padding: 16px; } header { flex-wrap: wrap; } }
</style>
</head>
<body>
<p class="bar"><button onclick="window.print()"><?= te('Print') ?></button> <a href="<?= $own ? '/my-payslips' : '/payroll-runs?id=' . (int) $run['id'] ?>"><?= te('Back') ?></a></p>
<header>
  <img src="/assets/app-icon.svg" alt="<?= e($name) ?>">
  <div><h1><?= te('Pay statement') ?></h1><div><?= e($name) ?> · <?= te('Week ending :date', ['date' => d($run['week_ending'])]) ?></div></div>
</header>

<table class="meta">
  <tr><td><?= te('Worker') ?></td><td><strong><?= e($slip['person']['full_name']) ?></strong></td></tr>
  <?php if ($slip['person']['employee_number']): ?><tr><td><?= te('Employee number') ?></td><td><?= e($slip['person']['employee_number']) ?></td></tr><?php endif; ?>
  <tr><td><?= te('Pay period') ?></td><td><?= e(d(date('Y-m-d', strtotime($run['week_ending'] . ' -6 days')))) ?> – <?= e(d($run['week_ending'])) ?> · <?= e(payroll_run_statuses()[$run['status']]) ?></td></tr>
</table>

<?php foreach ($slip['lines'] as $line): $s = $line['snap']; $f = $line['figures']; ?>
<h2><?= e($line['project']) ?></h2>
<table>
  <tr><th><?= te('Hours') ?></th><th class="num"><?= te('Hours') ?></th></tr>
  <?php foreach ($hours($s) as $label => $h): ?><tr><td><?= e($label) ?></td><td class="num"><?= e((string) $h) ?></td></tr><?php endforeach; ?>
  <tr><td><?= te('Rate') ?></td><td class="num"><?= e(money($s['pay_rate'] ?? 0)) ?><?= ! empty($s['shift_premium']) ? ' + ' . e(money($s['shift_premium'])) : '' ?></td></tr>
  <tr><td><?= te('Gross wages') ?></td><td class="num"><?= e(money($f['gross'])) ?></td></tr>
  <?php foreach (($s['gross_to_net']['deductions'] ?? []) as $dd): ?><tr><td><?= e(t((string) $dd['label'])) ?></td><td class="num">−<?= e(money($dd['amount'])) ?></td></tr><?php endforeach; ?>
  <?php if ((float) ($s['per_diem'] ?? 0) > 0): ?><tr><td><?= te('Per diem') ?></td><td class="num"><?= e(money($s['per_diem'])) ?></td></tr><?php endif; ?>
  <?php if ((float) ($s['expenses'] ?? 0) > 0): ?><tr><td><?= te('Expenses') ?></td><td class="num"><?= e(money($s['expenses'])) ?></td></tr><?php endif; ?>
  <?php if (! $f['recorded']): ?><tr><td colspan="2" class="note"><?= te('Approved before deductions were recorded: no deduction is shown for this week.') ?></td></tr><?php endif; ?>
</table>
<?php endforeach; ?>

<?php if ($slip['adjustments']): ?>
<h2><?= te('Adjustments') ?></h2>
<table>
  <?php foreach ($slip['adjustments'] as $a): ?><tr><td><?= e($kinds[$a['kind']] ?? $a['kind']) ?><?= $a['corrects'] ? ' · ' . te('corrects the week ending :date', ['date' => d($a['corrects'])]) : '' ?><div><?= e($a['reason']) ?></div></td><td class="num"><?= e(money($a['amount'])) ?></td></tr><?php endforeach; ?>
</table>
<?php endif; ?>

<h2><?= te('Summary') ?></h2>
<table>
  <tr><td><?= te('Gross wages') ?></td><td class="num"><?= e(money($slip['gross'])) ?></td></tr>
  <tr><td><?= te('Deductions') ?></td><td class="num">−<?= e(money($slip['deductions'])) ?></td></tr>
  <tr><td><?= te('Per diem and expenses') ?></td><td class="num"><?= e(money($slip['reimbursed'])) ?></td></tr>
  <?php if ($slip['adjusted'] != 0): ?><tr><td><?= te('Adjustments') ?></td><td class="num"><?= e(money($slip['adjusted'])) ?></td></tr><?php endif; ?>
  <tr class="total"><td><?= te('To be paid, before taxes') ?></td><td class="num" data-k="payable"><?= e(money($slip['payable'])) ?></td></tr>
</table>

<p class="note"><?= te('Taxes are withheld by the payroll provider and are not shown here. The provider\'s statement is the official record of your pay; this one shows how your week was worked out before taxes.') ?></p>
</body>
</html>
<?php exit;
