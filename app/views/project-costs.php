<?php
$pct = static fn(?float $v): string => $v === null ? '—' : number_format($v, 1) . '%';
?>
<h1><?= te('Project costs') ?></h1>
<?php if (! $costs): ?>
<p class="sub"><?= te('Select a project first.') ?></p>
<?php else: ?>
<p class="sub"><?= e($job['title']) ?> · <?= te('project to date, from approved weeks and approved or paid invoices and claims. Nothing here creates a transaction.') ?></p>

<div class="grid g4" style="margin-bottom:16px" id="cost-summary">
  <div class="stat"><div class="n" data-figure="revenue"><?= e(money($costs['actual']['revenue'])) ?></div><div class="l"><?= te('Revenue') ?></div></div>
  <div class="stat"><div class="n" data-figure="direct"><?= e(money($profit['direct'])) ?></div><div class="l"><?= te('Direct cost') ?></div></div>
  <div class="stat"><div class="n" data-figure="gross"><?= e(money($profit['gross'])) ?></div><div class="l"><?= te('Gross margin') ?> · <?= e($pct($profit['gross_pct'])) ?></div></div>
  <div class="stat"><div class="n" data-figure="net"><?= e(money($profit['net'])) ?></div><div class="l"><?= te('After overhead') ?> · <?= e($pct($profit['net_pct'])) ?></div></div>
</div>

<div class="card scroll" id="budget-vs-actual">
  <h2><?= te('Budget against actual') ?></h2>
  <table>
    <tr><th><?= te('Line') ?></th><th class="num"><?= te('Budget') ?></th><th class="num"><?= te('Actual') ?></th><th class="num"><?= te('Variance') ?></th><th class="num"><?= te('Used') ?></th><th><?= te('Made of') ?></th></tr>
    <?php foreach ($categories as $k => $label):
        $act = $costs['actual'][$k];
        $bud = $budget[$k] ?? null;
        // Revenue is good above budget; a cost is good below it.
        $var = $bud === null ? null : ($k === 'revenue' ? $act - $bud : $bud - $act);
        $used = $bud ? round($act / $bud * 100, 1) : null; ?>
    <tr data-line="<?= e($k) ?>" data-actual="<?= e(number_format($act, 2, '.', '')) ?>"<?= $bud !== null ? ' data-budget="' . e(number_format($bud, 2, '.', '')) . '"' : '' ?>>
      <td><?= e($label) ?></td>
      <td class="num mono"><?= $bud === null ? '—' : e(money($bud)) ?></td>
      <td class="num mono"><?= e(money($act)) ?></td>
      <td class="num mono<?= $var !== null && $var < 0 ? ' err' : '' ?>" data-variance="<?= $var === null ? '' : e(number_format($var, 2, '.', '')) ?>"><?= $var === null ? '—' : e(($var < 0 ? '−' : '') . money(abs($var))) ?></td>
      <td class="num mono"><?= e($pct($used)) ?></td>
      <td class="small"><?php foreach ($costs['lines'][$k] ?? [] as $what => $amount): if (abs($amount) < 0.005) continue; ?><div><?= e($what) ?> · <span class="mono"><?= e(money($amount)) ?></span></div><?php endforeach; ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p class="muted"><?= te('Ordered and not yet invoiced') ?>: <strong class="mono" id="committed"><?= e(money($costs['committed'])) ?></strong>. <?= te('A variance in red is over a cost budget, or under the revenue budget.') ?></p>
</div>

<div class="grid g2">
  <div class="card" id="labour-detail"><h2><?= te('Labour') ?></h2>
    <table>
      <tr><th><?= te('Approved weeks') ?></th><td class="num mono"><?= (int) $costs['labour']['sheets'] ?></td></tr>
      <tr><th><?= te('Hours worked') ?></th><td class="num mono"><?= e(number_format($costs['labour']['hours'], 2)) ?></td></tr>
      <tr><th><?= te('Overtime hours') ?></th><td class="num mono" data-figure="overtime-hours"><?= e(number_format($costs['labour']['overtime_hours'], 2)) ?></td></tr>
      <tr><th><?= te('Cost of the overtime hours') ?></th><td class="num mono" data-figure="overtime-cost"><?= e(money($costs['labour']['overtime_cost'])) ?></td></tr>
      <tr><th><?= te('Burden rate') ?></th><td class="num mono"><?= $job['burden_percent'] !== null ? e(number_format((float) $job['burden_percent'], 2)) . '%' : te('not set') ?></td></tr>
      <tr><th><?= te('Overhead rate') ?></th><td class="num mono"><?= $job['overhead_percent'] !== null ? e(number_format((float) $job['overhead_percent'], 2)) . '%' : te('not set') ?></td></tr>
    </table>
  </div>
  <div class="card" id="reconcile"><h2><?= te('To reconcile') ?></h2>
    <p class="muted"><?= te('Bills for what is already counted above. Shown to compare, never added.') ?></p>
    <table>
      <tr><th><?= te('Invoiced to the client') ?></th><td class="num mono" data-figure="invoiced-clients"><?= e(money($costs['reconcile']['invoiced_clients'])) ?></td><td class="num mono"><?= e(money($costs['reconcile']['invoiced_clients'] - $costs['actual']['revenue'])) ?></td></tr>
      <tr><th><?= te('Hotel invoices') ?></th><td class="num mono" data-figure="invoiced-hotels"><?= e(money($costs['reconcile']['invoiced_hotels'])) ?></td><td class="num mono"><?= e(money($costs['reconcile']['invoiced_hotels'] - ($costs['lines']['hotels'][t('Nights booked x nightly rate')] ?? 0))) ?></td></tr>
    </table>
    <p class="muted"><?= te('The last column is the invoices less what was earned or booked.') ?></p>
  </div>
</div>

<div class="card scroll" id="cost-weeks"><h2><?= te('Week by week') ?></h2>
  <?php if (! $costs['weeks']): ?><p class="muted"><?= te('No approved week yet.') ?></p><?php else: ?>
  <table><tr><th><?= te('Week ending') ?></th><th class="num"><?= te('Hours') ?></th><th class="num"><?= te('Revenue') ?></th><th class="num"><?= te('Labour, burden and per diem') ?></th><th class="num"><?= te('Difference') ?></th></tr>
  <?php foreach ($costs['weeks'] as $wk => $w): ?>
    <tr data-week="<?= e($wk) ?>"><td><?= e(d($wk)) ?></td><td class="num mono"><?= e(number_format($w['hours'], 2)) ?></td><td class="num mono"><?= e(money($w['revenue'])) ?></td><td class="num mono"><?= e(money($w['cost'])) ?></td><td class="num mono"><?= e(money($w['revenue'] - $w['cost'])) ?></td></tr>
  <?php endforeach; ?></table><?php endif; ?>
</div>

<?php if (can('admin')): ?>
<div class="grid g2">
  <form class="card" method="post" id="budget-form"><?= csrf_field() ?><input type="hidden" name="do" value="budget">
    <h2><?= te('Change a budget line') ?></h2>
    <div class="grid g2">
      <div><label><?= te('Line') ?></label><select name="category"><?php foreach ($categories as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
      <div><label><?= te('Amount for the whole project') ?></label><input name="amount" type="number" min="0" step="0.01" required></div>
    </div>
    <label><?= te('Reason') ?></label><input name="reason" required minlength="3" maxlength="500">
    <button class="btn"><?= te('Save') ?></button>
  </form>
  <form class="card" method="post" id="rates-form"><?= csrf_field() ?><input type="hidden" name="do" value="rates">
    <h2><?= te('Burden and overhead') ?></h2>
    <label><?= te('Burden on wages (%) · employer taxes and insurance') ?></label><input name="burden_percent" type="number" min="0" max="100" step="0.01" value="<?= e((string) ($job['burden_percent'] ?? '')) ?>">
    <label><?= te('Overhead on revenue (%)') ?></label><input name="overhead_percent" type="number" min="0" max="100" step="0.01" value="<?= e((string) ($job['overhead_percent'] ?? '')) ?>">
    <button class="btn"><?= te('Save') ?></button>
  </form>
</div>
<?php endif; ?>

<div class="card scroll" id="budget-history"><h2><?= te('Budget history') ?></h2>
  <?php if (! $history): ?><p class="muted"><?= te('No budget set yet.') ?></p><?php else: ?>
  <table><tr><th><?= te('When') ?></th><th><?= te('Line') ?></th><th class="num"><?= te('From') ?></th><th class="num"><?= te('To') ?></th><th><?= te('Reason') ?></th><th><?= te('By') ?></th></tr>
  <?php foreach ($history as $h): ?>
    <tr><td><?= e(d(substr((string) $h['changed_at'], 0, 10))) ?></td><td><?= e($categories[$h['category']]) ?></td><td class="num mono"><?= $h['previous_amount'] === null ? '—' : e(money($h['previous_amount'])) ?></td><td class="num mono"><?= e(money($h['amount'])) ?></td><td><?= e($h['reason']) ?></td><td><?= e($h['by_name'] ?? '') ?></td></tr>
  <?php endforeach; ?></table><?php endif; ?>
</div>
<?php endif; ?>
