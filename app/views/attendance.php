<?php
$statusWords = ['submitted' => t('Waiting for review'), 'approved' => t('Approved'), 'rejected' => t('Rejected')];
$staff = ! $own && (can('payroll') || $supervisor);
?>
<h1><?= te('Daily attendance') ?></h1><p class="sub"><?= te('Worker submissions, supervisor review and a dated record of who worked on site. Weekly payroll sheets remain a separate approval step.') ?></p><?php if($own && !$supervisor): ?><form class="card row" method="post"><?= csrf_field() ?><input type="hidden" name="do" value="submit"><div><label><?= te('Assignment') ?></label><select name="placement_id"><?php foreach($assignments as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['title']) ?></option><?php endforeach; ?></select></div><div><label><?= te('Date') ?></label><input type="date" name="work_date" required></div><div><label><?= te('Hours') ?></label><input name="hours" type="number" min="0" max="24" step="0.25" required></div><button class="btn"><?= te('Submit hours') ?></button></form><?php endif; ?>
<?php if($staff): ?>
<?php if(can('payroll')): ?><p><a class="btn ghost" href="/attendance-week"><?= te('Week check: exceptions and reconciliation') ?></a></p><?php endif; ?>
<form class="card" method="post" id="enter-day"><?= csrf_field() ?><input type="hidden" name="do" value="enter">
  <h2><?= te('Enter a day for a worker') ?></h2>
  <p class="muted"><?= te('For a day the worker could not enter themselves. You validate it by entering it, so say why in the note; the worker sees it.') ?></p>
  <?php if(!$crew): ?>
    <p class="muted"><?= te('Nobody on your crew is on an assignment, so there is nobody to enter a day for.') ?></p>
  <?php else: ?>
  <div class="row">
    <div><label><?= te('Worker') ?></label><select name="placement_id"><?php foreach($crew as $m): ?><option value="<?= (int)$m['id'] ?>"><?= e($m['full_name'].' · '.$m['title']) ?></option><?php endforeach; ?></select></div>
    <div><label><?= te('Date') ?></label><input type="date" name="work_date" required max="<?= e(date('Y-m-d')) ?>"></div>
    <div><label><?= te('Hours') ?></label><input name="hours" type="number" min="0" max="24" step="0.25" required></div>
  </div>
  <label><?= te('Note') ?></label><input name="note" required minlength="3" maxlength="500">
  <button class="btn"><?= te('Record the day') ?></button>
  <?php endif; ?>
</form>
<?php endif; ?>
<?php if(!$records): ?>
<div class="card"><div class="empty">
  <?php if($own && !$supervisor): ?>
    <?= te('No hours submitted yet. Use the form above for each day you worked.') ?>
  <?php else: ?>
    <?= te('No attendance yet. Workers submit their days from their own portal; deployed crew appear here for review.') ?>
  <?php endif; ?>
</div></div>
<?php endif; ?>
<div class="card scroll"><table><tr><th><?= te('Worker') ?></th><th><?= te('Project') ?></th><th><?= te('Date') ?></th><th><?= te('Hours') ?></th><th><?= te('Status') ?></th><th><?= te('Review') ?></th></tr><?php foreach($records as $r): $history = $corrections[(int)$r['id']] ?? []; ?><tr><td><?= e($r['full_name']) ?></td><td><?= e($r['title']) ?></td><td><?= e($r['work_date']) ?></td><td><?= e((string)(float)$r['hours']) ?><?php foreach($history as $h): ?><div class="small muted"><?= te('Was :old h, changed by :name on :date: :reason', ['old' => (string)(float)$h['old_hours'], 'name' => $h['corrected_by_name'] ?? '—', 'date' => d(substr((string)$h['corrected_at'],0,10)), 'reason' => $h['reason']]) ?></div><?php endforeach; ?></td><td><?= e($statusWords[$r['status']] ?? $r['status']) ?><?php if(($r['source'] ?? 'worker')==='staff'): ?><div class="small muted"><?= te('Entered by staff: :note', ['note' => (string)$r['note']]) ?></div><?php endif; ?></td><td><?php if((can('payroll') || $supervisor) && $r['status']==='submitted'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="review"><input type="hidden" name="attendance_id" value="<?= (int)$r['id'] ?>"><select name="status"><option value="approved"><?= te('approved') ?></option><option value="rejected"><?= te('rejected') ?></option></select><button class="btn sm"><?= te('Review') ?></button></form><?php elseif($staff && $r['status']==='approved'): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="correct"><input type="hidden" name="attendance_id" value="<?= (int)$r['id'] ?>"><input name="hours" type="number" min="0" max="24" step="0.25" required value="<?= e((string)(float)$r['hours']) ?>" style="max-width:6em" aria-label="<?= te('Corrected hours') ?>"><input name="reason" required minlength="3" maxlength="500" placeholder="<?= te('Reason') ?>" aria-label="<?= te('Reason') ?>"><button class="btn sm"><?= te('Correct') ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></table></div>
