<p><a class="btn ghost" href="/contracts"><?= te('Contracts & signatures') ?></a></p><p><?= te('Employee number') ?>: <?= e($profile['employee_number']??'') ?></p>
<h1>Employee folder · <?= e($c['full_name']) ?></h1><p class="sub"><?= te('Persistent identity, applications, assignments, credentials and audit history across projects.') ?></p><div class="grid g2"><div class="card"><h2><?= te('Employee profile') ?></h2><p><?= e($c['email'].' · '.$c['phone']) ?></p><p><?= e((employment_types()[$profile['employment_type']] ?? $profile['employment_type']).' · '.(flsa_statuses()[$profile['flsa_status'] ?? 'not_determined'] ?? '')) ?></p><p><?= e($profile['availability'].' · rehire '.$profile['rehire_status']) ?></p><?php if(can('recruiter')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="profile"><input type="hidden" name="candidate_id" value="<?= $cid ?>"><label><?= te('Availability') ?></label><select name="availability"><?php foreach(['available','unavailable','on_assignment'] as $x): ?><option <?= $x===$profile['availability']?'selected':'' ?>><?= e($x) ?></option><?php endforeach; ?></select><label><?= te('Rehire assessment') ?></label><select name="rehire_status"><?php foreach(['review','eligible','ineligible'] as $x): ?><option <?= $x===$profile['rehire_status']?'selected':'' ?>><?= e($x) ?></option><?php endforeach; ?></select><button class="btn"><?= te('Save profile') ?></button></form><?php endif; ?></div>
<?php if($own): $fields = self_service_fields(); $states = ['pending' => t('Waiting to be checked'), 'approved' => t('Accepted'), 'rejected' => t('Refused')]; ?>
<div class="card" id="my-details">
  <h2><?= te('My details') ?></h2>
  <p class="muted"><?= te('Coming back for another job? If nothing has changed, say so and you are done. To change something, ask below; somebody here checks it before your record changes.') ?></p>
  <table><?php foreach ($fields as $k => $label): ?><tr><td class="muted"><?= e($label) ?></td><td><?= e((string) ($c[$k] ?? '')) ?: '—' ?></td></tr><?php endforeach; ?></table>
  <p class="small"><?= $selfService['confirmed'] ? te('Last confirmed unchanged on :date.', ['date' => d(substr((string) $selfService['confirmed'], 0, 10))]) : te('Not confirmed yet.') ?></p>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="self_confirm"><button class="btn"><?= te('Nothing has changed') ?></button></form>
  <form method="post" class="row" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="do" value="self_detail">
    <select name="field" aria-label="<?= te('Detail') ?>"><?php foreach ($fields as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
    <input name="value" required maxlength="190" placeholder="<?= te('New value') ?>" aria-label="<?= te('New value') ?>">
    <input name="reason" maxlength="500" placeholder="<?= te('Why (optional)') ?>" aria-label="<?= te('Why (optional)') ?>">
    <button class="btn ghost"><?= te('Ask for the change') ?></button></form>
  <?php foreach ($selfService['details'] as $r): ?><div class="small" data-detail-request="<?= (int) $r['id'] ?>"><?= e($fields[$r['field']] ?? $r['field']) ?>: <?= e((string) $r['new_value']) ?> · <span class="tag <?= $r['status'] === 'approved' ? 'green' : ($r['status'] === 'rejected' ? 'red' : 'amber') ?>"><?= e($states[$r['status']]) ?></span><?= $r['review_note'] ? ' · ' . e($r['review_note']) : '' ?></div><?php endforeach; ?>
</div>
<div class="card" id="my-bank">
  <h2><?= te('My bank details') ?></h2>
  <?php if ($ownBank): ?><p><?= te('In use: :bank, account ending :last.', ['bank' => $ownBank['bank_label'], 'last' => $ownBank['last_four']]) ?></p><?php else: ?><p class="muted"><?= te('No bank details on file yet.') ?></p><?php endif; ?>
  <?php foreach ($selfService['bank'] as $r): ?><div class="small" data-bank-request="<?= (int) $r['id'] ?>"><?= te('Sent :date: :bank, ending :last', ['date' => d(substr((string) $r['submitted_at'], 0, 10)), 'bank' => $r['bank_label'], 'last' => $r['last_four']]) ?> · <span class="tag <?= $r['status'] === 'approved' ? 'green' : ($r['status'] === 'rejected' ? 'red' : 'amber') ?>"><?= e($states[$r['status']]) ?></span><?= $r['review_note'] ? ' · ' . e($r['review_note']) : '' ?></div><?php endforeach; ?>
  <form method="post" style="margin-top:8px" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="do" value="self_bank">
    <div class="grid g2">
      <div><label><?= te('Name on the account') ?></label><input name="account_holder" required maxlength="190" value="<?= e($c['full_name']) ?>"></div>
      <div><label><?= te('Bank') ?></label><input name="bank_name" required maxlength="90"></div>
      <div><label><?= te('Routing number') ?></label><input name="routing_number" required inputmode="numeric" pattern="[0-9 ]{9,11}" autocomplete="off"></div>
      <div><label><?= te('Account number') ?></label><input name="account_number" required inputmode="numeric" pattern="[0-9 ]{4,24}" autocomplete="off"></div>
    </div>
    <p class="small muted"><?= te('Encrypted as soon as it is sent. Only the last four digits are ever shown, and payroll checks it before it is used.') ?></p>
    <button class="btn"><?= te('Send my bank details') ?></button></form>
</div>
<?php elseif (can('recruiter') || can('payroll')): $waiting = count(array_filter($selfService['details'], fn($r) => $r['status'] === 'pending')) + count(array_filter($selfService['bank'], fn($r) => $r['status'] === 'pending')); ?>
<div class="card" id="self-service-summary"><h2><?= te('Asked for by the worker') ?></h2>
  <p><?= $waiting ? te(':n change(s) waiting to be checked.', ['n' => $waiting]) . ' <a href="/change-requests">' . te('Check them') . '</a>' : te('Nothing waiting from this person.') ?></p>
  <p class="small muted"><?= $selfService['confirmed'] ? te('Last confirmed unchanged on :date.', ['date' => d(substr((string) $selfService['confirmed'], 0, 10))]) : te('Not confirmed yet.') ?></p></div>
<?php endif; ?>
<?php if(can('payroll') && !$own): ?><div class="card" id="pay-items"><h2><?= te('Deductions and contributions') ?></h2><p class="muted"><?= te('Taken from each approved week, or paid on top by the employer, from the start date. Advances are repaid automatically and are not set here.') ?></p><?php if(!$payItems): ?><p class="muted"><?= te('Nothing is taken from this person\'s pay, and the employer adds nothing.') ?></p><?php else: ?><table><tr><th><?= te('Item') ?></th><th class="num"><?= te('Amount') ?></th><th><?= te('From') ?></th><th><?= te('Until') ?></th></tr><?php foreach($payItems as $pi): ?><tr data-pay-item="<?= e($pi['code']) ?>"><td><?= e(t((string)$pi['label'])) ?><div class="small muted"><?= e(pay_item_sides()[$pi['side']] ?? $pi['side']) ?></div></td><td class="num mono"><?= $pi['method']==='percent_of_gross' ? e((string)(float)$pi['amount']).'%' : e(money($pi['amount'])) ?></td><td><?= e(d($pi['starts_on'])) ?></td><td><?php if($pi['ends_on']): ?><?= e(d($pi['ends_on'])) ?><?php else: ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="pay_item_end"><input type="hidden" name="candidate_id" value="<?= $cid ?>"><input type="hidden" name="employee_pay_item_id" value="<?= (int)$pi['id'] ?>"><input type="date" name="ends_on" required aria-label="<?= te('Until') ?>"><button class="btn ghost sm"><?= te('End') ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></table><?php endif; ?><?php if($payItemChoices): ?><form method="post" class="row" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="do" value="pay_item_add"><input type="hidden" name="candidate_id" value="<?= $cid ?>"><select name="pay_item_id" aria-label="<?= te('Item') ?>"><?php foreach($payItemChoices as $ch): ?><option value="<?= (int)$ch['id'] ?>"><?= e(t((string)$ch['label'])) ?> · <?= e(pay_item_methods()[$ch['method']] ?? '') ?></option><?php endforeach; ?></select><input name="amount" type="number" min="0.01" step="0.01" required placeholder="<?= te('Amount or %') ?>" aria-label="<?= te('Amount') ?>" style="max-width:8em"><input type="date" name="starts_on" required value="<?= e(date('Y-m-d')) ?>" aria-label="<?= te('From') ?>"><input name="note" maxlength="255" placeholder="<?= te('Note') ?>" aria-label="<?= te('Note') ?>"><button class="btn sm"><?= te('Add') ?></button></form><?php else: ?><p class="small muted"><?= te('No pay item yet. Create them on the Pay items page.') ?></p><?php endif; ?></div><?php endif; ?>
<?php if(!$own): ?><div class="card" id="classification"><h2><?= te('Employment classification') ?></h2><p class="muted"><?= te('Decides whether a week is paid by the hour or as a salary, and whether overtime applies. Each change keeps the date it took effect and why.') ?></p><?php if(can('recruiter') || can('payroll')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="classification"><input type="hidden" name="candidate_id" value="<?= $cid ?>"><label><?= te('Kind of employment') ?></label><select name="employment_type"><?php foreach(employment_types() as $slug=>$label): ?><option value="<?= e($slug) ?>" <?= $slug===$profile['employment_type']?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select><label><?= te('Overtime status') ?></label><select name="flsa_status"><?php foreach(flsa_statuses() as $slug=>$label): ?><option value="<?= e($slug) ?>" <?= $slug===($profile['flsa_status'] ?? '')?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select><label><?= te('Takes effect on') ?></label><input type="date" name="effective_from" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>"><label><?= te('Reason') ?></label><input name="reason" required minlength="3" maxlength="500"><button class="btn"><?= te('Record the change') ?></button></form><?php endif; ?><?php if(!$classifications): ?><p class="muted"><?= te('No change recorded yet. The profile shows the classification held since the record was created.') ?></p><?php else: ?><table><tr><th><?= te('From') ?></th><th><?= te('Kind of employment') ?></th><th><?= te('Overtime status') ?></th><th><?= te('Reason') ?></th><th><?= te('Recorded by') ?></th></tr><?php foreach($classifications as $k): ?><tr><td><?= $k['effective_from'] ? e(d($k['effective_from'])) : te('Before history was kept') ?></td><td><?= e(employment_types()[$k['employment_type']] ?? $k['employment_type']) ?></td><td><?= e(flsa_statuses()[$k['flsa_status']] ?? $k['flsa_status']) ?></td><td><?= e($k['reason']==='Held before classification history was kept.' ? t('Held before classification history was kept.') : $k['reason']) ?></td><td><?= e(($k['recorded_by_name'] ?? '') !== '' ? $k['recorded_by_name'].' · '.d(substr((string)$k['recorded_at'],0,10)) : '—') ?></td></tr><?php endforeach; ?></table><?php endif; ?></div><?php endif; ?>

<?php if(can('payroll')): ?><form class="card" method="post"><?= csrf_field() ?><input type="hidden" name="do" value="payment"><input type="hidden" name="candidate_id" value="<?= $cid ?>"><h2><?= te('Payroll setup') ?></h2><label><?= te('Payment method') ?></label><select name="payment_method"><?php foreach(['direct_deposit','check','cash'] as $x): ?><option <?= $x===$profile['payment_method']?'selected':'' ?>><?= e($x) ?></option><?php endforeach; ?></select><label><?= te('ADP employee ID') ?></label><input name="adp_employee_id" value="<?= e($profile['adp_employee_id']) ?>"><label><?= te('Salary per payroll period · salaried workers') ?></label><input type="number" min="0" step="0.01" name="salary_per_period" value="<?= e($profile['salary_per_period']) ?>"><button class="btn"><?= te('Save payment setup') ?></button><p class="muted"><?= te('No bank account data is entered here. Use the approved ADP enrollment process.') ?></p></form><?php endif; ?></div>

<div class="card"><h2><?= te('Assignment history') ?></h2><?php foreach($placements as $a): ?><p><?= e($a['title'].' · '.$a['trade'].' · '.$a['status'].' · '.$a['start_date'].' → '.$a['end_date'].' · '.$a['supervisor']) ?></p><?php endforeach; ?></div><div class="card"><h2><?= te('Application history') ?></h2><?php foreach($applications as $a): ?><p><?= e($a['title'].' · '.$a['stage'].' · '.$a['created_at']) ?></p><?php endforeach; ?></div>

<form class="card" method="post"><?= csrf_field() ?><input type="hidden" name="do" value="credential"><input type="hidden" name="candidate_id" value="<?= $cid ?>"><h2><?= te('Add credential / driver licence') ?></h2><label><?= te('Type · use driver_license for driving eligibility') ?></label><input name="credential_type" required maxlength="120"><label><?= te('Description / class (e.g. CDL)') ?></label><input name="description" maxlength="255"><label><?= te('Expiry date') ?></label><input name="expires_on" type="date" required><label><?= te('Supporting uploaded proof') ?></label><select name="document_id"><option value=""><?= te('No proof yet') ?></option><?php foreach($docs as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['document_type']) ?></option><?php endforeach; ?></select><button class="btn"><?= te('Submit credential') ?></button></form><div class="card"><h2><?= te('Credentials') ?></h2><?php foreach($credentials as $d): ?><form class="row" method="post"><?= csrf_field() ?><input type="hidden" name="do" value="review_credential"><input type="hidden" name="candidate_id" value="<?= $cid ?>"><input type="hidden" name="credential_id" value="<?= (int)$d['id'] ?>"><span><?= e($d['credential_type'].' · '.$d['expires_on'].' · '.$d['status']) ?></span><?php if(can('recruiter')): ?><select name="status"><option value="verified"><?= te('verified') ?></option><option value="rejected"><?= te('rejected') ?></option></select><button class="btn sm"><?= te('Review') ?></button><?php endif; ?></form><?php endforeach; ?></div>

<div class="card"><h2><?= te('Document index') ?></h2><?php foreach($docs as $d): ?><p><?= e($d['document_type'].' · '.$d['status'].' · '.$d['created_at']) ?></p><?php endforeach; ?></div><div class="card"><h2><?= te('Traceability') ?></h2><?php foreach($events as $e): ?><p><?= e($e['created_at'].' · '.$e['name'].' · '.$e['event_type'].' · '.$e['detail']) ?></p><?php endforeach; ?></div><div class="card"><h2><?= te('Signed document history') ?></h2><?php foreach($signatures as $s): ?><p><?= e($s['title'].' · '.$s['status'].' · '.$s['signer_name'].' · '.$s['signed_at']) ?></p><?php endforeach; ?></div><?php if(!$own): ?><div class="card"><h2><?= te('Operational audit history') ?></h2><?php foreach($history as $h): ?><p><?= e($h['created_at'].' · '.$h['name'].' · '.$h['action'].' · '.$h['detail']) ?></p><?php endforeach; ?></div><?php endif; ?>

<?php if(!$own): ?><div class="card"><h2><?= te('Recruitment decisions') ?></h2><?php foreach($applicationHistory as $event): ?><p><?= e($event['created_at'].' · '.$event['title'].' · '.$event['stage'].' · '.$event['name'].' · '.$event['note']) ?></p><?php endforeach; ?></div><?php endif; ?>


<p><a class="btn ghost" href="/qualifications?candidate_id=<?= (int)$cid ?>"><?= te('Qualifications & expiry') ?></a></p>

<p><a class="btn ghost" href="/resumes?candidate_id=<?= (int)$cid ?>"><?= te('Candidate resumes') ?></a></p>

<?php if (can('payroll')): ?>
<?php require_once __DIR__ . '/../hr.php'; ?>
<section class="card" id="bank">
  <span class="eyebrow"><?= te('WHERE THE MONEY GOES') ?></span>
  <h2><?= te('Bank details') ?></h2>
  <p class="small muted" style="margin:-6px 0 12px">
    <?= te('Encrypted with the workspace key. Only the last four digits are shown, only the payroll desk can open this, and every time somebody reads the full record it is written down.') ?>
  </p>

  <?php if (! $bank): ?>
    <div class="empty"><?= te('Nothing on file. This person cannot be paid by transfer until there is.') ?></div>
  <?php else: ?>
    <p>
      <span class="tag <?= bank_tone((string) $bank['status']) ?>">
        <?= te(bank_states()[$bank['status']] ?? $bank['status']) ?>
      </span>
      <strong><?= e($bank['bank_label'] ?: t('bank not named')) ?></strong>
      <span class="mono">&bull;&bull;&bull;&bull;<?= e($bank['last_four'] ?: '????') ?></span>
      <?php if ($bank['reviewed_at']): ?>
        <span class="muted small"><?= te('checked :when', ['when' => d($bank['reviewed_at'])]) ?></span>
      <?php endif; ?>
    </p>

    <?php if ($bankFull && empty($bankFull['unreadable'])): ?>
      <div class="card" style="background:#FDF6E7;border-color:#EEDCB0">
        <p class="small" style="margin:0 0 8px">
          <strong><?= te('Full record — this read has been logged.') ?></strong>
        </p>
        <table><tbody>
          <tr><td><?= te('Account holder') ?></td>
              <td class="right mono"><?= e($bankFull['details']['account_holder'] ?? '—') ?></td></tr>
          <tr><td><?= te('Account number') ?></td>
              <td class="right mono"><?= e($bankFull['details']['account_number'] ?? '—') ?></td></tr>
          <tr><td><?= te('Routing number') ?></td>
              <td class="right mono"><?= e($bankFull['details']['routing_number'] ?? '—') ?></td></tr>
        </tbody></table>
      </div>
    <?php elseif ($bankFull && ! empty($bankFull['unreadable'])): ?>
      <p class="small" style="color:var(--red)">
        <?= te('This record cannot be decrypted. The encryption key has changed since it was saved; re-enter the details.') ?>
      </p>
    <?php else: ?>
      <p><a class="btn ghost sm" href="/employee-folder?id=<?= (int) $cid ?>&amp;reveal=bank#bank">
        <?= te('Show the full number') ?>
      </a></p>
    <?php endif; ?>
  <?php endif; ?>

  <form method="post" style="margin-top:14px">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="bank">
    <input type="hidden" name="candidate_id" value="<?= (int) $cid ?>">
    <h3 style="margin:0 0 10px">
      <?= $bank ? te('Replace these details') : te('Record bank details') ?>
    </h3>
    <div class="row">
      <div style="flex:2">
        <label for="b-holder"><?= te('Name on the account') ?></label>
        <input id="b-holder" name="account_holder" maxlength="190"
               value="<?= e($c['full_name']) ?>">
      </div>
      <div style="flex:2">
        <label for="b-bank"><?= te('Bank') ?></label>
        <input id="b-bank" name="bank_name" maxlength="90" required>
      </div>
    </div>
    <div class="row" style="margin-top:10px">
      <div>
        <label for="b-routing"><?= te('Routing number') ?></label>
        <input id="b-routing" name="routing_number" maxlength="9" required
               inputmode="numeric" autocomplete="off">
        <span class="hint"><?= te('Nine digits. The checksum is verified.') ?></span>
      </div>
      <div>
        <label for="b-account"><?= te('Account number') ?></label>
        <input id="b-account" name="account_number" maxlength="20" required
               inputmode="numeric" autocomplete="off">
      </div>
      <div class="row tight"><button class="btn" type="submit"><?= te('Save') ?></button></div>
    </div>
  </form>
</section>
<?php endif; ?>
