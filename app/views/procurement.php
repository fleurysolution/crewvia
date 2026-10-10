<?php
$cats = procurement_categories();
$rstat = procurement_request_statuses();
$ostat = procurement_order_statuses();
$rtone = ['requested' => 'blue', 'ordered' => 'amber', 'received' => 'green', 'closed' => 'grey', 'cancelled' => 'grey'];
$otone = ['awaiting_approval' => 'amber', 'approved' => 'green', 'rejected' => 'red', 'closed' => 'grey', 'cancelled' => 'grey'];
$buy = procurement_can_buy();
$qty = static fn($q): string => rtrim(rtrim(number_format((float) $q, 2, '.', ''), '0'), '.');
?>
<h1><?= te('Procurement') ?></h1>
<p class="sub"><?= te('What the job needs - rooms, vehicles, safety equipment and the rest - from the request to the purchase order, its approval by the budget owner, what arrived, and what is still to be billed. There is no bidding: a quotation can be attached, and the order is raised from it.') ?></p>

<?php if (! $job): ?>
<div class="card"><p class="muted"><?= te('Open a project first.') ?></p></div>
<?php else: ?>
<div class="card" id="procurement-settings">
  <h2><?= te('This project') ?></h2>
  <p><?= e($job['title']) ?> · <?= $owner ? te('Budget owner: :name', ['name' => $owner['name']]) : te('No budget owner named: an administrator approves its orders.') ?>
    · <?= (int) ($job['auto_lodging'] ?? 0) === 1 ? te('New hires raise a lodging request automatically.') : te('New hires do not raise lodging requests.') ?></p>
  <?php if (can('admin')): ?>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="settings">
    <label class="small"><?= te('Budget owner') ?> <select name="budget_owner_id"><option value=""><?= te('An administrator') ?></option><?php foreach ($owners as $o): ?><option value="<?= (int) $o['id'] ?>" <?= $owner && (int) $owner['id'] === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?></select></label>
    <label class="small"><input type="checkbox" name="auto_lodging" value="1" <?= (int) ($job['auto_lodging'] ?? 0) === 1 ? 'checked' : '' ?>> <?= te('Raise a lodging request for every new hire') ?></label>
    <button class="btn sm"><?= te('Save') ?></button></form>
  <?php endif; ?>
</div>

<form class="card" method="post" id="new-request"><?= csrf_field() ?><input type="hidden" name="do" value="request">
  <h2><?= te('New request') ?></h2>
  <div class="grid g2">
    <div><label><?= te('What') ?></label><select name="category"><?php foreach ($cats as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div><label><?= te('Title') ?></label><input name="title" required minlength="3" maxlength="190" placeholder="<?= te('for example: Steel-toe boots, size 10') ?>"></div>
    <div><label><?= te('Quantity') ?></label><input name="quantity" type="number" min="0.01" step="0.01" required></div>
    <div><label><?= te('Unit') ?></label><select name="unit_id"><?php foreach ($units as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e(procurement_unit_label($u)) ?></option><?php endforeach; ?></select></div>
    <div><label><?= te('Needed from') ?></label><input type="date" name="needed_from"></div>
    <div><label><?= te('Needed until') ?></label><input type="date" name="needed_to"></div>
    <div><label><?= te('Estimated cost per unit (optional)') ?></label><input name="estimated_unit_cost" type="number" min="0" step="0.01"></div>
    <div><label><?= te('Hotel, for lodging (optional)') ?></label><select name="hotel_id"><option value=""><?= te('Not chosen yet') ?></option><?php foreach ($hotels as $h): ?><option value="<?= (int) $h['id'] ?>"><?= e($h['name']) ?></option><?php endforeach; ?></select></div>
  </div>
  <label><?= te('Details') ?></label><input name="description" maxlength="1000">
  <button class="btn"><?= te('Raise request') ?></button>
</form>

<div class="card scroll" id="requests">
  <h2><?= te('Requests') ?></h2>
  <?php if (! $requests): ?><p class="muted"><?= te('No request on this project yet.') ?></p><?php endif; ?>
  <?php foreach ($requests as $r): $qs = $quotes[(int) $r['id']] ?? []; ?>
  <div class="row" style="border-bottom:1px solid var(--line);padding:8px 0;align-items:flex-start" data-request="<?= (int) $r['id'] ?>" data-status="<?= e($r['status']) ?>">
    <div style="min-width:260px">
      <strong><?= e($r['title']) ?></strong> <span class="tag <?= $rtone[$r['status']] ?>"><?= e($rstat[$r['status']]) ?></span><?= $r['source'] === 'hire' ? ' <span class="tag grey">' . te('raised by a hire') . '</span>' : '' ?>
      <div class="small muted"><?= e($cats[$r['category']]) ?> · <?= e($qty($r['quantity'])) ?> <?= e(t((string) $r['unit_label'])) ?><?= $r['needed_from'] ? ' · ' . e(d($r['needed_from'])) . ($r['needed_to'] ? ' – ' . e(d($r['needed_to'])) : '') : '' ?><?= $r['for_person'] ? ' · ' . te('for :name', ['name' => $r['for_person']]) : '' ?><?= $r['hotel_name'] ? ' · ' . e($r['hotel_name']) : '' ?><?= $r['reference'] ? ' · ' . e($r['reference']) : '' ?></div>
      <?php if ($r['cancel_reason']): ?><div class="small muted"><?= te('Cancelled: :why', ['why' => $r['cancel_reason']]) ?></div><?php endif; ?>
      <?php foreach ($qs as $q): ?><div class="small"><?= te('Quote: :vendor at :price', ['vendor' => $q['vendor_name'], 'price' => money($q['unit_price'])]) ?><?= $q['note'] ? ' · ' . e($q['note']) : '' ?></div><?php endforeach; ?>
    </div>
    <?php if ($buy && $r['status'] === 'requested'): $best = $qs[0] ?? null; ?>
    <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="quote"><input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
      <input name="vendor_name" required maxlength="190" placeholder="<?= te('Vendor') ?>" aria-label="<?= te('Vendor') ?>" style="max-width:10em"><input name="unit_price" type="number" min="0" step="0.01" required placeholder="<?= te('Unit price') ?>" aria-label="<?= te('Unit price') ?>" style="max-width:7em"><button class="btn ghost sm"><?= te('Add quote') ?></button></form>
    <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="order"><input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
      <input name="vendor_name" required maxlength="190" list="approved-vendors" value="<?= e($best['vendor_name'] ?? '') ?>" placeholder="<?= te('Approved vendor') ?>" aria-label="<?= te('Vendor') ?>" style="max-width:10em">
      <input name="unit_price" type="number" min="0" step="0.01" required value="<?= e((string) ($best['unit_price'] ?? $r['estimated_unit_cost'] ?? '')) ?>" placeholder="<?= $r['category'] === 'lodging' ? te('Per room per night') : te('Unit price') ?>" aria-label="<?= te('Unit price') ?>" style="max-width:8em">
      <?php if ($r['category'] === 'lodging'): ?><select name="hotel_id" aria-label="<?= te('Hotel') ?>"><option value=""><?= te('Hotel') ?></option><?php foreach ($hotels as $h): ?><option value="<?= (int) $h['id'] ?>" <?= (int) $r['hotel_id'] === (int) $h['id'] ? 'selected' : '' ?>><?= e($h['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
      <details class="small"><summary><?= te('Split across projects') ?></summary><?php for ($k = 0; $k < 3; $k++): ?><div class="row"><select name="share_job[]" aria-label="<?= te('Project') ?>"><option value=""><?= te('Project') ?></option><?php foreach ($otherJobs as $oj): ?><option value="<?= (int) $oj['id'] ?>"><?= e($oj['title']) ?></option><?php endforeach; ?></select><input name="share_amount[]" type="number" min="0.01" step="0.01" placeholder="<?= te('Share') ?>" aria-label="<?= te('Share') ?>" style="max-width:8em"></div><?php endfor; ?><span class="muted"><?= te('Empty, this project carries the whole order. The shares add up to the order\'s total.') ?></span></details>
      <button class="btn sm"><?= te('Raise purchase order') ?></button></form>
    <?php endif; ?>
    <?php if ($buy && in_array($r['status'], ['requested', 'ordered'], true)): ?>
    <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>"><input name="reason" required minlength="3" maxlength="500" placeholder="<?= te('Why cancelled') ?>" aria-label="<?= te('Reason') ?>" style="max-width:10em"><button class="btn ghost sm"><?= te('Cancel') ?></button></form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<datalist id="approved-vendors"><?php foreach ($vendorNames as $vn): ?><option value="<?= e($vn) ?>"><?php endforeach; ?></datalist>
<div class="card scroll" id="orders">
  <h2><?= te('Purchase orders') ?></h2>
  <?php if (! $orders): ?><p class="muted"><?= te('No purchase order on this project yet.') ?></p><?php else: ?>
  <table>
    <tr><th><?= te('Order') ?></th><th><?= te('Vendor') ?></th><th class="num"><?= te('Quantity') ?></th><th class="num"><?= te('Total') ?></th><th><?= te('Status') ?></th><th></th></tr>
    <?php foreach ($orders as $o): ?>
    <tr data-order="<?= (int) $o['id'] ?>" data-status="<?= e($o['status']) ?>">
      <td><strong><?= e($o['reference']) ?></strong><?= (int) $o['over_budget'] === 1 ? ' <span class="tag red">' . te('Over budget') . '</span>' : '' ?><div class="small"><?= e($o['title']) ?></div><?php if (count($o['shares']) > 1): ?><div class="small" data-shares="<?= count($o['shares']) ?>"><?php foreach ($o['shares'] as $sh): ?><?= e($sh['title'] . ' ' . money($sh['amount'])) ?> · <?php endforeach; ?></div><?php endif; ?><?php foreach ($o['given'] as $g): ?><div class="small muted"><?= te('Approved as :as by :name', ['as' => $g['approver'] === 'admin' ? t('administrator') : t('budget owner'), 'name' => $g['name']]) ?></div><?php endforeach; ?><div class="small muted"><?= te('Raised by :name', ['name' => $o['created_by_name'] ?? '—']) ?><?= $o['decided_by_name'] ? ' · ' . te('decided by :name', ['name' => $o['decided_by_name']]) : '' ?><?= $o['decision_note'] ? ' · ' . e($o['decision_note']) : '' ?></div></td>
      <td><?= e($o['vendor_name']) ?><?= $o['hotel_name'] ? '<div class="small muted">' . e($o['hotel_name']) . '</div>' : '' ?></td>
      <td class="num mono"><?= e($qty($o['received'])) ?> / <?= e($qty($o['quantity'])) ?> <?= e(t((string) $o['unit_label'])) ?></td>
      <td class="num mono"><?= e(money($o['total'])) ?><div class="small muted"><?= e(money($o['unit_price'])) ?></div></td>
      <td><span class="tag <?= $otone[$o['status']] ?>"><?= e($ostat[$o['status']]) ?></span></td>
      <td>
        <?php if ($o['can_decide']): ?>
          <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="purchase_order_id" value="<?= (int) $o['id'] ?>"><input name="note" maxlength="500" placeholder="<?= te('Note (needed to reject)') ?>" aria-label="<?= te('Note') ?>" style="max-width:10em">
            <button class="btn sm" name="do" value="approve"><?= te('Approve') ?></button><button class="btn ghost sm" name="do" value="reject"><?= te('Reject') ?></button></form>
        <?php elseif ($o['status'] === 'awaiting_approval'): ?>
          <span class="small muted"><?= te('Waiting for :who', ['who' => implode(' ' . t('and') . ' ', array_map(fn($n) => $n === 'admin' ? t('an administrator') : t('the budget owner'), $o['needed']))]) ?></span>
        <?php endif; ?>
        <?php if ($buy && $o['status'] === 'approved'): ?>
          <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="receive"><input type="hidden" name="purchase_order_id" value="<?= (int) $o['id'] ?>">
            <input name="quantity" type="number" min="0.01" step="0.01" required placeholder="<?= $o['category'] === 'lodging' ? te('Rooms confirmed') : te('Quantity arrived') ?>" aria-label="<?= te('Quantity') ?>" style="max-width:8em"><input type="date" name="received_on" required value="<?= e(date('Y-m-d')) ?>" aria-label="<?= te('Date') ?>"><button class="btn sm"><?= $o['category'] === 'lodging' ? te('Rooms available') : te('Received') ?></button></form>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="close"><input type="hidden" name="purchase_order_id" value="<?= (int) $o['id'] ?>"><button class="btn ghost sm"><?= te('Close') ?></button></form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<div class="card scroll" id="commitments">
  <h2><?= te('Commitments') ?></h2>
  <p class="muted"><?= te('What approved orders commit the project to, against the vendor invoices recorded for them on Vendor payments.') ?></p>
  <?php if (! $commitments): ?><p class="muted"><?= te('No approved order yet, so nothing is committed.') ?></p><?php else: ?>
  <table>
    <tr><th><?= te('Order') ?></th><th class="num"><?= te('Ordered') ?></th><th class="num"><?= te('Invoiced') ?></th><th class="num"><?= te('Still to be billed') ?></th></tr>
    <?php foreach ($commitments as $c): $open = round((float) $c['total'] - (float) $c['invoiced'], 2); ?>
    <tr data-commitment="<?= (int) $c['id'] ?>"><td><?= e($c['reference']) ?> · <?= e($c['title']) ?></td><td class="num mono"><?= e(money($c['total'])) ?></td><td class="num mono"><?= e(money($c['invoiced'])) ?></td><td class="num mono" data-k="open"><?= e(money($open)) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<div class="card" id="units">
  <h2><?= te('Units of measure') ?></h2>
  <p class="small"><?= e(implode(' · ', array_map('procurement_unit_label', $units))) ?></p>
  <?php if ($buy): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="unit"><input name="label" required minlength="2" maxlength="80" placeholder="<?= te('for example: Box') ?>" aria-label="<?= te('Unit') ?>"><button class="btn ghost sm"><?= te('Add unit') ?></button></form><?php endif; ?>
</div>
<?php endif; ?>
