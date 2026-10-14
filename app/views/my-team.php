<div class="page-head"><div><h1><?= te('My supervised team') ?></h1><p class="sub"><?= te('Workers currently assigned to you. Coordinate through your private channels and review time-off requests.') ?></p></div></div>
<div class="home-tiles" id="crew-today">
  <a class="card home-tile" href="/attendance" data-tile="on_site"><div class="n"><?= (int) $today['on_site'] ?></div><div class="l"><?= te('of your crew on site') ?></div></a>
  <a class="card home-tile<?= $today['on_site'] > $today['marked'] ? ' amber' : '' ?>" href="/attendance" data-tile="unmarked"><div class="n"><?= max(0, $today['on_site'] - $today['marked']) ?></div><div class="l"><?= te('not marked at roll call today') ?></div></a>
  <a class="card home-tile<?= $today['hours_waiting'] ? ' amber' : '' ?>" href="/attendance" data-tile="hours"><div class="n"><?= (int) $today['hours_waiting'] ?></div><div class="l"><?= te('days of hours to approve') ?></div></a>
  <a class="card home-tile<?= $today['tasks_late'] ? ' red' : '' ?>" href="/my-tasks" data-tile="tasks"><div class="n"><?= (int) $today['tasks'] ?></div><div class="l"><?= $today['tasks_late'] ? te(':n of your tasks, :late late', ['n' => $today['tasks'], 'late' => $today['tasks_late']]) : te('open tasks of yours') ?></div></a>
</div>
<p class="small" style="margin:-4px 0 14px"><a href="/lateness"><?= ten($today['late_week'], ':n late arrival recorded this week', ':n late arrivals recorded this week') ?></a> · <a href="/project-tasks"><?= te('Project tasks') ?></a> · <a href="/procurement"><?= te('Ask for something the crew needs') ?></a></p>
<h2 style="font-size:15px;margin:6px 0 10px"><?= te('Your crew') ?></h2><div class="grid g2"><?php foreach($team as $t): ?><div class="card"><h2><?= e($t['full_name']) ?></h2><p><?= e($t['title'].' · '.$t['trade'].' · '.$t['shift_label'].' · '.$t['status']) ?></p></div><?php endforeach; ?></div>
<?php if ($toGrade): ?>
<section class="card mt" id="grading">
  <span class="eyebrow"><?= te('HOW IT WENT') ?></span>
  <h2 style="margin:4px 0 2px"><?= ten(count($toGrade), ':n finished assignment to grade', ':n finished assignments to grade') ?></h2>
  <p class="small muted"><?= te('Asked now, while somebody still remembers. A year from now this is the only thing that answers whether this person is worth calling back.') ?></p>
  <?php foreach ($toGrade as $p): ?><?php require __DIR__ . '/_grade-form.php'; ?><?php endforeach; ?>
</section>
<?php endif; ?>
