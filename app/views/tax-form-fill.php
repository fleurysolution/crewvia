<?php
// P5-M64/M65: the worker fills in a W-4 or Section 1 of an I-9, saved as a draft, signed at the office. Secrets are masked and typed twice.
$v = static fn(string $k): string => e((string) ($old[$k] ?? ''));
$on = static fn(string $k): string => ! empty($old[$k]) ? 'checked' : '';
$statusWord = ['submitted' => t('Waiting to be checked'), 'accepted' => t('Accepted'), 'returned' => t('Sent back to you'), 'replaced' => t('Replaced by a newer one')];
$secret = static function (string $name, string $label, string $hint, bool $required, string $pattern) use ($kept) {
    $id = 'tf-' . $name;
    // P5-M65: a number already given stays unless it is typed again.
    if (($kept[$name] ?? '') !== '') {
        $required = false;
        $hint = t('Already given, ending :last: leave both empty to keep it.', ['last' => $kept[$name]]) . ($hint !== '' ? ' ' . $hint : '');
    } ?>
  <div class="grid g2" data-secret="<?= e($name) ?>">
    <div><label for="<?= $id ?>"><?= e($label) ?></label>
      <div class="row no-wrap"><input id="<?= $id ?>" name="<?= e($name) ?>" type="text" class="secret" data-lpignore="true" data-1p-ignore inputmode="<?= $pattern === 'digits' ? 'numeric' : 'text' ?>" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="24" <?= $required ? 'required' : '' ?>>
      <button type="button" class="btn ghost sm" data-reveal="<?= $id ?>" data-show="<?= te('Show') ?>" data-hide="<?= te('Hide') ?>" aria-pressed="false"><?= te('Show') ?></button></div>
      <?php if ($hint !== ''): ?><p class="small muted m0"><?= e($hint) ?></p><?php endif; ?></div>
    <div><label for="<?= $id ?>2"><?= te('The same number, again') ?></label>
      <input id="<?= $id ?>2" name="<?= e($name) ?>_confirm" type="text" class="secret" data-lpignore="true" data-1p-ignore inputmode="<?= $pattern === 'digits' ? 'numeric' : 'text' ?>" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="24" <?= $required ? 'required' : '' ?> data-secret-pair="<?= $id ?>">
      <p id="<?= $id ?>2-match" class="small" aria-live="polite" data-ok="<?= te('The two numbers match.') ?>" data-no="<?= te('The two numbers differ.') ?>"></p></div>
  </div>
<?php };
$signature = static function (string $heading, string $statement, string $sigLabel, string $button) { ?>
  <div class="card soft official" id="tf-sign">
    <h2><?= e($heading) ?></h2>
    <p class="small" lang="en"><strong><?= e($statement) ?></strong></p>
    <p><?= te('You sign at the office, in front of the coordinator: they check your form with you, give you a code, and you sign on your own phone. Nobody can sign in your place.') ?></p>
    <button class="btn"><?= te('Save: I sign it at the office') ?></button>
  </div>
<?php }; ?>
<div class="page-head"><div><h1><?= e(tf_forms()[$form]['name']) ?></h1>
  <p class="sub"><?= $form === 'w4' ? te('Your employer withholds federal income tax from your pay from what you say here. Payroll enters it in ADP for you.') : te('Section 1 is yours to fill in, no later than your first day. On your first day, the office examines your original documents and completes Section 2 with you.') ?></p></div>
  <div class="actions"><a class="btn ghost" href="/portal#onboarding"><?= te('Back to my portal') ?></a></div></div>

<?php if ($history): ?><div class="card"><h2><?= te('What you sent') ?></h2>
  <?php foreach ($history as $h): ?><div class="small" data-tax-form="<?= (int) $h['id'] ?>"><?= te('Signed :date', ['date' => d(substr((string) $h['signed_at'], 0, 10))]) ?> · <span class="tag <?= $h['status'] === 'accepted' ? 'green' : ($h['status'] === 'returned' ? 'red' : ($h['status'] === 'submitted' ? 'amber' : '')) ?>"><?= e($statusWord[$h['status']] ?? $h['status']) ?></span><?= $h['review_note'] ? ' · ' . e($h['review_note']) : '' ?> · <a href="/tax-forms?id=<?= (int) $h['id'] ?>"><?= te('See it') ?></a></div><?php endforeach; ?>
</div><?php endif; ?>

<?php if ($waiting): ?>
<div class="card"><p><?= $form === 'w4' ? te('Your W-4 is waiting for payroll. If something in it is wrong, ask payroll to send it back to you, then correct it here.') : te('Your I-9 is waiting for the office. If something in it is wrong, ask the office to send it back to you, then correct it here.') ?></p></div>
<?php else: ?>
<?php if ($draft): ?><div class="card"><p data-draft="<?= (int) $draft['id'] ?>"><span class="tag amber"><?= te('Filled in, to sign at the office') ?></span> <?= te('You can still change it here until you sign it.') ?></p></div><?php endif; ?>
<form method="post" action="/tax-forms" class="tax-form" autocomplete="off" enctype="multipart/form-data" data-conditional><?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="form" value="<?= e($form) ?>">
<p class="small muted"><?= te('Your Social Security number and document numbers turn into dots as you type them, and are typed twice so a wrong digit is caught. Everything is encrypted as soon as you sign; only payroll and the office see the full numbers, and each look is recorded.') ?> <a href="<?= $form === 'w4' ? 'https://www.irs.gov/forms-pubs/about-form-w-4' : 'https://www.uscis.gov/i-9' ?>" target="_blank" rel="noopener"><?= $form === 'w4' ? te('The official W-4 and its instructions') : te('The official I-9 and its instructions') ?></a></p>

<?php
// P5-M64c: each form as published, word for word (Form W-4 (2026); Form I-9, Edition 01/20/25). The official
// wording is kept in English, as on the form; a short help in the reader's language sits under it.
$states = static function (string $name, string $id, bool $required) use ($v) { ?>
  <select id="<?= $id ?>" name="<?= $name ?>" <?= $required ? 'required' : '' ?>><option value=""></option><?php foreach (TF_STATES as $st): ?><option <?= $v($name) === $st ? 'selected' : '' ?>><?= $st ?></option><?php endforeach; ?></select>
<?php };
if ($form === 'w4'): ?>
<div class="card official">
  <p class="official-head"><strong>Form W-4 · Employee's Withholding Certificate · <?= TF_W4_YEAR ?></strong><br>Department of the Treasury, Internal Revenue Service · OMB No. 1545-0074</p>
  <p class="small"><strong>Complete Form W-4 so that your employer can withhold the correct federal income tax from your pay. Give Form W-4 to your employer. Your withholding is subject to review by the IRS.</strong></p>
</div>
<div class="card official"><h2>Step 1: Enter Personal Information</h2>
  <div class="grid g3">
    <div><label for="tf-first">(a) First name</label><input id="tf-first" name="first_name" required maxlength="190" value="<?= $v('first_name') ?>" autocomplete="given-name"></div>
    <div><label for="tf-mi">and middle initial</label><input id="tf-mi" name="middle_initial" maxlength="1" value="<?= $v('middle_initial') ?>"></div>
    <div><label for="tf-last">Last name</label><input id="tf-last" name="last_name" required maxlength="190" value="<?= $v('last_name') ?>" autocomplete="family-name"></div>
  </div>
  <div class="grid g2">
    <div><label for="tf-address">Address</label><input id="tf-address" name="address" required maxlength="190" value="<?= $v('address') ?>" autocomplete="street-address"></div>
    <div><label for="tf-apt">Apt. (if any)</label><input id="tf-apt" name="apartment" maxlength="30" value="<?= $v('apartment') ?>"></div>
  </div>
  <div class="grid g3">
    <div><label for="tf-city">City or town,</label><input id="tf-city" name="city" required maxlength="190" value="<?= $v('city') ?>" autocomplete="address-level2"></div>
    <div><label for="tf-state">state,</label><?php $states('state', 'tf-state', true); ?></div>
    <div><label for="tf-zip">and ZIP code</label><input id="tf-zip" name="zip" required maxlength="10" pattern="[0-9]{5}(-?[0-9]{4})?" inputmode="numeric" value="<?= $v('zip') ?>" autocomplete="postal-code"></div>
  </div>
  <?php $secret('ssn', '(b) Social security number', 'Does your name match the name on your social security card? If not, to ensure you get credit for your earnings, contact SSA at 800-772-1213 or go to www.ssa.gov.', true, 'digits'); ?>
  <label class="tick"><input class="w-auto" type="checkbox" name="ssn_applied" value="1" <?= $on('ssn_applied') ?>> <?= te('I have applied for a Social Security number and do not have it yet') ?></label>
  <fieldset><legend>(c)</legend>
    <?php foreach (tf_filing_statuses() as $k => $label): ?><label class="tick"><input class="w-auto" type="radio" name="filing_status" value="<?= e($k) ?>" required <?= ($old['filing_status'] ?? '') === $k ? 'checked' : '' ?>> <span lang="en"><?= e(['single' => 'Single or Married filing separately', 'joint' => 'Married filing jointly or Qualifying surviving spouse', 'head' => 'Head of household (Check only if you\'re unmarried and pay more than half the costs of keeping up a home for yourself and a qualifying individual.)'][$k]) ?></span></label><?php endforeach; ?>
  </fieldset>
  <p class="small"><strong>Caution:</strong> To claim certain credits or deductions on your tax return, you (and/or your spouse if married filing jointly) are required to have a social security number valid for employment. See page 2 for more information.</p>
</div>
<div class="card official">
  <p class="small"><strong>TIP:</strong> Consider using the estimator at <a href="https://www.irs.gov/W4App" target="_blank" rel="noopener">www.irs.gov/W4App</a> to determine the most accurate withholding for the rest of the year if you: are completing this form after the beginning of the year; expect to work only part of the year; or have changes during the year in your marital status, number of jobs for you (and/or your spouse if married filing jointly), dependents, other income (not from jobs), deductions, or credits. Have your most recent pay stub(s) from this year available when using the estimator. At the beginning of next year, use the estimator again to recheck your withholding.</p>
  <p class="small"><strong>Complete Steps 2–4 ONLY if they apply to you; otherwise, skip to Step 5.</strong> See page 2 for more information on each step, who can claim exemption from withholding, and when to use the estimator at www.irs.gov/W4App.</p>
</div>
<div data-unless="exempt:1">
<div class="card official"><h2>Step 2: Multiple Jobs or Spouse Works</h2>
  <p class="small">Complete this step if you (1) hold more than one job at a time, or (2) are married filing jointly and your spouse also works. The correct amount of withholding depends on income earned from all of these jobs.</p>
  <p class="small">Do <strong>only one</strong> of the following.</p>
  <p class="small">(a) Use the estimator at www.irs.gov/W4App for the most accurate withholding for this step (and Steps 3–4). If you or your spouse have self-employment income, use this option; <strong>or</strong></p>
  <p class="small">(b) Use the Multiple Jobs Worksheet on page 3 and enter the result in Step 4(c) below; <strong>or</strong> (<a href="<?= e(TF_PDF['w4'][0]) ?>" target="_blank" rel="noopener"><?= te('the worksheets, pages 3 to 5 of the official form') ?></a>)</p>
  <label class="tick"><input class="w-auto" type="checkbox" name="two_jobs" value="1" <?= $on('two_jobs') ?>> <span lang="en">(c) If there are only two jobs total, you may check this box. Do the same on Form W-4 for the other job. This option is generally more accurate than Step 2(b) if pay at the lower paying job is more than half of the pay at the higher paying job. Otherwise, Step 2(b) is more accurate</span></label>
</div>
<div class="card official">
  <p class="small"><strong>Complete Steps 3–4(b) on Form W-4 for only ONE of these jobs.</strong> Leave those steps blank for the other jobs. (Your withholding will be most accurate if you complete Steps 3–4(b) on the Form W-4 for the highest paying job.)</p>
  <h2>Step 3: Claim Dependent and Other Credits</h2>
  <p class="small">If your total income will be $200,000 or less ($400,000 or less if married filing jointly):</p>
  <div class="grid g3">
    <div><label for="tf-children">(a) Multiply the number of qualifying children under age 17 by $<?= number_format(TF_W4_CHILD) ?></label><input id="tf-children" name="children" type="number" min="0" max="20" value="<?= $v('children') ?: '0' ?>"><p class="small muted m0">3(a) <strong data-times="children:<?= TF_W4_CHILD ?>">$0</strong></p></div>
    <div><label for="tf-otherdep">(b) Multiply the number of other dependents by $<?= number_format(TF_W4_OTHER) ?></label><input id="tf-otherdep" name="other_dependents" type="number" min="0" max="20" value="<?= $v('other_dependents') ?: '0' ?>"><p class="small muted m0">3(b) <strong data-times="other_dependents:<?= TF_W4_OTHER ?>">$0</strong></p></div>
    <div><label for="tf-credits">Plus the amount for other credits</label><p class="small muted m0"><?= te('Other tax credits you expect for the year, for instance education credits or the foreign tax credit: less tax is then withheld. Most people leave it empty.') ?></p><input id="tf-credits" name="other_credits" inputmode="decimal" maxlength="12" value="<?= $v('other_credits') ?>"></div>
  </div>
  <p>Add the amounts from Steps 3(a) and 3(b), plus the amount for other credits. Enter the total here: <strong>3 <span data-step3-total data-child="<?= TF_W4_CHILD ?>" data-other="<?= TF_W4_OTHER ?>">$0</span></strong></p>
</div>
<div class="card official"><h2>Step 4 (optional): Other Adjustments</h2>
  <label for="tf-income"><strong>(a) Other income (not from jobs).</strong> If you want tax withheld for other income you expect this year that won't have withholding, enter the amount of other income here. This may include interest, dividends, and retirement income · 4(a) $</label><input id="tf-income" name="other_income" inputmode="decimal" maxlength="12" value="<?= $v('other_income') ?>">
  <label for="tf-deductions"><strong>(b) Deductions.</strong> Use the Deductions Worksheet on page 4 to determine the amount of deductions you may claim, which will reduce your withholding. (If you skip this line, your withholding will be based on the standard deduction.) Enter the result here · 4(b) $</label><input id="tf-deductions" name="deductions" inputmode="decimal" maxlength="12" value="<?= $v('deductions') ?>">
  <label for="tf-extra"><strong>(c) Extra withholding.</strong> Enter any additional tax you want withheld each <strong>pay period</strong> · 4(c) $</label><input id="tf-extra" name="extra_withholding" inputmode="decimal" maxlength="12" value="<?= $v('extra_withholding') ?>">
</div>
</div>
<div class="card official"><h2>Exempt from withholding</h2>
  <label class="tick"><input class="w-auto" type="checkbox" name="exempt" value="1" <?= $on('exempt') ?>> <span lang="en">I claim exemption from withholding for <?= TF_W4_YEAR ?>, and I certify that I meet <strong>both</strong> of the conditions for exemption for <?= TF_W4_YEAR ?>. See <em>Exemption from withholding</em> on page 2. I understand I will need to submit a new Form W-4 for <?= TF_W4_YEAR + 1 ?>.</span></label>
  <p class="small muted m0"><?= te('Only if both are true. Steps 2 to 4 are then left empty.') ?></p>
</div>
<?php $signature('Step 5: Sign Here', TF_W4_ATTEST, 'Employee\'s signature (This form is not valid unless you sign it.)', t('Sign and send my W-4')); ?>
<div class="card official"><h2>Employers Only</h2><p class="small muted m0"><?= te('Employer\'s name and address, first date of employment and EIN: payroll completes them.') ?></p></div>

<?php else: ?>
<div class="card official">
  <p class="official-head"><strong>Employment Eligibility Verification · USCIS Form I-9</strong><br>Department of Homeland Security, U.S. Citizenship and Immigration Services · OMB No. 1615-0047 · Expires 05/31/2027 · Edition 01/20/25</p>
  <p class="small"><strong>START HERE:</strong> Employers must ensure the form instructions are available to employees when completing this form. Employers are liable for failing to comply with the requirements for completing this form. See below and the <a href="https://www.uscis.gov/i-9" target="_blank" rel="noopener">Instructions</a>.</p>
  <p class="small"><strong>ANTI-DISCRIMINATION NOTICE:</strong> All employees can choose which acceptable documentation to present for Form I-9. Employers cannot ask employees for documentation to verify information in <strong>Section 1</strong>, or specify which acceptable documentation employees must present for <strong>Section 2</strong> or Supplement B, Reverification and Rehire. Treating employees differently based on their citizenship, immigration status, or national origin may be illegal.</p>
</div>
<div class="card official"><h2>Section 1. Employee Information and Attestation</h2>
  <p class="small">Employees must complete and sign Section 1 of Form I-9 no later than the <strong>first day of employment</strong>, but not before accepting a job offer.</p>
  <div class="grid g2">
    <div><label for="tf-last">Last Name (Family Name)</label><input id="tf-last" name="last_name" required maxlength="190" value="<?= $v('last_name') ?>" autocomplete="family-name"></div>
    <div><label for="tf-first">First Name (Given Name)</label><input id="tf-first" name="first_name" required maxlength="190" value="<?= $v('first_name') ?>" autocomplete="given-name"></div>
    <div><label for="tf-mi">Middle Initial (if any)</label><input id="tf-mi" name="middle_initial" maxlength="1" value="<?= $v('middle_initial') ?>"></div>
    <div><label for="tf-other-names">Other Last Names Used (if any)</label><input id="tf-other-names" name="other_last_names" maxlength="190" value="<?= $v('other_last_names') ?>"></div>
  </div>
  <div class="grid g3">
    <div><label for="tf-address">Address (Street Number and Name)</label><input id="tf-address" name="address" required maxlength="190" value="<?= $v('address') ?>" autocomplete="street-address"></div>
    <div><label for="tf-apt">Apt. Number (if any)</label><input id="tf-apt" name="apartment" maxlength="30" value="<?= $v('apartment') ?>"></div>
    <div><label for="tf-city">City or Town</label><input id="tf-city" name="city" required maxlength="190" value="<?= $v('city') ?>" autocomplete="address-level2"></div>
    <div><label for="tf-state">State</label><?php $states('state', 'tf-state', true); ?></div>
    <div><label for="tf-zip">ZIP Code</label><input id="tf-zip" name="zip" required maxlength="10" pattern="[0-9]{5}(-?[0-9]{4})?" inputmode="numeric" value="<?= $v('zip') ?>" autocomplete="postal-code"></div>
  </div>
  <div class="grid g3">
    <div><label for="tf-dob">Date of Birth (mm/dd/yyyy)</label><input id="tf-dob" name="birth_date" type="date" required value="<?= $v('birth_date') ?>" autocomplete="bday"></div>
    <div><label for="tf-email">Employee's Email Address</label><input id="tf-email" name="email" type="email" maxlength="190" value="<?= $v('email') ?>"></div>
    <div><label for="tf-phone">Employee's Telephone Number</label><input id="tf-phone" name="phone" maxlength="30" value="<?= $v('phone') ?>"></div>
  </div>
  <?php $secret('ssn', 'U.S. Social Security Number', $everify ? t('Required: the employer uses E-Verify.') : t('Required if the employer uses E-Verify; otherwise you may leave both empty.'), $everify, 'digits'); ?>
</div>
<div class="card official">
  <p class="small"><strong>I am aware that federal law provides for imprisonment and/or fines for false statements, or the use of false documents, in connection with the completion of this form. I attest, under penalty of perjury, that this information, including my selection of the box attesting to my citizenship or immigration status, is true and correct.</strong></p>
  <p class="small">Check one of the following boxes to attest to your citizenship or immigration status (See page 2 and 3 of the instructions.):</p>
  <?php foreach (tf_i9_statuses() as $k => $label): ?><label class="tick"><input class="w-auto" type="radio" name="status" value="<?= e($k) ?>" required <?= ($old['status'] ?? '') === $k ? 'checked' : '' ?>> <span lang="en"><?= e(['citizen' => '1. A citizen of the United States', 'national' => '2. A noncitizen national of the United States (See Instructions.)', 'permanent' => '3. A lawful permanent resident (Enter USCIS or A-Number.)', 'authorized' => '4. An alien authorized to work until (exp. date, if any)'][$k]) ?></span></label><?php endforeach; ?>
  <div data-when="status:permanent,authorized">
    <div data-when="status:authorized">
      <div class="grid g2">
        <div><label for="tf-until">(exp. date, if any)</label><input id="tf-until" name="work_until" type="date" value="<?= $v('work_until') ?>"></div>
        <div><label class="tick mt-l"><input class="w-auto" type="checkbox" name="work_until_na" value="1" <?= $on('work_until_na') ?>> N/A — <?= te('My authorization has no end date') ?></label></div>
      </div>
      <p class="small">If you check <strong>Item Number 4.</strong>, enter one of these:</p>
      <select id="tf-doctype" name="doc_type" aria-label="If you check Item Number 4., enter one of these"><?php foreach (['a_number' => 'USCIS A-Number', 'i94' => 'Form I-94 Admission Number', 'passport' => 'Foreign Passport Number and Country of Issuance'] as $k => $label): ?><option value="<?= e($k) ?>" <?= ($old['doc_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
      <div data-when="doc_type:passport"><label for="tf-country">Country of Issuance</label><input id="tf-country" name="passport_country" maxlength="60" value="<?= $v('passport_country') ?>"></div>
    </div>
    <?php $secret('doc_number', 'USCIS or A-Number / Form I-94 Admission Number / Foreign Passport Number', t('A lawful permanent resident gives the USCIS or A-Number (7 to 9 digits).'), true, 'text'); ?>
  </div>
</div>
<div class="card official" id="my-documents"><h2><?= te('Your documents for Section 2 (optional)') ?></h2>
  <p class="small"><?= te('On your first day you show the office your original documents: one from List A, OR one from List B AND one from List C. You choose which, from the lists of acceptable documents. To save time, you may send a photo of them now; the office still examines the originals, and keeps a copy of everyone\'s documents.') ?> <a href="https://www.uscis.gov/i-9-central/form-i-9-acceptable-documents" target="_blank" rel="noopener"><?= te('The lists of acceptable documents') ?></a></p>
  <?php foreach ($copies as $k): ?><p class="small" data-copy="<?= (int) $k['id'] ?>">List <?= e($k['list']) ?> · <?= e($k['title']) ?> · <?= te('sent :date', ['date' => d(substr((string) $k['added_at'], 0, 10))]) ?></p><?php endforeach; ?>
  <?php $docs = tf_i9_documents(); foreach (['A', 'B', 'C'] as $l): ?><datalist id="mydocs-<?= $l ?>"><?php foreach ($docs[$l] as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist><?php endforeach; ?>
  <?php for ($i = 0; $i < 3; $i++): ?>
  <div class="grid g3">
    <div><label for="cp-l<?= $i ?>"><?= te('List') ?></label><select id="cp-l<?= $i ?>" name="copy_list[]" onchange="this.closest('.grid').querySelector('input[list]').setAttribute('list','mydocs-'+this.value)"><option value="A">List A</option><option value="B">List B</option><option value="C">List C</option></select></div>
    <div><label for="cp-t<?= $i ?>"><?= te('Document') ?></label><input id="cp-t<?= $i ?>" name="copy_title[]" list="mydocs-A" maxlength="150"></div>
    <div><label for="cp-f<?= $i ?>"><?= te('Photo or PDF') ?></label><input id="cp-f<?= $i ?>" name="copy_file[]" type="file" accept=".pdf,.jpg,.jpeg,.png,image/*" capture="environment"></div>
  </div>
  <?php endfor; ?>
</div>
<?php $signature('Signature of Employee', TF_I9_ATTEST, 'Signature of Employee · Today\'s Date (mm/dd/yyyy): ' . date('m/d/Y'), t('Sign and send Section 1 of my I-9')); ?>
<div class="card official">
  <p class="small"><strong>If a preparer and/or translator assisted you in completing Section 1, that person MUST complete the Preparer and/or Translator Certification on Page 3.</strong></p>
  <label class="tick"><input class="w-auto" type="checkbox" name="used_preparer" value="1" <?= $on('used_preparer') ?>> <?= te('Someone helped me fill in this section (a preparer or translator)') ?></label>
  <div data-when="used_preparer:1">
    <h3>Supplement A, Preparer and/or Translator Certification for Section 1</h3>
    <div class="grid g3">
      <div><label for="tf-plast">Preparer or Translator Last Name (Family Name)</label><input id="tf-plast" name="preparer_last" maxlength="190" required value="<?= $v('preparer_last') ?>"></div>
      <div><label for="tf-pfirst">Preparer or Translator First Name (Given Name)</label><input id="tf-pfirst" name="preparer_first" maxlength="190" required value="<?= $v('preparer_first') ?>"></div>
      <div><label for="tf-pmi">Middle Initial (if any)</label><input id="tf-pmi" name="preparer_mi" maxlength="1" value="<?= $v('preparer_mi') ?>"></div>
      <div><label for="tf-paddr">Address (Street Number and Name)</label><input id="tf-paddr" name="preparer_address" maxlength="190" required value="<?= $v('preparer_address') ?>"></div>
      <div><label for="tf-pcity">City or Town</label><input id="tf-pcity" name="preparer_city" maxlength="190" required value="<?= $v('preparer_city') ?>"></div>
      <div><label for="tf-pstate">State</label><?php $states('preparer_state', 'tf-pstate', true); ?></div>
      <div><label for="tf-pzip">ZIP Code</label><input id="tf-pzip" name="preparer_zip" maxlength="10" required pattern="[0-9]{5}(-?[0-9]{4})?" value="<?= $v('preparer_zip') ?>"></div>
    </div>
    <p class="small" lang="en"><strong><?= e(TF_I9_PREPARER_ATTEST) ?></strong></p>
    <label class="tick"><input class="w-auto" type="checkbox" name="preparer_attest" value="1" required> <?= te('The person who helped me will sign Supplement A in ink: the form says it cannot be signed electronically. The office prints it for them.') ?></label>
  </div>
</div>
<?php endif; ?>
</form>
<script src="/assets/masked-fields.js"></script>
<?php endif; ?>
