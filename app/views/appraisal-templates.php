<?php
$locked = $edit && (int) $edit['used'] > 0;
$v = static fn(string $col, $default) => $edit[$col] ?? $default;
?>
<h1><?= te('Appraisal templates') ?></h1>
<p class="sub"><?= te('What a review scores, how much each point counts, the scale and the grades. Once a review uses a template it is fixed; a new version is a new template.') ?></p>
<div class="card scroll" id="template-list">
  <table><tr><th><?= te('Template') ?></th><th><?= te('Kind') ?></th><th class="num"><?= te('Scale') ?></th><th class="num"><?= te('Criteria') ?></th><th class="num"><?= te('Reviews') ?></th><th></th></tr>
  <?php foreach ($templates as $tpl): ?>
    <tr data-template="<?= e($tpl['code']) ?>"<?= (int) $tpl['is_active'] === 1 ? '' : ' class="muted"' ?>>
      <td><a href="/appraisal-templates?id=<?= (int) $tpl['id'] ?>"><?= te($tpl['label']) ?></a><?= (int) $tpl['is_active'] === 1 ? '' : ' <span class="tag grey">' . te('Retired') . '</span>' ?></td>
      <td><?= e(appraisal_kinds()[$tpl['kind']]) ?></td><td class="num mono">1–<?= (int) $tpl['scale_max'] ?></td><td class="num mono"><?= (int) $tpl['criteria'] ?></td><td class="num mono"><?= (int) $tpl['used'] ?></td>
      <td><form method="post"><?= csrf_field() ?><input type="hidden" name="template_id" value="<?= (int) $tpl['id'] ?>"><input type="hidden" name="do" value="<?= (int) $tpl['is_active'] === 1 ? 'retire' : 'restore' ?>"><button class="btn sm ghost"><?= (int) $tpl['is_active'] === 1 ? te('Retire') : te('Put back in use') ?></button></form></td></tr>
  <?php endforeach; ?></table>
</div>
<div class="grid g2">
  <form class="card" method="post" id="template-form"><?= csrf_field() ?><input type="hidden" name="do" value="save"><?php if ($edit): ?><input type="hidden" name="template_id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
    <h2><?= $edit ? te('Change :template', ['template' => t($edit['label'])]) : te('New template') ?></h2>
    <?php if ($locked): ?><p class="err"><?= te('Reviews already use this template, so it cannot change. Create a new version and retire this one.') ?></p><?php endif; ?>
    <div class="grid g2">
      <div><label><?= te('Name') ?></label><input name="label" required minlength="3" maxlength="120" value="<?= e((string) $v('label', '')) ?>"></div>
      <div><label><?= te('Kind') ?></label><select name="kind"><?php foreach (appraisal_kinds() as $k => $l): ?><option value="<?= e($k) ?>"<?= $v('kind', 'end_of_assignment') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label><?= te('Scale: 1 to') ?></label><input name="scale_max" type="number" min="3" max="10" required value="<?= (int) $v('scale_max', 5) ?>"></div>
      <div><label><input type="checkbox" name="self_review" value="1"<?= (int) $v('self_review', 1) === 1 ? ' checked' : '' ?>> <?= te('The worker gives their own view first') ?></label></div>
      <?php foreach (['grade_a' => 'A', 'grade_b' => 'B', 'grade_c' => 'C', 'grade_d' => 'D'] as $col => $g): ?>
      <div><label><?= te(':grade from (%)', ['grade' => $g]) ?></label><input name="<?= $col ?>" type="number" min="0" max="100" step="0.01" required value="<?= e((string) $v($col, ['grade_a' => 90, 'grade_b' => 80, 'grade_c' => 70, 'grade_d' => 60][$col])) ?>"></div>
      <?php endforeach; ?>
    </div>
    <h3><?= te('Criteria and weights · 0 leaves it out') ?></h3>
    <table><?php foreach ($criteria as $slug => $label): ?><tr><td><?= te($label) ?></td><td><input name="weight[<?= e($slug) ?>]" type="number" min="0" max="10" value="<?= (int) ($edit ? ($editWeights[$slug] ?? 0) : 1) ?>"></td></tr><?php endforeach; ?></table>
    <p class="muted"><?= te('The score is each mark over the scale, weighted, as a percentage. Only the reviewer\'s marks count.') ?></p>
    <?php if (! $locked): ?><button class="btn"><?= te('Save') ?></button><?php endif; ?>
  </form>
  <form class="card" method="post" id="criterion-form"><?= csrf_field() ?><input type="hidden" name="do" value="criterion">
    <h2><?= te('Add a criterion') ?></h2>
    <p class="muted"><?= te('Criteria are shared with the roster\'s end-of-assignment review.') ?></p>
    <label><?= te('Name') ?></label><input name="label" required minlength="3" maxlength="90" placeholder="<?= te('for example: Kept the work area clean') ?>">
    <button class="btn"><?= te('Add') ?></button>
  </form>
</div>
