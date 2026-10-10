<?php
$labels = match_labels();
$figures = static function (array $m): string {
    if ($m['two_way']) {
        return t('No order: approval only');
    }
    return t('Ordered :o · received :r · billed so far :b', ['o' => money($m['ordered']), 'r' => money($m['received_value']), 'b' => money($m['billed_through'])]);
};
?>
<h1><?= te('Bill matching') ?></h1>
<p class="sub"><?= te('A bill against an order is paid only when it matches the order and what was received, within the tolerance, or when an administrator has cleared its exceptions with a reason.') ?>
  <?= te('Tolerance: :p % or :a, whichever is more.', ['p' => rtrim(rtrim(number_format($tolerance['percent'], 2), '0'), '.'), 'a' => money($tolerance['amount'])]) ?></p>

<div class="grid g4" style="margin-bottom:16px">
  <div class="stat"><div class="n" data-count="exceptions"><?= count($exceptions) ?></div><div class="l"><?= te('Exceptions to clear') ?></div></div>
  <div class="stat"><div class="n" data-count="ready"><?= count($ready) ?></div><div class="l"><?= te('Ready to pay') ?></div></div>
  <div class="stat"><div class="n"><?= e(money(array_sum(array_map(fn($b) => (float) $b['amount'], $ready)))) ?></div><div class="l"><?= te('Ready to pay, in all') ?></div></div>
</div>

<div class="card scroll" id="exceptions"><h2><?= te('Exceptions') ?></h2>
  <?php if (! $exceptions): ?><p class="muted"><?= te('No bill is held by an exception.') ?></p><?php else: ?>
  <table><tr><th><?= te('Bill') ?></th><th><?= te('Order') ?></th><th class="num"><?= te('Amount') ?></th><th><?= te('What does not match') ?></th><th></th></tr>
  <?php foreach ($exceptions as $b): ?>
    <tr data-bill="<?= e($b['reference']) ?>" data-codes="<?= e(implode(',', $b['match']['codes'])) ?>">
      <td><?= e($b['vendor_name'] . ' · ' . $b['reference']) ?><div class="small muted"><?= e($b['project']) ?> · <?= e($b['status']) ?></div></td>
      <td><?= e((string) ($b['po_reference'] ?? '—')) ?><div class="small muted"><?= e($figures($b['match'])) ?></div></td>
      <td class="num mono"><?= e(money($b['amount'])) ?></td>
      <td class="small"><?php foreach ($b['match']['codes'] as $c): ?><div class="err"><?= e($labels[$c]) ?></div><?php endforeach; ?></td>
      <td><?php if (can('admin')): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="clear"><input type="hidden" name="invoice_id" value="<?= (int) $b['id'] ?>"><input name="reason" required minlength="3" maxlength="500" placeholder="<?= te('Why it is paid anyway') ?>" style="max-width:12em"><button class="btn sm"><?= te('Clear') ?></button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></table><?php endif; ?>
</div>

<div class="card scroll" id="open-bills"><h2><?= te('Open bills') ?></h2>
  <?php if (! $bills): ?><p class="muted"><?= te('No open bill.') ?></p><?php else: ?>
  <table><tr><th><?= te('Bill') ?></th><th><?= te('Due') ?></th><th class="num"><?= te('Amount') ?></th><th><?= te('Match') ?></th><th><?= te('Payment') ?></th></tr>
  <?php foreach ($bills as $b): $m = $b['match']; ?>
    <tr data-open-bill="<?= e($b['reference']) ?>" data-ready="<?= $m['ready'] ? '1' : '0' ?>">
      <td><?= e($b['vendor_name'] . ' · ' . $b['reference']) ?><div class="small muted"><?= e($b['project']) ?><?= $b['po_reference'] ? ' · ' . e($b['po_reference']) : '' ?></div></td>
      <td><?= e(d((string) $b['due_on'])) ?></td><td class="num mono"><?= e(money($b['amount'])) ?></td>
      <td class="small"><?= $m['two_way'] ? te('No order: approval only') : ($m['matched'] ? '<span class="tag green">' . te('Matched') . '</span>'
          : ($m['clearance'] ? '<span class="tag amber">' . te('Exception cleared') . '</span> ' . e($m['clearance']['reason'] . ' · ' . ($m['clearance']['by_name'] ?? '')) : '<span class="tag red">' . te('Exception') . '</span>')) ?>
        <?= $m['two_way'] ? '' : '<div class="muted">' . e($figures($m)) . '</div>' ?></td>
      <td><?= $m['ready'] ? '<span class="tag green">' . te('Ready to pay') . '</span>' : ($b['status'] === 'received' ? te('Waiting for approval') : '<span class="tag grey">' . te('Held') . '</span>') ?></td></tr>
  <?php endforeach; ?></table><?php endif; ?>
</div>

<?php if (can('admin')): ?>
<form class="card" method="post" id="tolerance-form"><?= csrf_field() ?><input type="hidden" name="do" value="tolerance">
  <h2><?= te('Tolerance') ?></h2>
  <div class="grid g2"><div><label><?= te('Percent') ?></label><input name="percent" type="number" min="0" max="25" step="0.01" value="<?= e((string) $tolerance['percent']) ?>"></div>
    <div><label><?= te('Amount') ?></label><input name="amount" type="number" min="0" max="10000" step="0.01" value="<?= e((string) $tolerance['amount']) ?>"></div></div>
  <p class="muted"><?= te('A difference up to the larger of the two is not an exception.') ?></p>
  <button class="btn"><?= te('Save') ?></button>
</form>
<?php endif; ?>
