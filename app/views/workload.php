<?php $wlHours = fn($h) => rtrim(rtrim(number_format((float) $h, 1, '.', ''), '0'), '.') ?: '0'; ?>
<div class="page-head"><div><h1><?= te('Workload') ?></h1>
<p class="sub"><?= te('Open task hours per person and week, against the hours each can give, less approved time off. A task past its date counts in this week; one without a date or an estimate is shown apart.') ?></p></div></div>

<div class="card" id="workload" data-people="<?= count($workload['people']) ?>">
  <?php if (! $workload['people']): ?><p class="muted"><?= te('Nobody has open tasks.') ?></p><?php else: ?>
  <div class="scroll"><table>
    <tr><th><?= te('Person') ?></th>
      <?php foreach ($workload['weeks'] as $w): ?><th class="small"><?= te('Week of :date', ['date' => d($w)]) ?></th><?php endforeach; ?>
      <th class="small"><?= te('Later') ?></th><th class="small"><?= te('No date') ?></th><th class="small"><?= te('Open tasks') ?></th><?php if ($canSetCapacity): ?><th class="small"><?= te('Hours a week') ?></th><?php endif; ?></tr>
    <?php foreach ($workload['people'] as $p): ?>
    <tr data-person="<?= (int) $p['user_id'] ?>" data-over="<?= in_array(true, $p['over'], true) ? '1' : '0' ?>">
      <td><?= e($p['name']) ?></td>
      <?php foreach ($workload['weeks'] as $w): ?>
        <td data-week="<?= e($w) ?>" data-planned="<?= e($wlHours($p['planned'][$w])) ?>" data-capacity="<?= e($wlHours($p['capacity'][$w])) ?>"<?= $p['over'][$w] ? ' class="bg-alert"' : '' ?>>
          <?= e($wlHours($p['planned'][$w])) ?> / <?= e($wlHours($p['capacity'][$w])) ?> h
          <?php if ($p['over'][$w]): ?><span class="tag red"><?= te('Over') ?></span><?php endif; ?>
          <?php if ($p['leave_days'][$w]): ?><div class="small muted"><?= ten($p['leave_days'][$w], ':n day off', ':n days off') ?></div><?php endif; ?></td>
      <?php endforeach; ?>
      <td class="small"><?= e($wlHours($p['later'])) ?> h</td><td class="small"><?= e($wlHours($p['undated'])) ?> h</td>
      <td class="small"><?= (int) $p['open'] ?><?php if ($p['unestimated']): ?> <span class="muted">(<?= te(':n without an estimate', ['n' => $p['unestimated']]) ?>)</span><?php endif; ?>
        <?php if ($p['overdue']): ?> <span class="tag red"><?= te(':n overdue', ['n' => $p['overdue']]) ?></span><?php endif; ?></td>
      <?php if ($canSetCapacity): ?><td>
        <form method="post" class="row gap-xs"><?= csrf_field() ?><input type="hidden" name="do" value="capacity"><input type="hidden" name="user_id" value="<?= (int) $p['user_id'] ?>">
          <input name="hours_per_week" type="number" min="0" max="80" step="0.5" value="<?= e($wlHours($p['hours_per_week'])) ?>" class="fw-xs" aria-label="<?= te('Hours a week for :name', ['name' => $p['name']]) ?>">
          <button class="btn sm ghost"><?= te('Save') ?></button></form></td><?php endif; ?>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>

<div class="card" id="balancing">
  <h2><?= te('Balancing') ?></h2>
  <?php if (! $suggestions): ?><p class="muted"><?= te('Nobody is over capacity, or nobody on the same project has room to take a task.') ?></p><?php else: ?>
  <table><tr><th><?= te('Week of') ?></th><th><?= te('Task') ?></th><th><?= te('From') ?></th><th><?= te('Could go to') ?></th><th></th></tr>
  <?php foreach ($suggestions as $s): ?>
    <tr data-suggest="<?= (int) $s['task']['id'] ?>" data-to="<?= (int) $s['to']['user_id'] ?>">
      <td class="small"><?= e(d($s['week'])) ?></td>
      <td><a href="/project-tasks?task=<?= (int) $s['task']['id'] ?>"><?= e($s['task']['title']) ?></a><div class="small muted"><?= e($s['task']['job_title']) ?> · <?= e($wlHours($s['task']['estimate_hours'])) ?> h</div></td>
      <td><?= e($s['from']) ?></td><td><?= e($s['to']['name']) ?> <span class="small muted">(<?= te(':h h free that week', ['h' => $wlHours($s['to']['room'])]) ?>)</span></td>
      <td><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="reassign"><input type="hidden" name="task_id" value="<?= (int) $s['task']['id'] ?>"><input type="hidden" name="assignee_id" value="<?= (int) $s['to']['user_id'] ?>">
        <button class="btn sm"><?= te('Reassign') ?></button></form></td></tr>
  <?php endforeach; ?></table><?php endif; ?>
</div>
