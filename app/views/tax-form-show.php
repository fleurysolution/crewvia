<?php
// P5-M64: a signed form, as signed. In full for the people who process it (each opening recorded), masked for the worker.
$d = $tf['data'];
$x = static fn($k) => e((string) ($d[$k] ?? '')) ?: '—';
$money = static fn($k) => '$' . number_format((float) ($d[$k] ?? 0), 2);
$yes = static fn($k) => ! empty($d[$k]) ? te('Yes') : te('No');
$statusWord = ['draft' => t('Filled in, to sign at the office'), 'submitted' => t('Waiting to be checked'), 'accepted' => t('Accepted'), 'returned' => t('Sent back'), 'replaced' => t('Replaced by a newer one')];
$name = trim(($d['first_name'] ?? '') . ' ' . ($d['middle_initial'] ?? '') . ' ' . ($d['last_name'] ?? ''));
$address = trim(($d['address'] ?? '') . (! empty($d['apartment']) ? ', ' . $d['apartment'] : '') . ', ' . ($d['city'] ?? '') . ', ' . ($d['state'] ?? '') . ' ' . ($d['zip'] ?? ''));
?>
<div class="page-head no-print"><div><h1><?= e(tf_forms()[$tf['form']]['name']) ?> · <?= e($tf['full_name']) ?></h1>
  <p class="sub"><?= $own ? te('What you signed. The full numbers are shown only to payroll and the office.') : te('Opened in full: this look is recorded. Print it or save it as PDF from the browser for the employment file.') ?></p></div>
  <div class="actions"><?php if ($pdf): ?><button type="button" class="btn" data-official-pdf="tf-pdf-map"><?= $tf['form'] === 'w4' ? te('The official W-4, filled (PDF)') : te('The official I-9, filled (PDF)') ?></button><?php endif; ?><button type="button" class="btn ghost" onclick="window.print()"><?= te('Print') ?></button><a class="btn ghost" href="<?= $own ? '/portal#onboarding' : '/change-requests#tax-forms' ?>"><?= te('Back') ?></a></div></div>

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
  <?php if ($tf['status'] === 'draft'): ?><p class="t-red"><?= te('Not signed yet: it is signed in person, in front of the coordinator.') ?></p>
  <?php elseif ($witness !== ''): ?><p data-signed-in-person="1"><?= te('Signed in person by :name on :date, from their own phone, in front of :witness.', ['name' => $tf['signer_name'], 'date' => date('m/d/Y g:i a', strtotime((string) $tf['signed_at'])), 'witness' => $witness]) ?></p>
  <?php if (! empty($tf['signature'])): ?><img src="<?= e($tf['signature']) ?>" alt="<?= te('Signature') ?>" class="sign-image"><?php endif; ?>
  <?php else: ?><p><?= te('Signed electronically by :name on :date, from their own account in the portal.', ['name' => $tf['signer_name'], 'date' => date('m/d/Y g:i a', strtotime((string) $tf['signed_at']))]) ?></p><?php endif; ?>
  <?php if (! $own): ?><p class="small muted mono"><?= te('Address :ip · record :hash', ['ip' => (string) $tf['signer_ip'], 'hash' => substr((string) $tf['payload_hash'], 0, 16)]) ?> · <?= $tf['intact'] ? te('matches what was signed') : te('does not match') ?></p><?php endif; ?>
</div>
<?php if (! empty($tf['employer'])): $em = $tf['employer']; ?>
<div class="card" data-employer-part="1"><h2><?= $tf['form'] === 'w4' ? e('Employers Only') : e('Section 2. Employer Review and Verification') ?></h2>
  <?php if ($tf['form'] === 'w4'): ?>
  <p><?= e($em['name_address']) ?> · <?= te('First date of employment') ?>: <?= e(d($em['first_day'])) ?> · EIN <?= e($em['ein']) ?></p>
  <?php else: ?>
  <table><?php foreach ($em['list_a'] ?: [$em['list_b'], $em['list_c']] as $i => $doc): ?><tr><td class="muted"><?= $em['list_a'] ? 'List A' : ($i === 0 ? 'List B' : 'List C') ?></td><td><?= e($doc['title'] . ' · ' . $doc['authority'] . ' · ' . ($doc['number'] !== '' ? ($own ? '•••••' . substr($doc['number'], -4) : $doc['number']) : 'N/A') . ' · ' . ($doc['expires'] !== '' ? d($doc['expires']) : 'N/A')) ?></td></tr><?php endforeach; ?>
    <tr><td class="muted"><?= te('First day of employment') ?></td><td><?= e(d($em['first_day'])) ?></td></tr>
    <tr><td class="muted"><?= te('Examined by') ?></td><td><?= e($em['rep_name_title']) ?> · /s/ <?= e($em['signature']) ?> · <?= e(d($em['signed_on'])) ?><?= ! empty($em['alternative']) ? ' · ' . te('alternative procedure authorized by DHS') : '' ?></td></tr>
    <tr><td class="muted"><?= te('Employer') ?></td><td><?= e($em['business_name'] . ', ' . $em['business_address']) ?></td></tr>
    <?php if ($em['additional'] !== ''): ?><tr><td class="muted"><?= te('Additional Information') ?></td><td><?= e($em['additional']) ?></td></tr><?php endif; ?></table>
  <?php endif; ?>
</div>
<?php endif; ?>
</div>
<?php if ($pdf): ?><script type="application/json" id="tf-pdf-map"><?= json_encode($pdf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<p class="small muted no-print" id="tf-pdf-status" aria-live="polite" data-wait="<?= te('Filling the official form…') ?>" data-fail="<?= te('The official form could not be filled in this browser. Print this page instead.') ?>"><?= te('The official form, as published (:edition), filled from what was signed.', ['edition' => TF_PDF[$tf['form']][1]]) ?></p>
<script src="/assets/pdf-lib.min.js"></script><script src="/assets/official-form.js"></script><?php endif; ?>

<?php if ($tf['form'] === 'i9' && ! $own && in_array($tf['status'], ['draft', 'submitted'], true)): $docsList = tf_i9_documents(); ?>
<div class="card no-print" id="copies"><h2><?= te('Copies of the documents examined') ?></h2>
  <p class="small muted"><?= te('RSS keeps a copy of the documents of everyone. Compare each original with the photo the person sent, or photograph it here.') ?></p>
  <?php foreach ($copies as $k): ?><p class="small" data-copy="<?= (int) $k['id'] ?>">List <?= e($k['list']) ?> · <?= e($k['title']) ?> · <?= $k['by_office'] ? te('photographed by the office') : te('sent by the person') ?> · <a href="/tax-forms?copy=<?= (int) $k['document_id'] ?>" target="_blank" rel="noopener"><?= te('Open') ?></a></p><?php endforeach; ?>
  <?php if (! $copies): ?><p class="muted"><?= te('No copy yet.') ?></p><?php endif; ?>
  <?php foreach (['A', 'B', 'C'] as $l): ?><datalist id="cdocs-<?= $l ?>"><?php foreach ($docsList[$l] as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist><?php endforeach; ?>
  <form method="post" action="/tax-forms" enctype="multipart/form-data" class="grid g3 mt-s"><?= csrf_field() ?><input type="hidden" name="do" value="add_copy"><input type="hidden" name="candidate_id" value="<?= (int) $tf['candidate_id'] ?>"><input type="hidden" name="back" value="/tax-forms?id=<?= (int) $tf['id'] ?>">
    <div><label for="cc-l"><?= te('List') ?></label><select id="cc-l" name="list" onchange="document.getElementById('cc-t').setAttribute('list','cdocs-'+this.value)"><option>A</option><option>B</option><option>C</option></select></div>
    <div><label for="cc-t"><?= te('Document') ?></label><input id="cc-t" name="title" list="cdocs-A" maxlength="150" required></div>
    <div><label for="cc-f"><?= te('Photograph the original') ?></label><input id="cc-f" name="copy" type="file" accept=".pdf,.jpg,.jpeg,.png,image/*" capture="environment" required></div>
    <button class="btn ghost"><?= te('Keep the copy') ?></button>
  </form>
</div>
<?php endif; ?>
<?php if ($canDecide): $o = $decideOld; $ov = static fn($k, $def = '') => e((string) ($o[$k] ?? $def));
    $findings = [];
    if ($tf['form'] === 'i9' && $o) { [$fwhy, $fe] = tf_check_i9_section2($o); $findings = $fwhy === null ? tf_s2_findings($d, $fe, $copies) : []; } ?>
<form class="card no-print" method="post" action="/tax-forms" id="decide"><?= csrf_field() ?><input type="hidden" name="do" value="decide"><input type="hidden" name="form_id" value="<?= (int) $tf['id'] ?>">
  <h2><?= te('Decide') ?></h2>
  <?php if ($tf['form'] === 'w4'): ?>
  <h3>Employers Only</h3>
  <div class="grid g3">
    <div><label for="em-na"><?= te('Employer\'s name and address') ?></label><input id="em-na" name="employer_name_address" maxlength="250" value="<?= $ov('employer_name_address', $empDefaults['name_address']) ?>"></div>
    <div><label for="em-first"><?= te('First date of employment') ?></label><input id="em-first" name="first_day" type="date" value="<?= $ov('first_day', $firstDay) ?>"></div>
    <div><label for="em-ein"><?= te('Employer identification number (EIN)') ?></label><input id="em-ein" name="ein" maxlength="10" placeholder="12-3456789" value="<?= $ov('ein', $empDefaults['ein']) ?>"></div>
  </div>
  <label class="tick"><input class="w-auto" type="checkbox" name="processed" value="1"> <?= te('I entered this W-4 in ADP') ?></label>
  <?php else: $docs = tf_i9_documents(); ?>
  <h3>Section 2. Employer Review and Verification</h3>
  <p class="small muted"><?= te('With the person and their original documents in front of you, within three business days of their first day: one document from List A, OR one from List B AND one from List C. Accept only an unexpired original.') ?></p>
  <?php foreach (['A' => [1, 2, 3], 'B' => [1], 'C' => [1]] as $list => $rows): ?><datalist id="docs-<?= $list ?>"><?php foreach ($docs[$list] as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist><?php endforeach; ?>
  <?php foreach ([['a', 1, 'List A', 'A'], ['a', 2, 'List A · Document 2 (if any)', 'A'], ['a', 3, 'List A · Document 3 (if any)', 'A'], ['b', 1, 'OR List B', 'B'], ['c', 1, 'AND List C', 'C']] as [$l, $n, $label, $list]): $k = "s2_{$l}{$n}_"; ?>
  <fieldset class="tax-form"><legend><?= e($label) ?></legend><div class="grid g3">
    <div><label for="<?= $k ?>t"><?= e('Document Title') ?></label><input id="<?= $k ?>t" name="<?= $k ?>title" list="docs-<?= $list ?>" maxlength="190" value="<?= $ov($k . 'title') ?>"></div>
    <div><label for="<?= $k ?>a"><?= e('Issuing Authority') ?></label><input id="<?= $k ?>a" name="<?= $k ?>authority" maxlength="190" value="<?= $ov($k . 'authority') ?>"></div>
    <div><label for="<?= $k ?>n"><?= e('Document Number (if any)') ?></label><input id="<?= $k ?>n" name="<?= $k ?>number" maxlength="60" autocomplete="off" value="<?= $ov($k . 'number') ?>"></div>
    <div><label for="<?= $k ?>x"><?= e('Expiration Date (if any)') ?></label><input id="<?= $k ?>x" name="<?= $k ?>expires" type="date" value="<?= $ov($k . 'expires') ?>"></div>
  </div></fieldset>
  <?php endforeach; ?>
  <label for="s2-add"><?= e('Additional Information') ?></label><textarea id="s2-add" name="s2_additional" maxlength="1000"><?= $ov('s2_additional') ?></textarea>
  <label class="tick"><input class="w-auto" type="checkbox" name="s2_alternative" value="1" <?= ! empty($o['s2_alternative']) ? 'checked' : '' ?>> <?= e('Check here if you used an alternative procedure authorized by DHS to examine documents.') ?></label>
  <div class="grid g3">
    <div><label for="s2-first"><?= e('First Day of Employment (mm/dd/yyyy)') ?></label><input id="s2-first" name="first_day" type="date" value="<?= $ov('first_day', $firstDay) ?>"></div>
    <div><label for="s2-rep"><?= e('Last Name, First Name and Title of Employer or Authorized Representative') ?></label><input id="s2-rep" name="s2_rep_name_title" maxlength="190" value="<?= $ov('s2_rep_name_title') ?>"></div>
    <div><label for="s2-sig"><?= te('Your signature: type your first and last name') ?></label><input id="s2-sig" name="s2_signature" maxlength="190" autocomplete="off" value="<?= $ov('s2_signature') ?>"></div>
    <div><label for="s2-bn"><?= e('Employer\'s Business or Organization Name') ?></label><input id="s2-bn" name="s2_business_name" maxlength="190" value="<?= $ov('s2_business_name', $empDefaults['business_name']) ?>"></div>
    <div><label for="s2-ba"><?= e('Employer\'s Business or Organization Address, City or Town, State, ZIP Code') ?></label><input id="s2-ba" name="s2_business_address" maxlength="250" value="<?= $ov('s2_business_address', $empDefaults['business_address']) ?>"></div>
  </div>
  <div class="card soft" id="findings"><h3><?= te('What Crewvia compares') ?></h3>
    <ul class="small"><li><?= te('Section 1 attests: :status', ['status' => tf_i9_statuses()[$d['status'] ?? ''] ?? '—']) ?><?= ! empty($d['doc_number']) ? ' · ' . e(tf_i9_doc_types()[$d['doc_type']] ?? '') . ' ' . e((string) $d['doc_number']) : '' ?><?= ! empty($d['work_until']) ? ' · ' . te('until :date', ['date' => d((string) $d['work_until'])]) : '' ?></li>
    <?php foreach ($findings as [$level, $msg]): ?><li class="<?= $level === 'fail' ? 't-red' : ($level === 'warn' ? 't-amber' : 't-green') ?>" data-finding="<?= e($level) ?>"><?= e($msg) ?></li><?php endforeach; ?>
    <li><?= te('When you accept, Crewvia checks again: no expired document, a copy of each, the box of Section 1 consistent with the documents.') ?></li></ul>
    <label class="tick"><input class="w-auto" type="checkbox" name="s2_names_match" value="1" <?= ! empty($o['s2_names_match']) ? 'checked' : '' ?>> <?= te('The name on each document matches Section 1 (or the difference is explained in Additional Information)') ?></label>
    <label class="tick"><input class="w-auto" type="checkbox" name="s2_reviewed" value="1" <?= ! empty($o['s2_reviewed']) ? 'checked' : '' ?>> <?= te('I reviewed any difference Crewvia shows') ?></label>
  </div>
  <p class="small" lang="en"><strong>Certification: <?= e(TF_I9_S2_ATTEST) ?></strong></p>
  <label class="tick"><input class="w-auto" type="checkbox" name="processed" value="1"> <?= te('I certify it, and I sign Section 2') ?></label>
  <?php if (! empty($d['preparer'])): ?><p class="small t-red"><?= te('A preparer or translator helped: print the official I-9 and have them sign Supplement A (page 3) in ink.') ?></p><?php endif; ?>
  <?php endif; ?>
  <label for="tf-note"><?= te('Note (needed to send it back: say what to correct)') ?></label><input id="tf-note" name="note" maxlength="500" value="<?= $ov('note') ?>">
  <div class="row mt-s"><button class="btn" name="decision" value="accept"><?= te('Accept') ?></button><button class="btn ghost" name="decision" value="return"><?= te('Send back to the worker') ?></button></div>
</form>
<?php endif; ?>
