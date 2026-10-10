<?php
$cats = procurement_categories();
$soon = date('Y-m-d', strtotime('+30 days'));
$tone = ['pending' => 'amber', 'approved' => 'green', 'suspended' => 'grey'];
$who = ['budget_owner' => t('The budget owner'), 'admin' => t('An administrator'), 'both' => t('The budget owner and an administrator, two people')];
?>
<h1><?= te('Vendors') ?></h1>
<p class="sub"><?= te('Orders go only to an approved vendor, approved for what is bought, with a W-9 on file and insurance in date. Quotations stay notes on a request: there is no bid analysis.') ?></p>

<div class="card scroll" id="vendor-list">
  <?php if (! $vendors): ?><p class="muted"><?= te('No vendor registered yet.') ?></p><?php else: ?>
  <table><tr><th><?= te('Vendor') ?></th><th><?= te('Supplies') ?></th><th><?= te('W-9') ?></th><th><?= te('Insurance until') ?></th><th class="num"><?= te('Orders') ?></th><th><?= te('State') ?></th><th></th></tr>
  <?php foreach ($vendors as $v): $expired = $v['insurance_expires'] && $v['insurance_expires'] < date('Y-m-d'); ?>
    <tr data-vendor="<?= e($v['name']) ?>" data-status="<?= e($v['status']) ?>">
      <td><a href="/vendors?id=<?= (int) $v['id'] ?>"><?= e($v['name']) ?></a><?= $v['contact'] ? '<div class="small muted">' . e($v['contact']) . '</div>' : '' ?><?= $v['note'] ? '<div class="small muted">' . e($v['note']) . '</div>' : '' ?></td>
      <td class="small"><?= e(implode(', ', array_map(fn($c) => $cats[$c] ?? $c, array_filter(explode(',', (string) $v['categories']))))) ?></td>
      <td><?= (int) $v['w9_on_file'] === 1 ? te('On file') : '<span class="err">' . te('Missing') . '</span>' ?></td>
      <td<?= $expired ? ' class="err"' : ($v['insurance_expires'] && $v['insurance_expires'] < $soon ? ' class="warn"' : '') ?>><?= $v['insurance_expires'] ? e(d((string) $v['insurance_expires'])) . ($expired ? ' · ' . te('expired') : '') : '—' ?></td>
      <td class="num mono"><?= (int) $v['orders'] ?></td>
      <td><span class="tag <?= $tone[$v['status']] ?>"><?= e(vendor_statuses()[$v['status']]) ?></span><?= $v['decided_by_name'] ? '<div class="small muted">' . e($v['decided_by_name']) . '</div>' : '' ?></td>
      <td><?php if (can('admin')): ?>
        <?php if ($v['status'] === 'pending'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="approve"><input type="hidden" name="vendor_id" value="<?= (int) $v['id'] ?>"><button class="btn sm"><?= te('Approve') ?></button></form>
        <?php else: ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="<?= $v['status'] === 'approved' ? 'suspend' : 'reinstate' ?>"><input type="hidden" name="vendor_id" value="<?= (int) $v['id'] ?>"><input name="reason" required minlength="3" maxlength="500" placeholder="<?= te('Why') ?>" style="max-width:9em"><button class="btn ghost sm"><?= $v['status'] === 'approved' ? te('Suspend') : te('Reinstate') ?></button></form><?php endif; ?>
      <?php endif; ?></td></tr>
  <?php endforeach; ?></table><?php endif; ?>
</div>

<div class="grid g2">
  <form class="card" method="post" id="vendor-form"><?= csrf_field() ?><input type="hidden" name="do" value="save"><?php if ($edit): ?><input type="hidden" name="vendor_id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
    <h2><?= $edit ? te('Change :name', ['name' => $edit['name']]) : te('Propose a vendor') ?></h2>
    <label><?= te('Name, as on its invoices') ?></label><input name="name" required minlength="2" maxlength="190" value="<?= e($edit['name'] ?? '') ?>">
    <label><?= te('Supplies') ?></label><div class="row"><?php foreach ($cats as $k => $l): ?><label class="small"><input type="checkbox" name="categories[]" value="<?= e($k) ?>"<?= $edit && in_array($k, explode(',', (string) $edit['categories']), true) ? ' checked' : '' ?>> <?= e($l) ?></label><?php endforeach; ?></div>
    <div class="grid g2">
      <div><label><input type="checkbox" name="w9_on_file" value="1"<?= (int) ($edit['w9_on_file'] ?? 0) === 1 ? ' checked' : '' ?>> <?= te('W-9 on file') ?></label></div>
      <div><label><?= te('Insurance until') ?></label><input type="date" name="insurance_expires" value="<?= e((string) ($edit['insurance_expires'] ?? '')) ?>"></div>
    </div>
    <label><?= te('Contact') ?></label><input name="contact" maxlength="190" value="<?= e((string) ($edit['contact'] ?? '')) ?>">
    <label><?= te('Note') ?></label><input name="note" maxlength="500" value="<?= e((string) ($edit['note'] ?? '')) ?>">
    <p class="muted"><?= $edit ? te('Orders and bills already made keep the name they were made with.') : te('A new vendor waits for an administrator other than you to approve it.') ?></p>
    <button class="btn"><?= te('Save') ?></button>
  </form>

  <div class="card" id="thresholds"><h2><?= te('Who approves an order') ?></h2>
    <p class="muted"><?= te('By the order\'s total. An order that goes past a project\'s budget line also needs an administrator.') ?></p>
    <?php if (can('admin')): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="thresholds">
      <table><tr><th><?= te('Up to') ?></th><th><?= te('Approved by') ?></th></tr>
      <?php foreach (array_merge($thresholds, [['up_to' => '', 'approvers' => '']]) as $th): ?>
        <tr><td><input name="up_to[]" type="number" min="0.01" step="0.01" value="<?= e((string) ($th['up_to'] ?? '')) ?>" placeholder="<?= te('no limit') ?>" style="max-width:9em"></td>
          <td><select name="approvers[]"><option value=""></option><?php foreach ($who as $k => $l): ?><option value="<?= e($k) ?>"<?= $th['approvers'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></td></tr>
      <?php endforeach; ?></table>
      <p class="muted"><?= te('Leave the last tier\'s limit empty: it covers every larger order. Orders already raised keep the rule they were raised under.') ?></p>
      <button class="btn"><?= te('Save') ?></button></form>
    <?php else: ?>
    <table><?php foreach ($thresholds as $th): ?><tr><td><?= $th['up_to'] === null ? te('Above') : te('Up to :amount', ['amount' => money($th['up_to'])]) ?></td><td><?= e($who[$th['approvers']]) ?></td></tr><?php endforeach; ?></table>
    <?php endif; ?>
  </div>
</div>

<?php if ($edit): ?>
<div class="card scroll" id="vendor-history"><h2><?= te('History') ?></h2>
  <table><?php foreach ($history as $h): ?><tr data-event="<?= e($h['event']) ?>"><td><?= e(d(substr((string) $h['created_at'], 0, 10))) ?></td><td><?= e(['proposed' => t('Proposed'), 'changed' => t('Details changed'), 'approve' => t('Approved'), 'suspend' => t('Suspended'), 'reinstate' => t('Reinstated')][$h['event']] ?? $h['event']) ?></td><td><?= e((string) $h['detail']) ?></td><td><?= e($h['by_name'] ?? '') ?></td></tr><?php endforeach; ?></table>
</div>
<?php endif; ?>
