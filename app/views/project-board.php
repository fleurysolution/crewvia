<?php
$pbStatus = pt_statuses();
$pbPriority = pt_priorities();
$pbPriorityTone = ['urgent' => 'red', 'high' => 'amber', 'normal' => 'grey', 'low' => 'grey'];
$pbSprintStatus = pp_sprint_statuses();
?>
<h1><?= te('Project board') ?></h1>
<?php if (! $job): ?>
<p class="muted"><?= te('Open a project first: tasks belong to a project.') ?></p>
<?php return; endif; ?>
<p class="sub"><?= te('The tasks of :project by where they stand. Move a card when the work moves; the task\'s history keeps every move.', ['project' => $job['title']]) ?>
  <a href="/project-tasks"><?= te('Task list') ?></a> · <a href="/workload"><?= te('Workload') ?></a></p>

<form method="get" class="row card" id="board-filters">
  <label><?= te('Sprint') ?><select name="sprint">
    <option value="0"<?= $filter['sprint'] === 0 ? ' selected' : '' ?>><?= te('All tasks') ?></option>
    <option value="-1"<?= $filter['sprint'] === -1 ? ' selected' : '' ?>><?= te('Backlog (no sprint)') ?></option>
    <?php foreach ($sprints as $s): ?><option value="<?= (int) $s['id'] ?>"<?= $filter['sprint'] === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['name'] . ' · ' . $pbSprintStatus[$s['status']]) ?></option><?php endforeach; ?></select></label>
  <label><?= te('Assigned to') ?><select name="assignee"><option value="0"><?= te('Anyone') ?></option><?php foreach ($assignable as $a): ?><option value="<?= (int) $a['id'] ?>"<?= $filter['assignee'] === (int) $a['id'] ? ' selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></label>
  <label><?= te('Milestone') ?><select name="milestone"><option value="0"><?= te('Any') ?></option><?php foreach ($milestones as $m): ?><option value="<?= (int) $m['id'] ?>"<?= $filter['milestone'] === (int) $m['id'] ? ' selected' : '' ?>><?= e($m['title']) ?></option><?php endforeach; ?></select></label>
  <button class="btn ghost"><?= te('Show') ?></button>
</form>

<div id="board" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px;align-items:start">
<?php foreach ($columns as $status => $cards): ?>
  <section class="card" data-column="<?= e($status) ?>" data-count="<?= count($cards) ?>" aria-label="<?= e($pbStatus[$status]) ?>">
    <h2 style="font-size:16px"><?= e($pbStatus[$status]) ?> <span class="small muted">(<?= count($cards) ?>)</span></h2>
    <?php if (! $cards): ?><p class="small muted"><?= te('Nothing here.') ?></p><?php endif; ?>
    <?php foreach ($cards as $c): $late = $c['due_on'] && $c['due_on'] < $today && $status !== 'done'; $mayMove = $manage || (int) $c['assignee_id'] === uid(); ?>
    <article class="card" data-card="<?= (int) $c['id'] ?>" data-waiting="<?= (int) $c['waiting'] ?>" style="margin:8px 0;padding:10px">
      <a href="/project-tasks?task=<?= (int) $c['id'] ?>"><strong><?= e($c['title']) ?></strong></a>
      <?php if ($c['parent_title']): ?><div class="small muted"><?= te('Subtask of :title', ['title' => $c['parent_title']]) ?></div><?php endif; ?>
      <div class="small"><span class="tag <?= $pbPriorityTone[$c['priority']] ?>"><?= e($pbPriority[$c['priority']]) ?></span>
        <?= e((string) ($c['assignee_name'] ?? '–')) ?><?= $c['estimate_hours'] !== null ? ' · ' . e(t(':h h', ['h' => rtrim(rtrim((string) $c['estimate_hours'], '0'), '.')])) : '' ?>
        <?php if ($c['due_on']): ?> · <?= e(d($c['due_on'])) ?><?php if ($late): ?> <span class="tag red"><?= te('Overdue') ?></span><?php endif; ?><?php endif; ?></div>
      <?php if ($c['subtasks']): ?><div class="small"><?= te('Subtasks: :done of :live done', ['done' => $c['subtasks']['done'], 'live' => $c['subtasks']['live']]) ?></div><?php endif; ?>
      <?php if ($c['waiting']): ?><div class="small"><span class="tag amber"><?= te('Waits for :n task(s)', ['n' => $c['waiting']]) ?></span></div><?php endif; ?>
      <?php if ($status === 'blocked' && $c['blocked_reason']): ?><div class="small"><?= te('Waiting for') ?>: <?= e($c['blocked_reason']) ?></div><?php endif; ?>
      <?php if ($mayMove): ?>
      <div class="row" style="gap:4px;margin-top:6px">
        <?php foreach (pp_moves($status) as $to => $label): if ($to === 'blocked') continue; ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="move"><input type="hidden" name="task_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="status" value="<?= e($to) ?>">
            <?php foreach ($filter as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= (int) $v ?>"><?php endforeach; ?>
            <button class="btn sm ghost" aria-label="<?= e($label . ': ' . $c['title']) ?>"><?= e($label) ?></button></form>
        <?php endforeach; ?>
        <?php if ($status !== 'blocked' && $status !== 'done'): ?>
        <details><summary class="small"><?= te('Mark blocked') ?></summary>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="move"><input type="hidden" name="task_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="status" value="blocked">
            <?php foreach ($filter as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= (int) $v ?>"><?php endforeach; ?>
            <label class="small"><?= te('Waiting for') ?><input name="blocked_reason" required maxlength="500"></label><button class="btn sm"><?= te('Mark blocked') ?></button></form>
        </details><?php endif; ?>
      </div>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  </section>
<?php endforeach; ?>
</div>

<div class="card" id="sprints">
  <h2><?= te('Sprints') ?></h2>
  <p class="small muted"><?= te('Optional. A project works without them; with them, one runs at a time, and closing it returns its open tasks to the backlog.') ?></p>
  <?php if (! $sprints): ?><p class="muted"><?= te('No sprint on this project.') ?></p><?php else: ?>
  <table><tr><th><?= te('Sprint') ?></th><th><?= te('Dates') ?></th><th><?= te('State') ?></th><th><?= te('Tasks done') ?></th><th><?= te('Hours done') ?></th><?php if ($manage): ?><th></th><?php endif; ?></tr>
  <?php foreach ($sprints as $s): ?>
    <tr data-sprint="<?= (int) $s['id'] ?>" data-status="<?= e($s['status']) ?>" data-live="<?= (int) $s['live'] ?>" data-done="<?= (int) $s['done'] ?>" data-returned="<?= (int) $s['returned_count'] ?>">
      <td><a href="/project-board?sprint=<?= (int) $s['id'] ?>"><?= e($s['name']) ?></a><?php if ($s['goal']): ?><div class="small muted"><?= e($s['goal']) ?></div><?php endif; ?></td>
      <td class="small"><?= e(d($s['starts_on'])) ?> – <?= e(d($s['ends_on'])) ?></td>
      <td><span class="tag <?= ['planned' => 'blue', 'active' => 'green', 'closed' => 'grey'][$s['status']] ?>"><?= e($pbSprintStatus[$s['status']]) ?></span></td>
      <td><?= (int) $s['done'] ?> / <?= (int) $s['live'] ?><?php if ($s['status'] === 'closed' && $s['returned_count']): ?><div class="small muted"><?= te(':n back to the backlog', ['n' => (int) $s['returned_count']]) ?></div><?php endif; ?></td>
      <td class="small"><?= e(rtrim(rtrim((string) $s['hours_done'], '0'), '.') ?: '0') ?> / <?= e(rtrim(rtrim((string) $s['hours'], '0'), '.') ?: '0') ?></td>
      <?php if ($manage): ?><td>
        <?php if ($s['status'] === 'planned'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="sprint_start"><input type="hidden" name="sprint_id" value="<?= (int) $s['id'] ?>"><button class="btn sm"><?= te('Start') ?></button></form><?php endif; ?>
        <?php if ($s['status'] === 'active'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="sprint_close"><input type="hidden" name="sprint_id" value="<?= (int) $s['id'] ?>"><button class="btn sm ghost"><?= te('Close the sprint') ?></button></form><?php endif; ?>
      </td><?php endif; ?>
    </tr>
  <?php endforeach; ?></table><?php endif; ?>
  <?php if ($manage): ?>
  <form method="post" class="row" id="sprint-form" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="do" value="sprint_save">
    <label><?= te('New sprint') ?><input name="name" required maxlength="120"></label>
    <label><?= te('From') ?><input type="date" name="starts_on" required></label>
    <label><?= te('To') ?><input type="date" name="ends_on" required></label>
    <label><?= te('Goal') ?><input name="goal" maxlength="500"></label>
    <button class="btn"><?= te('Plan the sprint') ?></button></form>
  <?php endif; ?>
</div>
