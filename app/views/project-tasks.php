<?php
$ptStatus = pt_statuses();
$ptPriority = pt_priorities();
$ptStatusTone = ['todo' => 'grey', 'in_progress' => 'blue', 'blocked' => 'red', 'done' => 'green', 'cancelled' => 'grey'];
$ptPriorityTone = ['urgent' => 'red', 'high' => 'amber', 'normal' => 'grey', 'low' => 'grey'];
?>
<h1><?= te('Project tasks') ?></h1>
<?php if (! $job): ?>
<p class="muted"><?= te('Open a project first: tasks belong to a project.') ?></p>
<?php return; endif; ?>
<p class="sub"><?= te('The milestones of :project, the tasks that reach them, who does each and by when.', ['project' => $job['title']]) ?>
  <a href="/project-board"><?= te('Board') ?></a> · <a href="/workload"><?= te('Workload') ?></a></p>

<div class="card" id="task-progress" data-percent="<?= $progress['percent'] ?? '' ?>" data-live="<?= (int) $progress['live'] ?>" data-done="<?= (int) $progress['done'] ?>" data-overdue="<?= (int) $progress['overdue'] ?>">
  <h2><?= te('Progress') ?></h2>
  <?php if (! $progress['live']): ?><p class="muted"><?= te('No task on this project yet.') ?></p><?php else: ?>
  <div role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $progress['percent'] ?>" aria-label="<?= te('Tasks done') ?>" style="background:#e3e8ee;border-radius:6px;height:12px;overflow:hidden;max-width:520px">
    <div style="background:#2e7d4f;height:12px;width:<?= (int) $progress['percent'] ?>%"></div></div>
  <p><?= te(':done of :live tasks done (:percent%)', ['done' => $progress['done'], 'live' => $progress['live'], 'percent' => $progress['percent']]) ?>
     · <?= te(':n open', ['n' => $progress['open']]) ?> · <?= te(':n blocked', ['n' => $progress['counts']['blocked']]) ?>
     · <span class="<?= $progress['overdue'] ? 'tag red' : '' ?>"><?= te(':n overdue', ['n' => $progress['overdue']]) ?></span>
     · <?= te(':reached of :total milestones reached', ['reached' => $progress['milestones_reached'], 'total' => $progress['milestones']]) ?></p>
  <?php endif; ?>
</div>

<div class="card" id="milestones">
  <h2><?= te('Milestones') ?></h2>
  <?php if (! $milestones): ?><p class="muted"><?= te('No milestone yet.') ?></p><?php else: ?>
  <table><tr><th><?= te('Milestone') ?></th><th><?= te('Due') ?></th><th><?= te('Tasks done') ?></th><th><?= te('State') ?></th><?php if ($manage): ?><th></th><?php endif; ?></tr>
  <?php foreach ($milestones as $m): ?>
    <tr data-milestone="<?= (int) $m['id'] ?>" data-reached="<?= $m['reached'] ? '1' : '0' ?>" data-overdue="<?= $m['overdue'] ? '1' : '0' ?>">
      <td><a href="/project-tasks?milestone=<?= (int) $m['id'] ?>&amp;status=all"><?= e($m['title']) ?></a><?php if ($m['description']): ?><div class="small muted"><?= e($m['description']) ?></div><?php endif; ?></td>
      <td><?= $m['due_on'] ? e(d($m['due_on'])) : '–' ?></td>
      <td><?= (int) $m['done'] ?> / <?= (int) $m['live'] ?><?= $m['percent'] !== null ? ' (' . (int) $m['percent'] . '%)' : '' ?></td>
      <td><?php if ($m['cancelled_at']): ?><span class="tag grey"><?= te('Cancelled') ?></span><?php elseif ($m['reached']): ?><span class="tag green"><?= te('Milestone reached') ?></span><?php elseif ($m['overdue']): ?><span class="tag red"><?= te('Overdue') ?></span><?php else: ?><span class="tag blue"><?= te('Under way') ?></span><?php endif; ?></td>
      <?php if ($manage): ?><td><?php if (! $m['cancelled_at']): ?>
        <details><summary class="small"><?= te('Change') ?></summary>
          <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="milestone_save"><input type="hidden" name="milestone_id" value="<?= (int) $m['id'] ?>">
            <label><?= te('Title of the milestone') ?><input name="title" required maxlength="190" value="<?= e($m['title']) ?>"></label>
            <label><?= te('Due') ?><input type="date" name="due_on" value="<?= e((string) $m['due_on']) ?>"></label>
            <label><?= te('Description') ?><input name="description" maxlength="5000" value="<?= e((string) $m['description']) ?>"></label>
            <button class="btn sm"><?= te('Save') ?></button></form>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="milestone_cancel"><input type="hidden" name="milestone_id" value="<?= (int) $m['id'] ?>"><button class="btn sm ghost"><?= te('Cancel this milestone') ?></button></form>
        </details><?php endif; ?></td><?php endif; ?>
    </tr>
  <?php endforeach; ?></table><?php endif; ?>
  <?php if ($manage): ?>
  <form method="post" class="row" id="milestone-form" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="do" value="milestone_save">
    <label><?= te('New milestone') ?><input name="title" required maxlength="190"></label>
    <label><?= te('Due') ?><input type="date" name="due_on"></label>
    <label><?= te('Description') ?><input name="description" maxlength="5000"></label>
    <button class="btn"><?= te('Add the milestone') ?></button></form>
  <?php endif; ?>
</div>

<?php if ($detail): $mineTask = (int) $detail['assignee_id'] === uid(); ?>
<div class="card" id="task-detail" data-task="<?= (int) $detail['id'] ?>" data-status="<?= e($detail['status']) ?>">
  <h2><?= e($detail['title']) ?> <span class="tag <?= $ptStatusTone[$detail['status']] ?>"><?= e($ptStatus[$detail['status']]) ?></span> <span class="tag <?= $ptPriorityTone[$detail['priority']] ?>"><?= e($ptPriority[$detail['priority']]) ?></span></h2>
  <p><?= te('Assigned to') ?>: <?= e((string) ($detail['assignee_name'] ?? '–')) ?> · <?= te('Due') ?>: <?= $detail['due_on'] ? e(d($detail['due_on'])) : '–' ?>
     <?php if ($detail['due_on'] && $detail['due_on'] < $today && in_array($detail['status'], PT_OPEN, true)): ?><span class="tag red"><?= te('Overdue') ?></span><?php endif; ?>
     · <?= te('Milestone') ?>: <?= e((string) ($detail['milestone_title'] ?? '–')) ?>
     · <?= te('Estimate') ?>: <?= $detail['estimate_hours'] !== null ? e(t(':h h', ['h' => rtrim(rtrim((string) $detail['estimate_hours'], '0'), '.')])) : '–' ?></p>
  <?php if ($detail['description']): ?><p style="white-space:pre-wrap"><?= e($detail['description']) ?></p><?php endif; ?>
  <?php if ($detail['status'] === 'blocked'): ?><p class="flash err"><?= te('Waiting for') ?>: <?= e((string) $detail['blocked_reason']) ?></p><?php endif; ?>

  <?php if ($manage || $mineTask): ?>
  <form method="post" class="row" id="task-status-form"><?= csrf_field() ?><input type="hidden" name="do" value="update"><input type="hidden" name="task_id" value="<?= (int) $detail['id'] ?>">
    <label><?= te('Status') ?><select name="status"><?php foreach ($ptStatus as $k => $label): if ($k === 'cancelled' && ! $manage) continue; ?><option value="<?= e($k) ?>"<?= $k === $detail['status'] ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label><?= te('If blocked, waiting for') ?><input name="blocked_reason" maxlength="500" value="<?= e((string) $detail['blocked_reason']) ?>"></label>
    <label><?= te('Note') ?><input name="note" maxlength="1000"></label>
    <button class="btn"><?= te('Save') ?></button></form>
  <?php endif; ?>

  <?php if ($manage): ?>
  <details style="margin-top:10px"><summary><?= te('Change the task') ?></summary>
  <form method="post" class="grid g2" id="task-edit-form"><?= csrf_field() ?><input type="hidden" name="do" value="update"><input type="hidden" name="task_id" value="<?= (int) $detail['id'] ?>">
    <label><?= te('Title of the task') ?><input name="title" required maxlength="190" value="<?= e($detail['title']) ?>"></label>
    <label><?= te('Assigned to') ?><select name="assignee_id"><option value="0">–</option><?php foreach ($assignable as $a): ?><option value="<?= (int) $a['id'] ?>"<?= (int) $a['id'] === (int) $detail['assignee_id'] ? ' selected' : '' ?>><?= e($a['name'] . ($a['kind'] === 'crew' ? ' · ' . t('crew') : ' · ' . $a['role'])) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Priority') ?><select name="priority"><?php foreach ($ptPriority as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === $detail['priority'] ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Deadline') ?><input type="date" name="due_on" value="<?= e((string) $detail['due_on']) ?>"></label>
    <label><?= te('Milestone') ?><select name="milestone_id"><option value="0">–</option><?php foreach ($milestones as $m): if ($m['cancelled_at']) continue; ?><option value="<?= (int) $m['id'] ?>"<?= (int) $m['id'] === (int) $detail['milestone_id'] ? ' selected' : '' ?>><?= e($m['title']) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Estimate (hours)') ?><input name="estimate_hours" type="number" min="0.5" max="999" step="0.5" value="<?= e($detail['estimate_hours'] === null ? '' : rtrim(rtrim((string) $detail['estimate_hours'], '0'), '.')) ?>"></label>
    <label><?= te('Subtask of') ?><select name="parent_id"><option value="0">–</option><?php foreach ($parents as $pa): if ((int) $pa['id'] === (int) $detail['id']) continue; ?><option value="<?= (int) $pa['id'] ?>"<?= (int) $pa['id'] === (int) $detail['parent_id'] ? ' selected' : '' ?>><?= e($pa['title']) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Sprint') ?><select name="sprint_id"><option value="0"><?= te('Backlog (no sprint)') ?></option><?php foreach ($sprints as $sp): ?><option value="<?= (int) $sp['id'] ?>"<?= (int) $sp['id'] === (int) $detail['sprint_id'] ? ' selected' : '' ?>><?= e($sp['name']) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Description') ?><textarea name="description" maxlength="5000" rows="3"><?= e((string) $detail['description']) ?></textarea></label>
    <div><button class="btn"><?= te('Save the changes') ?></button></div></form>
  </details>
  <?php endif; ?>

  <div class="grid g2" style="margin-top:12px">
    <div id="task-subtasks" data-count="<?= count($subtasks) ?>"><h3><?= te('Subtasks') ?></h3>
      <?php if (! $subtasks): ?><p class="small muted"><?= te('None.') ?></p><?php endif; ?>
      <?php foreach ($subtasks as $st): ?><div class="small" data-subtask="<?= (int) $st['id'] ?>"><a href="/project-tasks?task=<?= (int) $st['id'] ?>"><?= e($st['title']) ?></a> · <?= e(pt_statuses()[$st['status']]) ?> · <?= e((string) ($st['assignee_name'] ?? '–')) ?></div><?php endforeach; ?></div>
    <div id="task-dependencies"><h3><?= te('Waits for') ?></h3>
      <?php if (! $waitsFor): ?><p class="small muted"><?= te('Nothing.') ?></p><?php endif; ?>
      <?php foreach ($waitsFor as $wf): ?><div class="small row" data-waits-for="<?= (int) $wf['id'] ?>" data-status="<?= e($wf['status']) ?>"><a href="/project-tasks?task=<?= (int) $wf['id'] ?>"><?= e($wf['title']) ?></a> · <?= e(pt_statuses()[$wf['status']]) ?>
        <?php if ($manage): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="dependency_remove"><input type="hidden" name="task_id" value="<?= (int) $detail['id'] ?>"><input type="hidden" name="depends_on_id" value="<?= (int) $wf['id'] ?>"><button class="btn sm ghost"><?= te('Remove') ?></button></form><?php endif; ?></div><?php endforeach; ?>
      <?php if ($manage): ?><form method="post" class="row" id="dependency-form"><?= csrf_field() ?><input type="hidden" name="do" value="dependency_add"><input type="hidden" name="task_id" value="<?= (int) $detail['id'] ?>">
        <label class="small"><?= te('Add a task this one waits for') ?><select name="depends_on_id"><?php foreach ($others as $ot): if ((int) $ot['id'] === (int) $detail['id']) continue; ?><option value="<?= (int) $ot['id'] ?>"><?= e($ot['title']) ?></option><?php endforeach; ?></select></label>
        <button class="btn sm"><?= te('Add') ?></button></form><?php endif; ?>
      <?php if ($blocking): ?><h3 style="margin-top:8px"><?= te('Waiting for this task') ?></h3><?php foreach ($blocking as $bl): ?><div class="small" data-blocks="<?= (int) $bl['id'] ?>"><a href="/project-tasks?task=<?= (int) $bl['id'] ?>"><?= e($bl['title']) ?></a> · <?= e(pt_statuses()[$bl['status']]) ?></div><?php endforeach; ?><?php endif; ?></div>
  </div>

  <h3 style="margin-top:14px"><?= te('History') ?></h3>
  <table id="task-history"><?php foreach ($events as $ev): ?>
    <tr data-event="<?= e($ev['kind']) ?>"><td class="small"><?= e((string) $ev['created_at']) ?></td><td class="small"><?= e((string) ($ev['by_name'] ?? '–')) ?></td>
      <td><?= e(pt_event_text($ev)) ?>
      <?php if ($ev['note']): ?><div class="small" style="white-space:pre-wrap"><?= e($ev['note']) ?></div><?php endif; ?></td></tr>
  <?php endforeach; ?></table>
</div>
<?php endif; ?>

<div class="card" id="task-list" data-count="<?= count($tasks) ?>">
  <h2><?= te('Tasks') ?></h2>
  <form method="get" class="row" id="task-filters">
    <label><?= te('Status') ?><select name="status"><option value="open"<?= $filter['status'] === 'open' ? ' selected' : '' ?>><?= te('Open tasks') ?></option><option value="all"<?= $filter['status'] === 'all' ? ' selected' : '' ?>><?= te('All') ?></option><?php foreach ($ptStatus as $k => $label): ?><option value="<?= e($k) ?>"<?= $filter['status'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Assigned to') ?><select name="assignee"><option value="0"><?= te('Anyone') ?></option><?php foreach ($assignable as $a): ?><option value="<?= (int) $a['id'] ?>"<?= $filter['assignee'] === (int) $a['id'] ? ' selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Milestone') ?><select name="milestone"><option value="0"><?= te('Any') ?></option><?php foreach ($milestones as $m): ?><option value="<?= (int) $m['id'] ?>"<?= $filter['milestone'] === (int) $m['id'] ? ' selected' : '' ?>><?= e($m['title']) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Priority') ?><select name="priority"><option value=""><?= te('Any') ?></option><?php foreach ($ptPriority as $k => $label): ?><option value="<?= e($k) ?>"<?= $filter['priority'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label><input type="checkbox" name="overdue" value="1"<?= $filter['overdue'] ? ' checked' : '' ?>> <?= te('Overdue only') ?></label>
    <button class="btn ghost"><?= te('Show') ?></button></form>
  <?php if (! $tasks): ?><p class="muted"><?= te('No task for these filters.') ?></p><?php else: ?>
  <table><tr><th><?= te('Task') ?></th><th><?= te('Assigned to') ?></th><th><?= te('Priority') ?></th><th><?= te('Deadline') ?></th><th><?= te('Milestone') ?></th><th><?= te('Status') ?></th></tr>
  <?php foreach ($tasks as $tk): $late = $tk['due_on'] && $tk['due_on'] < $today && in_array($tk['status'], PT_OPEN, true); ?>
    <tr data-task="<?= (int) $tk['id'] ?>" data-status="<?= e($tk['status']) ?>" data-overdue="<?= $late ? '1' : '0' ?>">
      <td><a href="/project-tasks?task=<?= (int) $tk['id'] ?>"><?= e($tk['title']) ?></a></td><td><?= e((string) ($tk['assignee_name'] ?? '–')) ?></td>
      <td><span class="tag <?= $ptPriorityTone[$tk['priority']] ?>"><?= e($ptPriority[$tk['priority']]) ?></span></td>
      <td><?= $tk['due_on'] ? e(d($tk['due_on'])) : '–' ?><?php if ($late): ?> <span class="tag red"><?= te('Overdue') ?></span><?php endif; ?></td>
      <td class="small"><?= e((string) ($tk['milestone_title'] ?? '–')) ?></td><td><span class="tag <?= $ptStatusTone[$tk['status']] ?>"><?= e($ptStatus[$tk['status']]) ?></span></td></tr>
  <?php endforeach; ?></table><?php endif; ?>
</div>

<?php if ($manage): ?>
<div class="card" id="task-create">
  <h2><?= te('New task') ?></h2>
  <form method="post" class="grid g2" id="task-create-form"><?= csrf_field() ?><input type="hidden" name="do" value="create">
    <label><?= te('Title of the task') ?><input name="title" required maxlength="190"></label>
    <label><?= te('Assigned to') ?><select name="assignee_id"><option value="0">–</option><?php foreach ($assignable as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['name'] . ($a['kind'] === 'crew' ? ' · ' . t('crew') : ' · ' . $a['role'])) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Priority') ?><select name="priority"><?php foreach ($ptPriority as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === 'normal' ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Deadline') ?><input type="date" name="due_on"></label>
    <label><?= te('Milestone') ?><select name="milestone_id"><option value="0">–</option><?php foreach ($milestones as $m): if ($m['cancelled_at']) continue; ?><option value="<?= (int) $m['id'] ?>"><?= e($m['title']) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Estimate (hours)') ?><input name="estimate_hours" type="number" min="0.5" max="999" step="0.5"></label>
    <label><?= te('Subtask of') ?><select name="parent_id"><option value="0">–</option><?php foreach ($parents as $pa): ?><option value="<?= (int) $pa['id'] ?>"><?= e($pa['title']) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Sprint') ?><select name="sprint_id"><option value="0"><?= te('Backlog (no sprint)') ?></option><?php foreach ($sprints as $sp): ?><option value="<?= (int) $sp['id'] ?>"><?= e($sp['name']) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Description') ?><textarea name="description" maxlength="5000" rows="3"></textarea></label>
    <div><button class="btn"><?= te('Create the task') ?></button></div></form>
</div>
<?php endif; ?>
