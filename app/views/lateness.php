<?php
$ltStatus = lateness_statuses();
$ltTone = ['recorded' => 'amber', 'disputed' => 'red', 'upheld' => 'amber', 'reduced' => 'blue', 'cancelled' => 'grey'];
$ltHm = static fn(?string $t): string => $t ? substr($t, 0, 5) : '—';
?>
<div class="page-head"><div><h1><?= te('Lateness') ?></h1>
  <p class="sub"><?= $ltOwn ? te('Late arrivals recorded on your days, what was taken off your paid hours, and why. If one is wrong, dispute it: nothing is docked until payroll settles it.')
                            : te('A late arrival is measured against the shift start. Within the grace period nothing is docked; past it, the whole lateness is unpaid, rounded up. The worker sees the reason and can dispute it.') ?></p></div></div>

<?php if ($ltPayroll && $ltPolicy !== null): ?>
<form method="post" action="/lateness" class="card" id="lateness-policy"><?= csrf_field() ?><input type="hidden" name="do" value="policy">
  <h2><?= te('Rules for :project', ['project' => $ltJob['title'] ?? '']) ?></h2>
  <div class="row">
    <div style="max-width:150px"><label for="lp-start"><?= te('Shift starts at') ?></label><input id="lp-start" name="shift_start" value="<?= e($ltHm($ltPolicy['shift_start']) === '—' ? '' : $ltHm($ltPolicy['shift_start'])) ?>" placeholder="07:00" maxlength="5"></div>
    <div style="max-width:150px"><label for="lp-grace"><?= te('Grace (minutes)') ?></label><input id="lp-grace" name="grace_minutes" type="number" min="0" max="30" value="<?= (int) $ltPolicy['grace_minutes'] ?>"></div>
    <div style="max-width:170px"><label for="lp-round"><?= te('Round up to (minutes)') ?></label><select id="lp-round" name="round_to_minutes"><?php foreach ([1, 5, 6, 10, 15, 30] as $r): ?><option value="<?= $r ?>"<?= $ltPolicy['round_to_minutes'] === $r ? ' selected' : '' ?>><?= $r ?></option><?php endforeach; ?></select></div>
    <div><label class="tick" style="margin-top:26px"><input type="checkbox" name="bill_docked" value="1"<?= $ltPolicy['bill_docked'] ? ' checked' : '' ?>> <?= te('Still bill the client for docked time') ?></label></div>
  </div>
  <p class="small muted" id="lateness-example"><?= te('With these rules: :a minutes late docks :b minutes; :c minutes late docks :d.', ['a' => max(1, $ltPolicy['grace_minutes']), 'b' => lateness_docked(max(1, $ltPolicy['grace_minutes']), $ltPolicy['grace_minutes'], $ltPolicy['round_to_minutes']), 'c' => $ltPolicy['grace_minutes'] + 2, 'd' => lateness_docked($ltPolicy['grace_minutes'] + 2, $ltPolicy['grace_minutes'], $ltPolicy['round_to_minutes'])]) ?>
    <?= te('Only time not worked is docked: no fixed fine is ever taken.') ?></p>
  <button class="btn"><?= te('Save the rules') ?></button>
</form>
<?php endif; ?>

<?php if (! $ltOwn && $ltCrew): ?>
<form method="post" action="/lateness" class="card" id="lateness-record"><?= csrf_field() ?><input type="hidden" name="do" value="record">
  <h2><?= te('Record a late arrival') ?></h2>
  <div class="row">
    <div style="flex:2"><label for="lr-who"><?= te('Worker') ?></label><select id="lr-who" name="placement_id" required><option value=""><?= te('Choose') ?></option><?php foreach ($ltCrew as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['full_name']) ?><?= $c['shift_start'] ? ' · ' . te('shift :time', ['time' => $ltHm($c['shift_start'])]) : '' ?></option><?php endforeach; ?></select></div>
    <div style="max-width:170px"><label for="lr-day"><?= te('Day') ?></label><input id="lr-day" name="work_date" type="date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
    <div style="max-width:130px"><label for="lr-at"><?= te('Arrived at') ?></label><input id="lr-at" name="arrived_at" placeholder="07:20" maxlength="5" required></div>
  </div>
  <label for="lr-why"><?= te('Reason the worker will read') ?></label><input id="lr-why" name="reason" maxlength="500" required placeholder="<?= te('Arrived at the gate after the toolbox talk') ?>">
  <button class="btn" style="margin-top:8px"><?= te('Record') ?></button>
</form>
<?php endif; ?>

<?php if (! $ltList): ?>
<div class="card empty-state" id="no-lateness"><h3><?= te('No late arrival recorded') ?></h3><p><?= $ltOwn ? te('Nothing has been taken off your hours for lateness.') : te('Late arrivals recorded for the crew appear here.') ?></p></div>
<?php else: ?>
<div class="card tight" id="lateness-list"><div class="scroll"><table>
  <tr><th><?= te('Day') ?></th><?php if (! $ltOwn): ?><th><?= te('Worker') ?></th><?php endif; ?><th><?= te('Shift start → arrived') ?></th><th class="num"><?= te('Late') ?></th><th class="num"><?= te('Unpaid') ?></th><th><?= te('Reason') ?></th><th><?= te('Status') ?></th><th></th></tr>
  <?php foreach ($ltList as $l): ?><tr data-lateness="<?= (int) $l['id'] ?>" data-status="<?= e($l['status']) ?>"<?= $ltFocus === (int) $l['id'] ? ' style="background:var(--ui-soft,#f3f6fb)"' : '' ?>>
    <td><?= e(d((string) $l['work_date'])) ?></td>
    <?php if (! $ltOwn): ?><td><?= e($l['full_name']) ?></td><?php endif; ?>
    <td><?= e($ltHm($l['shift_start'])) ?> → <?= e($ltHm($l['arrived_at'])) ?></td>
    <td class="num"><?= te(':n min', ['n' => (int) $l['minutes_late']]) ?></td>
    <td class="num" data-docked="<?= (int) $l['id'] ?>"><?= in_array($l['status'], LATENESS_DOCKING, true) ? te(':n min', ['n' => (int) $l['docked_minutes']]) : '—' ?></td>
    <td class="small"><?= e($l['reason']) ?><div class="muted"><?= te('by :name', ['name' => $l['recorded_by_name'] ?? '']) ?></div>
      <?php if ($l['dispute_note']): ?><div><strong><?= te('Dispute') ?>:</strong> <?= e($l['dispute_note']) ?></div><?php endif; ?>
      <?php if ($l['decision_note']): ?><div><strong><?= te('Decision') ?>:</strong> <?= e($l['decision_note']) ?> <span class="muted"><?= e($l['decided_by_name'] ?? '') ?></span></div><?php endif; ?></td>
    <td><span class="tag <?= $ltTone[$l['status']] ?? 'grey' ?>"><?= e($ltStatus[$l['status']] ?? $l['status']) ?></span><?php if ($l['frozen']): ?><div class="small muted"><?= te('week approved for pay') ?></div><?php endif; ?></td>
    <td style="min-width:220px">
      <?php if ($ltOwn && $l['status'] === 'recorded' && ! $l['frozen']): ?>
        <form method="post" action="/lateness" data-dispute="<?= (int) $l['id'] ?>"><?= csrf_field() ?><input type="hidden" name="do" value="dispute"><input type="hidden" name="lateness_id" value="<?= (int) $l['id'] ?>">
          <input name="note" maxlength="1000" required placeholder="<?= te('Why it is wrong') ?>"><button class="btn ghost sm" style="margin-top:4px"><?= te('Dispute') ?></button></form>
      <?php elseif ($ltPayroll && in_array($l['status'], ['recorded', 'disputed'], true) && ! $l['frozen']): ?>
        <form method="post" action="/lateness" data-decide="<?= (int) $l['id'] ?>"><?= csrf_field() ?><input type="hidden" name="do" value="decide"><input type="hidden" name="lateness_id" value="<?= (int) $l['id'] ?>">
          <select name="decision" aria-label="<?= te('Decision') ?>"><option value="upheld"><?= te('Uphold') ?></option><option value="reduced"><?= te('Reduce to') ?></option><option value="cancelled"><?= te('Cancel') ?></option></select>
          <input name="minutes" type="number" min="0" max="<?= (int) $l['docked_minutes'] ?>" placeholder="<?= te('minutes') ?>" style="width:90px">
          <input name="note" maxlength="1000" required placeholder="<?= te('Decision the worker will read') ?>" style="margin-top:4px"><button class="btn ghost sm" style="margin-top:4px"><?= te('Settle') ?></button></form>
      <?php endif; ?>
    </td>
  </tr><?php endforeach; ?>
</table></div></div>
<?php endif; ?>

<?php if ($ltPayroll && $ltCrew): ?>
<form method="post" action="/lateness" class="card" id="assignment-shift"><?= csrf_field() ?><input type="hidden" name="do" value="assignment_shift">
  <h2><?= te('A crew member on another shift') ?></h2>
  <p class="small muted"><?= te('Night crews and staggered starts: an assignment\'s own shift start wins over the project\'s. Leave it empty to use the project\'s.') ?></p>
  <div class="row"><div style="flex:2"><select name="placement_id" aria-label="<?= te('Worker') ?>"><?php foreach ($ltCrew as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['full_name']) ?><?= $c['shift_start'] ? ' · ' . e($ltHm($c['shift_start'])) : '' ?></option><?php endforeach; ?></select></div>
    <div style="max-width:130px"><input name="shift_start" placeholder="19:00" maxlength="5" aria-label="<?= te('Shift starts at') ?>"></div>
    <div><button class="btn ghost"><?= te('Save') ?></button></div></div>
</form>
<?php endif; ?>
