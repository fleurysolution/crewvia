<?php
$band = static fn($min, $max): string => $min === null && $max === null ? '—'
    : ($min !== null ? money($min) : '…') . ' – ' . ($max !== null ? money($max) : '…');
$form = static function (?array $g) { ?>
  <form class="card" method="post" id="<?= $g ? 'edit-grade' : 'new-grade' ?>"><?= csrf_field() ?><input type="hidden" name="do" value="save"><?php if ($g): ?><input type="hidden" name="grade_id" value="<?= (int) $g['id'] ?>"><?php endif; ?>
    <h2><?= $g ? te('Change :grade', ['grade' => $g['label']]) : te('New grade') ?></h2>
    <div class="grid g2">
      <div><label><?= te('Name') ?></label><input name="label" required minlength="2" maxlength="120" value="<?= e($g['label'] ?? '') ?>" placeholder="<?= te('for example: Journeyman welder') ?>"></div>
      <div><label><?= te('Order') ?></label><input name="sort_order" type="number" min="0" max="999" value="<?= (int) ($g['sort_order'] ?? 0) ?>"></div>
      <div><label><?= te('Hourly rate from') ?></label><input name="rate_min" type="number" min="0" step="0.01" value="<?= e((string) ($g['rate_min'] ?? '')) ?>"></div>
      <div><label><?= te('Hourly rate to') ?></label><input name="rate_max" type="number" min="0" step="0.01" value="<?= e((string) ($g['rate_max'] ?? '')) ?>"></div>
      <div><label><?= te('Salary per pay period from') ?></label><input name="salary_min" type="number" min="0" step="0.01" value="<?= e((string) ($g['salary_min'] ?? '')) ?>"></div>
      <div><label><?= te('Salary per pay period to') ?></label><input name="salary_max" type="number" min="0" step="0.01" value="<?= e((string) ($g['salary_max'] ?? '')) ?>"></div>
    </div>
    <button class="btn"><?= te('Save') ?></button>
  </form>
<?php };
?>
<h1><?= te('Pay grades') ?></h1>
<p class="sub"><?= te('A grade, and the band its pay is expected to sit in. Pay outside the band of somebody\'s grade can only be set by an administrator, with the reason, and it is marked.') ?></p>
<div class="card scroll" id="grade-list">
  <?php if (! $grades): ?><p class="muted"><?= te('No grade yet. Pay is not compared with any band until there is one.') ?></p><?php else: ?>
  <table>
    <tr><th><?= te('Grade') ?></th><th><?= te('Hourly band') ?></th><th><?= te('Salary band') ?></th><th class="num"><?= te('People') ?></th><th></th></tr>
    <?php foreach ($grades as $g): ?>
    <tr data-grade="<?= e($g['code']) ?>"<?= (int) $g['is_active'] === 1 ? '' : ' class="muted"' ?>>
      <td><a href="/pay-grades?id=<?= (int) $g['id'] ?>"><?= e($g['label']) ?></a><?= (int) $g['is_active'] === 1 ? '' : ' <span class="tag grey">' . te('Retired') . '</span>' ?></td>
      <td class="mono"><?= e($band($g['rate_min'], $g['rate_max'])) ?></td><td class="mono"><?= e($band($g['salary_min'], $g['salary_max'])) ?></td>
      <td class="num mono"><?= (int) $g['people'] ?></td>
      <td><form method="post"><?= csrf_field() ?><input type="hidden" name="grade_id" value="<?= (int) $g['id'] ?>"><input type="hidden" name="do" value="<?= (int) $g['is_active'] === 1 ? 'retire' : 'restore' ?>"><button class="btn ghost sm"><?= (int) $g['is_active'] === 1 ? te('Retire') : te('Restore') ?></button></form></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php if ($current) { $form($current); } $form(null); ?>
