<?php
$categories = expense_categories();
$statuses = expense_statuses();
$methods = expense_methods();
$tagFor = ['submitted' => 'amber', 'approved' => 'blue', 'rejected' => 'red', 'paid' => 'green'];
?>
<div class="page-head"><div>
<h1><?= $own ? te('My reimbursements') : te('Reimbursements') ?></h1>
<p class="sub"><?= $own ? te('What you paid yourself for the job: a bag at the airport, a taxi, a screening. Keep the receipt; you are paid back on your next pay.')
                       : te('Claims filed by workers with their receipt. Approve, then pay on the next pay or record a separate payment.') ?></p>
</div></div>

<?php if ($own): ?>
<form class="card" method="post" id="new-claim" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="do" value="claim">
  <h2 class="m0 mb-s"><?= te('Claim a reimbursement') ?></h2>
  <?php if (! $assignments): ?>
    <p class="muted m0"><?= te('A claim is filed for an assignment. You have none yet.') ?></p>
  <?php else: ?>
  <div class="grid g2">
    <div><label for="ex-assignment"><?= te('Assignment') ?></label><select id="ex-assignment" name="placement_id"><?php foreach ($assignments as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['title']) ?></option><?php endforeach; ?></select></div>
    <div><label for="ex-category"><?= te('What for') ?></label><select id="ex-category" name="category"><?php foreach ($categories as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div><label for="ex-amount"><?= te('Amount') ?></label><input id="ex-amount" name="amount" type="number" step="0.01" min="0.01" max="100000" required></div>
    <div><label for="ex-photo"><?= te('Receipt: take a photo or choose the file') ?></label><input id="ex-photo" name="receipt" type="file" accept="image/jpeg,image/png,application/pdf" <?= $receipts ? '' : 'required' ?>>
      <?php if ($receipts): ?><label for="ex-receipt" class="small mt-xs"><?= te('or one already uploaded') ?></label><select id="ex-receipt" name="receipt_document_id"><option value="0"><?= te('None') ?></option><?php foreach ($receipts as $r): ?><option value="<?= (int) $r['id'] ?>"><?= e($r['document_type'] . ' · ' . d(substr((string) $r['created_at'], 0, 10))) ?></option><?php endforeach; ?></select><?php endif; ?></div>
  </div>
  <label for="ex-note"><?= te('Details (optional)') ?></label><textarea id="ex-note" name="note" maxlength="2000" rows="2"></textarea>
  <button class="btn mt"><?= te('Send the claim') ?></button>
  <?php endif; ?>
</form>
<?php endif; ?>

<?php if (! $claims): ?>
  <div class="card"><div class="empty"><?= te('No claim yet.') ?></div></div>
<?php endif; ?>

<?php foreach ($claims as $c): $status = (string) $c['status']; ?>
<div class="card" id="claim-<?= (int) $c['id'] ?>" data-claim="<?= (int) $c['id'] ?>" data-status="<?= e($status) ?>">
  <div class="page-heading m0 mb-s"><div>
    <h2 class="m0"><?= e(($own ? '' : $c['full_name'] . ' · ') . ($categories[$c['category']] ?? $c['category'])) ?> · <span class="mono"><?= e(money($c['amount'])) ?></span></h2>
    <p class="small muted m0 mt-xs"><?= e($c['title']) ?> · <?= te('claim #:id, filed :date', ['id' => (int) $c['id'], 'date' => d(substr((string) $c['created_at'], 0, 10))]) ?>
      <?php if ($c['receipt_document_id']): ?> · <a href="/proofs?download=<?= (int) $c['receipt_document_id'] ?>"><?= te('Receipt') ?></a><?php endif; ?></p>
  </div><span class="tag <?= $tagFor[$status] ?? 'grey' ?>"><?= e($statuses[$status] ?? $status) ?></span></div>

  <?php if (trim((string) $c['note']) !== ''): ?><p class="small m0 mb-s"><?= e($c['note']) ?></p><?php endif; ?>

  <?php if ($status === 'paid' && $c['payment_method'] === 'payroll'): ?>
    <p class="small m0" data-paid-on-pay><?= te('Paid on the pay of the week ending :date.', ['date' => d((string) $c['paid_week'])]) ?>
      <?php if ($own): ?><a href="/my-payslips"><?= te('My pay statements') ?></a><?php else: ?><a href="/payroll-runs?id=<?= (int) $c['run_id'] ?>#adjustments"><?= te('Pay period') ?></a><?php endif; ?></p>
  <?php elseif ($status === 'paid'): ?>
    <p class="small m0"><?= te('Paid by :method on :date.', ['method' => $methods[$c['payment_method']] ?? (string) $c['payment_method'], 'date' => d(substr((string) $c['paid_at'], 0, 10))]) ?> · <?= e((string) $c['payment_reference']) ?></p>
  <?php elseif ($status !== 'submitted' && $c['reviewer']): ?>
    <p class="small muted m0"><?= te('Reviewed by :name', ['name' => $c['reviewer']]) ?></p>
  <?php endif; ?>

  <?php if (! $own && $status === 'submitted'): ?>
    <?php if ((int) $c['user_id'] === uid()): ?><p class="small muted m0"><?= te('Your own claim: another person in payroll reviews it.') ?></p><?php else: ?>
    <div class="row mt-s">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="review"><input type="hidden" name="claim_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="status" value="approved"><button class="btn sm"><?= te('Approve') ?></button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="review"><input type="hidden" name="claim_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="status" value="rejected"><button class="btn sm ghost"><?= te('Do not approve') ?></button></form>
    </div>
    <?php endif; ?>
  <?php elseif (! $own && $status === 'approved'): ?>
    <?php if ($runs): ?>
    <form method="post" class="row mt-s" data-pay-on-pay><?= csrf_field() ?><input type="hidden" name="do" value="pay_on_pay"><input type="hidden" name="claim_id" value="<?= (int) $c['id'] ?>">
      <label class="small m0" for="run-<?= (int) $c['id'] ?>"><?= te('Pay on the pay of the week ending') ?></label>
      <select id="run-<?= (int) $c['id'] ?>" name="run_id"><?php foreach ($runs as $r): ?><option value="<?= (int) $r['id'] ?>"><?= e(d((string) $r['week_ending'])) ?></option><?php endforeach; ?></select>
      <button class="btn sm"><?= te('Pay on the pay') ?></button></form>
    <?php else: ?>
    <p class="small muted m0 mt-s"><?= te('To pay it on the pay, open the week on') ?> <a href="/payroll-runs"><?= te('Pay periods') ?></a>.</p>
    <?php endif; ?>
    <details class="mt-s"><summary class="small"><?= te('Paid another way') ?></summary>
      <form method="post" class="row mt-s"><?= csrf_field() ?><input type="hidden" name="do" value="pay"><input type="hidden" name="claim_id" value="<?= (int) $c['id'] ?>">
        <select name="payment_method"><?php foreach ($methods as $k => $v): if ($k === 'payroll') { continue; } ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select>
        <input name="payment_reference" required minlength="2" maxlength="190" placeholder="<?= te('Payment reference') ?>">
        <button class="btn sm ghost"><?= te('Record payment') ?></button></form>
    </details>
  <?php endif; ?>
</div>
<?php endforeach; ?>
