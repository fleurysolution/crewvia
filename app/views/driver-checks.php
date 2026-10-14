<?php
$dkKinds = dr_kinds();
$dkDecisions = dr_decisions();
$dkTone = ['acceptable' => 'green', 'review' => 'amber', 'not_acceptable' => 'red', 'requested' => 'blue', 'cancelled' => 'grey', 'overdue' => 'red', 'due' => 'amber', 'current' => 'green'];
$dkDue = ['overdue' => t('Overdue'), 'due' => t('Due within the month'), 'current' => t('Current')];
?>
<?php if (isset($check)): $k = $check['kind']; ?>
<p class="back-link"><a href="/driver-checks?candidate=<?= (int) $check['candidate_id'] ?>">← <?= e($check['full_name']) ?></a></p>
<div class="page-head"><div><h1><?= e($dkKinds[$k]) ?></h1>
<p class="sub"><?= e($check['full_name']) ?> · <?= e($check['state']) ?> <?= te('licence ending :last4', ['last4' => $check['license_last4']]) ?><?= $check['query_type'] ? ' · ' . e($check['query_type'] === 'full' ? t('Full query') : t('Limited query')) : '' ?>
  · <span class="tag <?= $dkTone[$check['status'] === 'completed' ? (string) $check['decision'] : $check['status']] ?>" id="check-status" data-status="<?= e($check['status']) ?>"><?= e($check['status'] === 'completed' ? $dkDecisions[$check['decision']] : t(ucfirst($check['status']))) ?></span></p></div></div>
<div class="grid g2">
  <div class="card" id="consent">
    <h2><?= te('Consent') ?></h2>
    <p><?= e(dr_consents()[$check['consent_method']]) ?> · <?= e(d((string) $check['consent_on'])) ?></p>
    <?php if ($check['consent_reference']): ?><p class="small"><?= e($check['consent_reference']) ?></p><?php endif; ?>
    <?php if ($check['status'] === 'requested'): ?>
    <p><a class="btn" id="provider-link" href="<?= e($providerLinks[$k]) ?>" target="_blank" rel="noopener noreferrer"><?= te('Open :provider', ['provider' => dr_provider_names()[$k]]) ?></a></p>
    <p class="small muted"><?= te('Sign in to the agency\'s account there, run the check with the licence above, then record what it returned here. Nothing is sent from Crewvia.') ?></p>
    <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="check_id" value="<?= (int) $check['id'] ?>"><input name="reason" maxlength="500" placeholder="<?= te('Why it is cancelled') ?>" aria-label="<?= te('Why it is cancelled') ?>"><button class="btn sm ghost"><?= te('Cancel') ?></button></form>
    <?php endif; ?>
  </div>
  <?php if ($check['status'] === 'requested'): ?>
  <form method="post" class="card" id="result-form"><?= csrf_field() ?><input type="hidden" name="do" value="record"><input type="hidden" name="check_id" value="<?= (int) $check['id'] ?>">
    <h2><?= te('The result') ?></h2>
    <div class="grid g2"><label><?= te('Date of the result') ?><input type="date" name="result_on" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>"></label>
      <label><?= te('Provider reference (order number)') ?><input name="provider_reference" maxlength="80"></label></div>
    <?php if ($k === 'mvr'): ?>
      <label><?= te('Licence status') ?><select name="license_status"><?php foreach (dr_license_statuses() as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label><input type="checkbox" name="cdl" value="1"> <?= te('Commercial driver\'s licence (CDL)') ?></label>
      <div class="grid g2">
        <label><?= te('CDL class') ?><select name="cdl_class"><option value=""></option><option>A</option><option>B</option><option>C</option></select></label>
        <label><?= te('Endorsements (H, N, P, S, T, X)') ?><input name="endorsements" maxlength="20"></label>
        <label><?= te('Licence expires on') ?><input type="date" name="license_expires_on"></label>
        <label><?= te('Medical certificate expires on') ?><input type="date" name="medical_expires_on"></label>
        <label><?= te('Violations, last 3 years') ?><input type="number" name="violations_3y" min="0" max="99" required value="0"></label>
        <label><?= te('Accidents, last 3 years') ?><input type="number" name="accidents_3y" min="0" max="99" required value="0"></label>
      </div>
      <label><?= te('Restrictions') ?><input name="restrictions" maxlength="190"></label>
      <label><input type="checkbox" name="major_violation" value="1"> <?= te('A major violation (DUI, leaving the scene, a felony with a vehicle)') ?></label>
    <?php elseif ($k === 'clearinghouse'): ?>
      <label><?= te('What the Clearinghouse returned') ?><select name="clearinghouse_result"><?php foreach (dr_clearinghouse_results() as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
      <?php if ($check['query_type'] === 'limited'): ?><p class="small muted"><?= te('If a limited query finds information, a full query must follow within 24 hours, on the driver\'s consent in the Clearinghouse; Crewvia opens it.') ?></p><?php endif; ?>
    <?php else: ?>
      <div class="grid g2">
        <label><?= te('Crashes, last 5 years') ?><input type="number" name="crashes_5y" min="0" required value="0"></label>
        <label><?= te('Inspections, last 3 years') ?><input type="number" name="inspections_3y" min="0" required value="0"></label>
        <label><?= te('Out-of-service violations, last 3 years') ?><input type="number" name="oos_3y" min="0" required value="0"></label>
      </div>
    <?php endif; ?>
    <label><?= te('Decision') ?><select name="decision"><?php foreach ($dkDecisions as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
    <?php if ($k !== 'clearinghouse'): ?><label><?= te('Pre-adverse notice sent on (needed for a decision against the person)') ?><input type="date" name="adverse_notice_on" max="<?= e(date('Y-m-d')) ?>"></label><?php endif; ?>
    <label><?= te('Notes') ?><textarea name="notes" rows="3" maxlength="1000"></textarea></label>
    <button class="btn"><?= te('Record the result') ?></button></form>
  <?php else: ?>
  <div class="card" id="result" data-decision="<?= e((string) $check['decision']) ?>">
    <h2><?= te('The result') ?></h2>
    <?php if ($check['status'] === 'completed'): ?>
    <p><?= e(d((string) $check['result_on'])) ?><?= $check['provider_reference'] ? ' · ' . e($check['provider_reference']) : '' ?></p>
    <?php if ($k === 'mvr'): ?><p><?= e(dr_license_statuses()[$check['license_status']]) ?><?= (int) $check['cdl'] ? ' · CDL ' . e((string) $check['cdl_class']) . ($check['endorsements'] ? ' · ' . e($check['endorsements']) : '') : '' ?><?= $check['license_expires_on'] ? ' · ' . te('expires :date', ['date' => d((string) $check['license_expires_on'])]) : '' ?></p>
      <p class="small"><?= te(':v violation(s), :a accident(s) in 3 years', ['v' => (int) $check['violations_3y'], 'a' => (int) $check['accidents_3y']]) ?><?= (int) $check['major_violation'] ? ' · ' . te('a major violation') : '' ?><?= $check['medical_expires_on'] ? ' · ' . te('medical certificate until :date', ['date' => d((string) $check['medical_expires_on'])]) : '' ?></p>
    <?php elseif ($k === 'clearinghouse'): ?><p><?= e(dr_clearinghouse_results()[$check['clearinghouse_result']]) ?></p>
    <?php else: ?><p class="small"><?= te(':c crash(es) in 5 years; :i inspection(s) and :o out-of-service violation(s) in 3 years', ['c' => (int) $check['crashes_5y'], 'i' => (int) $check['inspections_3y'], 'o' => (int) $check['oos_3y']]) ?></p><?php endif; ?>
    <?php if ($check['adverse_notice_on']): ?><p class="small"><?= te('Pre-adverse notice sent on :date', ['date' => d((string) $check['adverse_notice_on'])]) ?></p><?php endif; ?>
    <?php if ($check['next_due_on']): ?><p class="small" id="next-due"><?= te('Due again on :date', ['date' => d((string) $check['next_due_on'])]) ?></p><?php endif; ?>
    <?php endif; ?>
    <?php if ($check['notes']): ?><p class="small pre-wrap"><?= e($check['notes']) ?></p><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php else: ?>
<div class="page-head"><div><h1><?= te('Driving records') ?></h1>
<p class="sub"><?= te('The motor vehicle record (MVRNOW), the FMCSA Clearinghouse and PSP. Each is run in the provider\'s own site; Crewvia keeps the consent, the result, the decision and when it is due again. A valid CDL and a medical certificate go into Qualifications.') ?></p></div></div>
<?php if ($person): ?>
<h2 id="person"><?= e($person['full_name']) ?></h2>
<div class="grid g2">
  <form method="post" class="card" id="request-form"><?= csrf_field() ?><input type="hidden" name="do" value="request"><input type="hidden" name="candidate_id" value="<?= (int) $person['id'] ?>">
    <h3><?= te('Request a check') ?></h3>
    <label><?= te('Check') ?><select name="kind"><?php foreach ($dkKinds as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Clearinghouse query') ?><select name="query_type"><option value="full"><?= te('Full query (before driving)') ?></option><option value="limited"><?= te('Limited query (yearly)') ?></option></select></label>
    <div class="grid g2"><label><?= te('State that issued the licence') ?><select name="state"><?php foreach (DR_STATES as $s): ?><option><?= e($s) ?></option><?php endforeach; ?></select></label>
      <label><?= te('Licence number') ?><input name="license_number" required maxlength="25" autocomplete="off"></label></div>
    <label><?= te('Consent') ?><select name="consent_method"><?php foreach (dr_consents() as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
    <div class="grid g2"><label><?= te('Consent given on') ?><input type="date" name="consent_on" required max="<?= e(date('Y-m-d')) ?>"></label>
      <label><?= te('Where the consent is kept (optional)') ?><input name="consent_reference" maxlength="190"></label></div>
    <button class="btn"><?= te('Request') ?></button></form>
  <div class="card scroll" id="person-checks" data-count="<?= count($checks) ?>"><h3><?= te('Checks') ?></h3>
    <?php if (! $checks): ?><p class="muted"><?= te('None yet.') ?></p><?php else: ?><table>
    <?php foreach ($checks as $ch): ?><tr data-check="<?= (int) $ch['id'] ?>" data-status="<?= e($ch['status']) ?>"><td><a href="/driver-checks?check=<?= (int) $ch['id'] ?>"><?= e($dkKinds[$ch['kind']]) ?></a><?= $ch['follows_check_id'] ? ' <span class="tag amber">' . te('follows a limited query') . '</span>' : '' ?></td>
      <td class="small"><?= e($ch['status'] === 'completed' ? d((string) $ch['result_on']) : d(substr((string) $ch['requested_at'], 0, 10))) ?></td>
      <td><span class="tag <?= $dkTone[$ch['status'] === 'completed' ? (string) $ch['decision'] : $ch['status']] ?>"><?= e($ch['status'] === 'completed' ? $dkDecisions[$ch['decision']] : t(ucfirst($ch['status']))) ?></span></td></tr><?php endforeach; ?></table><?php endif; ?>
  </div>
</div>
<?php endif; ?>
<div class="grid g2">
  <div class="card scroll" id="due" data-count="<?= count(array_filter($latest, fn($d) => dr_due_state($d) !== 'current')) ?>"><h2><?= te('Due again') ?></h2>
    <?php $dueList = array_filter($latest, fn($d) => $d['next_due_on'] !== null); if (! $dueList): ?><p class="muted"><?= te('Nothing recorded yet.') ?></p><?php else: ?><table>
    <?php foreach ($dueList as $d): $st = dr_due_state($d); ?><tr data-latest="<?= (int) $d['id'] ?>" data-due="<?= e($st) ?>"><td><a href="/driver-checks?candidate=<?= (int) $d['candidate_id'] ?>"><?= e($d['full_name']) ?></a></td><td class="small"><?= e($dkKinds[$d['kind']]) ?></td>
      <td class="small"><?= e(d((string) $d['next_due_on'])) ?></td><td><span class="tag <?= $dkTone[$st] ?>"><?= e($dkDue[$st]) ?></span></td></tr><?php endforeach; ?></table><?php endif; ?>
  </div>
  <div class="card scroll" id="open" data-count="<?= count($open) ?>"><h2><?= te('Waiting for a result') ?></h2>
    <?php if (! $open): ?><p class="muted"><?= te('None.') ?></p><?php else: ?><table>
    <?php foreach ($open as $o): ?><tr data-open="<?= (int) $o['id'] ?>"><td><a href="/driver-checks?check=<?= (int) $o['id'] ?>"><?= e($o['full_name']) ?></a></td><td class="small"><?= e($dkKinds[$o['kind']]) ?></td><td class="small"><?= e(d(substr((string) $o['requested_at'], 0, 10))) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
  </div>
</div>
<?php if (can('admin')): ?>
<form method="post" class="card" id="links"><?= csrf_field() ?><input type="hidden" name="do" value="links">
  <h2><?= te('Where each check is run') ?></h2>
  <?php foreach ($dkKinds as $v => $l): ?><label><?= e($l) ?><input name="<?= e($v) ?>" type="url" value="<?= e($providerLinks[$v]) ?>"></label><?php endforeach; ?>
  <button class="btn sm"><?= te('Save') ?></button></form>
<?php endif; ?>
<?php endif; ?>
