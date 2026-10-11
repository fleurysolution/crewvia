<?php $avMoney = fn($v) => $v === null ? '—' : money((float) $v); ?>
<?php if (can('recruiter', 'hotels')): ?><p><a href="/assets">← <?= te('Assets') ?></a></p><?php endif; ?>
<h1><?= te('Asset valuation') ?></h1>
<p class="sub"><?= te('What each agency-owned item is worth on a date: straight-line depreciation of its cost down to its residual value over its useful life, from the day it went into service. A management figure: equipment is expensed when bought, and nothing here is posted to the ledger.') ?></p>
<form method="get" class="card row" id="valuation-filters">
  <label><?= te('On') ?><input type="date" name="as_of" value="<?= e($asOf) ?>"></label>
  <label><?= te('Class') ?><select name="class"><option value=""><?= te('Every class') ?></option><?php foreach (al_classes() as $k => $label): ?><option value="<?= e($k) ?>"<?= $class === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
  <button class="btn ghost"><?= te('Show') ?></button>
  <a class="btn ghost" href="/asset-valuation?<?= e(http_build_query(['as_of' => $asOf, 'class' => $class, 'csv' => 1])) ?>"><?= te('Download CSV') ?></a>
</form>
<div class="card scroll" id="valuation-totals">
  <h2><?= te('By class') ?></h2>
  <?php if (! $report['totals']): ?><p class="muted"><?= te('No item.') ?></p><?php else: ?>
  <table><tr><th><?= te('Class') ?></th><th class="num"><?= te('Items') ?></th><th class="num"><?= te('Valued') ?></th><th class="num"><?= te('Cost') ?></th><th class="num"><?= te('Depreciation to date') ?></th><th class="num"><?= te('Book value') ?></th><th class="num"><?= te('Service costs') ?></th></tr>
  <?php $sum = ['count' => 0, 'valued' => 0, 'cost' => 0, 'accumulated' => 0, 'book' => 0, 'service' => 0]; foreach ($report['totals'] as $k => $tt): foreach ($sum as $f => $_) { $sum[$f] += $tt[$f]; } ?>
    <tr data-class="<?= e($k) ?>" data-book="<?= e(number_format($tt['book'], 2, '.', '')) ?>"><td><?= e(al_classes()[$k] ?? $k) ?></td><td class="num"><?= (int) $tt['count'] ?></td><td class="num"><?= (int) $tt['valued'] ?></td>
      <td class="num"><?= e(money($tt['cost'])) ?></td><td class="num"><?= e(money($tt['accumulated'])) ?></td><td class="num"><?= e(money($tt['book'])) ?></td><td class="num"><?= e(money($tt['service'])) ?></td></tr>
  <?php endforeach; ?>
    <tr id="valuation-total" data-book="<?= e(number_format($sum['book'], 2, '.', '')) ?>"><th><?= te('Total') ?></th><th class="num"><?= (int) $sum['count'] ?></th><th class="num"><?= (int) $sum['valued'] ?></th><th class="num"><?= e(money($sum['cost'])) ?></th><th class="num"><?= e(money($sum['accumulated'])) ?></th><th class="num"><?= e(money($sum['book'])) ?></th><th class="num"><?= e(money($sum['service'])) ?></th></tr>
  </table><?php endif; ?>
</div>
<div class="card scroll" id="valuation-items">
  <h2><?= te('Items') ?></h2>
  <table><tr><th><?= te('Tag') ?></th><th><?= te('Item') ?></th><th><?= te('State') ?></th><th class="num"><?= te('Cost') ?></th><th class="num"><?= te('Book value') ?></th><th class="num"><?= te('Cost of ownership') ?></th><th><?= te('Note') ?></th></tr>
  <?php foreach ($report['rows'] as $r): $a = $r['item']; $v = $r['value']; ?>
    <tr data-item="<?= e($a['asset_tag']) ?>" data-valued="<?= $v['valued'] ? '1' : '0' ?>" data-book="<?= $v['book'] === null ? '' : e(number_format((float) $v['book'], 2, '.', '')) ?>">
      <td class="mono"><?php if (can('recruiter', 'hotels')): ?><a href="/assets?id=<?= (int) $a['id'] ?>"><?= e($a['asset_tag']) ?></a><?php else: ?><?= e($a['asset_tag']) ?><?php endif; ?></td><td><?= e($a['name']) ?></td><td class="small"><?= e(asset_statuses()[$a['status']] ?? $a['status']) ?></td>
      <td class="num"><?= e($avMoney($v['cost'])) ?></td><td class="num"><?= e($avMoney($v['book'])) ?></td><td class="num"><?= e($avMoney($v['tco'])) ?></td>
      <td class="small"><?= $v['valued'] ? ($v['gain'] !== null ? e(($v['gain'] >= 0 ? t('Gain on disposal') : t('Loss on disposal')) . ' ' . money(abs((float) $v['gain']))) : te(':n of :life months used', ['n' => (int) $v['months'], 'life' => (int) $v['life']])) : e($v['reason']) ?></td></tr>
  <?php endforeach; ?></table>
</div>
