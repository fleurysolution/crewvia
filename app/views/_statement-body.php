<?php
/** The statement itself, the same on screen and on paper. Needs $statement and $figures. */
$num = static fn($v): string => number_format((float) $v, 2, '.', '');
$section = static function (string $title, array $s, string $id) use ($num): string {
    $h = '<tr class="head"><th colspan="2">' . te($title) . '</th></tr>';
    foreach ($s['rows'] as $r) {
        $h .= '<tr data-line="' . e($r['key']) . '" data-amount="' . e($num($r['amount'])) . '"><td>' . e(trim($r['number'] . ' ' . t($r['label']))) . '</td><td class="num mono">' . e(money($r['amount'])) . '</td></tr>';
    }
    if (! $s['rows']) {
        $h .= '<tr><td class="muted">' . te('None') . '</td><td></td></tr>';
    }
    return $h . '<tr class="total" id="' . e($id) . '" data-amount="' . e($num($s['total'])) . '"><th>' . te('Total :what', ['what' => mb_strtolower(t($title))]) . '</th><th class="num mono">' . e(money($s['total'])) . '</th></tr>';
};
?>
<?php if ($statement === 'income'): ?>
<table class="statement" id="income-statement" data-net="<?= e($num($figures['net'])) ?>">
  <?= $section('Income', $figures['income'], 'income-total') ?>
  <?= $section('Expenses', $figures['expense'], 'expense-total') ?>
  <tr class="grand"><th><?= $figures['net'] >= 0 ? te('Net income') : te('Net loss') ?></th><th class="num mono"><?= e(money($figures['net'])) ?></th></tr>
</table>
<?php elseif ($statement === 'balance'): ?>
<table class="statement" id="balance-sheet" data-balanced="<?= $figures['balanced'] ? '1' : '0' ?>">
  <?= $section('Assets', $figures['assets'], 'assets-total') ?>
  <?= $section('Liabilities', $figures['liabilities'], 'liabilities-total') ?>
  <tr class="head"><th colspan="2"><?= te('Equity') ?></th></tr>
  <?php foreach ($figures['equity']['rows'] as $r): ?><tr data-line="<?= e($r['key']) ?>" data-amount="<?= e($num($r['amount'])) ?>"><td><?= e(trim($r['number'] . ' ' . t($r['label']))) ?></td><td class="num mono"><?= e(money($r['amount'])) ?></td></tr><?php endforeach; ?>
  <tr data-line="prior_earnings" data-amount="<?= e($num($figures['prior_earnings'])) ?>"><td><?= te('Retained earnings of earlier years') ?></td><td class="num mono"><?= e(money($figures['prior_earnings'])) ?></td></tr>
  <tr data-line="current_earnings" data-amount="<?= e($num($figures['current_earnings'])) ?>"><td><?= te('Earnings this year to date') ?></td><td class="num mono"><?= e(money($figures['current_earnings'])) ?></td></tr>
  <tr class="grand" id="le-total" data-amount="<?= e($num($figures['total_le'])) ?>"><th><?= te('Total liabilities and equity') ?></th><th class="num mono"><?= e(money($figures['total_le'])) ?></th></tr>
</table>
<p class="<?= $figures['balanced'] ? 'muted' : 'err' ?>"><?= $figures['balanced'] ? te('Assets equal liabilities and equity.') : te('Assets and liabilities with equity differ: check the ledger integrity.') ?></p>
<?php elseif ($statement === 'cash'): ?>
<?php $sectionNames = ['operating' => 'Operating activities', 'investing' => 'Investing activities', 'financing' => 'Financing activities']; ?>
<table class="statement" id="cash-flow" data-opening="<?= e($num($figures['opening'])) ?>" data-closing="<?= e($num($figures['closing'])) ?>" data-change="<?= e($num($figures['change'])) ?>" data-reconciled="<?= $figures['reconciled'] ? '1' : '0' ?>">
  <tr data-line="opening"><th><?= te('Cash at the start') ?></th><th class="num mono"><?= e(money($figures['opening'])) ?></th></tr>
  <?php foreach ($sectionNames as $k => $title): $s = $figures['sections'][$k]; ?>
    <tr class="head"><th colspan="2"><?= te($title) ?></th></tr>
    <?php foreach ($s['lines'] as $label => $v): ?><tr data-cash="<?= e($label) ?>" data-amount="<?= e($num($v)) ?>"><td><?= te($label) ?></td><td class="num mono"><?= e(money($v)) ?></td></tr><?php endforeach; ?>
    <?php if (! $s['lines']): ?><tr><td class="muted"><?= te('None') ?></td><td></td></tr><?php endif; ?>
    <tr class="total" id="cash-<?= e($k) ?>" data-amount="<?= e($num($s['total'])) ?>"><th><?= te('Net cash from :what', ['what' => mb_strtolower(t($title))]) ?></th><th class="num mono"><?= e(money($s['total'])) ?></th></tr>
  <?php endforeach; ?>
  <tr class="grand"><th><?= te('Cash at the end') ?></th><th class="num mono"><?= e(money($figures['closing'])) ?></th></tr>
</table>
<p class="<?= $figures['reconciled'] ? 'muted' : 'err' ?>"><?= $figures['reconciled'] ? te('The movements explain the bank balance to the cent.') : te('The movements do not explain the bank balance: check the ledger integrity.') ?></p>
<?php endif; ?>
