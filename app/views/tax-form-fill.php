<?php
// P5-M64: the worker fills in and signs a W-4 or Section 1 of an I-9. Secrets are masked and typed twice.
$v = static fn(string $k): string => e((string) ($old[$k] ?? ''));
$on = static fn(string $k): string => ! empty($old[$k]) ? 'checked' : '';
$statusWord = ['submitted' => t('Waiting to be checked'), 'accepted' => t('Accepted'), 'returned' => t('Sent back to you'), 'replaced' => t('Replaced by a newer one')];
$secret = static function (string $name, string $label, string $hint, bool $required, string $pattern) {
    $id = 'tf-' . $name; ?>
  <div class="grid g2" data-secret="<?= e($name) ?>">
    <div><label for="<?= $id ?>"><?= e($label) ?></label>
      <div class="row no-wrap"><input id="<?= $id ?>" name="<?= e($name) ?>" type="password" inputmode="<?= $pattern === 'digits' ? 'numeric' : 'text' ?>" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="24" <?= $required ? 'required' : '' ?>>
      <button type="button" class="btn ghost sm" data-reveal="<?= $id ?>" data-show="<?= te('Show') ?>" data-hide="<?= te('Hide') ?>" aria-pressed="false"><?= te('Show') ?></button></div>
      <?php if ($hint !== ''): ?><p class="small muted m0"><?= e($hint) ?></p><?php endif; ?></div>
    <div><label for="<?= $id ?>2"><?= te('The same number, again') ?></label>
      <input id="<?= $id ?>2" name="<?= e($name) ?>_confirm" type="password" inputmode="<?= $pattern === 'digits' ? 'numeric' : 'text' ?>" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="24" <?= $required ? 'required' : '' ?> data-secret-pair="<?= $id ?>">
      <p id="<?= $id ?>2-match" class="small" aria-live="polite" data-ok="<?= te('The two numbers match.') ?>" data-no="<?= te('The two numbers differ.') ?>"></p></div>
  </div>
<?php };
$identity = static function () use ($v) { ?>
  <div class="grid g3">
    <div><label for="tf-first"><?= te('First name') ?></label><input id="tf-first" name="first_name" required maxlength="190" value="<?= $v('first_name') ?>" autocomplete="given-name"></div>
    <div><label for="tf-mi"><?= te('Middle initial') ?></label><input id="tf-mi" name="middle_initial" maxlength="1" value="<?= $v('middle_initial') ?>"></div>
    <div><label for="tf-last"><?= te('Last name') ?></label><input id="tf-last" name="last_name" required maxlength="190" value="<?= $v('last_name') ?>" autocomplete="family-name"></div>
  </div>
  <div class="grid g2">
    <div><label for="tf-address"><?= te('Street address') ?></label><input id="tf-address" name="address" required maxlength="190" value="<?= $v('address') ?>" autocomplete="street-address"></div>
    <div><label for="tf-apt"><?= te('Apartment (if any)') ?></label><input id="tf-apt" name="apartment" maxlength="30" value="<?= $v('apartment') ?>"></div>
  </div>
  <div class="grid g3">
    <div><label for="tf-city"><?= te('City or town') ?></label><input id="tf-city" name="city" required maxlength="190" value="<?= $v('city') ?>" autocomplete="address-level2"></div>
    <div><label for="tf-state"><?= te('State') ?></label><input id="tf-state" name="state" required maxlength="2" pattern="[A-Za-z]{2}" value="<?= $v('state') ?>" autocomplete="address-level1" placeholder="IN"></div>
    <div><label for="tf-zip"><?= te('ZIP code') ?></label><input id="tf-zip" name="zip" required maxlength="10" pattern="[0-9]{5}(-?[0-9]{4})?" inputmode="numeric" value="<?= $v('zip') ?>" autocomplete="postal-code"></div>
  </div>
<?php };
$signature = static function (string $statement, string $button) { ?>
  <div class="card soft" id="tf-sign">
    <h3><?= te('Sign') ?></h3>
    <p class="small" lang="en"><strong><?= e($statement) ?></strong></p>
    <label class="tick"><input class="w-auto" type="checkbox" name="attest" value="1" required> <?= te('I have read this statement and I sign it.') ?></label>
    <label for="tf-signer"><?= te('Type your first and last name to sign') ?></label>
    <input id="tf-signer" name="signer_name" required maxlength="190" autocomplete="off">
    <p class="small muted"><?= te('Your typed name is your signature. It is recorded with today\'s date and time, from your own account; the form can no longer change once signed.') ?></p>
    <button class="btn"><?= e($button) ?></button>
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
<form method="post" action="/tax-forms" class="tax-form" autocomplete="off" data-conditional><?= csrf_field() ?><input type="hidden" name="do" value="sign"><input type="hidden" name="form" value="<?= e($form) ?>">
<p class="small muted"><?= te('Your Social Security number and document numbers turn into dots as you type them, and are typed twice so a wrong digit is caught. Everything is encrypted as soon as you sign; only payroll and the office see the full numbers, and each look is recorded.') ?> <a href="<?= $form === 'w4' ? 'https://www.irs.gov/forms-pubs/about-form-w-4' : 'https://www.uscis.gov/i-9' ?>" target="_blank" rel="noopener"><?= $form === 'w4' ? te('The official W-4 and its instructions') : te('The official I-9 and its instructions') ?></a></p>

<?php if ($form === 'w4'): ?>
<div class="card"><h2><?= te('Step 1: your information') ?></h2>
  <?php $identity(); ?>
  <label class="tick"><input class="w-auto" type="checkbox" name="ssn_applied" value="1" <?= $on('ssn_applied') ?>> <?= te('I have applied for a Social Security number and do not have it yet') ?></label>
  <div data-unless="ssn_applied:1"><?php $secret('ssn', t('Social Security number'), t('Nine digits, as on your Social Security card. Your name must match your card.'), true, 'digits'); ?></div>
  <fieldset><legend><?= te('Filing status') ?></legend>
    <?php foreach (tf_filing_statuses() as $k => $label): ?><label class="tick"><input class="w-auto" type="radio" name="filing_status" value="<?= e($k) ?>" required <?= ($old['filing_status'] ?? '') === $k ? 'checked' : '' ?>> <?= e($label) ?></label><?php endforeach; ?>
    <p class="small muted"><?= te('Head of household: only if you are unmarried and pay more than half the costs of keeping up a home for yourself and a qualifying person.') ?></p>
  </fieldset>
</div>
<div class="card"><label class="tick"><input class="w-auto" type="checkbox" name="exempt" value="1" <?= $on('exempt') ?>> <?= te('I claim exemption from withholding for :year: I had no federal income tax liability last year and expect none this year', ['year' => date('Y')]) ?></label>
  <p class="small muted m0"><?= te('Only if both are true. An exemption lasts for this year: you fill a new W-4 by February 15 next year to keep it. Steps 2 to 4 are then left empty.') ?></p></div>
<div data-unless="exempt:1">
<div class="card"><h2><?= te('Step 2: more than one job, or a spouse who works') ?></h2>
  <label class="tick"><input class="w-auto" type="checkbox" name="two_jobs" value="1" <?= $on('two_jobs') ?>> <?= te('There are only two jobs in total (mine and my spouse\'s, or two of mine), with similar pay') ?></label>
  <p class="small muted m0"><?= te('Tick it only on the W-4 for one of the two jobs. For three jobs or more, or very different pay, use the IRS estimator or the worksheet of the official form, and enter the result in Step 4(c).') ?></p></div>
<div class="card"><h2><?= te('Step 3: dependents and other credits') ?></h2>
  <p class="small muted"><?= te('If your income will be :single or less (:joint or less if married filing jointly).', ['single' => '$200,000', 'joint' => '$400,000']) ?></p>
  <div class="grid g3">
    <div><label for="tf-children"><?= te('Qualifying children under 17') ?> × $<?= number_format(TF_W4_CHILD) ?></label><input id="tf-children" name="children" type="number" min="0" max="20" value="<?= $v('children') ?: '0' ?>"></div>
    <div><label for="tf-otherdep"><?= te('Other dependents') ?> × $<?= number_format(TF_W4_OTHER) ?></label><input id="tf-otherdep" name="other_dependents" type="number" min="0" max="20" value="<?= $v('other_dependents') ?: '0' ?>"></div>
    <div><label for="tf-credits"><?= te('Other credits, in dollars (optional)') ?></label><input id="tf-credits" name="other_credits" inputmode="decimal" maxlength="12" value="<?= $v('other_credits') ?>"></div>
  </div>
  <p><?= te('Step 3 total') ?>: <strong data-step3-total data-child="<?= TF_W4_CHILD ?>" data-other="<?= TF_W4_OTHER ?>">$0</strong></p></div>
<div class="card"><h2><?= te('Step 4 (optional): other adjustments') ?></h2>
  <div class="grid g3">
    <div><label for="tf-income"><?= te('(a) Other income not from jobs, for the year') ?></label><input id="tf-income" name="other_income" inputmode="decimal" maxlength="12" value="<?= $v('other_income') ?>"></div>
    <div><label for="tf-deductions"><?= te('(b) Deductions other than the standard deduction') ?></label><input id="tf-deductions" name="deductions" inputmode="decimal" maxlength="12" value="<?= $v('deductions') ?>"></div>
    <div><label for="tf-extra"><?= te('(c) Extra withholding each pay period') ?></label><input id="tf-extra" name="extra_withholding" inputmode="decimal" maxlength="12" value="<?= $v('extra_withholding') ?>"></div>
  </div></div>
</div>
<?php $signature(TF_W4_ATTEST, t('Sign and send my W-4')); ?>

<?php else: ?>
<div class="card"><h2><?= te('Your information') ?></h2>
  <?php $identity(); ?>
  <div class="grid g3">
    <div><label for="tf-other-names"><?= te('Other last names used (if any)') ?></label><input id="tf-other-names" name="other_last_names" maxlength="190" value="<?= $v('other_last_names') ?>"></div>
    <div><label for="tf-dob"><?= te('Date of birth') ?></label><input id="tf-dob" name="birth_date" type="date" required value="<?= $v('birth_date') ?>" autocomplete="bday"></div>
    <div></div>
    <div><label for="tf-email"><?= te('Email (optional)') ?></label><input id="tf-email" name="email" type="email" maxlength="190" value="<?= $v('email') ?>"></div>
    <div><label for="tf-phone"><?= te('Phone (optional)') ?></label><input id="tf-phone" name="phone" maxlength="30" value="<?= $v('phone') ?>"></div>
  </div>
  <?php $secret('ssn', t('Social Security number'), t('Required if the employer uses E-Verify; otherwise you may leave both empty.'), false, 'digits'); ?>
</div>
<div class="card"><h2><?= te('I attest that I am') ?></h2><p class="small muted"><?= te('Tick one box.') ?></p>
  <?php foreach (tf_i9_statuses() as $k => $label): ?><label class="tick"><input class="w-auto" type="radio" name="status" value="<?= e($k) ?>" required <?= ($old['status'] ?? '') === $k ? 'checked' : '' ?>> <?= e($label) ?></label><?php endforeach; ?>
  <div data-when="status:permanent,authorized">
    <div data-when="status:authorized">
      <label for="tf-doctype"><?= te('The number you give') ?></label>
      <select id="tf-doctype" name="doc_type"><?php foreach (tf_i9_doc_types() as $k => $label): ?><option value="<?= e($k) ?>" <?= ($old['doc_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
      <div class="grid g2">
        <div><label for="tf-until"><?= te('Authorized to work until') ?></label><input id="tf-until" name="work_until" type="date" value="<?= $v('work_until') ?>"></div>
        <div><label class="tick mt-l"><input class="w-auto" type="checkbox" name="work_until_na" value="1" <?= $on('work_until_na') ?>> <?= te('My authorization has no end date') ?></label></div>
      </div>
      <div data-when="doc_type:passport"><label for="tf-country"><?= te('Country of issuance') ?></label><input id="tf-country" name="passport_country" maxlength="60" value="<?= $v('passport_country') ?>"></div>
    </div>
    <?php $secret('doc_number', t('Document number'), t('A lawful permanent resident gives the USCIS or A-Number (7 to 9 digits).'), true, 'text'); ?>
  </div>
</div>
<div class="card"><label class="tick"><input class="w-auto" type="checkbox" name="used_preparer" value="1" <?= $on('used_preparer') ?>> <?= te('Someone helped me fill in this section (a preparer or translator)') ?></label>
  <div data-when="used_preparer:1">
    <div class="grid g3">
      <div><label for="tf-pfirst"><?= te('Their first name') ?></label><input id="tf-pfirst" name="preparer_first" maxlength="190" required value="<?= $v('preparer_first') ?>"></div>
      <div><label for="tf-plast"><?= te('Their last name') ?></label><input id="tf-plast" name="preparer_last" maxlength="190" required value="<?= $v('preparer_last') ?>"></div>
      <div><label for="tf-paddr"><?= te('Their address') ?></label><input id="tf-paddr" name="preparer_address" maxlength="250" required value="<?= $v('preparer_address') ?>"></div>
    </div>
    <p class="small" lang="en"><strong><?= e(TF_I9_PREPARER_ATTEST) ?></strong></p>
    <label class="tick"><input class="w-auto" type="checkbox" name="preparer_attest" value="1" required> <?= te('The person who helped me read this statement and agrees to it') ?></label>
  </div></div>
<?php $signature(TF_I9_ATTEST, t('Sign and send Section 1 of my I-9')); ?>
<?php endif; ?>
</form>
<script src="/assets/masked-fields.js"></script>
<?php endif; ?>
