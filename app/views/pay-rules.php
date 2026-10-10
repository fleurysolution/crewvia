<?php
$h = static fn($v): string => $v === null || $v === '' ? '' : (string) (float) $v;
$line = static fn($after, $mult): string => $after === null || $after === ''
    ? t('none')
    : t('after :h h at ×:m', ['h' => (string) (float) $after, 'm' => (string) (float) $mult]);
$tone = ['draft' => 'amber', 'active' => 'green', 'retired' => 'grey'];
$draft = $current && $current['status'] === 'draft';
?>
<h1><?= te('Pay rules') ?></h1>
<p class="sub"><?= te('Overtime, double time, holidays and shift premiums, set per jurisdiction. A rule set is applied to nobody until an administrator activates it, naming who confirmed the values. Crewvia applies the rules; it does not decide what the law requires.') ?></p>

<div class="card" id="project-rules">
  <h2><?= te('This project') ?></h2>
  <?php if (! $job): ?>
    <p class="muted"><?= te('Open a project to choose its rule set.') ?></p>
  <?php else: ?>
    <p><?= e($job['title']) ?> · <?php if ($projectSet): ?><strong><?= e($projectSet['name']) ?></strong> <span class="tag <?= $tone[$projectSet['status']] ?>"><?= e($statuses[$projectSet['status']]) ?></span><?php else: ?><span class="muted"><?= te('No rule set: overtime follows the weekly line in the Hours policy, as before.') ?></span><?php endif; ?></p>
  <?php endif; ?>
</div>

<div class="grid g2">
  <div class="card scroll">
    <h2><?= te('Rule sets') ?></h2>
    <?php if (! $sets): ?>
      <p class="muted"><?= te('No rule set yet. Create one below.') ?></p>
    <?php else: ?>
    <table>
      <tr><th><?= te('Name') ?></th><th><?= te('Jurisdiction') ?></th><th><?= te('Status') ?></th><th class="num"><?= te('Projects') ?></th></tr>
      <?php foreach ($sets as $s): ?>
      <tr><td><a href="/pay-rules?id=<?= (int) $s['id'] ?>"><?= e($s['name']) ?></a></td><td><?= e($s['jurisdiction']) ?></td>
        <td><span class="tag <?= $tone[$s['status']] ?>" data-status="<?= e($s['status']) ?>"><?= e($statuses[$s['status']]) ?></span></td><td class="num mono"><?= (int) $s['projects'] ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <?php if ($current): ?>
  <div class="card" id="rule-set" data-id="<?= (int) $current['id'] ?>">
    <h2><?= e($current['name']) ?> <span class="tag <?= $tone[$current['status']] ?>"><?= e($statuses[$current['status']]) ?></span></h2>
    <p class="small muted"><?= e($current['jurisdiction']) ?><?php if ($current['confirmed_by']): ?> · <?= te('Confirmed by :who on :date', ['who' => $current['confirmed_by'], 'date' => d(substr((string) $current['confirmed_at'], 0, 10))]) ?><?php endif; ?></p>
    <table>
      <tr><td class="muted"><?= te('Weekly overtime') ?></td><td><?= e($line($current['weekly_overtime_after'], $current['weekly_overtime_multiplier'])) ?></td></tr>
      <tr><td class="muted"><?= te('Daily overtime') ?></td><td><?= e($line($current['daily_overtime_after'], $current['daily_overtime_multiplier'])) ?></td></tr>
      <tr><td class="muted"><?= te('Double time') ?></td><td><?= e($line($current['daily_double_after'], $current['daily_double_multiplier'])) ?></td></tr>
      <tr><td class="muted"><?= te('Holiday rate') ?></td><td><?= $current['holiday_multiplier'] !== null ? '×' . e($h($current['holiday_multiplier'])) : te('none') ?></td></tr>
    </table>
    <?php if ($current['notes']): ?><p class="small"><?= e($current['notes']) ?></p><?php endif; ?>

    <div class="row" style="margin-top:10px">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="copy"><input type="hidden" name="rule_set_id" value="<?= (int) $current['id'] ?>"><button class="btn ghost sm"><?= te('Copy into a new draft') ?></button></form>
      <?php if ($job && $current['status'] === 'active' && (int) ($job['pay_rule_set_id'] ?? 0) !== (int) $current['id']): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="assign"><input type="hidden" name="rule_set_id" value="<?= (int) $current['id'] ?>"><button class="btn sm"><?= te('Use for this project') ?></button></form>
      <?php endif; ?>
      <?php if (can('admin') && $current['status'] === 'active'): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="retire"><input type="hidden" name="rule_set_id" value="<?= (int) $current['id'] ?>"><button class="btn ghost sm"><?= te('Retire') ?></button></form>
      <?php endif; ?>
    </div>

    <?php if (can('admin') && $draft): ?>
    <form method="post" style="margin-top:12px" id="activate"><?= csrf_field() ?><input type="hidden" name="do" value="activate"><input type="hidden" name="rule_set_id" value="<?= (int) $current['id'] ?>">
      <label><?= te('Who confirmed these values for the jurisdiction') ?></label><input name="confirmed_by" required minlength="3" maxlength="190" placeholder="<?= te('for example: our payroll provider, 9 Oct 2026') ?>">
      <button class="btn"><?= te('Activate') ?></button>
    </form>
    <?php elseif ($draft): ?>
    <p class="small muted"><?= te('A draft. An administrator activates it once the values are confirmed.') ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($current): ?>
<div class="grid g2">
  <div class="card" id="holidays">
    <h2><?= te('Holidays') ?></h2>
    <?php if (! $holidays): ?><p class="muted"><?= te('No holiday in this rule set: no day is paid at the holiday rate.') ?></p><?php endif; ?>
    <?php foreach ($holidays as $d): ?>
      <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="holiday_remove"><input type="hidden" name="rule_set_id" value="<?= (int) $current['id'] ?>"><input type="hidden" name="holiday_id" value="<?= (int) $d['id'] ?>">
        <span><?= e(d($d['holiday_date'])) ?> · <?= e($d['name']) ?></span><?php if ($current['status'] !== 'retired'): ?><button class="btn ghost sm"><?= te('Remove') ?></button><?php endif; ?></form>
    <?php endforeach; ?>
    <?php if ($current['status'] !== 'retired'): ?>
    <form method="post" class="row" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="do" value="holiday_add"><input type="hidden" name="rule_set_id" value="<?= (int) $current['id'] ?>">
      <input type="date" name="holiday_date" required aria-label="<?= te('Date') ?>"><input name="holiday_name" required minlength="2" maxlength="120" placeholder="<?= te('Name') ?>" aria-label="<?= te('Name') ?>"><button class="btn sm"><?= te('Add holiday') ?></button></form>
    <?php endif; ?>
  </div>
  <div class="card" id="shift-premiums">
    <h2><?= te('Shift premiums') ?></h2>
    <p class="small muted"><?= te('An amount per hour added for a shift, matched on the shift written on the assignment. Overtime is paid on it.') ?></p>
    <?php if (! $premiums): ?><p class="muted"><?= te('No shift premium in this rule set.') ?></p><?php endif; ?>
    <?php foreach ($premiums as $s): ?>
      <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="shift_remove"><input type="hidden" name="rule_set_id" value="<?= (int) $current['id'] ?>"><input type="hidden" name="premium_id" value="<?= (int) $s['id'] ?>">
        <span><?= e($s['shift_label']) ?> · <?= e(money($s['amount_per_hour'])) ?>/h</span><?php if ($draft): ?><button class="btn ghost sm"><?= te('Remove') ?></button><?php endif; ?></form>
    <?php endforeach; ?>
    <?php if ($draft): ?>
    <form method="post" class="row" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="do" value="shift_add"><input type="hidden" name="rule_set_id" value="<?= (int) $current['id'] ?>">
      <input name="shift_label" required maxlength="190" placeholder="<?= te('Shift, e.g. Night shift') ?>" aria-label="<?= te('Shift') ?>"><input name="amount_per_hour" type="number" min="0.01" max="100" step="0.01" required placeholder="<?= te('Per hour') ?>" aria-label="<?= te('Per hour') ?>"><button class="btn sm"><?= te('Add premium') ?></button></form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php
$form = static function (?array $s, string $do) use ($h) { ?>
  <form class="card" method="post" id="<?= $do === 'create' ? 'new-rule-set' : 'edit-rule-set' ?>"><?= csrf_field() ?><input type="hidden" name="do" value="<?= $do ?>"><?php if ($s): ?><input type="hidden" name="rule_set_id" value="<?= (int) $s['id'] ?>"><?php endif; ?>
    <h2><?= $do === 'create' ? te('New rule set') : te('Change this draft') ?></h2>
    <div class="grid g2">
      <div><label><?= te('Name') ?></label><input name="name" required minlength="3" maxlength="120" value="<?= e($s['name'] ?? '') ?>"></div>
      <div><label><?= te('Jurisdiction') ?></label><input name="jurisdiction" required minlength="2" maxlength="80" value="<?= e($s['jurisdiction'] ?? '') ?>" placeholder="<?= te('for example: Michigan') ?>"></div>
      <div><label><?= te('Weekly overtime after (hours, blank for none)') ?></label><input name="weekly_overtime_after" type="number" min="0" max="168" step="0.25" value="<?= e($h($s['weekly_overtime_after'] ?? '')) ?>"></div>
      <div><label><?= te('Weekly overtime multiplier') ?></label><input name="weekly_overtime_multiplier" type="number" min="1" max="5" step="0.05" required value="<?= e($h($s['weekly_overtime_multiplier'] ?? 1.5)) ?>"></div>
      <div><label><?= te('Daily overtime after (hours, blank for none)') ?></label><input name="daily_overtime_after" type="number" min="0" max="24" step="0.25" value="<?= e($h($s['daily_overtime_after'] ?? '')) ?>"></div>
      <div><label><?= te('Daily overtime multiplier') ?></label><input name="daily_overtime_multiplier" type="number" min="1" max="5" step="0.05" required value="<?= e($h($s['daily_overtime_multiplier'] ?? 1.5)) ?>"></div>
      <div><label><?= te('Double time after (hours in a day, blank for none)') ?></label><input name="daily_double_after" type="number" min="0" max="24" step="0.25" value="<?= e($h($s['daily_double_after'] ?? '')) ?>"></div>
      <div><label><?= te('Double time multiplier') ?></label><input name="daily_double_multiplier" type="number" min="1" max="5" step="0.05" required value="<?= e($h($s['daily_double_multiplier'] ?? 2)) ?>"></div>
      <div><label><?= te('Holiday multiplier (blank for none)') ?></label><input name="holiday_multiplier" type="number" min="1" max="5" step="0.05" value="<?= e($h($s['holiday_multiplier'] ?? '')) ?>"></div>
    </div>
    <label><?= te('Notes') ?></label><textarea name="notes" maxlength="1000" rows="2"><?= e($s['notes'] ?? '') ?></textarea>
    <button class="btn"><?= te('Save draft') ?></button>
  </form>
<?php };
if ($draft) { $form($current, 'update'); }
$form(null, 'create');
?>
