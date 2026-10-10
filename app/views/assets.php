<?php
$tagFor = static fn(string $s): string => ['available' => 'green', 'issued' => 'blue', 'in_repair' => 'amber', 'lost' => 'red', 'retired' => 'grey'][$s] ?? 'grey';
$overdue = static fn(?string $due): bool => $due !== null && $due !== '' && $due < date('Y-m-d');
?>
<?php if (isset($item)): ?>
<p><a href="/assets">← <?= te('Assets') ?></a></p>
<h1><?= e($item['name']) ?> <span class="mono"><?= e($item['asset_tag']) ?></span></h1>
<p class="sub"><span class="tag <?= $tagFor($item['state']) ?>" id="asset-state" data-state="<?= e($item['state']) ?>"><?= e($statuses[$item['state']]) ?></span>
  <?php if ($holder): ?> <?= te('with :name on :project since :date', ['name' => $holder['full_name'], 'project' => $holder['project'], 'date' => d(substr((string) $holder['issued_at'], 0, 10))]) ?><?php endif; ?></p>
<div class="grid g2">
  <div class="card">
    <h2><?= te('Details') ?></h2>
    <table>
      <tr><th><?= te('Category') ?></th><td><?= ($item['category'] ? te($item['category']) : te('Uncategorised')) ?></td></tr>
      <tr><th><?= te('Equipment owner') ?></th><td><?= $item['owner_type'] === 'client' ? te('Client') : te('Agency') ?></td></tr>
      <tr><th><?= te('Serial number') ?></th><td class="mono"><?= e($item['serial_number'] ?? '—') ?></td></tr>
      <tr><th><?= te('Bought') ?></th><td><?= $item['purchase_date'] ? e(d((string) $item['purchase_date'])) : '—' ?><?= $item['purchase_cost'] !== null ? ' · ' . e(money($item['purchase_cost'])) : '' ?><?= $order ? ' · ' . e($order['reference']) : '' ?></td></tr>
      <tr><th><?= te('Next inspection') ?></th><td id="inspection-due"<?= $overdue($item['inspection_due']) ? ' class="err"' : '' ?>><?= $item['inspection_due'] ? e(d((string) $item['inspection_due'])) . ($overdue($item['inspection_due']) ? ' · ' . te('overdue') : '') : '—' ?></td></tr>
    </table>
  </div>
  <?php if (can('hotels')): ?>
  <form class="card" method="post" id="asset-action"><?= csrf_field() ?><input type="hidden" name="do" value="act"><input type="hidden" name="equipment_id" value="<?= (int) $item['id'] ?>">
    <h2><?= te('Record') ?></h2>
    <label><?= te('What happened') ?></label>
    <select name="action">
      <option value="inspect"><?= te('Inspected') ?></option>
      <option value="repair"><?= te('Sent for repair') ?></option>
      <option value="repaired"><?= te('Back from repair') ?></option>
      <option value="lose"><?= te('Lost') ?></option>
      <option value="retire"><?= te('Retired') ?></option>
      <option value="restore"><?= te('Found or put back in service') ?></option>
    </select>
    <label><?= te('Note · required to repair, lose or retire') ?></label><input name="note" maxlength="400">
    <div class="grid g2">
      <div><label><?= te('Cost') ?></label><input name="cost" type="number" min="0" step="0.01"></div>
      <div><label><?= te('Next inspection · after an inspection') ?></label><input name="next_due" type="date"></div>
    </div>
    <p class="muted"><?= te('Left empty, the next inspection is set from the category.') ?></p>
    <button class="btn"><?= te('Record') ?></button>
  </form>
  <?php endif; ?>
</div>
<div class="card scroll" id="asset-issues"><h2><?= te('Who had it') ?></h2>
  <?php if (! $issues): ?><p class="muted"><?= te('Never issued.') ?></p><?php else: ?>
  <table><tr><th><?= te('Worker') ?></th><th><?= te('Project') ?></th><th><?= te('Issued') ?></th><th><?= te('Returned') ?></th><th><?= te('Condition') ?></th></tr>
  <?php foreach ($issues as $i): ?>
    <tr><td><?= e($i['full_name']) ?></td><td><?= e($i['project']) ?></td><td><?= e(d(substr((string) $i['issued_at'], 0, 10))) ?></td>
      <td><?= $i['returned_at'] ? e(d(substr((string) $i['returned_at'], 0, 10))) : '<span class="tag blue">' . te('Out') . '</span>' ?></td>
      <td><?= e(($i['issue_condition'] ? asset_conditions()[$i['issue_condition']] : '—') . ' → ' . ($i['return_condition'] ? asset_conditions()[$i['return_condition']] : '—')) ?><?= $i['return_note'] ? ' · ' . e($i['return_note']) : '' ?></td></tr>
  <?php endforeach; ?></table><?php endif; ?>
</div>
<div class="card scroll" id="asset-history"><h2><?= te('History') ?></h2>
  <table><tr><th><?= te('When') ?></th><th><?= te('What') ?></th><th><?= te('Detail') ?></th><th class="num"><?= te('Cost') ?></th><th><?= te('By') ?></th></tr>
  <?php foreach ($events as $ev): ?>
    <tr data-event="<?= e($ev['event']) ?>"><td><?= e(d(substr((string) $ev['created_at'], 0, 10))) ?></td><td><?= e(asset_event_labels()[$ev['event']] ?? $ev['event']) ?></td><td><?= e($ev['detail'] ?? '') ?></td>
      <td class="num mono"><?= $ev['cost'] !== null ? e(money($ev['cost'])) : '' ?></td><td><?= e($ev['by_name'] ?? '') ?></td></tr>
  <?php endforeach; ?></table>
</div>
<?php else: ?>
<h1><?= te('Assets') ?></h1>
<p class="sub"><?= te('Every item the agency issues: where it is, its state and its next inspection. Items are issued and taken back on Operations.') ?></p>
<div class="grid g4" id="asset-counts" style="margin-bottom:16px">
  <?php foreach (['issued', 'available', 'in_repair', 'lost', 'overdue'] as $k): ?>
  <a class="stat" href="/assets?state=<?= e($k) ?>" data-count="<?= e($k) ?>"><div class="n"><?= (int) $counts[$k] ?></div><div class="l"><?= $k === 'overdue' ? te('Inspection overdue') : e($statuses[$k]) ?></div></a>
  <?php endforeach; ?>
</div>
<form class="card row" method="get">
  <select name="state"><option value=""><?= te('Any state') ?></option><?php foreach ($statuses + ['overdue' => t('Inspection overdue')] as $k => $label): ?><option value="<?= e($k) ?>"<?= $state === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
  <select name="category"><option value="0"><?= te('Any category') ?></option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $category === (int) $c['id'] ? ' selected' : '' ?>><?= te($c['label']) ?></option><?php endforeach; ?></select>
  <input name="q" value="<?= e($search) ?>" placeholder="<?= te('Name, tag or serial') ?>">
  <button class="btn"><?= te('Filter') ?></button>
</form>
<div class="card scroll" id="asset-list">
  <?php if (! $items): ?><p class="muted"><?= te('No item matches.') ?></p><?php else: ?>
  <table><tr><th><?= te('Tag') ?></th><th><?= te('Item') ?></th><th><?= te('Category') ?></th><th><?= te('State') ?></th><th><?= te('With') ?></th><th><?= te('Next inspection') ?></th></tr>
  <?php foreach ($items as $a): $s = $a['issue_id'] ? 'issued' : $a['status']; ?>
    <tr data-tag="<?= e($a['asset_tag']) ?>" data-state="<?= e($s) ?>"><td class="mono"><a href="/assets?id=<?= (int) $a['id'] ?>"><?= e($a['asset_tag']) ?></a></td><td><?= e($a['name']) ?><?= $a['owner_type'] === 'client' ? ' <span class="tag grey">' . e($a['owner_client']) . '</span>' : '' ?></td>
      <td><?= ($a['category'] ? te($a['category']) : '—') ?></td><td><span class="tag <?= $tagFor($s) ?>"><?= e($statuses[$s]) ?></span></td>
      <td><?= $a['holder'] ? e($a['holder'] . ' · ' . $a['project']) : '' ?></td>
      <td<?= $overdue($a['inspection_due']) && ! in_array($a['status'], ['lost', 'retired'], true) ? ' class="err"' : '' ?>><?= $a['inspection_due'] ? e(d((string) $a['inspection_due'])) : '—' ?></td></tr>
  <?php endforeach; ?></table><?php endif; ?>
</div>
<?php if (can('hotels')): ?>
<div class="grid g2">
  <form class="card" method="post" id="asset-register"><?= csrf_field() ?><input type="hidden" name="do" value="register">
    <h2><?= te('Register an item') ?></h2>
    <div class="grid g2">
      <div><label><?= te('Name') ?></label><input name="name" required minlength="2" maxlength="190"></div>
      <div><label><?= te('Unique asset tag') ?></label><input name="asset_tag" required maxlength="120"></div>
      <div><label><?= te('Category') ?></label><select name="category_id"><option value="0"><?= te('Uncategorised') ?></option><?php foreach ($categories as $c): if ((int) $c['is_active'] === 1): ?><option value="<?= (int) $c['id'] ?>"><?= te($c['label']) ?><?= $c['inspection_days'] ? ' · ' . te('every :n days', ['n' => (int) $c['inspection_days']]) : '' ?></option><?php endif; endforeach; ?></select></div>
      <div><label><?= te('Serial number') ?></label><input name="serial_number" maxlength="190"></div>
      <div><label><?= te('Equipment owner') ?></label><select name="owner_type"><option value="agency"><?= te('Agency') ?></option><option value="client"><?= te('Client') ?></option></select></div>
      <div><label><?= te('Client owner · when applicable') ?></label><select name="owner_client_id"><option value=""><?= te('Select client') ?></option><?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div><label><?= te('Bought on') ?></label><input name="purchase_date" type="date"></div>
      <div><label><?= te('Cost') ?></label><input name="purchase_cost" type="number" min="0" step="0.01"></div>
    </div>
    <button class="btn"><?= te('Register') ?></button>
  </form>
  <form class="card" method="post" id="asset-from-po"><?= csrf_field() ?><input type="hidden" name="do" value="from_po">
    <h2><?= te('Register what an order delivered') ?></h2>
    <?php if (! $orders): ?><p class="muted"><?= te('No approved equipment order has items received and not yet registered.') ?></p><?php else: ?>
    <label><?= te('Order') ?></label><select name="purchase_order_id"><?php foreach ($orders as $o): ?><option value="<?= (int) $o['id'] ?>"><?= e($o['reference'] . ' · ' . $o['title'] . ' · ' . $o['vendor_name']) ?> · <?= te(':n to register', ['n' => $o['left']]) ?></option><?php endforeach; ?></select>
    <div class="grid g2">
      <div><label><?= te('Name') ?></label><input name="name" required minlength="2" maxlength="190"></div>
      <div><label><?= te('Category') ?></label><select name="category_id"><option value="0"><?= te('Uncategorised') ?></option><?php foreach ($categories as $c): if ((int) $c['is_active'] === 1): ?><option value="<?= (int) $c['id'] ?>"><?= te($c['label']) ?></option><?php endif; endforeach; ?></select></div>
      <div><label><?= te('Tag prefix') ?></label><input name="tag_prefix" required pattern="[A-Za-z0-9][A-Za-z0-9-]{1,30}" placeholder="HARN"></div>
      <div><label><?= te('How many') ?></label><input name="count" type="number" min="1" required></div>
    </div>
    <p class="muted"><?= te('Each item gets the prefix and a number, and the order\'s unit price as its cost.') ?></p>
    <button class="btn"><?= te('Register') ?></button><?php endif; ?>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>
