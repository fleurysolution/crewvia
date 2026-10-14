<?php
$ptStatus = pt_statuses();
$ptPriority = pt_priorities();
$ptStatusTone = ['todo' => 'grey', 'in_progress' => 'blue', 'blocked' => 'red', 'done' => 'green', 'cancelled' => 'grey'];
$ptPriorityTone = ['urgent' => 'red', 'high' => 'amber', 'normal' => 'grey', 'low' => 'grey'];
?>
<div class="page-head"><div><h1><?= te('My tasks') ?></h1>
<p class="sub"><?= te('What is assigned to you on every project, the open ones first. Move each along and say when something stops you.') ?></p></div></div>
<div class="card" id="my-task-list" data-count="<?= count($tasks) ?>">
  <?php if (! $tasks): ?><p class="muted"><?= te('Nothing is assigned to you.') ?></p><?php else: ?>
  <?php foreach ($tasks as $tk): $late = $tk['due_on'] && $tk['due_on'] < $today && in_array($tk['status'], PT_OPEN, true); $closed = $tk['status'] === 'cancelled'; ?>
  <div class="card" data-task="<?= (int) $tk['id'] ?>" data-status="<?= e($tk['status']) ?>" id="task-<?= (int) $tk['id'] ?>" style="margin:8px 0">
    <h3 class="m0"><?= e($tk['title']) ?> <span class="tag <?= $ptStatusTone[$tk['status']] ?>"><?= e($ptStatus[$tk['status']]) ?></span> <span class="tag <?= $ptPriorityTone[$tk['priority']] ?>"><?= e($ptPriority[$tk['priority']]) ?></span>
      <?php if ($late): ?><span class="tag red"><?= te('Overdue') ?></span><?php endif; ?></h3>
    <p class="small muted"><?= e($tk['job_title']) ?><?= $tk['milestone_title'] ? ' · ' . e($tk['milestone_title']) : '' ?> · <?= te('Due') ?>: <?= $tk['due_on'] ? e(d($tk['due_on'])) : '–' ?></p>
    <?php if ($tk['description']): ?><p class="pre-wrap"><?= e($tk['description']) ?></p><?php endif; ?>
    <?php if ($tk['status'] === 'blocked'): ?><p class="small"><?= te('Waiting for') ?>: <?= e((string) $tk['blocked_reason']) ?></p><?php endif; ?>
    <?php if (! $closed): ?>
    <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="task_id" value="<?= (int) $tk['id'] ?>">
      <label><?= te('Status') ?><select name="status"><?php foreach ($ptStatus as $k => $label): if ($k === 'cancelled') continue; ?><option value="<?= e($k) ?>"<?= $k === $tk['status'] ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
      <label><?= te('If blocked, waiting for') ?><input name="blocked_reason" maxlength="500" value="<?= e((string) $tk['blocked_reason']) ?>"></label>
      <label><?= te('Note') ?><input name="note" maxlength="1000"></label>
      <button class="btn sm"><?= te('Save') ?></button></form>
    <?php endif; ?>
    <?php if ((int) $tk['id'] === $focus && $events): ?>
      <table class="small" id="my-task-history"><?php foreach ($events as $ev): ?><tr data-event="<?= e($ev['kind']) ?>"><td><?= e((string) $ev['created_at']) ?></td><td><?= e((string) ($ev['by_name'] ?? '–')) ?></td><td><?= e(pt_event_text($ev)) ?><?php if ($ev['note']): ?><div class="pre-wrap"><?= e($ev['note']) ?></div><?php endif; ?></td></tr><?php endforeach; ?></table>
    <?php else: ?><p class="small"><a href="/my-tasks?task=<?= (int) $tk['id'] ?>#task-<?= (int) $tk['id'] ?>"><?= te('History') ?></a></p><?php endif; ?>
  </div>
  <?php endforeach; ?><?php endif; ?>
</div>
