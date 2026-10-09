<p><a class="btn ghost" href="/contracts"><?= te('Contracts & signatures') ?></a></p><p><?= te('Employee number') ?>: <?= e($profile['employee_number']??'') ?></p>
<h1>Employee folder · <?= e($c['full_name']) ?></h1><p class="sub"><?= te('Persistent identity, applications, assignments, credentials and audit history across projects.') ?></p><div class="grid g2"><div class="card"><h2><?= te('Employee profile') ?></h2><p><?= e($c['email'].' · '.$c['phone']) ?></p><p><?= e($profile['employment_type'].' · '.$profile['availability'].' · rehire '.$profile['rehire_status']) ?></p><?php if(can('recruiter')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="profile"><input type="hidden" name="candidate_id" value="<?= $cid ?>"><label><?= te('Employment category') ?></label><select name="employment_type"><?php foreach(['hourly','salaried','contractor','external'] as $x): ?><option <?= $x===$profile['employment_type']?'selected':'' ?>><?= e($x) ?></option><?php endforeach; ?></select><label><?= te('Availability') ?></label><select name="availability"><?php foreach(['available','unavailable','on_assignment'] as $x): ?><option <?= $x===$profile['availability']?'selected':'' ?>><?= e($x) ?></option><?php endforeach; ?></select><label><?= te('Rehire assessment') ?></label><select name="rehire_status"><?php foreach(['review','eligible','ineligible'] as $x): ?><option <?= $x===$profile['rehire_status']?'selected':'' ?>><?= e($x) ?></option><?php endforeach; ?></select><button class="btn"><?= te('Save profile') ?></button></form><?php endif; ?></div>

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
