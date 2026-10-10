<?php
$gtone = ['open' => 'blue', 'achieved' => 'green', 'missed' => 'red', 'dropped' => 'grey'];
$actionRow = static function (array $a, bool $closable): void { ?>
  <tr data-action="<?= (int) $a['id'] ?>" data-action-status="<?= e($a['status']) ?>"<?= $a['overdue'] ? ' data-overdue="1"' : '' ?>>
    <td><?= e(action_kinds()[$a['kind']]) ?></td><td><?= e($a['description']) ?><div class="small muted"><?= e($a['full_name']) ?></div></td><td><?= e($a['owner']) ?></td>
    <td<?= $a['overdue'] ? ' class="err"' : '' ?>><?= e(d((string) $a['due_on'])) ?><?= $a['overdue'] ? ' · ' . te('overdue') : '' ?></td>
    <td><?php if ($a['status'] !== 'open'): ?><span class="tag grey"><?= $a['status'] === 'done' ? te('Done') : te('Cancelled') ?></span> <span class="small"><?= e((string) $a['outcome']) ?></span>
      <?php elseif ($closable): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="action_close"><input type="hidden" name="action_id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="candidate_id" value="<?= (int) $a['candidate_id'] ?>">
        <input name="outcome" required minlength="3" maxlength="1000" placeholder="<?= te('What came of it') ?>" style="max-width:12em"><button class="btn sm" name="as" value="done"><?= te('Done') ?></button><button class="btn ghost sm" name="as" value="cancelled"><?= te('Cancel') ?></button></form><?php endif; ?></td></tr>
<?php };
?>
<h1><?= te('Performance') ?></h1>
<p class="sub"><?= te('Review cycles, goals and how they progress, and the development plan with whoever follows it up.') ?></p>

<?php if ($person): ?>
<div class="card" id="person"><h2><?= e($person['name']) ?></h2>
  <?php if ($person['reviews']): ?><p class="small"><?php foreach ($person['reviews'] as $rv): ?><a href="/appraisals?id=<?= (int) $rv['id'] ?>"><?= te($rv['label']) ?></a> · <?= e(appraisal_statuses()[$rv['status']]) ?> &nbsp; <?php endforeach; ?></p><?php endif; ?>
</div>

<div class="card scroll" id="goals"><h2><?= te('Goals') ?></h2>
  <?php if (! $person['goals']): ?><p class="muted"><?= te('No goal yet.') ?></p><?php endif; ?>
  <?php foreach ($person['goals'] as $g): ?>
  <div style="border-bottom:1px solid var(--line);padding:8px 0" data-goal="<?= (int) $g['id'] ?>" data-goal-status="<?= e($g['status']) ?>" data-progress="<?= (int) $g['progress'] ?>">
    <strong><?= e($g['title']) ?></strong> <span class="tag <?= $gtone[$g['status']] ?>"><?= e(goal_statuses()[$g['status']]) ?></span> <span class="mono"><?= (int) $g['progress'] ?>%</span>
    <div class="small muted"><?= te('Measured by: :m', ['m' => $g['measure']]) ?> · <span<?= $g['overdue'] ? ' class="err"' : '' ?>><?= te('by :date', ['date' => d((string) $g['target_on'])]) ?><?= $g['overdue'] ? ' · ' . te('overdue') : '' ?></span><?= $g['close_note'] ? ' · ' . e($g['close_note']) : '' ?></div>
    <?php foreach ($g['updates'] as $x): ?><div class="small"><?= e(d(substr((string) $x['created_at'], 0, 10))) ?> · <?= (int) $x['progress'] ?>% · <?= e($x['note']) ?> · <?= e($x['by_name'] ?? '') ?><?= $x['by_self'] ? ' <span class="tag grey">' . te('own account') . '</span>' : '' ?></div><?php endforeach; ?>
    <?php if ($g['status'] === 'open'): ?>
    <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="goal_update"><input type="hidden" name="goal_id" value="<?= (int) $g['id'] ?>"><input type="hidden" name="candidate_id" value="<?= (int) $person['id'] ?>">
      <input name="progress" type="number" min="0" max="100" step="1" required value="<?= (int) $g['progress'] ?>" aria-label="<?= te('Progress (%)') ?>" style="max-width:6em"><input name="note" required minlength="3" maxlength="1000" placeholder="<?= te('What was done') ?>" style="max-width:16em"><button class="btn sm"><?= te('Record progress') ?></button></form>
    <?php if ($person['manage']): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="goal_close"><input type="hidden" name="goal_id" value="<?= (int) $g['id'] ?>"><input type="hidden" name="candidate_id" value="<?= (int) $person['id'] ?>">
      <select name="status"><option value="achieved"><?= te('Achieved') ?></option><option value="missed"><?= te('Missed') ?></option><option value="dropped"><?= te('Dropped') ?></option></select><input name="note" required minlength="3" maxlength="500" placeholder="<?= te('How it ended') ?>" style="max-width:14em"><button class="btn ghost sm"><?= te('Close the goal') ?></button></form><?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if ($person['manage']): ?>
  <form method="post" id="goal-form"><?= csrf_field() ?><input type="hidden" name="do" value="goal_add"><input type="hidden" name="candidate_id" value="<?= (int) $person['id'] ?>">
    <h3><?= te('Set a goal') ?></h3>
    <div class="grid g2"><div><label><?= te('Goal') ?></label><input name="title" required minlength="3" maxlength="190"></div><div><label><?= te('By') ?></label><input type="date" name="target_on" required min="<?= e(date('Y-m-d')) ?>"></div></div>
    <label><?= te('How it is measured') ?></label><input name="measure" required minlength="3" maxlength="500">
    <label><?= te('From the review') ?></label><select name="appraisal_id"><option value="0">—</option><?php foreach ($person['reviews'] as $rv): ?><option value="<?= (int) $rv['id'] ?>"<?= $person['appraisal'] === (int) $rv['id'] ? ' selected' : '' ?>><?= te($rv['label']) ?> #<?= (int) $rv['id'] ?></option><?php endforeach; ?></select>
    <button class="btn"><?= te('Set the goal') ?></button></form>
  <?php endif; ?>
</div>

<div class="card scroll" id="plan"><h2><?= te('Development plan') ?></h2>
  <?php if (! $person['actions']): ?><p class="muted"><?= te('Nothing planned yet.') ?></p><?php else: ?>
  <table><tr><th><?= te('Kind') ?></th><th><?= te('Action') ?></th><th><?= te('Followed up by') ?></th><th><?= te('Due') ?></th><th></th></tr>
  <?php foreach ($person['actions'] as $a): $actionRow($a, $person['manage'] || (int) $a['owner_id'] === uid()); endforeach; ?></table><?php endif; ?>
  <?php if ($person['manage']): ?>
  <form method="post" id="action-form"><?= csrf_field() ?><input type="hidden" name="do" value="action_add"><input type="hidden" name="candidate_id" value="<?= (int) $person['id'] ?>"><input type="hidden" name="appraisal_id" value="<?= (int) $person['appraisal'] ?>">
    <h3><?= te('Plan an action') ?></h3>
    <div class="grid g2"><div><label><?= te('Kind') ?></label><select name="kind"><?php foreach (action_kinds() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label><?= te('Due') ?></label><input type="date" name="due_on" required min="<?= e(date('Y-m-d')) ?>"></div></div>
    <label><?= te('Action') ?></label><input name="description" required minlength="3" maxlength="500" placeholder="<?= te('for example: confined-space entry course') ?>">
    <label><?= te('Followed up by') ?></label><select name="owner_id"><?php foreach ($owners as $o): ?><option value="<?= (int) $o['id'] ?>"<?= (int) $o['id'] === uid() ? ' selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?></select>
    <button class="btn"><?= te('Plan the action') ?></button></form>
  <?php endif; ?>
</div>

<?php if ($person['record']): ?>
<div class="card scroll" id="progress-record"><h2><?= te('Progress record') ?></h2>
  <?php foreach ($person['record'] as $ev): ?><div class="small" data-record="1"><?= e(d(substr($ev['at'], 0, 10))) ?> · <strong><?= e($ev['what']) ?></strong> · <?= e($ev['detail']) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($role !== 'worker'): ?>
<?php if ($mine): ?>
<div class="card scroll" id="my-actions"><h2><?= te('Yours to follow up') ?></h2>
  <table><?php foreach ($mine as $a): $actionRow($a, true); endforeach; ?></table></div>
<?php endif; ?>

<?php if ($crew): ?>
<div class="card" id="crew"><h2><?= te('Your crew') ?></h2>
  <?php foreach ($crew as $c): ?><div><a href="/performance?candidate=<?= (int) $c['id'] ?>"><?= e($c['full_name']) ?></a> <span class="small muted"><?= e($c['title']) ?></span></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if (can('recruiter')): ?>
<?php if ($overdueGoals || $overdueActions): ?>
<div class="card scroll" id="overdue"><h2><?= te('Overdue') ?></h2>
  <?php foreach ($overdueGoals as $g): ?><div class="small err" data-overdue-goal="<?= (int) $g['id'] ?>"><a href="/performance?candidate=<?= (int) $g['candidate_id'] ?>"><?= e($g['full_name']) ?></a> · <?= te('Goal') ?>: <?= e($g['title']) ?> · <?= te('by :date', ['date' => d((string) $g['target_on'])]) ?></div><?php endforeach; ?>
  <?php foreach ($overdueActions as $a): ?><div class="small err" data-overdue-action="<?= (int) $a['id'] ?>"><a href="/performance?candidate=<?= (int) $a['candidate_id'] ?>"><?= e($a['full_name']) ?></a> · <?= e(action_kinds()[$a['kind']]) ?>: <?= e($a['description']) ?> · <?= e($a['owner']) ?> · <?= te('by :date', ['date' => d((string) $a['due_on'])]) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card scroll" id="cycles"><h2><?= te('Review cycles') ?></h2>
  <?php if ($cycles): ?><table><tr><th><?= te('Cycle') ?></th><th><?= te('Template') ?></th><th><?= te('Scope') ?></th><th><?= te('Due') ?></th><th class="num"><?= te('Reviews') ?></th><th><?= te('State') ?></th><th></th></tr>
  <?php foreach ($cycles as $c): ?>
    <tr data-cycle="<?= e($c['name']) ?>" data-cycle-status="<?= e($c['status']) ?>" data-reviews="<?= (int) $c['reviews'] ?>">
      <td><?= e($c['name']) ?><div class="small muted"><?= e(d((string) $c['period_from']) . ' – ' . d((string) $c['period_to'])) ?></div></td><td><?= te($c['template']) ?></td><td><?= e($c['project'] ?? t('All projects')) ?></td>
      <td><?= e(d((string) $c['due_on'])) ?></td><td class="num mono"><?= (int) $c['approved'] ?> / <?= (int) $c['reviews'] ?></td>
      <td><?= e(['planned' => t('Planned'), 'open' => t('Open'), 'closed' => t('Closed')][$c['status']]) ?></td>
      <td><?php if ($c['status'] !== 'closed'): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="cycle_id" value="<?= (int) $c['id'] ?>"><button class="btn sm" name="do" value="cycle_open"><?= $c['status'] === 'planned' ? te('Open the cycle') : te('Open for new assignments') ?></button>
        <?php if ($c['status'] === 'open'): ?><input name="note" maxlength="500" placeholder="<?= te('Why, if reviews are unfinished') ?>" style="max-width:11em"><button class="btn ghost sm" name="do" value="cycle_close"><?= te('Close') ?></button><?php endif; ?></form><?php endif; ?></td></tr>
  <?php endforeach; ?></table><?php else: ?><p class="muted"><?= te('No cycle yet.') ?></p><?php endif; ?>
  <form method="post" id="cycle-form"><?= csrf_field() ?><input type="hidden" name="do" value="cycle_create">
    <h3><?= te('New cycle') ?></h3>
    <div class="grid g2">
      <div><label><?= te('Name') ?></label><input name="name" required minlength="3" maxlength="120" placeholder="<?= te('for example: Fourth-quarter reviews') ?>"></div>
      <div><label><?= te('Template') ?></label><select name="template_id"><?php foreach ($templates as $tpl): ?><option value="<?= (int) $tpl['id'] ?>"><?= te($tpl['label']) ?></option><?php endforeach; ?></select></div>
      <div><label><?= te('Scope') ?></label><select name="job_id"><option value="0"><?= te('All projects') ?></option><?php foreach ($jobs as $j): ?><option value="<?= (int) $j['id'] ?>"><?= e($j['title']) ?></option><?php endforeach; ?></select></div>
      <div><label><?= te('Due') ?></label><input type="date" name="due_on" required></div>
      <div><label><?= te('From') ?></label><input type="date" name="period_from" required></div>
      <div><label><?= te('To') ?></label><input type="date" name="period_to" required></div>
    </div>
    <button class="btn"><?= te('Create the cycle') ?></button></form>
</div>
<?php endif; ?>
<?php endif; ?>
