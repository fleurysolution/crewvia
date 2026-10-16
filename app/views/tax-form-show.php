<?php
// P5-M64: a signed form, as signed. In full for the people who process it (each opening recorded), masked for the worker.
$d = $tf['data'];
$x = static fn($k) => e((string) ($d[$k] ?? '')) ?: '—';
$money = static fn($k) => '$' . number_format((float) ($d[$k] ?? 0), 2);
$yes = static fn($k) => ! empty($d[$k]) ? te('Yes') : te('No');
$statusWord = ['submitted' => t('Waiting to be checked'), 'accepted' => t('Accepted'), 'returned' => t('Sent back'), 'replaced' => t('Replaced by a newer one')];
$name = trim(($d['first_name'] ?? '') . ' ' . ($d['middle_initial'] ?? '') . ' ' . ($d['last_name'] ?? ''));
$address = trim(($d['address'] ?? '') . (! empty($d['apartment']) ? ', ' . $d['apartment'] : '') . ', ' . ($d['city'] ?? '') . ', ' . ($d['state'] ?? '') . ' ' . ($d['zip'] ?? ''));
?>
<div class="page-head no-print"><div><h1><?= e(tf_forms()[$tf['form']]['name']) ?> · <?= e($tf['full_name']) ?></h1>
  <p class="sub"><?= $own ? te('What you signed. The full numbers are shown only to payroll and the office.') : te('Opened in full: this look is recorded. Print it or save it as PDF from the browser for the employment file.') ?></p></div>
  <div class="actions"><button type="button" class="btn ghost" onclick="window.print()"><?= te('Print') ?></button><a class="btn ghost" href="<?= $own ? '/portal#onboarding' : '/change-requests#tax-forms' ?>"><?= te('Back') ?></a></div></div>

<div class="tf-sheet">
<?php if (! $tf['intact']): ?><div class="card"><p class="t-red" data-tax-form-broken="1"><strong><?= te('This form cannot be read, or it no longer matches what was signed. Do not use it: ask the worker to sign it again, and tell an administrator.') ?></strong></p></div><?php endif; ?>

<div class="card" data-tax-form="<?= (int) $tf['id'] ?>">
  <h2><?= $tf['form'] === 'w4' ? e('Form W-4 (' . ($d['tax_year'] ?? '') . ') · Employee\'s Withholding Certificate') : e('Form I-9 · Section 1. Employee Information and Attestation') ?></h2>
  <p><span class="tag <?= $tf['status'] === 'accepted' ? 'green' : ($tf['status'] === 'returned' ? 'red' : 'amber') ?>"><?= e($statusWord[$tf['status']] ?? $tf['status']) ?></span>
    <?= $tf['reviewed_at'] ? te(':date by :name', ['date' => d(substr((string) $tf['reviewed_at'], 0, 10)), 'name' => $reviewer]) : '' ?><?= $tf['review_note'] ? ' · ' . e($tf['review_note']) : '' ?></p>
  <?php if ($tf['form'] === 'w4'): ?>
  <table>
    <tr><td class="muted">1(a) <?= te('Name') ?></td><td><?= e($name) ?></td></tr>
    <tr><td class="muted">1(a) <?= te('Address') ?></td><td><?= e($address) ?></td></tr>
    <tr><td class="muted">1(b) <?= te('Social Security number') ?></td><td class="mono" data-ssn><?= ! empty($d['ssn_applied']) && empty($d['ssn']) ? te('Applied for') : $x('ssn') ?></td></tr>
    <tr><td class="muted">1(c) <?= te('Filing status') ?></td><td><?= e(tf_filing_statuses()[$d['filing_status'] ?? ''] ?? '—') ?></td></tr>
    <?php if (! empty($d['exempt'])): ?>
    <tr><td class="muted"><?= te('Exempt') ?></td><td><strong>Exempt</strong> · <?= te('claimed for :year', ['year' => $d['tax_year'] ?? '']) ?></td></tr>
    <?php else: ?>
    <tr><td class="muted">2(c) <?= te('Two jobs in total') ?></td><td><?= $yes('two_jobs') ?></td></tr>
    <tr><td class="muted">3 <?= te('Dependents and other credits') ?></td><td><?= te(':children qualifying children, :others other dependents, other credits :credits', ['children' => (int) ($d['children'] ?? 0), 'others' => (int) ($d['other_dependents'] ?? 0), 'credits' => $money('other_credits')]) ?> · <strong><?= $money('step3_total') ?></strong></td></tr>
    <tr><td class="muted">4(a) <?= te('Other income') ?></td><td><?= $money('other_income') ?></td></tr>
    <tr><td class="muted">4(b) <?= te('Deductions') ?></td><td><?= $money('deductions') ?></td></tr>
    <tr><td class="muted">4(c) <?= te('Extra withholding') ?></td><td><?= $money('extra_withholding') ?></td></tr>
    <?php endif; ?>
    <tr><td class="muted"><?= te('Employer') ?></td><td><?= e(agency_name()) ?> · <?= te('First date of employment') ?>: <?= $firstDay ? e(d($firstDay)) : '—' ?></td></tr>
  </table>
  <?php else: ?>
  <table>
    <tr><td class="muted"><?= te('Last name, first name, middle initial') ?></td><td><?= e(($d['last_name'] ?? '') . ', ' . ($d['first_name'] ?? '') . ' ' . ($d['middle_initial'] ?? '')) ?></td></tr>
    <tr><td class="muted"><?= te('Other last names used') ?></td><td><?= $x('other_last_names') ?></td></tr>
    <tr><td class="muted"><?= te('Address') ?></td><td><?= e($address) ?></td></tr>
    <tr><td class="muted"><?= te('Date of birth') ?></td><td><?= ! empty($d['birth_date']) ? e(d((string) $d['birth_date'])) : '—' ?></td></tr>
    <tr><td class="muted"><?= te('Social Security number') ?></td><td class="mono" data-ssn><?= $x('ssn') ?></td></tr>
    <tr><td class="muted"><?= te('Email · phone') ?></td><td><?= $x('email') ?> · <?= $x('phone') ?></td></tr>
    <tr><td class="muted"><?= te('Status attested') ?></td><td><?= e(tf_i9_statuses()[$d['status'] ?? ''] ?? '—') ?></td></tr>
    <?php if (! empty($d['doc_type'])): ?>
    <tr><td class="muted"><?= e(tf_i9_doc_types()[$d['doc_type']] ?? '') ?></td><td class="mono"><?= $x('doc_number') ?><?= ! empty($d['passport_country']) ? ' · ' . e($d['passport_country']) : '' ?></td></tr>
    <?php endif; ?>
    <?php if (($d['status'] ?? '') === 'authorized'): ?><tr><td class="muted"><?= te('Authorized to work until') ?></td><td><?= ! empty($d['work_until']) ? e(d((string) $d['work_until'])) : 'N/A' ?></td></tr><?php endif; ?>
    <tr><td class="muted"><?= te('Preparer or translator') ?></td><td><?= ! empty($d['preparer']) ? e($d['preparer']['first_name'] . ' ' . $d['preparer']['last_name'] . ', ' . $d['preparer']['address']) . ' · ' . te('attested') : te('None used') ?></td></tr>
  </table>
  <?php endif; ?>
</div>

<div class="card"><h2><?= te('Signature') ?></h2>
  <p class="small" lang="en"><?= e((string) ($d['statement'] ?? '')) ?></p>
  <p><?= te('Signed electronically by :name on :date, from their own account in the portal.', ['name' => $tf['signer_name'], 'date' => date('m/d/Y g:i a', strtotime((string) $tf['signed_at']))]) ?></p>
  <?php if (! $own): ?><p class="small muted mono"><?= te('Address :ip · record :hash', ['ip' => (string) $tf['signer_ip'], 'hash' => substr((string) $tf['payload_hash'], 0, 16)]) ?> · <?= $tf['intact'] ? te('matches what was signed') : te('does not match') ?></p><?php endif; ?>
</div>
</div>

<?php if ($canDecide): ?>
<form class="card no-print" method="post" action="/tax-forms" id="decide"><?= csrf_field() ?><input type="hidden" name="do" value="decide"><input type="hidden" name="form_id" value="<?= (int) $tf['id'] ?>">
  <h2><?= te('Decide') ?></h2>
  <label class="tick"><input class="w-auto" type="checkbox" name="processed" value="1"> <?= $tf['form'] === 'w4' ? te('I entered this W-4 in ADP') : te('Section 1 is complete and legible; Section 2 is done with the person and their original documents, within three business days of the first day') ?></label>
  <label for="tf-note"><?= te('Note (needed to send it back: say what to correct)') ?></label><input id="tf-note" name="note" maxlength="500">
  <div class="row mt-s"><button class="btn" name="decision" value="accept"><?= te('Accept') ?></button><button class="btn ghost" name="decision" value="return"><?= te('Send back to the worker') ?></button></div>
</form>
<?php endif; ?>
