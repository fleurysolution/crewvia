<?php
$tagFor = static fn(string $s): string => ['available' => 'green', 'issued' => 'blue', 'in_repair' => 'amber', 'lost' => 'red', 'retired' => 'grey', 'disposed' => 'grey'][$s] ?? 'grey';
$overdue = static fn(?string $due): bool => $due !== null && $due !== '' && $due < date('Y-m-d');
?>
<?php if (isset($item)): ?>
<p class="back-link"><a href="/assets">← <?= te('Assets') ?></a></p>
<div class="page-head"><div><h1><?= e($item['name']) ?> <span class="mono"><?= e($item['asset_tag']) ?></span></h1>
<p class="sub"><span class="tag <?= $tagFor($item['state']) ?>" id="asset-state" data-state="<?= e($item['state']) ?>"><?= e($statuses[$item['state']]) ?></span>
  <?php if ($holder): ?> <?= te('with :name on :project since :date', ['name' => $holder['full_name'], 'project' => $holder['project'], 'date' => d(substr((string) $holder['issued_at'], 0, 10))]) ?><?php endif; ?></p></div></div>
<div class="grid g2">
  <div class="card">
    <h2><?= te('Details') ?></h2>
    <table>
      <tr><th><?= te('Category') ?></th><td><?= ($item['category'] ? te($item['category']) : te('Uncategorised')) ?></td></tr>
      <tr><th><?= te('Equipment owner') ?></th><td><?= $item['owner_type'] === 'client' ? te('Client') : te('Agency') ?></td></tr>
      <tr><th><?= te('Serial number') ?></th><td class="mono"><?= e($item['serial_number'] ?? '—') ?></td></tr>
      <tr><th><?= te('Bought') ?></th><td><?= $item['purchase_date'] ? e(d((string) $item['purchase_date'])) : '—' ?><?= $item['purchase_cost'] !== null ? ' · ' . e(money($item['purchase_cost'])) : '' ?><?= $order ? ' · ' . e($order['reference']) : '' ?></td></tr>
      <tr><th><?= te('Next inspection') ?></th><td id="inspection-due"<?= $overdue($item['inspection_due']) ? ' class="err"' : '' ?>><?= $item['inspection_due'] ? e(d((string) $item['inspection_due'])) . ($overdue($item['inspection_due']) ? ' · ' . te('overdue') : '') : '—' ?></td></tr>
      <tr><th><?= te('Class') ?></th><td><?= $item['asset_class'] ? e(al_classes()[$item['asset_class']]) : '—' ?></td></tr>
      <tr><th><?= te('In service since') ?></th><td id="in-service"><?= $item['in_service_on'] ? e(d((string) $item['in_service_on'])) : '—' ?></td></tr>
      <?php if ($item['retired_on']): ?><tr><th><?= te('Retired') ?></th><td><?= e(d((string) $item['retired_on'])) ?></td></tr><?php endif; ?>
      <?php if ($item['disposed_on']): ?><tr><th><?= te('Disposed of') ?></th><td id="disposal"><?= e(d((string) $item['disposed_on']) . ' · ' . al_disposal_methods()[$item['disposal_method']]) ?><?= $item['disposal_proceeds'] !== null ? ' · ' . e(money((float) $item['disposal_proceeds'])) : '' ?></td></tr><?php endif; ?>
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
<?php $alMoney = fn($v) => $v === null ? '—' : money((float) $v); ?>
<div class="grid g2">
  <?php if (can('hotels')): ?>
  <div class="card" id="asset-valuation" data-valued="<?= $valuation['valued'] ? '1' : '0' ?>" data-book="<?= $valuation['book'] ?? '' ?>" data-accumulated="<?= $valuation['accumulated'] ?? '' ?>" data-tco="<?= $valuation['tco'] ?? '' ?>">
    <h2><?= te('Value today') ?></h2>
    <?php if (! $valuation['valued']): ?><p class="muted"><?= te('Not valued') ?>: <?= e($valuation['reason']) ?></p><?php endif; ?>
    <table>
      <tr><th><?= te('Cost') ?></th><td class="num"><?= e($alMoney($valuation['cost'])) ?></td></tr>
      <?php if ($valuation['valued']): ?>
      <tr><th><?= te('Residual value') ?></th><td class="num"><?= e($alMoney($valuation['residual'])) ?></td></tr>
      <tr><th><?= te('Useful life') ?></th><td class="num"><?= te(':n months', ['n' => (int) $valuation['life']]) ?> · <?= te(':n used', ['n' => (int) $valuation['months']]) ?></td></tr>
      <tr><th><?= te('Depreciation to date') ?></th><td class="num"><?= e($alMoney($valuation['accumulated'])) ?></td></tr>
      <tr><th><?= te('Book value') ?></th><td class="num" id="book-value"><?= e($alMoney($valuation['book'])) ?></td></tr>
      <?php if ($valuation['gain'] !== null): ?><tr><th><?= $valuation['gain'] >= 0 ? te('Gain on disposal') : te('Loss on disposal') ?></th><td class="num" id="disposal-gain"><?= e(money(abs((float) $valuation['gain']))) ?></td></tr><?php endif; ?>
      <?php endif; ?>
      <tr><th><?= te('Service costs') ?></th><td class="num"><?= e(money((float) $valuation['service_cost'])) ?></td></tr>
      <tr><th><?= te('Cost of ownership') ?></th><td class="num"><?= e($alMoney($valuation['tco'])) ?></td></tr>
    </table>
    <p class="small muted"><?= te('Straight line from the day it went into service. A management figure: equipment is expensed when bought, and nothing here is posted to the ledger.') ?></p>
    <?php if (can('hotels') && $item['state'] !== 'disposed'): ?>
    <details><summary class="small"><?= te('Change what the value is based on') ?></summary>
      <form method="post" class="grid g2" id="asset-value-form"><?= csrf_field() ?><input type="hidden" name="do" value="value"><input type="hidden" name="equipment_id" value="<?= (int) $item['id'] ?>">
        <label><?= te('In service since') ?><input type="date" name="in_service_on" value="<?= e((string) $item['in_service_on']) ?>"></label>
        <label><?= te('Useful life (months), if not the category\'s') ?><input type="number" name="useful_life_months" min="1" max="600" value="<?= e((string) $item['useful_life_months']) ?>"></label>
        <label><?= te('Residual value, if not the category\'s share') ?><input type="number" name="residual_value" min="0" step="0.01" value="<?= e((string) $item['residual_value']) ?>"></label>
        <div><button class="btn sm"><?= te('Save') ?></button></div></form>
    </details><?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="card" id="asset-lifecycle"><h2><?= te('Lifecycle') ?></h2>
    <?php if (! $lifecycle): ?><p class="muted"><?= te('Nothing dated yet.') ?></p><?php else: ?>
    <table><?php foreach ($lifecycle as [$when, $what, $detail]): ?><tr data-stage="<?= e($what) ?>"><td class="small"><?= e(d((string) $when)) ?></td><td><?= e($what) ?></td><td class="small"><?= e($detail) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
    <?php if (can('hotels') && in_array($item['state'], ['retired', 'lost'], true)): ?>
    <form method="post" class="grid g2 mt" id="asset-dispose-form"><?= csrf_field() ?><input type="hidden" name="do" value="dispose"><input type="hidden" name="equipment_id" value="<?= (int) $item['id'] ?>">
      <h3 style="grid-column:1/-1;margin:0"><?= te('Dispose of it') ?></h3>
      <label><?= te('How') ?><select name="disposal_method"><?php foreach (al_disposal_methods() as $k => $label): if ($k === 'returned_to_client' && $item['owner_type'] !== 'client') continue; ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
      <label><?= te('When') ?><input type="date" name="disposed_on" value="<?= e(date('Y-m-d')) ?>" required></label>
      <label><?= te('Sold for (a sale only)') ?><input type="number" name="proceeds" min="0" step="0.01"></label>
      <label><?= te('Note') ?><input name="note" maxlength="400"></label>
      <div><button class="btn"><?= te('Dispose of it, for good') ?></button></div></form>
    <?php endif; ?>
  </div>
</div>
<div class="grid g2">
  <div class="card scroll" id="asset-inspections" data-count="<?= count($inspections) ?>"><h2><?= te('Inspections') ?></h2>
    <?php if (! $inspections): ?><p class="muted"><?= te('No inspection recorded.') ?></p><?php else: ?>
    <table><tr><th><?= te('When') ?></th><th><?= te('Result') ?></th><th><?= te('Findings') ?></th><th><?= te('Next') ?></th><th><?= te('By') ?></th></tr>
    <?php foreach ($inspections as $in): ?><tr data-inspection="<?= e($in['result']) ?>"><td class="small"><?= e(d((string) $in['inspected_on'])) ?></td>
      <td><span class="tag <?= $in['result'] === 'pass' ? 'green' : 'red' ?>"><?= $in['result'] === 'pass' ? te('Passed') : te('Failed') ?></span></td><td class="small"><?= e((string) $in['findings']) ?></td>
      <td class="small"><?= $in['next_due'] ? e(d((string) $in['next_due'])) : '—' ?></td><td class="small"><?= e((string) ($in['by_name'] ?? '')) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
    <?php if (can('hotels') && ! in_array($item['state'], ['lost', 'retired', 'disposed'], true)): ?>
    <form method="post" class="grid g2 mt" id="asset-inspection-form"><?= csrf_field() ?><input type="hidden" name="do" value="inspection"><input type="hidden" name="equipment_id" value="<?= (int) $item['id'] ?>">
      <label><?= te('Result') ?><select name="result"><option value="pass"><?= te('Passed') ?></option><option value="fail"><?= te('Failed') ?></option></select></label>
      <label><?= te('On') ?><input type="date" name="inspected_on" value="<?= e(date('Y-m-d')) ?>" required></label>
      <label><?= te('Findings · required when it fails') ?><input name="findings" maxlength="1000"></label>
      <label><?= te('Next inspection · after a pass') ?><input type="date" name="next_due"></label>
      <div><button class="btn sm"><?= te('Record the inspection') ?></button></div></form>
    <?php endif; ?>
  </div>
  <div class="card scroll" id="asset-maintenance" data-count="<?= count($plans) ?>"><h2><?= te('Maintenance') ?></h2>
    <?php if (! $plans): ?><p class="muted"><?= te('No maintenance planned.') ?></p><?php else: ?>
    <table><tr><th><?= te('What') ?></th><th><?= te('Every') ?></th><th><?= te('Next') ?></th><th><?= te('Last done') ?></th><th></th></tr>
    <?php foreach ($plans as $pl): $late = $pl['is_active'] && $pl['next_due'] < date('Y-m-d'); ?><tr data-plan="<?= (int) $pl['id'] ?>" data-active="<?= (int) $pl['is_active'] ?>" data-next="<?= e((string) $pl['next_due']) ?>">
      <td><?= e($pl['task']) ?><?= $pl['blocks_issue'] ? ' <span class="tag amber">' . te('blocks issue when overdue') . '</span>' : '' ?></td><td class="small"><?= te(':n days', ['n' => (int) $pl['every_days']]) ?></td>
      <td class="small<?= $late ? ' err' : '' ?>"><?= $pl['is_active'] ? e(d((string) $pl['next_due'])) . ($late ? ' · ' . te('overdue') : '') : te('Stopped') ?></td><td class="small"><?= $pl['last_done_on'] ? e(d((string) $pl['last_done_on'])) : '—' ?></td>
      <td><?php if (can('hotels') && $pl['is_active']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="plan_stop"><input type="hidden" name="equipment_id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="plan_id" value="<?= (int) $pl['id'] ?>"><button class="btn sm ghost"><?= te('Stop') ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></table><?php endif; ?>
    <?php if (can('hotels') && ! in_array($item['state'], ['retired', 'disposed'], true)): ?>
    <form method="post" class="grid g2 mt" id="asset-plan-form"><?= csrf_field() ?><input type="hidden" name="do" value="plan_save"><input type="hidden" name="equipment_id" value="<?= (int) $item['id'] ?>">
      <label><?= te('Maintenance') ?><input name="task" required minlength="3" maxlength="190" placeholder="<?= te('Calibrate the sensor') ?>"></label>
      <label><?= te('Every (days)') ?><input type="number" name="every_days" min="1" max="3650" required></label>
      <label><?= te('First due · empty: one interval from today') ?><input type="date" name="next_due"></label>
      <label><input type="checkbox" name="blocks_issue" value="1"> <?= te('The item does not go out while this is overdue') ?></label>
      <div><button class="btn sm"><?= te('Plan it') ?></button></div></form>
    <?php endif; ?>
  </div>
</div>
<div class="card scroll" id="asset-services" data-count="<?= count($services) ?>"><h2><?= te('Service history') ?></h2>
  <?php if (! $services): ?><p class="muted"><?= te('No service recorded.') ?></p><?php else: ?>
  <table><tr><th><?= te('When') ?></th><th><?= te('What') ?></th><th><?= te('Who') ?></th><th class="num"><?= te('Cost') ?></th><th><?= te('Out of use') ?></th><th><?= te('Reference') ?></th><th><?= te('Notes') ?></th></tr>
  <?php foreach ($services as $sv): ?><tr data-service="<?= e($sv['kind']) ?>"><td class="small"><?= e(d((string) $sv['performed_on'])) ?></td><td><?= e(al_service_kinds()[$sv['kind']]) ?></td>
    <td class="small"><?= e((string) ($sv['vendor_name'] ?? $sv['performed_by'] ?? '')) ?></td><td class="num mono"><?= $sv['cost'] !== null ? e(money((float) $sv['cost'])) : '' ?></td>
    <td class="small"><?= $sv['downtime_days'] !== null ? te(':n days', ['n' => (int) $sv['downtime_days']]) : '' ?></td><td class="small mono"><?= e((string) $sv['reference']) ?></td><td class="small"><?= e((string) $sv['notes']) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
  <?php if (can('hotels') && $item['state'] !== 'disposed'): ?>
  <form method="post" class="grid g4 mt" id="asset-service-form"><?= csrf_field() ?><input type="hidden" name="do" value="service"><input type="hidden" name="equipment_id" value="<?= (int) $item['id'] ?>">
    <label><?= te('What') ?><select name="kind"><?php foreach (al_service_kinds() as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label><?= te('For a plan') ?><select name="plan_id"><option value="0">—</option><?php foreach ($plans as $pl): if (! $pl['is_active']) continue; ?><option value="<?= (int) $pl['id'] ?>"><?= e($pl['task']) ?></option><?php endforeach; ?></select></label>
    <label><?= te('On') ?><input type="date" name="performed_on" value="<?= e(date('Y-m-d')) ?>" required></label>
    <label><?= te('Vendor') ?><select name="vendor_id"><option value="0">—</option><?php foreach ($vendors as $vd): ?><option value="<?= (int) $vd['id'] ?>"><?= e($vd['name']) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Or done by') ?><input name="performed_by" maxlength="190"></label>
    <label><?= te('Cost') ?><input type="number" name="cost" min="0" step="0.01"></label>
    <label><?= te('Days out of use') ?><input type="number" name="downtime_days" min="0" max="365"></label>
    <label><?= te('Reference') ?><input name="reference" maxlength="120"></label>
    <label style="grid-column:1/-1"><?= te('Notes') ?><input name="notes" maxlength="1000"></label>
    <div><button class="btn sm"><?= te('Record the service') ?></button></div></form>
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
<div class="page-head"><div><h1><?= te('Assets') ?></h1>
<p class="sub"><?= te('Every item the agency issues: where it is, its state and its next inspection. Items are issued and taken back on Operations.') ?>
  <?php if (can('hotels', 'payroll')): ?><a href="/asset-valuation"><?= te('Valuation') ?></a><?php endif; ?></p></div></div>
<div class="grid g4 mb-l" id="asset-counts">
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
<div class="card scroll" id="asset-due" data-inspections="<?= count($due['inspections']) ?>" data-plans="<?= count($due['plans']) ?>"><h2><?= te('Due within two weeks') ?></h2>
  <?php if (! $due['inspections'] && ! $due['plans']): ?><p class="muted"><?= te('Nothing is due.') ?></p><?php else: ?>
  <table><tr><th><?= te('When') ?></th><th><?= te('Item') ?></th><th><?= te('What') ?></th></tr>
  <?php foreach ($due['inspections'] as $di): ?><tr data-due-inspection="<?= (int) $di['id'] ?>"><td class="small<?= $overdue($di['inspection_due']) ? ' err' : '' ?>"><?= e(d((string) $di['inspection_due'])) ?></td><td><a href="/assets?id=<?= (int) $di['id'] ?>"><?= e($di['asset_tag'] . ' · ' . $di['name']) ?></a></td><td><?= te('Inspection') ?></td></tr><?php endforeach; ?>
  <?php foreach ($due['plans'] as $dp): ?><tr data-due-plan="<?= (int) $dp['id'] ?>"><td class="small<?= $overdue($dp['next_due']) ? ' err' : '' ?>"><?= e(d((string) $dp['next_due'])) ?></td><td><a href="/assets?id=<?= (int) $dp['equipment_id'] ?>"><?= e($dp['asset_tag'] . ' · ' . $dp['name']) ?></a></td><td><?= e($dp['task']) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>
<?php if (can('hotels')): ?>
<div class="card scroll" id="asset-categories"><h2><?= te('Categories') ?></h2>
  <table><tr><th><?= te('Code') ?></th><th><?= te('Name') ?></th><th><?= te('Class') ?></th><th><?= te('Inspection every (days)') ?></th><th><?= te('Useful life (months)') ?></th><th><?= te('Residual %') ?></th><th><?= te('In use') ?></th><th></th></tr>
  <?php foreach ($categories as $c): ?><tr data-category="<?= e($c['code']) ?>"><td colspan="8">
    <form method="post" class="row" style="gap:6px"><?= csrf_field() ?><input type="hidden" name="do" value="category_save"><input type="hidden" name="category_id" value="<?= (int) $c['id'] ?>">
      <input name="code" value="<?= e($c['code']) ?>" maxlength="40" style="width:120px" aria-label="<?= te('Code') ?>"><input name="label" value="<?= e($c['label']) ?>" maxlength="120" aria-label="<?= te('Name') ?>">
      <select name="asset_class" aria-label="<?= te('Class') ?>"><?php foreach (al_classes() as $k => $label): ?><option value="<?= e($k) ?>"<?= ($c['asset_class'] ?? 'other') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
      <input type="number" name="inspection_days" min="1" max="3650" value="<?= e((string) $c['inspection_days']) ?>" style="width:90px" aria-label="<?= te('Inspection every (days)') ?>">
      <input type="number" name="useful_life_months" min="1" max="600" value="<?= e((string) ($c['useful_life_months'] ?? '')) ?>" style="width:90px" aria-label="<?= te('Useful life (months)') ?>">
      <input type="number" name="residual_percent" min="0" max="90" step="0.5" value="<?= e((string) ($c['residual_percent'] ?? '')) ?>" style="width:80px" aria-label="<?= te('Residual %') ?>">
      <label><input type="checkbox" name="is_active" value="1"<?= (int) $c['is_active'] ? ' checked' : '' ?>> <?= te('In use') ?></label>
      <button class="btn sm ghost"><?= te('Save') ?></button></form></td></tr><?php endforeach; ?></table>
  <form method="post" class="row" id="category-form" style="gap:6px;margin-top:8px"><?= csrf_field() ?><input type="hidden" name="do" value="category_save">
    <input name="code" placeholder="<?= te('Code') ?>" maxlength="40" required style="width:120px" aria-label="<?= te('Code') ?>"><input name="label" placeholder="<?= te('Name') ?>" maxlength="120" required aria-label="<?= te('Name') ?>">
    <select name="asset_class" aria-label="<?= te('Class') ?>"><?php foreach (al_classes() as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
    <input type="number" name="inspection_days" min="1" max="3650" placeholder="<?= te('Inspection every (days)') ?>" style="width:90px" aria-label="<?= te('Inspection every (days)') ?>">
    <input type="number" name="useful_life_months" min="1" max="600" placeholder="<?= te('Useful life (months)') ?>" style="width:90px" aria-label="<?= te('Useful life (months)') ?>">
    <input type="number" name="residual_percent" min="0" max="90" step="0.5" placeholder="<?= te('Residual %') ?>" style="width:80px" aria-label="<?= te('Residual %') ?>">
    <input type="hidden" name="is_active" value="1"><button class="btn sm"><?= te('Add the category') ?></button></form>
</div>
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
