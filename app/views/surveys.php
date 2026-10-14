<?php
$svKinds = sv_kinds();
$svAud = sv_audiences();
$svStatus = sv_statuses();
$svSeg = sv_segments();
$svTone = ['draft' => 'grey', 'open' => 'blue', 'closed' => 'green'];
require_once __DIR__ . '/../training.php';
$svRoles = tr_staff_roles();
$svForm = static function (?array $s, array $jobs) use ($svAud, $svRoles): void { ?>
  <form method="post" class="card" id="survey-form"><?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="survey_id" value="<?= (int) ($s['id'] ?? 0) ?>">
    <h2><?= $s ? te('The survey') : te('New survey') ?></h2>
    <label><?= te('Title') ?><input name="title" required maxlength="190" value="<?= e((string) ($s['title'] ?? '')) ?>"></label>
    <label><?= te('What it is about') ?><input name="intro" maxlength="1000" value="<?= e((string) ($s['intro'] ?? '')) ?>"></label>
    <div class="grid g2">
      <label><?= te('For') ?><select name="audience"><?php foreach ($svAud as $k => $l): ?><option value="<?= e($k) ?>"<?= ($s['audience'] ?? 'everyone') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label><?= te('Desk role') ?><select name="audience_role"><option value=""></option><?php foreach ($svRoles as $k => $l): ?><option value="<?= e($k) ?>"<?= ($s['audience_role'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label><?= te('Project') ?><select name="job_id"><option value="0"></option><?php foreach ($jobs as $j): ?><option value="<?= (int) $j['id'] ?>"<?= (int) ($s['job_id'] ?? 0) === (int) $j['id'] ? ' selected' : '' ?>><?= e($j['title']) ?></option><?php endforeach; ?></select></label>
      <label><?= te('Closes on') ?><input type="date" name="closes_on" required min="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>" value="<?= e((string) ($s['closes_on'] ?? date('Y-m-d', strtotime('+14 days')))) ?>"></label>
      <label><?= te('Results show from (answers)') ?><input type="number" name="min_group" min="3" max="50" value="<?= (int) ($s['min_group'] ?? 5) ?>"></label>
    </div>
    <label><input type="checkbox" name="is_anonymous" value="1"<?= (int) ($s['is_anonymous'] ?? 1) ? ' checked' : '' ?>> <?= te('Anonymous: no name is kept with the answers') ?></label>
    <button class="btn"><?= $s ? te('Save') : te('Create') ?></button></form>
<?php };
?>
<?php if ($take): ?>
<p class="back-link"><a href="/surveys">← <?= te('Surveys') ?></a></p>
<div class="page-head"><div><h1><?= e($take['title']) ?></h1>
<p class="sub"><?= te('Until :date', ['date' => d((string) $take['closes_on'])]) ?> · <span id="anonymity" data-anonymous="<?= (int) $take['is_anonymous'] ?>"><?= (int) $take['is_anonymous'] ? te('Anonymous: your name is not kept with your answers, and results show only once enough people have answered.') : te('Not anonymous: your name is kept with your answers.') ?></span></p></div></div>
<?php if ($take['intro']): ?><p><?= e($take['intro']) ?></p><?php endif; ?>
<?php if ($take['responded_on'] !== null): ?><p class="card" id="answered"><?= te('You answered on :date. Thank you.', ['date' => d((string) $take['responded_on'])]) ?></p>
<?php elseif ($take['status'] !== 'open' || (string) $take['closes_on'] < date('Y-m-d')): ?><p class="card muted" id="survey-closed"><?= te('That survey is closed.') ?></p>
<?php else: ?>
<form method="post" class="card" id="respond-form"><?= csrf_field() ?><input type="hidden" name="do" value="respond"><input type="hidden" name="survey_id" value="<?= (int) $take['id'] ?>">
  <?php foreach ($questions as $i => $qq): $nm = 'answer[' . (int) $qq['id'] . ']'; $req = (int) $qq['is_required'] ? ' required' : ''; ?>
  <fieldset data-question="<?= (int) $qq['id'] ?>" data-kind="<?= e($qq['kind']) ?>" style="margin:12px 0"><legend><strong><?= $i + 1 ?>. <?= e($qq['prompt']) ?></strong><?= (int) $qq['is_required'] ? '' : ' <span class="small muted">' . te('optional') . '</span>' ?></legend>
    <?php if ($qq['kind'] === 'scale'): ?><div class="row"><span class="small muted"><?= te('Not at all') ?></span><?php for ($v = 1; $v <= 5; $v++): ?><label><input type="radio" name="<?= e($nm) ?>" value="<?= $v ?>"<?= $req ?>> <?= $v ?></label><?php endfor; ?><span class="small muted"><?= te('Completely') ?></span></div>
    <?php elseif ($qq['kind'] === 'enps'): ?><div class="row"><span class="small muted"><?= te('Not likely') ?></span><?php for ($v = 0; $v <= 10; $v++): ?><label><input type="radio" name="<?= e($nm) ?>" value="<?= $v ?>"<?= $req ?>> <?= $v ?></label><?php endfor; ?><span class="small muted"><?= te('Very likely') ?></span></div>
    <?php elseif ($qq['kind'] === 'choice'): ?><?php foreach ($qq['options'] as $oi => $op): ?><label style="display:block"><input type="radio" name="<?= e($nm) ?>" value="<?= (int) $oi ?>"<?= $req ?>> <?= e($op) ?></label><?php endforeach; ?>
    <?php else: ?><textarea name="<?= e($nm) ?>" rows="3" maxlength="2000"<?= $req ?> aria-label="<?= e($qq['prompt']) ?>"></textarea><?php endif; ?>
  </fieldset><?php endforeach; ?>
  <button class="btn"><?= te('Hand in my answers') ?></button></form>
<?php endif; ?>

<?php elseif ($survey): ?>
<p class="back-link"><a href="/surveys">← <?= te('Surveys') ?></a></p>
<div class="page-head"><div><h1><?= e($survey['title']) ?></h1>
<p class="sub"><span class="tag <?= $svTone[$survey['status']] ?>" id="survey-status" data-status="<?= e($survey['status']) ?>"><?= e($svStatus[$survey['status']]) ?></span> · <?= e($svAud[$survey['audience']]) ?><?= $survey['job_title'] ? ' · ' . e($survey['job_title']) : '' ?><?= $survey['audience_role'] ? ' · ' . e($svRoles[$survey['audience_role']] ?? $survey['audience_role']) : '' ?> · <?= te('Until :date', ['date' => d((string) $survey['closes_on'])]) ?> · <?= (int) $survey['is_anonymous'] ? te('Anonymous') : te('Not anonymous') ?></p></div></div>
<?php if ($survey['status'] === 'draft'): ?>
<div class="grid g2">
  <?php $svForm($survey, $jobs); ?>
  <div class="card" id="survey-questions" data-count="<?= count($questions) ?>"><h2><?= te('Questions') ?></h2>
    <?php foreach ($questions as $i => $qq): ?><div class="small m0 mt-s mb-s" data-question="<?= (int) $qq['id'] ?>"><strong><?= $i + 1 ?>. <?= e($qq['prompt']) ?></strong> · <?= e($svKinds[$qq['kind']]) ?><?= (int) $qq['is_required'] ? '' : ' · ' . te('optional') ?>
      <?php if ($qq['options']): ?><ol type="a"><?php foreach ($qq['options'] as $op): ?><li><?= e($op) ?></li><?php endforeach; ?></ol><?php endif; ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="question_remove"><input type="hidden" name="survey_id" value="<?= (int) $survey['id'] ?>"><input type="hidden" name="question_id" value="<?= (int) $qq['id'] ?>"><button class="btn sm ghost"><?= te('Remove') ?></button></form></div><?php endforeach; ?>
    <form method="post" id="question-form"><?= csrf_field() ?><input type="hidden" name="do" value="question_add"><input type="hidden" name="survey_id" value="<?= (int) $survey['id'] ?>">
      <label><?= te('Question') ?><input name="prompt" required maxlength="500"></label>
      <label><?= te('Kind') ?><select name="kind"><?php foreach ($svKinds as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
      <?php for ($i = 0; $i < 5; $i++): ?><input name="options[]" maxlength="200" placeholder="<?= te('Choice :n (one-choice questions)', ['n' => $i + 1]) ?>" aria-label="<?= te('Choice :n (one-choice questions)', ['n' => $i + 1]) ?>"><?php endfor; ?>
      <label><input type="checkbox" name="is_required" value="1" checked> <?= te('Required') ?></label>
      <button class="btn sm"><?= te('Add the question') ?></button></form>
    <form method="post" id="open-form"><?= csrf_field() ?><input type="hidden" name="do" value="open"><input type="hidden" name="survey_id" value="<?= (int) $survey['id'] ?>"><button class="btn"><?= te('Open it and ask everyone in the audience') ?></button></form>
  </div>
</div>
<?php else: ?>
<div class="card" id="survey-results" data-invited="<?= (int) $results['invited'] ?>" data-responded="<?= (int) $results['responded'] ?>" data-shown="<?= $results['shown'] ? 1 : 0 ?>">
  <h2><?= te('Results') ?></h2>
  <p><?= te(':a of :n answered', ['a' => (int) $results['responded'], 'n' => (int) $results['invited']]) ?><?= $results['invited'] ? ' · ' . round(100 * $results['responded'] / $results['invited']) . '%' : '' ?></p>
  <?php if ($survey['status'] === 'open'): ?><div class="row">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="remind"><input type="hidden" name="survey_id" value="<?= (int) $survey['id'] ?>"><button class="btn sm"><?= te('Remind those who have not answered') ?></button></form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="close"><input type="hidden" name="survey_id" value="<?= (int) $survey['id'] ?>"><button class="btn sm ghost"><?= te('Close it now') ?></button></form></div><?php endif; ?>
  <?php if (! $results['shown']): ?><p class="muted" id="results-held"><?= te('Results show once :n people have answered, so nobody can be picked out.', ['n' => (int) $survey['min_group']]) ?></p>
  <?php else: ?>
  <?php if ($results['segments']): ?><p class="small" id="segments"><?php foreach ($results['segments'] as $sg => $n): ?><span class="tag grey" data-segment="<?= e($sg) ?>"><?= e($svSeg[$sg] ?? $sg) ?>: <?= (int) $n ?></span> <?php endforeach; ?></p><?php endif; ?>
  <?php foreach ($results['questions'] as $i => $r): $qq = $r['question']; ?>
  <div data-result="<?= (int) $qq['id'] ?>" data-kind="<?= e($qq['kind']) ?>"<?= isset($r['average']) ? ' data-average="' . e((string) $r['average']) . '"' : '' ?><?= array_key_exists('enps', $r) ? ' data-enps="' . e((string) $r['enps']) . '"' : '' ?> style="margin:12px 0">
    <strong><?= $i + 1 ?>. <?= e($qq['prompt']) ?></strong> <span class="small muted"><?= ten((int) $r['n'], ':n answer', ':n answers') ?></span>
    <?php if ($qq['kind'] === 'scale'): ?><div><?= te('Average: :v of 5', ['v' => $r['average'] ?? '—']) ?></div><div class="small"><?= e(implode(' · ', array_map(fn($v, $c) => $v . ': ' . $c, array_keys($r['counts']), $r['counts']))) ?></div>
    <?php elseif ($qq['kind'] === 'enps'): ?><div><?= te('Score: :v', ['v' => $r['enps'] ?? '—']) ?></div><div class="small"><?= te('Would recommend (9-10): :a · neutral (7-8): :b · would not (0-6): :c', ['a' => $r['promoters'], 'b' => $r['passives'], 'c' => $r['detractors']]) ?></div>
    <?php elseif ($qq['kind'] === 'choice'): ?><div class="small"><?php foreach ($qq['options'] as $oi => $op): ?><div data-choice="<?= (int) $oi ?>" data-n="<?= (int) $r['counts'][$oi] ?>"><?= e($op) ?>: <?= (int) $r['counts'][$oi] ?></div><?php endforeach; ?></div>
    <?php else: ?><?php foreach ($r['texts'] as $tx): ?><div class="small" data-text style="white-space:pre-wrap;overflow-wrap:anywhere;margin:4px 0">“<?= e($tx['text']) ?>”<?= $tx['name'] ? ' — ' . e($tx['name']) : '' ?></div><?php endforeach; ?><?php endif; ?>
    <?php if (! empty($r['by_segment'])): ?><div class="small muted"><?= implode(' · ', array_map(fn($sg, $v) => '<span data-by-segment="' . e((string) $sg) . '">' . e($svSeg[$sg] ?? (string) $sg) . ': ' . ($v === null ? '—' : e((string) $v)) . '</span>', array_keys($r['by_segment']), $r['by_segment'])) ?></div><?php endif; ?>
  </div><?php endforeach; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php else: ?>
<div class="page-head"><div><h1><?= te('Surveys') ?></h1>
<p class="sub"><?= te('Short surveys on how it is to work here. Anonymous ones keep no name with the answers.') ?></p></div></div>
<div class="card" id="my-surveys" data-count="<?= count($mine) ?>"><h2><?= te('Sent to me') ?></h2>
  <?php if (! $mine): ?><p class="muted"><?= te('No survey has been sent to you.') ?></p><?php else: ?><table>
  <?php foreach ($mine as $m): ?><tr data-my-survey="<?= (int) $m['id'] ?>" data-answered="<?= $m['responded_on'] !== null ? 1 : 0 ?>"><td><a href="/surveys?take=<?= (int) $m['id'] ?>"><?= e($m['title']) ?></a></td><td class="small"><?= te('Until :date', ['date' => d((string) $m['closes_on'])]) ?></td>
    <td><?= $m['responded_on'] !== null ? '<span class="tag green">' . te('Answered') . '</span>' : '<span class="tag ' . $svTone[$m['status']] . '">' . e($svStatus[$m['status']]) . '</span>' ?></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>
<?php if (eng_manager()): ?>
<div class="grid g2">
  <div class="card scroll" id="all-surveys"><h2><?= te('All surveys') ?></h2>
    <?php if (! $all): ?><p class="muted"><?= te('None yet.') ?></p><?php else: ?><table>
    <?php foreach ($all as $s): ?><tr data-survey="<?= (int) $s['id'] ?>"><td><a href="/surveys?survey=<?= (int) $s['id'] ?>"><?= e($s['title']) ?></a></td><td><span class="tag <?= $svTone[$s['status']] ?>"><?= e($svStatus[$s['status']]) ?></span></td><td class="small"><?= te(':a of :n answered', ['a' => (int) $s['responded'], 'n' => (int) $s['invited']]) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
  </div>
  <?php $svForm(null, $jobs); ?>
</div>
<?php endif; ?>
<?php endif; ?>
