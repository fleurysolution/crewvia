<?php
$foPayer = ['agency' => t('Agency'), 'client' => t('Client (rebill)'), 'worker' => t('Worker')];
$foKind = ['room' => t('Room'), 'tax' => t('Tax'), 'incidental' => t('Incidental')];
?>
<?php if (! $foFolio): ?>
<div class="page-head"><div><h1><?= te('Hotel folios') ?></h1>
  <p class="sub"><?= te('Each hotel\'s weekly folio, checked against the rooms booked in Crewvia. Open one and it starts with a line for every guest booked that week; correct the lines to what the hotel billed, explain each difference, then turn it into the hotel\'s bill.') ?></p></div></div>

<form method="post" action="/folios" class="card" id="folio-open"><?= csrf_field() ?><input type="hidden" name="do" value="open">
  <h2><?= te('Open a folio') ?></h2>
  <div class="row">
    <div style="flex:2"><label for="fo-hotel"><?= te('Hotel') ?></label><select id="fo-hotel" name="hotel_id" required><option value=""><?= te('Choose') ?></option><?php foreach ($foHotels as $h): ?><option value="<?= (int) $h['id'] ?>"><?= e($h['name']) ?></option><?php endforeach; ?></select></div>
    <div style="max-width:180px"><label for="fo-week"><?= te('Any day of the week') ?></label><input id="fo-week" name="week" type="date" value="<?= e(date('Y-m-d', strtotime('-7 days'))) ?>" required></div>
    <div style="max-width:200px"><label for="fo-ref"><?= te('Folio number') ?></label><input id="fo-ref" name="reference" maxlength="190" required></div>
    <div style="max-width:170px"><label for="fo-due"><?= te('Payment due') ?></label><input id="fo-due" name="due_on" type="date"></div>
  </div>
  <button class="btn"><?= te('Open the folio') ?></button>
</form>

<?php if (! $foList): ?>
<div class="card empty-state" id="no-folios"><h3><?= te('No folio yet') ?></h3><p><?= te('Folios opened for this project appear here, week by week.') ?></p></div>
<?php else: ?>
<div class="card tight" id="folios"><table>
  <tr><th><?= te('Week ending') ?></th><th><?= te('Hotel') ?></th><th><?= te('Folio number') ?></th><th class="num"><?= te('Billed') ?></th><th class="num"><?= te('Booked') ?></th><th><?= te('Status') ?></th></tr>
  <?php foreach ($foList as $f): ?><tr data-folio="<?= (int) $f['id'] ?>">
    <td><a href="/folios?folio=<?= (int) $f['id'] ?>"><?= e(d((string) $f['week_ending'])) ?></a></td><td><?= e($f['hotel']) ?></td><td><?= e($f['reference']) ?></td>
    <td class="num"><?= e(money($f['totals']['billed'])) ?></td><td class="num"><?= e(money($f['totals']['booked'])) ?></td>
    <td><?php if ($f['bill_reference']): ?><span class="tag green"><?= te('Bill in Payables') ?></span><?php elseif ($f['status'] === 'reconciled'): ?><span class="tag blue"><?= te('Reconciled') ?></span><?php elseif ($f['open_lines']): ?><span class="tag amber"><?= te(':n to check', ['n' => $f['open_lines']]) ?></span><?php else: ?><span class="tag"><?= te('Ready to reconcile') ?></span><?php endif; ?></td>
  </tr><?php endforeach; ?>
</table></div>
<?php endif; ?>

<?php else: $f = $foFolio; $foOpen = $f['status'] === 'open'; ?>
<p class="small" style="margin:0 0 6px"><a href="/folios">← <?= te('All folios') ?></a></p>
<div class="page-head"><div><h1 id="folio-title"><?= e($f['hotel']) ?> · <?= te('week ending :date', ['date' => d((string) $f['week_ending'])]) ?></h1>
  <div class="meta"><?= te('Folio :ref', ['ref' => $f['reference']]) ?><?= $f['due_on'] ? ' · ' . te('due :date', ['date' => d((string) $f['due_on'])]) : '' ?> ·
    <span class="tag <?= $f['vendor_invoice_id'] ? 'green' : ($foOpen ? 'amber' : 'blue') ?>" id="folio-status" data-status="<?= e($f['status']) ?>"><?= $f['vendor_invoice_id'] ? te('Bill in Payables') : ($foOpen ? te('Open') : te('Reconciled')) ?></span></div></div>
  <div class="actions">
    <?php if ($foOpen): ?><form method="post" action="/folios"><?= csrf_field() ?><input type="hidden" name="do" value="reconcile"><input type="hidden" name="folio_id" value="<?= (int) $f['id'] ?>"><button class="btn" id="reconcile"><?= te('Reconcile') ?></button></form>
    <?php elseif (! $f['vendor_invoice_id']): ?>
      <form method="post" action="/folios" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="reopen"><input type="hidden" name="folio_id" value="<?= (int) $f['id'] ?>"><button class="btn ghost"><?= te('Reopen') ?></button></form>
      <?php if (can('payroll')): ?><form method="post" action="/folios" style="display:inline" id="make-bill"><?= csrf_field() ?><input type="hidden" name="do" value="bill"><input type="hidden" name="folio_id" value="<?= (int) $f['id'] ?>"><input type="date" name="due_on" value="<?= e((string) ($f['due_on'] ?? '')) ?>" aria-label="<?= te('Payment due') ?>" style="width:auto"> <button class="btn"><?= te('Make it the hotel\'s bill') ?></button></form><?php endif; ?>
    <?php else: ?><a class="btn ghost" href="/accounts-payable"><?= te('Open in Payables') ?></a><?php endif; ?>
  </div>
</div>

<div class="grid g3" id="folio-totals" style="margin-bottom:14px">
  <div class="card"><div class="small muted"><?= te('Billed by the hotel') ?></div><div style="font-size:22px;font-weight:700" data-total="billed"><?= e(money($foTotals['billed'])) ?></div><div class="small muted"><?= te('Rooms as booked: :amount', ['amount' => money($foTotals['booked'])]) ?></div></div>
  <div class="card"><div class="small muted"><?= te('Who pays') ?></div><div class="small"><?= te('Agency :a · Client :c · Workers :w', ['a' => money($foTotals['agency']), 'c' => money($foTotals['client']), 'w' => money($foTotals['worker'])]) ?></div></div>
  <div class="card"><div class="small muted"><?= te('Lines to check') ?></div><div style="font-size:22px;font-weight:700" data-open-lines><?= count(array_filter($foLines, static fn($l) => $l['needs_note'] || $l['needs_charge'])) ?></div></div>
</div>

<div class="card tight" id="folio-lines"><div class="scroll"><table>
  <tr><th><?= te('Line') ?></th><th><?= te('Guest or charge') ?></th><th><?= te('Billed') ?></th><th><?= te('In Crewvia') ?></th><th><?= te('Note or payer') ?></th><th></th></tr>
  <?php foreach ($foLines as $l): $lf = 'line-' . (int) $l['id']; ?><tr data-line="<?= (int) $l['id'] ?>" data-flags="<?= e(implode(' ', $l['flags'])) ?>">
    <td><span class="tag"><?= e($foKind[$l['kind']]) ?></span></td>
    <td><?php if ($l['kind'] === 'room'): ?><strong><?= e((string) $l['guest_name']) ?></strong><div class="small muted"><?= $l['room_number'] ? te('room :n', ['n' => $l['room_number']]) : '' ?></div><?php else: ?><input form="<?= $lf ?>" name="description" value="<?= e((string) $l['description']) ?>" maxlength="255"<?= $foOpen ? '' : ' disabled' ?>><?php endif; ?>
      <?php foreach ($l['flags'] as $flag): ?><div class="small" style="color:<?= $flag === 'absent' ? 'var(--ui-muted,#667)' : '#b42318' ?>" data-flag="<?= e($flag) ?>">• <?= e($foFlags[$flag]) ?><?= $flag === 'absent' ? ' (' . (int) $l['absent_nights'] . ')' : '' ?></div><?php endforeach; ?></td>
    <td style="white-space:nowrap"><?php if ($l['kind'] === 'room'): ?><input form="<?= $lf ?>" name="nights" type="number" min="0" max="7" value="<?= (int) $l['nights'] ?>" style="width:64px"<?= $foOpen ? '' : ' disabled' ?>> × <input form="<?= $lf ?>" name="rate" type="number" step="0.01" min="0" value="<?= e(number_format((float) $l['rate'], 2, '.', '')) ?>" style="width:96px"<?= $foOpen ? '' : ' disabled' ?>><div class="small"><?= e(money($l['amount'])) ?></div>
      <?php else: ?><input form="<?= $lf ?>" name="amount" type="number" step="0.01" min="0.01" value="<?= e(number_format((float) $l['amount'], 2, '.', '')) ?>" style="width:110px"<?= $foOpen ? '' : ' disabled' ?>><?php endif; ?></td>
    <td class="small"><?php if ($l['kind'] === 'room' && $l['booked_nights'] !== null): ?><?= te(':n night(s) at :rate', ['n' => $l['booked_nights'], 'rate' => $l['booked_rate'] !== null ? money($l['booked_rate']) : '—']) ?><?php elseif ($l['kind'] === 'room'): ?><span class="muted"><?= te('Not booked') ?></span><?php else: ?>—<?php endif; ?></td>
    <td><?php if ($l['kind'] === 'incidental'): ?><select form="<?= $lf ?>" name="charge_to" aria-label="<?= te('Who pays') ?>"<?= $foOpen ? '' : ' disabled' ?>><option value=""><?= te('Who pays?') ?></option><?php foreach ($foPayer as $k => $label): ?><option value="<?= $k ?>"<?= $l['charge_to'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
        <select form="<?= $lf ?>" name="placement_id" aria-label="<?= te('Which guest') ?>"<?= $foOpen ? '' : ' disabled' ?>><option value=""><?= te('Which guest') ?></option><?php foreach ($foGuests as $pid => $guest): ?><option value="<?= $pid ?>"<?= (int) $l['placement_id'] === $pid ? ' selected' : '' ?>><?= e($guest) ?></option><?php endforeach; ?></select><?php endif; ?>
      <input form="<?= $lf ?>" name="accepted_note" value="<?= e((string) $l['accepted_note']) ?>" maxlength="500" placeholder="<?= $l['needs_note'] ? te('Why the difference is right') : te('Note') ?>"<?= $l['needs_note'] ? ' style="border-color:#b42318"' : '' ?><?= $foOpen ? '' : ' disabled' ?>></td>
    <td style="white-space:nowrap"><?php if ($foOpen): ?><form method="post" action="/folios" id="<?= $lf ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="line_save"><input type="hidden" name="line_id" value="<?= (int) $l['id'] ?>"><button class="btn ghost sm"><?= te('Save') ?></button></form>
      <form method="post" action="/folios" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="line_delete"><input type="hidden" name="line_id" value="<?= (int) $l['id'] ?>"><button class="btn ghost sm" aria-label="<?= te('Remove the line') ?>">✕</button></form><?php endif; ?></td>
  </tr><?php endforeach; ?>
</table></div></div>

<?php if ($foOpen): ?>
<div class="grid g3" id="folio-add">
  <form method="post" action="/folios" class="card"><?= csrf_field() ?><input type="hidden" name="do" value="line_add"><input type="hidden" name="kind" value="room"><input type="hidden" name="folio_id" value="<?= (int) $f['id'] ?>">
    <h3><?= te('A guest Crewvia did not book') ?></h3>
    <label><?= te('Guest') ?></label><input name="guest_name" maxlength="190" required><div class="row"><div><label><?= te('Room') ?></label><input name="room_number" maxlength="30"></div><div><label><?= te('Nights') ?></label><input name="nights" type="number" min="0" max="7" required></div><div><label><?= te('Rate') ?></label><input name="rate" type="number" step="0.01" min="0" required></div></div>
    <label><?= te('Note') ?></label><input name="accepted_note" maxlength="500"><button class="btn ghost" style="margin-top:6px"><?= te('Add') ?></button></form>
  <form method="post" action="/folios" class="card"><?= csrf_field() ?><input type="hidden" name="do" value="line_add"><input type="hidden" name="kind" value="tax"><input type="hidden" name="folio_id" value="<?= (int) $f['id'] ?>">
    <h3><?= te('A tax') ?></h3>
    <label><?= te('As printed') ?></label><input name="description" maxlength="255" required placeholder="<?= te('Occupancy tax') ?>"><label><?= te('Amount') ?></label><input name="amount" type="number" step="0.01" min="0.01" required><button class="btn ghost" style="margin-top:6px"><?= te('Add') ?></button></form>
  <form method="post" action="/folios" class="card"><?= csrf_field() ?><input type="hidden" name="do" value="line_add"><input type="hidden" name="kind" value="incidental"><input type="hidden" name="folio_id" value="<?= (int) $f['id'] ?>">
    <h3><?= te('An incidental') ?></h3>
    <label><?= te('As printed') ?></label><input name="description" maxlength="255" required placeholder="<?= te('Laundry') ?>"><label><?= te('Amount') ?></label><input name="amount" type="number" step="0.01" min="0.01" required>
    <label><?= te('Who pays') ?></label><select name="charge_to" required><option value=""><?= te('Choose') ?></option><?php foreach ($foPayer as $k => $label): ?><option value="<?= $k ?>"><?= e($label) ?></option><?php endforeach; ?></select>
    <label><?= te('Which guest, when the worker pays') ?></label><select name="placement_id"><option value=""><?= te('Choose') ?></option><?php foreach ($foGuests as $pid => $guest): ?><option value="<?= $pid ?>"><?= e($guest) ?></option><?php endforeach; ?></select>
    <button class="btn ghost" style="margin-top:6px"><?= te('Add') ?></button></form>
</div>
<p class="small muted"><?= te('A charge to a worker is shown to them on their portal with what the hotel printed. Payroll recovers it with an adjustment they approve; nothing is taken from pay on its own.') ?></p>
<?php endif; ?>
<?php endif; ?>
