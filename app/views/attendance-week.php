<?php
$prev = date('Y-m-d', strtotime($week . ' -7 days'));
$next = date('Y-m-d', strtotime($week . ' +7 days'));
$good = ['agrees'];
$warn = ['not_on_sheet', 'nothing_approved', 'changed_since_import', 'typed_without_attendance'];
?>
<p class="small"><a href="/attendance"><?= te('← Daily attendance') ?></a></p>
<h1><?= te('Week check') ?></h1>
<p class="sub"><?= te('Week ending :date on :project. Read-only: correct days on the attendance page, import and approve sheets on Hours.',
    ['date' => d($week), 'project' => (string) ($job['title'] ?? '—')]) ?></p>

<p class="row">
  <a class="btn ghost sm" href="/attendance-week?week=<?= e($prev) ?>"><?= te('Previous week') ?></a>
  <a class="btn ghost sm" href="/attendance-week?week=<?= e($next) ?>"><?= te('Next week') ?></a>
  <a class="btn ghost sm" href="/hours?week=<?= e($week) ?>"><?= te('Open this week in Hours') ?></a>
</p>

<div class="card scroll" id="reconciliation">
  <h2><?= te('Approved days against the weekly sheet') ?></h2>
  <?php if (! $reconciliation): ?>
    <p class="muted"><?= te('Nobody on this project has days or a weekly sheet this week, so there is nothing to compare.') ?></p>
  <?php else: ?>
  <table>
    <tr><th><?= te('Worker') ?></th><th class="num"><?= te('Approved days') ?></th><th class="num"><?= te('Approved hours') ?></th>
      <th class="num"><?= te('Waiting') ?></th><th class="num"><?= te('Imported') ?></th><th class="num"><?= te('On the sheet') ?></th><th><?= te('Check') ?></th></tr>
    <?php foreach ($reconciliation as $r): $tone = in_array($r['state'], $good, true) ? 'green' : (in_array($r['state'], $warn, true) ? 'amber' : 'red'); ?>
    <tr>
      <td><a href="/placements/<?= (int) $r['placement_id'] ?>"><?= e($r['full_name']) ?></a></td>
      <td class="num mono"><?= (int) $r['approved_days'] ?></td>
      <td class="num mono"><?= e((string) $r['approved_hours']) ?></td>
      <td class="num mono"><?= (int) $r['pending_days'] ?></td>
      <td class="num mono"><?= $r['imported_hours'] === null ? '—' : e((string) $r['imported_hours']) ?></td>
      <td class="num mono"><?= $r['sheet_hours'] === null ? '—' : e((string) $r['sheet_hours']) ?></td>
      <td><span class="tag <?= $tone ?>" data-state="<?= e($r['state']) ?>"><?= e($states[$r['state']] ?? $r['state']) ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<div class="card" id="exceptions">
  <h2><?= te('Days that do not add up') ?></h2>
  <?php if (! $exceptions): ?>
    <p class="muted"><?= te('Nothing to look at: roll call and hours agree, no long days, nobody on two jobs the same day, nothing left waiting.') ?></p>
  <?php else: ?>
  <div class="scroll"><table>
    <tr><th><?= te('Date') ?></th><th><?= te('Worker') ?></th><th><?= te('What') ?></th><th><?= te('Detail') ?></th></tr>
    <?php foreach ($exceptions as $x): ?>
    <tr data-kind="<?= e($x['kind']) ?>">
      <td><?= e(d($x['work_date'])) ?></td>
      <td><a href="/placements/<?= (int) $x['placement_id'] ?>"><?= e($x['full_name']) ?></a></td>
      <td><?= e($kinds[$x['kind']] ?? $x['kind']) ?></td>
      <td class="small"><?= e($x['detail']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>

<div class="card" id="several">
  <h2><?= te('On more than one assignment this week') ?></h2>
  <p class="muted"><?= te('Approved hours across every project they worked on. Overtime is counted on the week, not per assignment.') ?></p>
  <?php if (! $several): ?>
    <p class="muted"><?= te('Everybody on this project worked a single assignment this week.') ?></p>
  <?php else: ?>
  <div class="scroll"><table>
    <tr><th><?= te('Worker') ?></th><th class="num"><?= te('Assignments') ?></th><th class="num"><?= te('Hours this week') ?></th><th><?= te('Projects') ?></th></tr>
    <?php foreach ($several as $s): ?>
    <tr><td><?= e($s['full_name']) ?></td><td class="num mono"><?= (int) $s['assignments'] ?></td>
      <td class="num mono"><?= e((string) (float) $s['total_hours']) ?></td><td class="small"><?= e($s['projects']) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
