<?php foreach($lines as $reviewLine): if(!empty($reviewLine['m']['requires_provider_review']) && ($reviewLine['m']['method'] ?? '') !== 'pay_rules'): ?><p class="flash err"><?= e($reviewLine['full_name']) ?>: <?= te('Salaried overtime requires payroll provider reconciliation.') ?></p><?php endif; endforeach; ?>
<?php
$prev = date('Y-m-d', strtotime($week . ' -7 days'));
$next = date('Y-m-d', strtotime($week . ' +7 days'));
$guarantee = (int) (($job['strike_live'] ?? 0) ? ($job['strike_hours'] ?? 60) : ($job['guarantee_hours'] ?? 50));
?>

<h1><?= te('Hours') ?></h1>
<p class="sub">
  Week ending <?= e(date('l j F Y', strtotime($week))) ?>
  &middot; <?= $guarantee ?>-hour guarantee
  <?php if ((int) ($job['strike_live'] ?? 0) === 1): ?>
    <span class="tag red"><?= te('strike live') ?></span>
  <?php endif; ?>
</p>

<div class="row tight" style="margin-bottom:14px">
  <a class="btn ghost sm" href="/hours?week=<?= e($prev) ?>"><?= te('← Previous week') ?></a>
  <a class="btn ghost sm" href="/hours?week=<?= e(week_ending()) ?>"><?= te('This week') ?></a>
  <a class="btn ghost sm" href="/hours?week=<?= e($next) ?>"><?= te('Next week &rarr;') ?></a>
</div>

<div class="grid g4" style="margin-bottom:16px">
  <div class="stat"><div class="n"><?= rtrim(rtrim(number_format($totals['hours'], 1), '0'), '.') ?></div>
    <div class="l"><?= te('Hours worked') ?></div></div>
  <div class="stat"><div class="n" style="<?= $totals['short'] > 0 ? 'color:var(--amber)' : '' ?>">
    <?= rtrim(rtrim(number_format($totals['short'], 1), '0'), '.') ?></div>
    <div class="l"><?= te('Guarantee gap') ?></div><div class="h"><?= te('paid, not worked') ?></div></div>
  <div class="stat"><div class="n"><?= money($totals['perdiem']) ?></div>
    <div class="l"><?= te('Per diem') ?></div></div>
  <div class="stat"><div class="n"><?= money($totals['pay']) ?></div>
    <div class="l"><?= te('Payroll this week') ?></div></div>
</div>

<form method="post" action="/hours">
<?= csrf_field() ?>
<input type="hidden" name="do" value="save">
<input type="hidden" name="week_ending" value="<?= e($week) ?>">

<div class="card tight">
  <?php if (! $lines): ?>
    <div class="empty"><?= te('Nobody is on site yet. Once a placement is marked') ?> <strong><?= te('On site') ?></strong> <?= te('they appear here.') ?></div>
  <?php else: ?>
  <div class="scroll">
  <table>
    <thead><tr>
      <th><?= te('Engineer') ?></th><th style="width:110px"><?= te('Hours') ?></th><th style="width:100px"><?= te('Per-diem days') ?></th>
      <th style="width:110px"><?= te('Expenses') ?></th><th class="num"><?= te('Paid hours') ?></th><th class="num"><?= te('Gap') ?></th>
      <th class="num"><?= te('Pay') ?></th><th class="num"><?= te('Bill') ?></th><th><?= te('State') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($lines as $l): $m = $l['m']; ?>
      <tr>
        <td>
          <strong><?= e($l['full_name']) ?></strong>
          <div class="small muted"><?= e(ucfirst($l['discipline'])) ?> &middot; <?= money($m['pay_rate']) ?>/h</div>
        </td>
        <td><input type="number" step="0.25" min="0" max="168" name="hours[<?= (int) $l['id'] ?>]"
                   value="<?= $l['hours_worked'] !== null ? e((float) $l['hours_worked']) : '' ?>"
                   placeholder="0" style="padding:5px 8px"></td>
        <td><input type="number" step="1" min="0" max="7" name="per_diem[<?= (int) $l['id'] ?>]"
                   value="<?= $l['per_diem_days'] !== null ? (int) $l['per_diem_days'] : '' ?>"
                   placeholder="0" style="padding:5px 8px"></td>
        <td><input type="number" step="0.01" min="0" name="expenses[<?= (int) $l['id'] ?>]"
                   value="<?= $l['expenses'] !== null && (float) $l['expenses'] > 0 ? e((float) $l['expenses']) : '' ?>"
                   placeholder="0.00" style="padding:5px 8px"></td>
        <td class="num mono"><?= rtrim(rtrim(number_format($m['paid_hours'], 1), '0'), '.') ?></td>
        <td class="num mono" style="<?= $m['short_by'] > 0 ? 'color:var(--amber);font-weight:600' : 'color:var(--muted)' ?>">
          <?= $m['short_by'] > 0 ? '+' . rtrim(rtrim(number_format($m['short_by'], 1), '0'), '.') : '—' ?>
        </td>
        <td class="num mono"><?= money($m['pay_total']) ?></td>
        <td class="num mono"><?= $m['bill_total'] > 0 ? money($m['bill_total']) : '—' ?></td>
        <td>
          <?php $s = $l['sheet_status'] ?? null; ?>
          <?php if ($s === 'approved' || $s === 'paid'): ?>
            <span class="tag green"><?= e(ucfirst($s)) ?></span>
          <?php elseif ($s !== null): ?>
            <span class="tag amber"><?= te('Draft') ?></span>
          <?php else: ?>
            <span class="tag grey"><?= te('Not entered') ?></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <div style="padding:12px 16px;border-top:1px solid var(--line);display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <button class="btn" type="submit"><?= te('Save hours') ?></button>
    <span class="small muted"><?= te('An empty row is left alone &mdash; it is not recorded as a zero week.') ?></span>
  </div>
  <?php endif; ?>
</div>
</form>

<?php if ($lines): ?>
<form method="post" action="/hours" style="margin-bottom:30px">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="approve_week">
  <input type="hidden" name="week_ending" value="<?= e($week) ?>">
  <button class="btn ghost" type="submit"
          onclick="return confirm('Approve every entered timesheet for week ending <?= e(date('j M', strtotime($week))) ?>?')">
    <?= te('Approve this week') ?>
  </button>
</form>
<?php endif; ?>

<div class="grid g2"><form class="card" method="post"><?= csrf_field() ?><input type="hidden" name="do" value="import_attendance"><h2><?= te('Import approved attendance') ?></h2><label><?= te('Week ending') ?><input name="week_ending" type="date" required value="<?= e($week) ?>"></label><p><?= te('Approved weeks remain frozen. Per diem and expenses require separate review.') ?></p><button class="btn"><?= te('Import approved attendance') ?></button></form><form class="card" method="post"><?= csrf_field() ?><input type="hidden" name="do" value="policy"><h2><?= te('Gross payroll policy') ?></h2><p><?= te('Configure reviewed weekly overtime rules. Taxes, daily overtime and deductions remain with the payroll provider.') ?></p><label><?= te('Weekly overtime after hours') ?><input name="weekly_overtime_after" type="number" min="0" max="168" step="0.25" value="<?= e($job['weekly_overtime_after']??'') ?>"></label><label><?= te('Overtime multiplier') ?><input name="overtime_multiplier" type="number" min="1" max="5" step="0.01" value="<?= e($job['overtime_multiplier']??1.5) ?>"></label><button class="btn ghost"><?= te('Save policy') ?></button></form></div>

<?php
// Under a pay rule set: who earns more than regular time this week, and why
// a week needs the provider. Only lines with something to say are listed.
$ruled = array_filter($lines, fn($l) => ($l['m']['method'] ?? '') === 'pay_rules' && ($l['hours_worked'] ?? null) !== null);
?>
<div class="card" id="pay-rules-week">
  <h2><?= te('Pay rules this week') ?></h2>
  <?php if (! $ruleSet): ?>
    <p class="muted"><?= te('This project has no pay rule set. Overtime follows the weekly line in the policy above. Choose a confirmed rule set on the Pay rules page to apply daily overtime, double time, holidays and shift premiums.') ?></p>
  <?php else: ?>
    <p class="muted"><?= te('Rule set: :name (:jurisdiction). Weeks already approved keep the figures they were approved with.', ['name' => $ruleSet['name'], 'jurisdiction' => $ruleSet['jurisdiction']]) ?><?php if ($ruleSet['status'] === 'retired'): ?> <span class="tag amber"><?= te('Retired - choose a current rule set') ?></span><?php endif; ?></p>
    <?php if (! $ruled): ?>
      <p class="muted"><?= te('No hours entered for this week yet.') ?></p>
    <?php else: ?>
    <div class="scroll"><table>
      <tr><th><?= te('Worker') ?></th><th class="num"><?= te('Regular') ?></th><th class="num"><?= te('Daily overtime') ?></th><th class="num"><?= te('Weekly overtime') ?></th><th class="num"><?= te('Double time') ?></th><th class="num"><?= te('Holiday') ?></th><th class="num"><?= te('Shift premium') ?></th><th><?= te('Needs a look') ?></th></tr>
      <?php foreach ($ruled as $l): $m = $l['m']; ?>
      <tr data-placement="<?= (int) $l['id'] ?>">
        <td><?= e($l['full_name']) ?></td>
        <td class="num mono" data-k="regular"><?= e((string) (float) $m['regular_hours']) ?></td>
        <td class="num mono" data-k="daily"><?= e((string) (float) $m['daily_overtime_hours']) ?></td>
        <td class="num mono" data-k="weekly"><?= e((string) (float) $m['weekly_overtime_hours']) ?></td>
        <td class="num mono" data-k="double"><?= e((string) (float) $m['double_hours']) ?></td>
        <td class="num mono" data-k="holiday"><?= e((string) (float) $m['holiday_hours']) ?></td>
        <td class="num mono"><?= (float) $m['shift_premium'] > 0 ? e(money($m['shift_premium'])) . '/h' : '—' ?></td>
        <td class="small"><?= e(implode('; ', array_map(fn($r) => $reviewReasons[$r] ?? $r, $m['review_reasons']))) ?: '—' ?></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  <?php endif; ?>
</div>
