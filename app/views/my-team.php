<div class="page-head"><div><h1><?= $managed ? te('My projects and team') : te('My supervised team') ?></h1><p class="sub"><?= $managed ? te('The projects you manage, then the workers assigned to you directly.') : te('Workers currently assigned to you. Coordinate through your private channels and review time-off requests.') ?></p></div>
<?php if ($managed): ?><div class="actions"><a class="btn ghost" href="/project-board"><?= te('Project board') ?></a><a class="btn ghost" href="/project-tasks"><?= te('Project tasks') ?></a><a class="btn ghost" href="/workload"><?= te('Workload') ?></a></div><?php endif; ?></div>
<?php foreach ($managed as $mp): $pp = $mp['people']; $rc = $mp['recruiting']; ?>
<section class="card" id="managed-<?= (int) $mp['id'] ?>" data-managed="<?= (int) $mp['id'] ?>">
  <h2 class="m0"><?= e($mp['title']) ?> <span class="small muted"><?= e(trim(($mp['site_city'] ?? '') . ', ' . ($mp['site_state'] ?? ''), ', ')) ?> · <?= te(ucfirst((string) $mp['status'])) ?></span></h2>
  <div class="home-tiles mt-s">
    <div class="card home-tile" data-pm="on_site"><div class="n"><?= (int) $pp['on_site'] ?></div><div class="l"><?= te('on site') ?></div></div>
    <div class="card home-tile" data-pm="not_started"><div class="n"><?= (int) $pp['not_started'] ?></div><div class="l"><?= te('hired, not started yet') ?></div></div>
    <div class="card home-tile<?= $pp['unassigned'] ? ' amber' : '' ?>" data-pm="unassigned"><div class="n"><?= (int) $pp['unassigned'] ?></div><div class="l"><?= te('without a supervisor') ?></div></div>
    <div class="card home-tile" data-pm="recruiting"><div class="n"><?= (int) $rc['applicants'] ?></div><div class="l"><?= ten((int) $rc['requisitions'], 'applicants on :n open requisition (:o openings)', 'applicants on :n open requisitions (:o openings)', ['o' => (int) $rc['openings']]) ?></div></div>
  </div>
  <?php if ($mp['supervisors']): ?>
  <table class="mt-s"><thead><tr><th><?= te('Supervisor') ?></th><th class="right"><?= te('Crew') ?></th><th class="right"><?= te('On site') ?></th></tr></thead><tbody>
    <?php foreach ($mp['supervisors'] as $s): ?><tr><td><?= e($s['name']) ?></td><td class="right"><?= (int) $s['crew'] ?></td><td class="right"><?= (int) $s['on_site'] ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php else: ?><p class="small muted mt-s"><?= te('No crew is assigned to a supervisor on this project yet.') ?></p><?php endif; ?>
</section>
<?php endforeach; ?>
<div class="home-tiles" id="crew-today">
  <a class="card home-tile" href="/attendance" data-tile="on_site"><div class="n"><?= (int) $today['on_site'] ?></div><div class="l"><?= te('of your crew on site') ?></div></a>
  <a class="card home-tile<?= $today['on_site'] > $today['marked'] ? ' amber' : '' ?>" href="#crew" data-tile="unmarked"><div class="n"><?= max(0, $today['on_site'] - $today['marked']) ?></div><div class="l"><?= te('not marked at roll call today') ?></div></a>
  <a class="card home-tile<?= $today['hours_waiting'] ? ' amber' : '' ?>" href="/attendance" data-tile="hours"><div class="n"><?= (int) $today['hours_waiting'] ?></div><div class="l"><?= te('days of hours to approve') ?></div></a>
  <a class="card home-tile<?= $today['tasks_late'] ? ' red' : '' ?>" href="/my-tasks" data-tile="tasks"><div class="n"><?= (int) $today['tasks'] ?></div><div class="l"><?= $today['tasks_late'] ? te(':n of your tasks, :late late', ['n' => $today['tasks'], 'late' => $today['tasks_late']]) : te('open tasks of yours') ?></div></a>
</div>
<p class="small m0 pull-up mb"><a href="/lateness"><?= ten($today['late_week'], ':n late arrival recorded this week', ':n late arrivals recorded this week') ?></a> · <a href="/project-tasks"><?= te('Project tasks') ?></a> · <a href="/procurement"><?= te('Ask for something the crew needs') ?></a></p>
<?php if (! $clockDay['clock_ins'] && ! $clockDay['rooms']): ?><p><a class="btn" href="/clock-qr"><?= te('Show the clock-in code') ?></a></p><?php endif; ?>
<?php if ($clockDay['clock_ins'] || $clockDay['rooms']): $waitingN = count(array_filter($clockDay['clock_ins'], static fn($c) => $c['status'] === 'waiting')); ?>
<section class="card" id="clock-ins" data-waiting="<?= $waitingN ?>">
  <h2 class="m0 mb-xs"><?= $waitingN ? ten($waitingN, ':n clock-in to validate', ':n clock-ins to validate') : te('Clock-ins') ?></h2>
  <p class="small muted"><?= te('Validate each one by 30 minutes after the shift starts. A validation after that is kept, and marked late.') ?></p>
  <p><a class="btn" href="/clock-qr"><?= te('Show the clock-in code') ?></a></p>
  <?php foreach ($clockDay['clock_ins'] as $c): $over = $c['status'] === 'waiting' && time() > strtotime($c['validate_by']); ?>
  <div class="row items-center gap-s mb-xs" data-clock-in="<?= (int) $c['id'] ?>" data-status="<?= e($c['status']) ?>"<?= $over ? ' data-overdue="1"' : '' ?>>
    <strong><?= e($c['full_name']) ?></strong>
    <?php if (! empty($c['in_flag'])): ?><span class="small t-red" data-clock-flag="in"><?= e($c['in_flag']) ?></span><?php endif; ?>
    <?php if (! empty($c['clocked_out_at'])): ?><span class="small" data-clocked-out="1"><?= te('out at :time (:h h)', ['time' => date('g:i a', strtotime($c['clocked_out_at'])), 'h' => ci_hours($c)]) ?></span><?php if (! empty($c['out_flag'])): ?><span class="small t-red" data-clock-flag="out"><?= e($c['out_flag']) ?></span><?php endif; ?><?php endif; ?>
    <span class="small"><?= te('in at :time', ['time' => date('g:i a', strtotime($c['clocked_in_at']))]) ?><?php if ($c['shift_start'] && $c['clocked_in_at'] > $c['shift_start']): ?> <span class="tag amber"><?= te(':n min late', ['n' => (int) round((strtotime($c['clocked_in_at']) - strtotime($c['shift_start'])) / 60)]) ?></span><?php endif; ?></span>
    <?php if ($c['status'] === 'waiting'): ?>
      <span class="small<?= $over ? ' t-red' : ' muted' ?>"><?= $over ? te('was due by :time', ['time' => date('g:i a', strtotime($c['validate_by']))]) : te('validate by :time', ['time' => date('g:i a', strtotime($c['validate_by']))]) ?></span>
      <?php foreach (['1' => t('Validate'), '0' => t('Not here')] as $v => $label): ?><form method="post" action="/my-team" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="clock"><input type="hidden" name="clock_in_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="present" value="<?= $v ?>"><button class="btn sm<?= $v === '1' ? '' : ' ghost' ?>"><?= e($label) ?></button></form><?php endforeach; ?>
    <?php else: ?><span class="tag <?= $c['status'] === 'validated' ? 'green' : 'red' ?>"><?= $c['status'] === 'validated' ? te('Validated') : te('Not here') ?></span><?php if ((int) $c['decided_late']): ?> <span class="tag amber"><?= te('validated late') ?></span><?php endif; ?><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php foreach ($clockDay['rooms'] as $r): ?><p class="small t-red m0" data-room-overdue="<?= (int) $r['id'] ?>"><?= te(':name has not entered their room number at :hotel: they cannot clock in.', ['name' => $r['full_name'], 'hotel' => $r['hotel']]) ?></p><?php endforeach; ?>
</section>
<?php endif; ?>
<h2 class="t-md m0 mt-s mb"><?= te('Your crew') ?></h2><div class="grid g2" id="crew"><?php foreach($team as $t): ?><div class="card" data-crew="<?= (int) $t['id'] ?>"><h2><?= e($t['full_name']) ?></h2><p><?= e($t['title'].' · '.$t['trade'].' · '.$t['shift_label']) ?> · <?= te(placement_word((string) $t['status'])) ?></p><?php if ($t['status'] === 'on_site'): ?><div class="row items-center" data-rollcall="<?= $t['today_present'] === null ? 'unmarked' : ((int) $t['today_present'] ? 'present' : 'absent') ?>"><span class="small"><?= te('Roll call today:') ?> <strong><?= $t['today_present'] === null ? te('not marked') : ((int) $t['today_present'] ? te('present') : te('absent')) ?></strong></span><?php foreach (['1' => t('Present'), '0' => t('Absent')] as $v => $label): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="rollcall"><input type="hidden" name="placement_id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="present" value="<?= $v ?>"><button class="btn sm<?= $v === '1' ? '' : ' ghost' ?>"><?= e($label) ?></button></form><?php endforeach; ?></div><?php endif; ?></div><?php endforeach; ?></div>
<?php if ($toGrade): ?>
<section class="card mt" id="grading">
  <span class="eyebrow"><?= te('HOW IT WENT') ?></span>
  <h2 class="m0 mt-xs mb-xs"><?= ten(count($toGrade), ':n finished assignment to grade', ':n finished assignments to grade') ?></h2>
  <p class="small muted"><?= te('Asked now, while somebody still remembers. A year from now this is the only thing that answers whether this person is worth calling back.') ?></p>
  <?php foreach ($toGrade as $p): ?><?php require __DIR__ . '/_grade-form.php'; ?><?php endforeach; ?>
</section>
<?php endif; ?>
