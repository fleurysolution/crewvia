<?php
$ctone = ['open' => 'amber', 'decided' => 'blue', 'closed' => 'grey'];
$cstat = ['open' => t('Open'), 'decided' => t('Outcome recorded'), 'closed' => t('Closed')];
?>
<h1><?= $role === 'worker' ? te('My HR record') : te('HR records') ?></h1>
<p class="sub"><?= te('Recognition, disciplinary cases and why assignments ended. Cases and separations are seen only by administrators and those granted HR access; every opening is logged. A case is never edited: notes, the outcome and the person\'s own account are added to it.') ?></p>

<?php if ($mode === 'case'): ?>
<p><a href="/hr-records?candidate=<?= (int) $c['candidate_id'] ?>">← <?= e($c['full_name']) ?></a></p>
<div class="card" id="case" data-case="<?= e($c['reference']) ?>" data-case-status="<?= e($c['status']) ?>">
  <h2><?= e($c['reference']) ?> · <?= e(case_categories()[$c['category']]) ?> <span class="tag <?= $ctone[$c['status']] ?>"><?= e($cstat[$c['status']]) ?></span></h2>
  <p class="small muted"><?= te('What happened on :date · opened by :name on :when', ['date' => d((string) $c['incident_on']), 'name' => $c['opened_by_name'] ?? '—', 'when' => d(substr((string) $c['opened_at'], 0, 10))]) ?><?= $c['safety_incident_id'] ? ' · ' . te('safety incident #:n', ['n' => (int) $c['safety_incident_id']]) : '' ?></p>
  <h3><?= te('The facts') ?></h3><p style="white-space:pre-wrap"><?= e($c['facts']) ?></p>
  <?php if ($c['outcome']): ?><h3><?= te('Outcome') ?></h3><p data-outcome="<?= e($c['outcome']) ?>"><strong><?= e(case_outcomes()[$c['outcome']]) ?></strong> · <?= e((string) $c['outcome_note']) ?> · <?= e($c['decided_by_name'] ?? '') ?></p><?php endif; ?>
  <?php if ($c['response']): ?><h3><?= te('The person\'s account') ?></h3><p style="white-space:pre-wrap" id="case-response"><?= e($c['response']) ?></p><?php endif; ?>
  <p class="small"><?= $c['acknowledged_at'] ? te('Acknowledged by the person on :date', ['date' => d(substr((string) $c['acknowledged_at'], 0, 10))]) : ($c['outcome'] ? te('Not yet acknowledged by the person.') : '') ?></p>
  <h3><?= te('Record') ?></h3>
  <?php foreach ($notes as $n): ?><div class="small" data-note="<?= e($n['kind']) ?>"><?= e(d(substr((string) $n['created_at'], 0, 10))) ?> · <?= e($n['by_name'] ?? '') ?> · <?= e($n['note']) ?></div><?php endforeach; ?>
</div>
<?php if ($hr && $c['status'] !== 'closed'): ?>
<div class="grid g2">
  <form class="card" method="post" id="note-form"><?= csrf_field() ?><input type="hidden" name="do" value="case_note"><input type="hidden" name="case_id" value="<?= (int) $c['id'] ?>"><h3><?= te('Add a note') ?></h3><textarea name="note" required minlength="3" rows="3"></textarea><button class="btn ghost"><?= te('Add') ?></button></form>
  <?php if ($c['status'] === 'open'): ?>
  <form class="card" method="post" id="decide-form"><?= csrf_field() ?><input type="hidden" name="do" value="case_decide"><input type="hidden" name="case_id" value="<?= (int) $c['id'] ?>"><h3><?= te('Record the outcome') ?></h3>
    <select name="outcome"><?php foreach (case_outcomes() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
    <textarea name="note" required minlength="10" rows="3" placeholder="<?= te('Why this outcome') ?>"></textarea><button class="btn"><?= te('Record the outcome') ?></button>
    <p class="muted"><?= te('A termination is decided by an administrator. Whoever opened the case does not decide a final warning, a suspension or a termination on it.') ?></p></form>
  <?php else: ?>
  <form class="card" method="post" id="close-form"><?= csrf_field() ?><input type="hidden" name="do" value="case_close"><input type="hidden" name="case_id" value="<?= (int) $c['id'] ?>"><h3><?= te('Close the case') ?></h3><input name="note" required minlength="3" maxlength="500"><button class="btn ghost"><?= te('Close') ?></button></form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($self === (int) $c['candidate_id'] && $c['status'] === 'decided'): ?>
<form class="card" method="post" id="respond-form"><?= csrf_field() ?><input type="hidden" name="do" value="case_respond"><input type="hidden" name="case_id" value="<?= (int) $c['id'] ?>">
  <h3><?= te('Your account') ?></h3>
  <?php if ($c['response'] === null): ?><textarea name="response" rows="4" placeholder="<?= te('What you want on record, in your own words') ?>"></textarea><?php endif; ?>
  <?php if ($c['acknowledged_at'] === null): ?><label><input type="checkbox" name="acknowledge" value="1"> <?= te('I have read this outcome') ?></label><?php endif; ?>
  <p class="muted"><?= te('Acknowledging means you read it, not that you agree.') ?></p>
  <button class="btn"><?= te('Send') ?></button></form>
<?php endif; ?>

<?php elseif ($mode === 'person'): ?>
<?php if ($role !== 'worker'): ?><p><a href="/hr-records">← <?= te('HR records') ?></a></p><?php endif; ?>
<h2 id="person-name"><?= e($person['name']) ?></h2>
<div class="card scroll" id="recognition"><h3><?= te('Recognition') ?></h3>
  <?php if (! $person['recognition']): ?><p class="muted"><?= te('None yet.') ?></p><?php endif; ?>
  <?php foreach ($person['recognition'] as $r): ?><div data-recognition="<?= (int) $r['id'] ?>"><strong><?= e($r['title']) ?></strong> · <?= e(recognition_kinds()[$r['kind']]) ?> · <?= e(d((string) $r['awarded_on'])) ?><?= $r['description'] ? '<div class="small">' . e($r['description']) . '</div>' : '' ?></div><?php endforeach; ?>
  <?php if ($person['may_recognise']): ?>
  <form method="post" id="recognise-form"><?= csrf_field() ?><input type="hidden" name="do" value="recognise"><input type="hidden" name="candidate_id" value="<?= (int) $person['id'] ?>">
    <div class="grid g2"><div><label><?= te('Kind') ?></label><select name="kind"><?php foreach (recognition_kinds() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label><?= te('On') ?></label><input type="date" name="awarded_on" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>"></div></div>
    <label><?= te('For') ?></label><input name="title" required minlength="3" maxlength="190" placeholder="<?= te('for example: 1,000 hours without a recordable incident') ?>">
    <label><?= te('Details') ?></label><input name="description" maxlength="1000"><button class="btn"><?= te('Record the recognition') ?></button></form>
  <?php endif; ?>
</div>

<?php if ($person['cases'] || $person['may_report']): ?>
<div class="card scroll" id="cases"><h3><?= te('Disciplinary cases') ?></h3>
  <?php if (! $person['cases']): ?><p class="muted"><?= te('None.') ?></p><?php endif; ?>
  <?php foreach ($person['cases'] as $cs): ?><div data-person-case="<?= e($cs['reference']) ?>"><a href="/hr-records?case=<?= (int) $cs['id'] ?>"><?= e($cs['reference']) ?></a> · <?= e(case_categories()[$cs['category']]) ?> · <?= e(d((string) $cs['incident_on'])) ?> · <span class="tag <?= $ctone[$cs['status']] ?>"><?= e($cstat[$cs['status']]) ?></span><?= $cs['outcome'] ? ' · ' . e(case_outcomes()[$cs['outcome']]) : '' ?></div><?php endforeach; ?>
  <?php if ($person['may_report']): ?>
  <form method="post" id="case-form"><?= csrf_field() ?><input type="hidden" name="do" value="case_open"><input type="hidden" name="candidate_id" value="<?= (int) $person['id'] ?>">
    <h3><?= te('Open a case') ?></h3>
    <div class="grid g2"><div><label><?= te('About') ?></label><select name="category"><?php foreach (case_categories() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label><?= te('What happened on') ?></label><input type="date" name="incident_on" required max="<?= e(date('Y-m-d')) ?>"></div></div>
    <label><?= te('Safety incident, if any') ?></label><select name="safety_incident_id"><option value="0">—</option><?php foreach ($person['incidents'] as $i): ?><option value="<?= (int) $i['id'] ?>">#<?= (int) $i['id'] ?> · <?= e(d(substr((string) $i['created_at'], 0, 10))) ?> · <?= e($i['severity']) ?></option><?php endforeach; ?></select>
    <label><?= te('The facts: what happened, when, who saw it') ?></label><textarea name="facts" required minlength="20" rows="4"></textarea>
    <p class="muted"><?= te('The facts are not edited once the case is open.') ?></p><button class="btn"><?= te('Open the case') ?></button></form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($hr): ?>
<div class="card scroll" id="separations"><h3><?= te('Separations') ?></h3>
  <?php foreach ($person['separations'] as $s): ?><div data-separation="<?= (int) $s['placement_id'] ?>"><?= e(d((string) $s['separated_on'])) ?> · <?= e($s['project']) ?> · <strong><?= e(separation_reasons()[$s['reason']]) ?></strong> · <?= (int) $s['voluntary'] === 1 ? te('voluntary') : te('not voluntary') ?> · <?= e(t(['eligible' => 'Fine to call', 'review' => 'Check before calling', 'ineligible' => 'No rehire'][$s['rehire']])) ?><?= $s['case_reference'] ? ' · ' . e($s['case_reference']) : '' ?><?= $s['detail'] ? '<div class="small">' . e($s['detail']) . '</div>' : '' ?></div><?php endforeach; ?>
  <?php foreach ($person['unrecorded'] as $u): ?>
  <form method="post" data-unrecorded="<?= (int) $u['id'] ?>"><?= csrf_field() ?><input type="hidden" name="do" value="separation"><input type="hidden" name="placement_id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="candidate_id" value="<?= (int) $person['id'] ?>">
    <h3><?= te('Why :project ended', ['project' => $u['title']]) ?></h3>
    <div class="grid g2"><div><label><?= te('Reason') ?></label><select name="reason"><?php foreach (separation_reasons() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label><?= te('On') ?></label><input type="date" name="separated_on" required max="<?= e(date('Y-m-d')) ?>" value="<?= e((string) ($u['end_date'] ?: date('Y-m-d'))) ?>"></div>
      <div><label><?= te('Rehire') ?></label><select name="rehire"><option value="eligible"><?= te('Fine to call') ?></option><option value="review"><?= te('Check before calling') ?></option><option value="ineligible"><?= te('No rehire') ?></option></select></div>
      <div><label><?= te('The case that decided it, for a termination') ?></label><select name="case_id"><option value="0">—</option><?php foreach ($person['terminations'] as $tc): ?><option value="<?= (int) $tc['id'] ?>"><?= e($tc['reference']) ?></option><?php endforeach; ?></select></div></div>
    <label><?= te('What happened · required unless the assignment simply finished') ?></label><input name="detail" maxlength="1000">
    <button class="btn"><?= te('Record the separation') ?></button></form>
  <?php endforeach; ?>
  <?php if (! $person['separations'] && ! $person['unrecorded']): ?><p class="muted"><?= te('No assignment has ended.') ?></p><?php endif; ?>
</div>
<?php endif; ?>

<?php else: ?>
<?php if ($overview['crew']): ?><div class="card" id="crew"><h2><?= te('Your crew') ?></h2><?php foreach ($overview['crew'] as $c): ?><div><a href="/hr-records?candidate=<?= (int) $c['id'] ?>"><?= e($c['full_name']) ?></a></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($hr || $role === 'supervisor'): ?>
<div class="card scroll" id="open-cases"><h2><?= $hr ? te('Open cases') : te('Cases you reported') ?></h2>
  <?php if (! $overview['open']): ?><p class="muted"><?= te('None.') ?></p><?php endif; ?>
  <?php foreach ($overview['open'] as $cs): ?><div data-open-case="<?= e($cs['reference']) ?>"><a href="/hr-records?case=<?= (int) $cs['id'] ?>"><?= e($cs['reference']) ?></a> · <?= e($cs['full_name']) ?> · <?= e(case_categories()[$cs['category']]) ?> · <span class="tag <?= $ctone[$cs['status']] ?>"><?= e($cstat[$cs['status']]) ?></span></div><?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($hr): ?>
<div class="card scroll" id="to-record"><h2><?= te('Ended assignments with no separation recorded') ?></h2>
  <?php if (! $overview['to_record']): ?><p class="muted"><?= te('None.') ?></p><?php endif; ?>
  <?php foreach ($overview['to_record'] as $p): ?><div data-to-record="<?= (int) $p['id'] ?>"><a href="/hr-records?candidate=<?= (int) $p['candidate_id'] ?>"><?= e($p['full_name']) ?></a> · <?= e($p['title']) ?><?= $p['end_date'] ? ' · ' . e(d((string) $p['end_date'])) : '' ?></div><?php endforeach; ?>
</div>
<?php endif; ?>
<?php if (! $hr && $role === 'recruiter'): ?><p class="muted"><?= te('Open a person from their employee folder to record recognition. Cases and separations need HR access, granted by an administrator.') ?></p><?php endif; ?>
<?php if (can('admin')): ?>
<div class="grid g2">
  <div class="card scroll" id="grants"><h2><?= te('HR access') ?></h2>
    <p class="muted"><?= te('Administrators always have it. Anyone else sees cases and separations only once granted here.') ?></p>
    <?php foreach ($overview['grants'] as $g): ?><form method="post" class="row" data-grant="<?= (int) $g['user_id'] ?>"><?= csrf_field() ?><input type="hidden" name="do" value="revoke"><input type="hidden" name="user_id" value="<?= (int) $g['user_id'] ?>"><span><?= e($g['name']) ?> · <?= e($g['role']) ?> · <?= e($g['reason']) ?></span><input name="reason" required minlength="3" placeholder="<?= te('Why it ends') ?>" style="max-width:9em"><button class="btn ghost sm"><?= te('Revoke') ?></button></form><?php endforeach; ?>
    <?php if ($overview['grantable']): ?><form method="post" class="row" id="grant-form"><?= csrf_field() ?><input type="hidden" name="do" value="grant"><select name="user_id"><?php foreach ($overview['grantable'] as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name'] . ' · ' . $u['role']) ?></option><?php endforeach; ?></select><input name="reason" required minlength="3" placeholder="<?= te('Why they need it') ?>"><button class="btn sm"><?= te('Grant') ?></button></form><?php endif; ?>
  </div>
  <div class="card scroll" id="access-log"><h2><?= te('Who opened what') ?></h2>
    <?php foreach ($overview['log'] as $l): ?><div class="small" data-log="<?= e($l['action']) ?>"><?= e(substr((string) $l['created_at'], 0, 16)) ?> · <?= e($l['by_name'] ?? '') ?> · <?= e($l['action']) ?><?= $l['full_name'] ? ' · ' . e($l['full_name']) : '' ?><?= $l['subject'] ? ' · ' . e($l['subject']) : '' ?><?= $l['detail'] ? ' · ' . e($l['detail']) : '' ?></div><?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>
