<?php
$mtTone = ['due' => 'blue', 'overdue' => 'red', 'done' => 'green', 'expiring' => 'amber', 'expired' => 'red', 'none' => 'grey'];
$mtStatus = tr_statuses();
?>
<?php if ($take): $st = tr_status($take); ?>
<p class="back-link"><a href="/my-training">← <?= te('My training') ?></a></p>
<div class="page-head"><div><h1><?= e($take['title']) ?></h1>
<p class="sub"><span class="tag <?= $mtTone[$st] ?>" id="take-status" data-status="<?= e($st) ?>"><?= e($mtStatus[$st]) ?></span> · <?= te('Due') ?>: <?= e(d((string) $take['due_on'])) ?></p></div></div>
<?php if ($take['description']): ?><p><?= e($take['description']) ?></p><?php endif; ?>
<div class="card" id="course-content"><div class="pre-wrap wrap-any"><?= e((string) $take['content']) ?></div>
  <?php if ($take['resource_url']): ?><p><a href="<?= e($take['resource_url']) ?>" rel="noopener noreferrer" target="_blank"><?= te('Open the course material') ?></a></p><?php endif; ?></div>
<?php if ($take['completed_at'] === null): ?>
<form method="post" class="card" id="assessment-form"><?= csrf_field() ?><input type="hidden" name="assignment_id" value="<?= (int) $take['id'] ?>">
  <?php if ($questions): ?>
  <h2><?= te('Assessment') ?></h2>
  <p class="small muted"><?= te('Pass mark: :n%.', ['n' => (int) $take['pass_mark']]) ?></p>
  <?php foreach ($questions as $i => $qq): ?>
  <fieldset data-question="<?= (int) $qq['id'] ?>" style="margin:10px 0"><legend><strong><?= $i + 1 ?>. <?= e($qq['question']) ?></strong></legend>
    <?php foreach ($qq['options'] as $oi => $op): ?><label style="display:block"><input type="radio" name="answer[<?= (int) $qq['id'] ?>]" value="<?= (int) $oi ?>" required> <?= e($op) ?></label><?php endforeach; ?></fieldset>
  <?php endforeach; ?>
  <button class="btn"><?= te('Hand it in') ?></button>
  <?php else: ?>
  <label><input type="checkbox" name="confirmed" value="1" required> <?= te('I have read and understood it.') ?></label>
  <button class="btn"><?= te('Done') ?></button>
  <?php endif; ?>
</form>
<?php endif; ?>
<?php if ($attempts): ?><div class="card" id="attempts"><h2><?= te('Attempts') ?></h2><table><?php foreach ($attempts as $at): ?><tr data-attempt-passed="<?= (int) $at['passed'] ?>"><td class="small"><?= e((string) $at['submitted_at']) ?></td><td><?= (int) $at['score'] ?>%</td><td><?= (int) $at['passed'] ? te('Passed') : te('Not passed') ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
<?php else: ?>
<div class="page-head"><div><h1><?= te('My training') ?></h1>
<p class="sub"><?= te('What you have to take and by when, and what you have done, until it needs renewing.') ?></p></div></div>
<div class="card scroll" id="my-training-list" data-count="<?= count($mine) ?>">
  <?php if (! $mine): ?><p class="muted"><?= te('No training is assigned to you.') ?></p><?php else: ?>
  <table><tr><th><?= te('Course') ?></th><th><?= te('State') ?></th><th><?= te('Due') ?></th><th><?= te('Score') ?></th><th><?= te('Counts until') ?></th></tr>
  <?php foreach ($mine as $m): $st = tr_status($m); ?><tr data-assignment="<?= (int) $m['id'] ?>" data-status="<?= e($st) ?>">
    <td><a href="/my-training?take=<?= (int) $m['id'] ?>"><?= e($m['title']) ?></a></td><td><span class="tag <?= $mtTone[$st] ?>"><?= e($mtStatus[$st]) ?></span></td>
    <td class="small"><?= $m['completed_at'] === null ? e(d((string) $m['due_on'])) : '' ?></td><td class="small"><?= $m['score'] !== null ? (int) $m['score'] . '%' : '' ?></td>
    <td class="small"><?= $m['completed_at'] !== null ? ($m['expires_on'] ? e(d((string) $m['expires_on'])) : te('for good')) : '' ?></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>
<?php endif; ?>
