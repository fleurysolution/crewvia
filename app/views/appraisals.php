<?php
$tone = static fn(string $s): string => ['self_review' => 'blue', 'supervisor_review' => 'amber', 'awaiting_approval' => 'amber', 'approved' => 'green', 'cancelled' => 'grey'][$s] ?? 'grey';
?>
<?php if (isset($a)): ?>
<p><a href="/appraisals">← <?= te('Performance reviews') ?></a></p>
<h1><?= e($a['full_name']) ?> · <?= te($a['template']) ?></h1>
<p class="sub"><span class="tag <?= $tone($a['status']) ?>" id="appraisal-status" data-status="<?= e($a['status']) ?>"><?= e($statuses[$a['status']]) ?></span>
  <?= e($a['project']) ?><?= $a['period_from'] ? ' · ' . e(d((string) $a['period_from'])) . ' – ' . e(d((string) ($a['period_to'] ?? $a['period_from']))) : '' ?>
  · <?= te('Reviewer') ?>: <?= e($a['reviewer'] ?? '—') ?></p>
<?php if ($showSupervisor && $a['score_percent'] !== null): ?>
<div class="grid g4" style="margin-bottom:16px" id="appraisal-result">
  <div class="stat"><div class="n" data-percent="<?= e((string) $a['score_percent']) ?>"><?= e(number_format((float) $a['score_percent'], 1)) ?>%</div><div class="l"><?= te('Weighted score') ?></div></div>
  <div class="stat"><div class="n" data-grade="<?= e((string) $a['grade']) ?>"><?= e((string) $a['grade']) ?></div><div class="l"><?= te('Grade') ?></div></div>
  <div class="stat"><div class="n"><?= (int) $a['would_rehire'] === 1 ? te('Yes') : te('No') ?></div><div class="l"><?= te('Would have them back') ?></div></div>
</div>
<?php endif; ?>

<?php if ($maySelf || $mayScore): $rater = $maySelf ? 'self' : 'supervisor'; ?>
<form class="card" method="post" id="appraisal-form"><?= csrf_field() ?><input type="hidden" name="do" value="<?= $maySelf ? 'self' : 'score' ?>"><input type="hidden" name="appraisal_id" value="<?= (int) $a['id'] ?>">
  <h2><?= $maySelf ? te('Your own view') : te('Your scores') ?></h2>
  <p class="muted"><?= $maySelf ? te('Score yourself from 1 to :max on each point. The reviewer sees it before scoring.', ['max' => (int) $a['scale_max']])
                               : te('Score from 1 to :max on each point. A score of 1 needs a comment. Only your scores make the grade.', ['max' => (int) $a['scale_max']]) ?></p>
  <?php if ($a['decision_note'] && $a['status'] === 'supervisor_review'): ?><p class="err"><?= te('Returned') ?>: <?= e($a['decision_note']) ?></p><?php endif; ?>
  <table><tr><th><?= te('Criterion') ?></th><th class="num"><?= te('Weight') ?></th><?php if ($mayScore): ?><th><?= te('Worker\'s view') ?></th><?php endif; ?><th><?= te('Score') ?></th><th><?= te('Comment') ?></th></tr>
  <?php foreach ($criteria as $c): $mine = $given[$rater][$c['slug']] ?? null; ?>
    <tr><td><?= te($c['label']) ?></td><td class="num mono">×<?= (int) $c['weight'] ?></td>
      <?php if ($mayScore): $sv = $given['self'][$c['slug']] ?? null; ?><td><?= $sv ? (int) $sv['score'] . ($sv['comment'] ? ' · ' . e($sv['comment']) : '') : '—' ?></td><?php endif; ?>
      <td><select name="score[<?= e($c['slug']) ?>]" required><option value=""></option><?php for ($n = 1; $n <= (int) $a['scale_max']; $n++): ?><option value="<?= $n ?>"<?= $mine && (int) $mine['score'] === $n ? ' selected' : '' ?>><?= $n ?></option><?php endfor; ?></select></td>
      <td><input name="score_comment[<?= e($c['slug']) ?>]" maxlength="500" value="<?= e($mine['comment'] ?? '') ?>"></td></tr>
  <?php endforeach; ?></table>
  <?php if ($mayScore && $a['self_comment']): ?><p><strong><?= te('Worker\'s comment') ?>:</strong> <?= e($a['self_comment']) ?></p><?php endif; ?>
  <?php if ($mayScore): ?>
  <label><?= te('Would you have them back?') ?></label><select name="would_rehire" required><option value=""></option><option value="1"<?= (string) $a['would_rehire'] === '1' ? ' selected' : '' ?>><?= te('Yes') ?></option><option value="0"<?= (string) $a['would_rehire'] === '0' ? ' selected' : '' ?>><?= te('No') ?></option></select>
  <?php endif; ?>
  <label><?= $maySelf ? te('Anything you want the reviewer to know') : te('Overall comment · required if you would not have them back') ?></label>
  <textarea name="comment" maxlength="2000" rows="3"><?= e((string) ($maySelf ? $a['self_comment'] : $a['supervisor_comment'])) ?></textarea>
  <button class="btn"><?= $maySelf ? te('Send my view') : te('Submit for approval') ?></button>
</form>
<?php else: ?>
<div class="card scroll" id="appraisal-scores"><h2><?= te('Scores') ?></h2>
  <table><tr><th><?= te('Criterion') ?></th><th class="num"><?= te('Weight') ?></th><th><?= te('Worker\'s view') ?></th><?php if ($showSupervisor): ?><th><?= te('Reviewer') ?></th><?php endif; ?></tr>
  <?php foreach ($criteria as $c): $sv = $given['self'][$c['slug']] ?? null; $rv = $given['supervisor'][$c['slug']] ?? null; ?>
    <tr data-criterion="<?= e($c['slug']) ?>"><td><?= te($c['label']) ?></td><td class="num mono">×<?= (int) $c['weight'] ?></td>
      <td><?= $sv ? (int) $sv['score'] . ' / ' . (int) $a['scale_max'] . ($sv['comment'] ? ' · ' . e($sv['comment']) : '') : '—' ?></td>
      <?php if ($showSupervisor): ?><td data-score="<?= $rv ? (int) $rv['score'] : '' ?>"><?= $rv ? (int) $rv['score'] . ' / ' . (int) $a['scale_max'] . ($rv['comment'] ? ' · ' . e($rv['comment']) : '') : '—' ?></td><?php endif; ?></tr>
  <?php endforeach; ?></table>
  <?php if ($a['self_comment']): ?><p><strong><?= te('Worker\'s comment') ?>:</strong> <?= e($a['self_comment']) ?></p><?php endif; ?>
  <?php if ($showSupervisor && $a['supervisor_comment']): ?><p><strong><?= te('Reviewer\'s comment') ?>:</strong> <?= e($a['supervisor_comment']) ?></p><?php endif; ?>
</div>
<?php endif; ?>

<?php if (can('recruiter') && ! in_array($a['status'], ['approved', 'cancelled'], true)): ?>
<div class="grid g2">
  <?php if ($mayDecide): ?>
  <form class="card" method="post" id="appraisal-decide"><?= csrf_field() ?><input type="hidden" name="do" value="decide"><input type="hidden" name="appraisal_id" value="<?= (int) $a['id'] ?>">
    <h2><?= te('Approve') ?></h2>
    <label><?= te('Note · required to return it') ?></label><input name="note" maxlength="1000">
    <div class="row"><button class="btn" name="decision" value="approve"><?= te('Approve') ?></button><button class="btn ghost" name="decision" value="return"><?= te('Return to the reviewer') ?></button></div>
  </form>
  <?php elseif ($a['status'] === 'awaiting_approval'): ?>
  <div class="card"><p class="muted"><?= te('Somebody other than the reviewer approves a review.') ?></p></div>
  <?php endif; ?>
  <?php if ($a['status'] === 'self_review'): ?>
  <form class="card" method="post" id="appraisal-skip"><?= csrf_field() ?><input type="hidden" name="do" value="skip"><input type="hidden" name="appraisal_id" value="<?= (int) $a['id'] ?>">
    <h2><?= te('Go on without the worker\'s view') ?></h2><label><?= te('Reason') ?></label><input name="reason" required minlength="3" maxlength="500"><button class="btn ghost"><?= te('Skip the self-review') ?></button>
  </form>
  <?php endif; ?>
  <form class="card" method="post" id="appraisal-cancel"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="appraisal_id" value="<?= (int) $a['id'] ?>">
    <h2><?= te('Cancel the review') ?></h2><label><?= te('Reason') ?></label><input name="reason" required minlength="3" maxlength="500"><button class="btn ghost"><?= te('Cancel') ?></button>
  </form>
</div>
<?php endif; ?>

<?php if ($role !== 'worker'): ?>
<p><a href="/performance?candidate=<?= (int) $a['candidate_id'] ?>&amp;appraisal=<?= (int) $a['id'] ?>"><?= te('Goals and development plan for :name', ['name' => $a['full_name']]) ?></a></p>
<div class="card scroll" id="appraisal-history"><h2><?= te('History') ?></h2>
  <table><tr><th><?= te('When') ?></th><th><?= te('What') ?></th><th><?= te('Detail') ?></th><th><?= te('By') ?></th></tr>
  <?php foreach ($events as $ev): ?>
    <tr data-event="<?= e($ev['event']) ?>"><td><?= e(d(substr((string) $ev['created_at'], 0, 10))) ?></td><td><?= e(appraisal_event_labels()[$ev['event']] ?? $ev['event']) ?></td><td><?= e($ev['detail'] ?? '') ?></td><td><?= e($ev['by_name'] ?? '') ?></td></tr>
  <?php endforeach; ?></table>
</div>
<?php endif; ?>

<?php else: ?>
<h1><?= te('Performance reviews') ?></h1>
<p class="sub"><?= $role === 'worker' ? te('Your reviews: the ones waiting for your own view, and those approved.')
                                       : te('A review moves from the worker\'s own view, to the reviewer\'s scores, to approval by somebody else.') ?></p>
<?php if ($role !== 'worker'): ?>
<form class="card row" method="get"><select name="status"><option value=""><?= te('Any state') ?></option><?php foreach ($statuses as $k => $label): ?><option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><button class="btn"><?= te('Filter') ?></button></form>
<?php endif; ?>
<div class="card scroll" id="appraisal-list">
  <?php if (! $list): ?><p class="muted"><?= te('No review yet.') ?></p><?php else: ?>
  <table><tr><th><?= te('Person') ?></th><th><?= te('Template') ?></th><th><?= te('Project') ?></th><th><?= te('Reviewer') ?></th><th><?= te('State') ?></th><th class="num"><?= te('Score') ?></th></tr>
  <?php foreach ($list as $r): ?>
    <tr data-appraisal="<?= (int) $r['id'] ?>" data-status="<?= e($r['status']) ?>"><td><a href="/appraisals?id=<?= (int) $r['id'] ?>"><?= e($r['full_name']) ?></a></td><td><?= te($r['template']) ?></td><td><?= e($r['project']) ?></td><td><?= e($r['reviewer'] ?? '—') ?></td>
      <td><span class="tag <?= $tone($r['status']) ?>"><?= e($statuses[$r['status']]) ?></span></td>
      <td class="num mono"><?= ($role !== 'worker' || $r['status'] === 'approved') && $r['score_percent'] !== null ? e(number_format((float) $r['score_percent'], 1)) . '% · ' . e((string) $r['grade']) : '' ?></td></tr>
  <?php endforeach; ?></table><?php endif; ?>
</div>
<?php if (can('recruiter')): ?>
<form class="card" method="post" id="appraisal-open"><?= csrf_field() ?><input type="hidden" name="do" value="open">
  <h2><?= te('Open a review') ?></h2>
  <?php if (! $templates): ?><p class="muted"><?= te('No template in use. An administrator sets them up under Appraisal templates.') ?></p><?php else: ?>
  <div class="grid g2">
    <div><label><?= te('Person on this project') ?></label><select name="placement_id" required><?php foreach ($crew as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['full_name'] . ($c['trade'] ? ' · ' . $c['trade'] : '')) ?></option><?php endforeach; ?></select></div>
    <div><label><?= te('Template') ?></label><select name="template_id" required><?php foreach ($templates as $tpl): ?><option value="<?= (int) $tpl['id'] ?>"><?= te($tpl['label']) ?> · <?= e(appraisal_kinds()[$tpl['kind']]) ?></option><?php endforeach; ?></select></div>
    <div><label><?= te('Reviewer · empty for the assignment\'s supervisor') ?></label><select name="reviewer_id"><option value="0"><?= te('The assignment\'s supervisor') ?></option><?php foreach ($reviewers as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
    <div class="grid g2"><div><label><?= te('From') ?></label><input type="date" name="period_from"></div><div><label><?= te('To') ?></label><input type="date" name="period_to"></div></div>
  </div>
  <button class="btn"><?= te('Open') ?></button><?php endif; ?>
</form>
<?php endif; ?>
<?php endif; ?>
