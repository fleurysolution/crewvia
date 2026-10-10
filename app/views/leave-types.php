<?php
$earned = static fn(array $t): string => $t['accrual_method'] === 'hours_worked'
    ? t('1 day per :h hours worked', ['h' => (string) (float) $t['accrual_hours_per_day']])
    : ($t['days_allowed'] === null ? t('Unlimited') : t(':n days a year', ['n' => (int) $t['days_allowed']]));
$who = static function (?string $list) use ($employment): string {
    if ($list === null || $list === '') { return t('Everybody'); }
    return implode(', ', array_map(fn($k) => $employment[$k] ?? $k, explode(',', $list)));
};
$form = static function (?array $t) use ($employment) { $chosen = $t && $t['eligible_employment_types'] ? explode(',', $t['eligible_employment_types']) : []; ?>
  <form class="card" method="post" id="<?= $t ? 'edit-leave-type' : 'new-leave-type' ?>"><?= csrf_field() ?>
    <input type="hidden" name="do" value="<?= $t ? 'update' : 'create' ?>"><?php if ($t): ?><input type="hidden" name="slug" value="<?= e($t['slug']) ?>"><?php endif; ?>
    <h2><?= $t ? te('Change :kind', ['kind' => t((string) $t['label'])]) : te('New kind of leave') ?></h2>
    <div class="grid g2">
      <div><label><?= te('Name') ?></label><input name="label" required minlength="2" maxlength="90" value="<?= e($t['label'] ?? '') ?>"></div>
      <div><label><?= te('How it is earned') ?></label><select name="accrual_method">
        <option value="annual" <?= ($t['accrual_method'] ?? 'annual') === 'annual' ? 'selected' : '' ?>><?= te('A number of days each year') ?></option>
        <option value="hours_worked" <?= ($t['accrual_method'] ?? '') === 'hours_worked' ? 'selected' : '' ?>><?= te('Earned from hours worked') ?></option></select></div>
      <div><label><?= te('Days a year (blank for unlimited)') ?></label><input name="days_allowed" type="number" min="0" max="366" value="<?= e((string) ($t['days_allowed'] ?? '')) ?>"></div>
      <div><label><?= te('Hours worked to earn one day') ?></label><input name="accrual_hours_per_day" type="number" min="1" max="2000" step="0.01" value="<?= e((string) (($t['accrual_hours_per_day'] ?? null) !== null ? (float) $t['accrual_hours_per_day'] : '')) ?>"></div>
      <div><label><?= te('Most days available at once (blank for no cap)') ?></label><input name="accrual_cap_days" type="number" min="0" max="366" value="<?= e((string) ($t['accrual_cap_days'] ?? '')) ?>"></div>
      <div><label><?= te('Unused days that carry into next year') ?></label><input name="carryover_max_days" type="number" min="0" max="366" value="<?= e((string) ($t['carryover_max_days'] ?? 0)) ?>"></div>
      <div><label><?= te('Days on assignment before it can be taken') ?></label><input name="eligible_after_days" type="number" min="0" max="3650" value="<?= e((string) ($t['eligible_after_days'] ?? 0)) ?>"></div>
      <div><label><?= te('Hours paid for a day of it') ?></label><input name="hours_per_day" type="number" min="0.25" max="24" step="0.25" required value="<?= e((string) (float) ($t['hours_per_day'] ?? 8)) ?>"></div>
      <div><label><?= te('Order in the list') ?></label><input name="sort_order" type="number" min="0" max="999" value="<?= e((string) ($t['sort_order'] ?? 0)) ?>"></div>
      <div><label><input type="checkbox" name="is_paid" value="1" <?= ! $t || (int) $t['is_paid'] === 1 ? 'checked' : '' ?>> <?= te('Paid') ?></label></div>
    </div>
    <fieldset style="border:0;padding:0;margin:8px 0"><legend class="small"><?= te('Who may take it (none ticked: everybody)') ?></legend>
      <?php foreach ($employment as $k => $label): ?><label class="small" style="margin-right:12px"><input type="checkbox" name="eligible_employment_types[]" value="<?= e($k) ?>" <?= in_array($k, $chosen, true) ? 'checked' : '' ?>> <?= e($label) ?></label><?php endforeach; ?>
    </fieldset>
    <button class="btn"><?= te('Save') ?></button>
  </form>
<?php };
?>
<h1><?= te('Leave types') ?></h1>
<p class="sub"><?= te('The kinds of time off, how each is earned, how much carries over and who may take it. Balances are always worked out from these rules and the approved requests, never typed.') ?></p>

<div class="card scroll" id="leave-type-list">
  <table>
    <tr><th><?= te('Kind') ?></th><th><?= te('Earned') ?></th><th class="num"><?= te('Carries over') ?></th><th class="num"><?= te('Waiting period') ?></th><th><?= te('Who') ?></th><th><?= te('Paid') ?></th><th class="num"><?= te('Requests') ?></th><th></th></tr>
    <?php foreach ($types as $t): ?>
    <tr data-slug="<?= e($t['slug']) ?>"<?= (int) $t['is_active'] === 1 ? '' : ' class="muted"' ?>>
      <td><a href="/leave-types?slug=<?= e(rawurlencode($t['slug'])) ?>"><?= e(t((string) $t['label'])) ?></a><?php if ((int) $t['is_active'] !== 1): ?> <span class="tag grey"><?= te('Retired') ?></span><?php endif; ?></td>
      <td><?= e($earned($t)) ?><?php if ($t['accrual_cap_days'] !== null): ?><div class="small muted"><?= te('Cap :n days', ['n' => (int) $t['accrual_cap_days']]) ?></div><?php endif; ?></td>
      <td class="num mono"><?= (int) $t['carryover_max_days'] ?></td>
      <td class="num mono"><?= (int) $t['eligible_after_days'] ?></td>
      <td class="small"><?= e($who($t['eligible_employment_types'])) ?></td>
      <td><?= (int) $t['is_paid'] === 1 ? te('Paid') : te('Unpaid') ?></td>
      <td class="num mono"><?= (int) $t['requests'] ?></td>
      <td><form method="post"><?= csrf_field() ?><input type="hidden" name="slug" value="<?= e($t['slug']) ?>"><input type="hidden" name="do" value="<?= (int) $t['is_active'] === 1 ? 'retire' : 'restore' ?>"><button class="btn ghost sm"><?= (int) $t['is_active'] === 1 ? te('Retire') : te('Restore') ?></button></form></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php if ($current) { $form($current); } $form(null); ?>
