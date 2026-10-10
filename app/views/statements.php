<?php
$tabs = ['income' => t('Income statement'), 'balance' => t('Balance sheet'), 'cash' => t('Cash flow'), 'budget' => t('Budgets')];
$num = static fn($v): string => number_format((float) $v, 2, '.', '');
$query = $statement === 'balance' ? 'asof=' . rawurlencode($asof) : 'from=' . rawurlencode($from) . '&to=' . rawurlencode($to) . ($class !== '' ? '&class=' . rawurlencode($class) : '');
?>
<style>
  table.statement { max-width: 640px; }
  table.statement tr.head th { padding-top: 14px; }
  table.statement tr.total th, table.statement tr.grand th { border-top: 1px solid var(--line, #ccc); }
  table.statement tr.grand th { font-size: 1.05em; }
</style>
<h1><?= te('Financial statements') ?></h1>
<p class="sub"><?= te('Read from the Crewvia ledger: what Crewvia records, and the journals typed for the rest. Nothing here is entered. They are not audited statements.') ?></p>

<div class="row" id="statement-tabs"><?php foreach ($tabs as $k => $label): ?><a class="btn<?= $k === $statement ? '' : ' ghost' ?>" href="/statements?view=<?= e($k) ?>"><?= e($label) ?></a> <?php endforeach; ?></div>

<?php if (! $integrity['ok']): ?><div class="err" id="statement-integrity"><?= te('The ledger integrity check fails: these figures cannot be trusted until it is resolved.') ?> <a href="/ledger"><?= te('Ledger') ?></a></div><?php endif; ?>

<?php if ($statement !== 'budget'): ?>
<div class="card scroll" id="statement" data-view="<?= e($statement) ?>">
  <h2><?= e($tabs[$statement]) ?> · <?= $statement === 'balance' ? te('at :d', ['d' => d($asof)]) : te(':a to :b', ['a' => d($from), 'b' => d($to)]) ?><?= $class !== '' ? ' · ' . e($class) : '' ?></h2>
  <form method="get" class="row"><input type="hidden" name="view" value="<?= e($statement) ?>">
    <?php if ($statement === 'balance'): ?><label><?= te('At') ?></label><input type="date" name="asof" value="<?= e($asof) ?>">
    <?php else: ?><label><?= te('From') ?></label><input type="date" name="from" value="<?= e($from) ?>"><label><?= te('To') ?></label><input type="date" name="to" value="<?= e($to) ?>">
      <?php if ($statement === 'income'): ?><select name="class"><option value=""><?= te('Every project') ?></option><?php foreach ($classes as $c): ?><option value="<?= e($c) ?>"<?= $c === $class ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select><?php endif; ?>
    <?php endif; ?>
    <button class="btn ghost"><?= te('Show') ?></button>
    <a class="btn ghost" href="/statements?view=<?= e($statement) ?>&amp;<?= e($query) ?>&amp;print=1" target="_blank" rel="noopener"><?= te('Print') ?></a></form>
  <?php require __DIR__ . '/_statement-body.php'; ?>
  <?php if ($statement === 'income' && $class !== ''): ?><p class="small muted"><?= te('One project: only the lines that carry its name. Costs not booked to a project are not in it.') ?></p><?php endif; ?>
  <?php if ($statement === 'cash'): ?><p class="small muted"><?= te('Direct method: every journal that moves the bank account, by the account on its other side.') ?></p><?php endif; ?>
</div>
<?php else: ?>
<div class="card scroll" id="budget-variance">
  <h2><?= te('Budget and actual') ?> · <?= e($bfrom) ?> – <?= e($bto) ?></h2>
  <form method="get" class="row"><input type="hidden" name="view" value="budget"><input type="month" name="from" value="<?= e($bfrom) ?>"><input type="month" name="to" value="<?= e($bto) ?>"><button class="btn ghost"><?= te('Show') ?></button></form>
  <?php if (! $figures['rows']): ?><p class="muted"><?= te('No budget and nothing posted in these months.') ?></p><?php else: ?>
  <table><tr><th><?= te('Account') ?></th><th class="num"><?= te('Budget') ?></th><th class="num"><?= te('Actual') ?></th><th class="num"><?= te('Variance') ?></th><th class="num">%</th>
    <?php foreach ($figures['months'] as $m): ?><th class="num small"><?= e($m) ?></th><?php endforeach; ?></tr>
  <?php foreach ($figures['rows'] as $r): ?>
    <tr data-account="<?= e($r['key']) ?>" data-budget="<?= e($num($r['budget'])) ?>" data-actual="<?= e($num($r['actual'])) ?>" data-variance="<?= e($num($r['variance'])) ?>">
      <td><?= e(trim($r['number'] . ' ' . t($r['label']))) ?></td><td class="num mono"><?= $r['budgeted'] ? e(money($r['budget'])) : '<span class="muted">-</span>' ?></td><td class="num mono"><?= e(money($r['actual'])) ?></td>
      <td class="num"><?php if ($r['budgeted']): ?><span class="tag <?= $r['variance'] >= 0 ? 'green' : 'red' ?>" data-favourable="<?= $r['variance'] >= 0 ? '1' : '0' ?>"><?= e(money($r['variance'])) ?></span><?php endif; ?></td>
      <td class="num mono small"><?= $r['percent'] !== null ? e(number_format($r['percent'], 1)) : '' ?></td>
      <?php foreach ($r['cells'] as $m => $c): ?><td class="num mono small" data-month="<?= e($m) ?>"><?= e(money($c['actual'])) ?><?= $c['budget'] !== null ? '<div class="muted">' . e(money($c['budget'])) . '</div>' : '' ?></td><?php endforeach; ?></tr>
  <?php endforeach; ?></table>
  <p class="small muted"><?= te('Each month shows the actual, with the budget beneath. A positive variance is favourable: more income than budgeted, or a cost under its budget.') ?></p>
  <?php endif; ?>
</div>
<div class="grid g2">
  <form class="card" method="post" id="budget-form"><?= csrf_field() ?><input type="hidden" name="do" value="budget">
    <h2><?= te('Set a budget') ?></h2>
    <label><?= te('Account') ?></label><select name="account" required><?php foreach ($budgetAccounts as $k => $a): ?><option value="<?= e($k) ?>"><?= e(trim($a['number'] . ' ' . t($a['label']))) ?></option><?php endforeach; ?></select>
    <div class="row"><label><?= te('From') ?></label><input type="month" name="from" value="<?= e($bfrom) ?>" required><label><?= te('To') ?></label><input type="month" name="to" value="<?= e($bfrom) ?>" required></div>
    <label><?= te('Amount for each month') ?></label><input name="amount" inputmode="decimal" required>
    <label><?= te('Note') ?></label><input name="note" maxlength="500">
    <button class="btn"><?= te('Save') ?></button>
  </form>
  <div class="card scroll" id="budget-history"><h2><?= te('Changes') ?></h2>
    <?php if (! $history): ?><p class="muted"><?= te('No budget set yet.') ?></p><?php else: ?>
    <table class="small"><?php foreach ($history as $h): ?><tr data-budget-event="<?= e($h['account_key'] . ':' . $h['period']) ?>"><td><?= e(d(substr((string) $h['created_at'], 0, 10))) ?></td><td><?= e(isset($accounts[$h['account_key']]) ? trim($accounts[$h['account_key']]['number'] . ' ' . t($accounts[$h['account_key']]['label'])) : $h['account_key']) ?></td>
      <td><?= e($h['period']) ?></td><td class="num mono"><?= $h['old_amount'] !== null ? e(money($h['old_amount'])) . ' → ' : '' ?><?= e(money($h['new_amount'])) ?></td><td><?= e((string) $h['note']) ?></td><td><?= e((string) ($h['by_name'] ?? '')) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
  </div>
</div>
<?php endif; ?>
