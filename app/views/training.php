<?php
$trTone = ['due' => 'blue', 'overdue' => 'red', 'done' => 'green', 'expiring' => 'amber', 'expired' => 'red', 'none' => 'grey'];
$trStatus = tr_statuses();
?>
<?php if (isset($course)): ?>
<p class="back-link"><a href="/training">← <?= te('Training') ?></a></p>
<div class="page-head"><div><h1><?= e($course['title']) ?></h1></div></div>
<div class="grid g2">
  <form method="post" class="card" id="course-form"><?= csrf_field() ?><input type="hidden" name="do" value="course_save"><input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
    <h2><?= te('The course') ?></h2>
    <label><?= te('Title') ?><input name="title" required maxlength="190" value="<?= e($course['title']) ?>"></label>
    <label><?= te('In short') ?><input name="description" maxlength="1000" value="<?= e((string) $course['description']) ?>"></label>
    <label><?= te('What to learn') ?><textarea name="content" rows="8" maxlength="100000"><?= e((string) $course['content']) ?></textarea></label>
    <label><?= te('Resource link (HTTPS)') ?><input name="resource_url" type="url" value="<?= e((string) $course['resource_url']) ?>"></label>
    <div class="grid g2">
      <label><?= te('Counts for (months) · empty: for good') ?><input name="valid_months" type="number" min="1" max="120" value="<?= e((string) $course['valid_months']) ?>"></label>
      <label><?= te('Pass mark (%)') ?><input name="pass_mark" type="number" min="1" max="100" value="<?= (int) $course['pass_mark'] ?>"></label>
    </div>
    <label><?= te('Passing it certifies') ?><select name="awards_type_id"><option value="0"><?= te('Nothing') ?></option><?php foreach ($types as $ty): ?><option value="<?= (int) $ty['id'] ?>"<?= (int) $course['awards_type_id'] === (int) $ty['id'] ? ' selected' : '' ?>><?= e($ty['name']) ?></option><?php endforeach; ?></select></label>
    <label><input type="checkbox" name="is_active" value="1"<?= (int) $course['is_active'] ? ' checked' : '' ?>> <?= te('In use') ?></label>
    <button class="btn"><?= te('Save') ?></button>
  </form>
  <div class="card" id="course-questions" data-count="<?= count($questions) ?>">
    <h2><?= te('Assessment') ?></h2>
    <p class="small muted"><?= te('With questions, the course is passed at the pass mark; without, the person confirms they read and understood it.') ?></p>
    <?php foreach ($questions as $qi => $qq): $opts = json_decode($qq['options_json'], true); ?>
    <div class="small m0 mt-s mb-s" data-question="<?= (int) $qq['id'] ?>"><strong><?= $qi + 1 ?>. <?= e($qq['question']) ?></strong>
      <ol type="a"><?php foreach ($opts as $oi => $op): ?><li><?= e($op) ?><?= $oi === (int) $qq['correct_index'] ? ' <span class="tag green">' . te('right answer') . '</span>' : '' ?></li><?php endforeach; ?></ol>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="question_remove"><input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>"><input type="hidden" name="question_id" value="<?= (int) $qq['id'] ?>"><button class="btn sm ghost"><?= te('Remove') ?></button></form></div>
    <?php endforeach; ?>
    <form method="post" id="question-form"><?= csrf_field() ?><input type="hidden" name="do" value="question_add"><input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
      <label><?= te('Question') ?><input name="question" required maxlength="500"></label>
      <?php for ($i = 0; $i < 4; $i++): ?><div class="row"><label class="small"><input type="radio" name="correct" value="<?= $i ?>"<?= $i === 0 ? ' checked' : '' ?>> <?= te('right') ?></label><input name="options[]" maxlength="300" placeholder="<?= te('Answer :n', ['n' => $i + 1]) ?>" aria-label="<?= te('Answer :n', ['n' => $i + 1]) ?>"></div><?php endfor; ?>
      <button class="btn sm"><?= te('Add the question') ?></button></form>
  </div>
</div>
<div class="grid g2">
  <form method="post" class="card" id="assign-form"><?= csrf_field() ?><input type="hidden" name="do" value="assign"><input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
    <h2><?= te('Assign it') ?></h2>
    <label><?= te('To') ?><select name="user_ids[]" multiple size="8"><?php foreach ($people as $pe): ?><option value="<?= (int) $pe['id'] ?>"><?= e($pe['name'] . ' · ' . $pe['role']) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Due') ?><input type="date" name="due_on" required value="<?= e(date('Y-m-d', strtotime('+30 days'))) ?>"></label>
    <button class="btn"><?= te('Assign') ?></button></form>
  <div class="card scroll" id="course-taken"><h2><?= te('Who has it') ?></h2>
    <?php if (! $taken): ?><p class="muted"><?= te('Nobody yet.') ?></p><?php else: ?>
    <table><tr><th><?= te('Person') ?></th><th><?= te('Why') ?></th><th><?= te('Due') ?></th><th><?= te('State') ?></th><th><?= te('Score') ?></th><th><?= te('Counts until') ?></th></tr>
    <?php foreach ($taken as $tk): $st = tr_status($tk); ?><tr data-assignment="<?= (int) $tk['id'] ?>" data-status="<?= e($st) ?>"><td><?= e($tk['name']) ?></td><td class="small"><?= e($tk['source']) ?></td><td class="small"><?= e(d((string) $tk['due_on'])) ?></td>
      <td><span class="tag <?= $trTone[$st] ?>"><?= e($trStatus[$st]) ?></span></td><td class="small"><?= $tk['score'] !== null ? (int) $tk['score'] . '%' : '' ?></td><td class="small"><?= $tk['expires_on'] ? e(d((string) $tk['expires_on'])) : ($tk['completed_at'] ? te('for good') : '') ?></td></tr><?php endforeach; ?></table><?php endif; ?>
  </div>
</div>

<?php elseif (isset($plan)): ?>
<p class="back-link"><a href="/training">← <?= te('Training') ?></a></p>
<div class="page-head"><div><h1><?= e($plan['name']) ?></h1></div></div>
<div class="grid g2">
  <div class="card" id="plan-courses" data-count="<?= count($planCourses) ?>"><h2><?= te('Courses, in order') ?></h2>
    <?php if (! $planCourses): ?><p class="muted"><?= te('No course yet.') ?></p><?php else: ?>
    <table><tr><th>#</th><th><?= te('Course') ?></th><th><?= te('Due after') ?></th><th></th></tr>
    <?php foreach ($planCourses as $i => $pc): ?><tr data-plan-course="<?= (int) $pc['course_id'] ?>"><td><?= $i + 1 ?></td><td><?= e($pc['title']) ?></td><td class="small"><?= te(':n days', ['n' => (int) $pc['due_days']]) ?></td>
      <td><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="plan_course_remove"><input type="hidden" name="plan_id" value="<?= (int) $plan['id'] ?>"><input type="hidden" name="course_id" value="<?= (int) $pc['course_id'] ?>"><button class="btn sm ghost"><?= te('Remove') ?></button></form></td></tr><?php endforeach; ?></table><?php endif; ?>
    <form method="post" class="row" id="plan-course-form"><?= csrf_field() ?><input type="hidden" name="do" value="plan_course_add"><input type="hidden" name="plan_id" value="<?= (int) $plan['id'] ?>">
      <label><?= te('Course') ?><select name="course_id"><?php foreach ($courses as $co): ?><option value="<?= (int) $co['id'] ?>"><?= e($co['title']) ?></option><?php endforeach; ?></select></label>
      <label><?= te('Due after (days)') ?><input type="number" name="due_days" min="1" max="365" value="14" required></label>
      <button class="btn sm"><?= te('Add') ?></button></form>
  </div>
  <form method="post" class="card" id="plan-assign-form"><?= csrf_field() ?><input type="hidden" name="do" value="plan_assign"><input type="hidden" name="plan_id" value="<?= (int) $plan['id'] ?>">
    <h2><?= te('Give the plan') ?></h2>
    <label><?= te('To') ?><select name="user_ids[]" multiple size="8"><?php foreach ($people as $pe): ?><option value="<?= (int) $pe['id'] ?>"><?= e($pe['name'] . ' · ' . $pe['role']) ?></option><?php endforeach; ?></select></label>
    <p class="small muted"><?= te('Each course is due its days from today. Anyone who has a course open, or done and still current, keeps it as it is.') ?></p>
    <button class="btn"><?= te('Give the plan') ?></button></form>
</div>

<?php else: ?>
<div class="page-head"><div><h1><?= te('Training') ?></h1>
<p class="sub"><?= te('Agency courses, their assessments, plans, and the training required of each desk role, trade or project. Project briefings stay under Safety & learning.') ?></p></div></div>
<div class="grid g4" id="compliance-counts">
  <?php foreach (['overdue', 'expired', 'expiring', 'due', 'done'] as $k): ?><a class="stat" href="/training?status=<?= e($k) ?>#compliance" data-count="<?= e($k) ?>"><div class="n"><?= (int) ($counts[$k] ?? 0) ?></div><div class="l"><?= e($trStatus[$k]) ?></div></a><?php endforeach; ?>
</div>
<div class="grid g2">
  <div class="card scroll" id="courses"><h2><?= te('Courses') ?></h2>
    <?php if (! $courses): ?><p class="muted"><?= te('No course yet.') ?></p><?php else: ?>
    <table><tr><th><?= te('Course') ?></th><th><?= te('Questions') ?></th><th><?= te('Counts for') ?></th><th><?= te('Certifies') ?></th></tr>
    <?php foreach ($courses as $co): ?><tr data-course="<?= (int) $co['id'] ?>"><td><a href="/training?course=<?= (int) $co['id'] ?>"><?= e($co['title']) ?></a><?= (int) $co['is_active'] ? '' : ' <span class="tag grey">' . te('Not in use') . '</span>' ?></td>
      <td class="small"><?= (int) $co['questions'] ?></td><td class="small"><?= $co['valid_months'] ? te(':n months', ['n' => (int) $co['valid_months']]) : te('for good') ?></td><td class="small"><?= e((string) ($co['awards'] ?? '—')) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
    <form method="post" class="row" id="new-course-form"><?= csrf_field() ?><input type="hidden" name="do" value="course_save">
      <label><?= te('New course') ?><input name="title" required maxlength="190"></label><input type="hidden" name="pass_mark" value="80"><input type="hidden" name="is_active" value="1">
      <button class="btn sm"><?= te('Create') ?></button></form>
  </div>
  <div class="card scroll" id="plans"><h2><?= te('Plans') ?></h2>
    <?php if (! $plans): ?><p class="muted"><?= te('No plan yet.') ?></p><?php else: ?>
    <table><tr><th><?= te('Plan') ?></th><th><?= te('Courses') ?></th></tr>
    <?php foreach ($plans as $pl): ?><tr data-plan="<?= (int) $pl['id'] ?>"><td><a href="/training?plan=<?= (int) $pl['id'] ?>"><?= e($pl['name']) ?></a></td><td class="small"><?= (int) $pl['courses'] ?></td></tr><?php endforeach; ?></table><?php endif; ?>
    <form method="post" class="row" id="new-plan-form"><?= csrf_field() ?><input type="hidden" name="do" value="plan_save">
      <label><?= te('New plan') ?><input name="name" required maxlength="190"></label><input type="hidden" name="is_active" value="1">
      <button class="btn sm"><?= te('Create') ?></button></form>
  </div>
</div>
<div class="card scroll" id="requirements"><h2><?= te('Required training') ?></h2>
  <?php if (! $requirements): ?><p class="muted"><?= te('Nothing is required yet.') ?></p><?php else: ?>
  <table><tr><th><?= te('Course') ?></th><th><?= te('Required of') ?></th><th><?= te('Due within') ?></th><th></th></tr>
  <?php foreach ($requirements as $rq): ?><tr data-requirement="<?= (int) $rq['id'] ?>"><td><?= e($rq['title']) ?></td>
    <td><?= e(tr_scopes()[$rq['scope']]) ?>: <strong><?= e($rq['scope'] === 'staff_role' ? (tr_staff_roles()[$rq['staff_role']] ?? $rq['staff_role']) : ($rq['scope'] === 'trade' ? $rq['trade'] : (string) $rq['job_title'])) ?></strong></td>
    <td class="small"><?= te(':n days', ['n' => (int) $rq['due_days']]) ?></td>
    <td><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="requirement_stop"><input type="hidden" name="requirement_id" value="<?= (int) $rq['id'] ?>"><button class="btn sm ghost"><?= te('Stop requiring it') ?></button></form></td></tr><?php endforeach; ?></table><?php endif; ?>
  <form method="post" class="row mt-s" id="requirement-form"><?= csrf_field() ?><input type="hidden" name="do" value="requirement_save">
    <label><?= te('Course') ?><select name="course_id"><?php foreach ($courses as $co): if (! (int) $co['is_active']) continue; ?><option value="<?= (int) $co['id'] ?>"><?= e($co['title']) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Required of') ?><select name="scope"><?php foreach (tr_scopes() as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Desk role') ?><select name="staff_role"><?php foreach (tr_staff_roles() as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Trade') ?><input name="trade" maxlength="190" placeholder="<?= te('Electrician') ?>"></label>
    <label><?= te('Project') ?><select name="job_id"><option value="0">—</option><?php foreach ($jobs as $jb): ?><option value="<?= (int) $jb['id'] ?>"><?= e($jb['title']) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Due within (days)') ?><input type="number" name="due_days" min="1" max="365" value="30" required></label>
    <button class="btn sm"><?= te('Require it') ?></button></form>
</div>
<div class="card scroll" id="compliance" data-rows="<?= count($compliance) ?>"><h2><?= te('Compliance') ?></h2>
  <form method="get" class="row"><label><?= te('Required of') ?><select name="scope"><option value=""><?= te('Anyone') ?></option><?php foreach (tr_scopes() as $k => $label): ?><option value="<?= e($k) ?>"<?= $filter['scope'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label><?= te('State') ?><select name="status"><option value=""><?= te('Any') ?></option><?php foreach ($trStatus as $k => $label): ?><option value="<?= e($k) ?>"<?= $filter['status'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <button class="btn ghost"><?= te('Show') ?></button></form>
  <form class="m0 mt-s mb-s" method="post"><?= csrf_field() ?><input type="hidden" name="do" value="sync"><button class="btn sm ghost"><?= te('Assign what is required now') ?></button> <span class="small muted"><?= te('This also runs with the scheduled workflows.') ?></span></form>
  <?php if (! $compliance): ?><p class="muted"><?= te('Nobody for these filters.') ?></p><?php else: ?>
  <table><tr><th><?= te('Person') ?></th><th><?= te('Course') ?></th><th><?= te('State') ?></th><th><?= te('Due') ?></th><th><?= te('Counts until') ?></th></tr>
  <?php foreach ($compliance as $cm): $as = $cm['assignment']; ?><tr data-person="<?= (int) $cm['user_id'] ?>" data-course="<?= (int) $cm['requirement']['course_id'] ?>" data-status="<?= e($cm['status']) ?>">
    <td><?= e($cm['name']) ?></td><td><?= e($cm['requirement']['title']) ?></td><td><span class="tag <?= $trTone[$cm['status']] ?>"><?= e($trStatus[$cm['status']]) ?></span></td>
    <td class="small"><?= $as && $as['completed_at'] === null ? e(d((string) $as['due_on'])) : '' ?></td><td class="small"><?= $as && $as['expires_on'] ? e(d((string) $as['expires_on'])) : '' ?></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>
<?php endif; ?>
