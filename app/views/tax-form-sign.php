<?php
// P5-M65: the worker signs their forms on their own phone, in front of the coordinator who gave them the code.
$summary = static function (array $d, string $form): array {
    $name = trim(($d['first_name'] ?? '') . ' ' . ($d['middle_initial'] ?? '') . ' ' . ($d['last_name'] ?? ''));
    $rows = [t('Name') => $name, t('Address') => trim(($d['address'] ?? '') . ', ' . ($d['city'] ?? '') . ', ' . ($d['state'] ?? '') . ' ' . ($d['zip'] ?? ''))];
    $rows[t('Social Security number')] = ! empty($d['ssn']) ? '•••••' . substr((string) $d['ssn'], -4) : (! empty($d['ssn_applied']) ? 'Applied for' : '—');
    if ($form === 'w4') {
        $rows['1(c)'] = tf_filing_statuses()[$d['filing_status'] ?? ''] ?? '—';
        $rows['3'] = '$' . number_format((float) ($d['step3_total'] ?? 0), 2);
        $rows['4(c)'] = '$' . number_format((float) ($d['extra_withholding'] ?? 0), 2);
        if (! empty($d['exempt'])) {
            $rows[t('Exempt')] = 'Exempt ' . TF_W4_YEAR;
        }
    } else {
        $rows[t('Date of birth')] = ! empty($d['birth_date']) ? d((string) $d['birth_date']) : '—';
        $rows[t('Status attested')] = tf_i9_statuses()[$d['status'] ?? ''] ?? '—';
    }

    return $rows;
}; ?>
<div class="page-head"><div><h1><?= te('Sign my forms') ?></h1>
  <p class="sub"><?= te('In front of the coordinator, on your own phone. Read each form again: once signed, it can no longer change.') ?></p></div>
  <div class="actions"><a class="btn ghost" href="/portal#onboarding"><?= te('Back to my portal') ?></a></div></div>

<?php if (! $drafts): ?>
<div class="card"><p><?= te('There is no form to sign. Fill your W-4 and your I-9 first, from your onboarding tasks.') ?></p></div>
<?php else: ?>
<form method="post" action="/tax-forms" class="tax-form" id="sign-form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="do" value="sign_in_person">
  <div class="card"><label for="sg-code"><?= te('The code the coordinator shows you') ?></label>
    <input id="sg-code" name="code" inputmode="numeric" maxlength="9" required value="<?= e($signCode) ?>" autocomplete="one-time-code"></div>
  <?php foreach ($drafts as $dr): $f = $dr['row']['form']; ?>
  <div class="card official" data-sign-form="<?= e($f) ?>">
    <h2><?= e(tf_forms()[$f]['name']) ?></h2>
    <table><?php foreach ($summary($dr['data'], $f) as $label => $value): ?><tr><td class="muted"><?= e((string) $label) ?></td><td><?= e((string) $value) ?></td></tr><?php endforeach; ?></table>
    <p class="small"><a href="/tax-forms?fill=<?= e($f) ?>"><?= te('Something is wrong? Correct it first') ?></a></p>
    <p class="small" lang="en"><strong><?= e($f === 'w4' ? TF_W4_ATTEST : TF_I9_ATTEST) ?></strong></p>
    <label class="tick"><input class="w-auto" type="checkbox" name="attest_<?= e($f) ?>" value="1" required> <?= te('I have read this statement and I sign it.') ?></label>
  </div>
  <?php endforeach; ?>
  <div class="card soft">
    <label for="sg-name"><?= te('Type your first and last name') ?></label>
    <input id="sg-name" name="signer_name" required maxlength="190" autocomplete="off">
    <label><?= te('Sign here, with your finger') ?></label>
    <canvas id="sg-pad" class="sign-pad" width="600" height="180" aria-label="<?= te('Signature box') ?>"></canvas>
    <input type="hidden" name="signature_png" id="sg-png">
    <div class="row mt-s"><button type="button" class="btn ghost sm" id="sg-clear"><?= te('Clear') ?></button></div>
    <p class="small muted"><?= te('Your signature is recorded with the date, the time and the coordinator who witnessed it, and placed on the official forms.') ?></p>
    <button class="btn" id="sg-submit"><?= te('Sign my forms') ?></button>
    <p class="small t-red" id="sg-empty" hidden><?= te('Sign in the box first.') ?></p>
  </div>
</form>
<script src="/assets/signature-pad.js"></script>
<?php endif; ?>
