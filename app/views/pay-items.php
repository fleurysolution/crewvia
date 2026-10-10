<h1><?= te('Pay items') ?></h1>
<p class="sub"><?= te('Deductions agreed with people, and contributions the employer pays on top. They prepare gross to net before taxes; the payroll provider withholds taxes.') ?></p>

<div class="card scroll" id="pay-item-list">
  <table>
    <tr><th><?= te('Item') ?></th><th><?= te('Side') ?></th><th><?= te('Worked out as') ?></th><th><?= te('Provider code') ?></th><th class="num"><?= te('People') ?></th><th></th></tr>
    <?php foreach ($items as $i): ?>
    <tr data-code="<?= e($i['code']) ?>"<?= (int) $i['is_active'] === 1 ? '' : ' class="muted"' ?>>
      <td><?= e(t((string) $i['label'])) ?><?= (int) $i['pre_tax'] === 1 ? ' <span class="tag grey">' . te('pre-tax') . '</span>' : '' ?><?php if ((int) $i['is_active'] !== 1): ?> <span class="tag grey"><?= te('Retired') ?></span><?php endif; ?></td>
      <td><?= e($sides[$i['side']] ?? $i['side']) ?></td>
      <td class="small"><?= e($methods[$i['method']] ?? $i['method']) ?></td>
      <td><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="code"><input type="hidden" name="pay_item_id" value="<?= (int) $i['id'] ?>"><input name="provider_code" maxlength="40" value="<?= e((string) $i['provider_code']) ?>" style="max-width:9em" aria-label="<?= te('Provider code') ?>"><button class="btn ghost sm"><?= te('Save') ?></button></form></td>
      <td class="num mono"><?= $i['method'] === 'advance_repayment' ? '—' : (int) $i['people'] ?></td>
      <td><form method="post"><?= csrf_field() ?><input type="hidden" name="pay_item_id" value="<?= (int) $i['id'] ?>"><input type="hidden" name="do" value="<?= (int) $i['is_active'] === 1 ? 'retire' : 'restore' ?>"><button class="btn ghost sm"><?= (int) $i['is_active'] === 1 ? te('Retire') : te('Restore') ?></button></form></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<form class="card" method="post" id="new-pay-item"><?= csrf_field() ?><input type="hidden" name="do" value="create">
  <h2><?= te('New pay item') ?></h2>
  <div class="grid g2">
    <div><label><?= te('Name') ?></label><input name="label" required minlength="2" maxlength="120" placeholder="<?= te('for example: Uniform') ?>"></div>
    <div><label><?= te('Side') ?></label><select name="side"><?php foreach ($sides as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div><label><?= te('Worked out as') ?></label><select name="method"><option value="fixed"><?= e($methods['fixed']) ?></option><option value="percent_of_gross"><?= e($methods['percent_of_gross']) ?></option></select></div>
    <div><label><?= te('Provider code (optional)') ?></label><input name="provider_code" maxlength="40"></div>
    <div><label><input type="checkbox" name="pre_tax" value="1"> <?= te('Taken before taxes (the provider applies it)') ?></label></div>
    <div><label><?= te('Order') ?></label><input name="sort_order" type="number" min="0" max="999" value="0"></div>
  </div>
  <button class="btn"><?= te('Save') ?></button>
</form>
