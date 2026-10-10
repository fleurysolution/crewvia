<?php
$tone = ['open' => 'blue', 'submitted' => 'amber', 'approved' => 'green', 'locked' => 'grey'];
$eventWords = ['open' => t('Opened'), 'submit' => t('Submitted'), 'approve' => t('Approved'), 'lock' => t('Locked'),
               'reopen' => t('Reopened'), 'adjustment' => t('Adjustment recorded')];
?>
<h1><?= te('Pay periods') ?></h1>
<p class="sub"><?= te('One period per week, across every project. Payroll submits it once every sheet is approved, an administrator who did not submit it approves it, and it is locked once paid. From submission on, nothing in the week changes: a later difference is an adjustment in an open period.') ?></p>

<div class="grid g2">
  <div class="card scroll">
    <h2><?= te('Periods') ?></h2>
    <?php if (! $runs): ?><p class="muted"><?= te('No pay period yet. Weeks are approved project by project, as before, until one is opened.') ?></p><?php endif; ?>
    <?php if ($runs): ?><table>
      <tr><th><?= te('Week ending') ?></th><th><?= te('Status') ?></th><th class="num"><?= te('Adjustments') ?></th></tr>
      <?php foreach ($runs as $r): ?>
      <tr><td><a href="/payroll-runs?id=<?= (int) $r['id'] ?>"><?= e(d($r['week_ending'])) ?></a></td>
        <td><span class="tag <?= $tone[$r['status']] ?>" data-status="<?= e($r['status']) ?>"><?= e($statuses[$r['status']]) ?></span></td>
        <td class="num mono"><?= (int) $r['adjustments'] ?></td></tr>
      <?php endforeach; ?>
    </table><?php endif; ?>
  </div>
  <form class="card" method="post" id="open-period"><?= csrf_field() ?><input type="hidden" name="do" value="open">
    <h2><?= te('Open a pay period') ?></h2>
    <label><?= te('Week ending (Saturday)') ?></label><input type="date" name="week_ending" required value="<?= e(week_ending()) ?>">
    <button class="btn"><?= te('Open') ?></button>
  </form>
</div>

<?php if ($run): ?>
<div class="card" id="period" data-id="<?= (int) $run['id'] ?>">
  <h2><?= te('Week ending :date', ['date' => d($run['week_ending'])]) ?> <span class="tag <?= $tone[$run['status']] ?>"><?= e($statuses[$run['status']]) ?></span></h2>
  <div class="row">
    <?php if ($run['status'] === 'open'): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="submit"><input type="hidden" name="run_id" value="<?= (int) $run['id'] ?>"><button class="btn"><?= te('Submit for approval') ?></button></form>
    <?php elseif ($run['status'] === 'submitted' && can('admin')): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="approve"><input type="hidden" name="run_id" value="<?= (int) $run['id'] ?>"><button class="btn"><?= te('Approve') ?></button></form>
    <?php elseif ($run['status'] === 'approved'): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="lock"><input type="hidden" name="run_id" value="<?= (int) $run['id'] ?>"><button class="btn"><?= te('Lock as paid') ?></button></form>
    <?php endif; ?>
    <?php if (can('admin') && in_array($run['status'], ['submitted', 'approved'], true)): ?>
      <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="reopen"><input type="hidden" name="run_id" value="<?= (int) $run['id'] ?>"><input name="reason" required minlength="3" maxlength="500" placeholder="<?= te('Why it is reopened') ?>" aria-label="<?= te('Reason') ?>"><button class="btn ghost"><?= te('Reopen') ?></button></form>
    <?php endif; ?>
  </div>

  <?php if ($blockers): ?>
    <h3 style="margin:14px 0 6px"><?= te('Not approved yet') ?></h3>
    <p class="small" style="color:var(--amber)"><?= te('The period is submitted once these are approved on Hours, on their projects.') ?></p>
    <?php foreach ($blockers as $b): ?><div class="small" data-blocker="<?= (int) $b['id'] ?>"><?= e($b['full_name'] . ' · ' . $b['project']) ?></div><?php endforeach; ?>
  <?php endif; ?>

  <h3 style="margin:14px 0 6px"><?= te('Reconciliation') ?></h3>
  <?php if (! $summary['people']): ?>
    <p class="muted"><?= te('No approved week and no adjustment in this period yet.') ?></p>
  <?php else: ?>
  <div class="scroll"><table>
    <tr><th><?= te('Worker') ?></th><th><?= te('Projects') ?></th><th class="num"><?= te('Gross wages') ?></th><th class="num"><?= te('Deductions') ?></th>
      <th class="num"><?= te('Net before taxes') ?></th><th class="num"><?= te('Adjustments') ?></th><th class="num"><?= te('To pay') ?></th><th><?= te('Paid') ?></th><th></th></tr>
    <?php foreach ($summary['people'] as $p): ?>
    <tr data-person="<?= (int) $p['candidate_id'] ?>">
      <td><?= e($p['full_name']) ?></td><td class="small"><?= e(implode(', ', $p['projects'])) ?: '—' ?></td>
      <td class="num mono"><?= e(money($p['gross'])) ?></td><td class="num mono"><?= e(money($p['deductions'])) ?></td>
      <td class="num mono"><?= e(money($p['net'])) ?></td><td class="num mono"><?= e(money($p['adjustments'])) ?></td>
      <td class="num mono" data-k="payable"><?= e(money($p['payable'])) ?></td>
      <td><?= $p['sheets'] > 0 && $p['paid_sheets'] === $p['sheets'] ? '<span class="tag green">' . te('Paid') . '</span>' : '<span class="tag amber">' . te('Not recorded') . '</span>' ?></td>
      <td><a class="btn ghost sm" href="/payslip?run=<?= (int) $run['id'] ?>&amp;candidate=<?= (int) $p['candidate_id'] ?>"><?= te('Payslip') ?></a></td>
    </tr>
    <?php endforeach; ?>
    <tr><th><?= te('Total') ?></th><th></th><th class="num mono"><?= e(money($summary['totals']['gross'])) ?></th><th class="num mono"><?= e(money($summary['totals']['deductions'])) ?></th>
      <th class="num mono"><?= e(money($summary['totals']['net'])) ?></th><th class="num mono"><?= e(money($summary['totals']['adjustments'])) ?></th><th class="num mono" data-k="total-payable"><?= e(money($summary['totals']['payable'])) ?></th><th></th><th></th></tr>
  </table></div>
  <p class="small muted"><?= te('Employer contributions this week: :amount. Taxes are withheld by the payroll provider and are not in these figures.', ['amount' => money($summary['totals']['employer'])]) ?></p>
  <?php endif; ?>

  <h3 style="margin:14px 0 6px"><?= te('Adjustments in this period') ?></h3>
  <?php if (! $adjustments): ?><p class="muted"><?= te('None.') ?></p><?php endif; ?>
  <?php foreach ($adjustments as $a): ?>
    <div class="small" data-adjustment="<?= (int) $a['id'] ?>"><?= e($a['full_name']) ?> · <?= e($kinds[$a['kind']] ?? $a['kind']) ?> · <span class="mono"><?= e(money($a['amount'])) ?></span><?= $a['corrects'] ? ' · ' . te('corrects the week ending :date', ['date' => d($a['corrects'])]) : '' ?> · <?= e($a['reason']) ?> · <?= e($a['by_name'] ?? '') ?></div>
  <?php endforeach; ?>

  <?php if ($run['status'] === 'open'): ?>
    <?php if ($differences): ?>
    <h3 style="margin:14px 0 6px"><?= te('Paid weeks that no longer match their days') ?></h3>
    <p class="small muted"><?= te('Found by the week check. Each can be settled here; the amount suggested is the hours difference at the person\'s rate.') ?></p>
    <?php foreach ($differences as $x): $diff = round((float) $x['approved_hours'] - (float) $x['hours_worked'], 2); $amt = abs(round($diff * (float) $x['pay_rate'], 2)); ?>
      <form method="post" class="row" data-difference="<?= (int) $x['timesheet_id'] ?>"><?= csrf_field() ?><input type="hidden" name="do" value="adjust"><input type="hidden" name="run_id" value="<?= (int) $run['id'] ?>">
        <input type="hidden" name="candidate_id" value="<?= (int) $x['candidate_id'] ?>"><input type="hidden" name="timesheet_id" value="<?= (int) $x['timesheet_id'] ?>">
        <input type="hidden" name="kind" value="<?= $diff > 0 ? 'correction' : 'recovery' ?>"><input type="hidden" name="hours" value="<?= e((string) $diff) ?>">
        <span class="small"><?= e($x['full_name'] . ' · ' . $x['project'] . ' · ' . d($x['week_ending'])) ?>: <?= te(':paid h paid, :days h approved', ['paid' => (string) (float) $x['hours_worked'], 'days' => (string) (float) $x['approved_hours']]) ?></span>
        <input name="amount" type="number" min="0.01" step="0.01" required value="<?= e((string) $amt) ?>" style="max-width:8em" aria-label="<?= te('Amount') ?>">
        <input name="reason" required minlength="3" maxlength="500" value="<?= e(t('Attendance corrected after the week was paid')) ?>" aria-label="<?= te('Reason') ?>">
        <button class="btn sm"><?= $diff > 0 ? te('Pay the difference') : te('Recover the difference') ?></button></form>
    <?php endforeach; ?>
    <?php endif; ?>

    <form method="post" class="card" id="new-adjustment" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="do" value="adjust"><input type="hidden" name="run_id" value="<?= (int) $run['id'] ?>">
      <h3 style="margin:0 0 8px"><?= te('Record an adjustment') ?></h3>
      <div class="grid g2">
        <div><label><?= te('Worker') ?></label><select name="candidate_id"><?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['full_name']) ?></option><?php endforeach; ?></select></div>
        <div><label><?= te('Kind') ?></label><select name="kind"><?php foreach ($kinds as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div><label><?= te('Amount') ?></label><input name="amount" type="number" min="0.01" step="0.01" required></div>
        <div><label><?= te('Hours (optional)') ?></label><input name="hours" type="number" step="0.25"></div>
      </div>
      <label><?= te('Reason') ?></label><input name="reason" required minlength="3" maxlength="500">
      <button class="btn"><?= te('Record') ?></button>
    </form>
  <?php endif; ?>

  <h3 style="margin:14px 0 6px"><?= te('Audit trail') ?></h3>
  <?php foreach ($events as $ev): ?>
    <div class="small" data-event="<?= e($ev['event']) ?>"><?= e(substr((string) $ev['created_at'], 0, 16)) ?> · <?= e($ev['name'] ?? '—') ?> · <?= e($eventWords[$ev['event']] ?? $ev['event']) ?><?= $ev['detail'] ? ' · ' . e($ev['detail']) : '' ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
