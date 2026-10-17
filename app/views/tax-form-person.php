<?php
// P5-M65: the coordinator, with the person in front of them: their forms filled in, their documents, the signature to open.
$word = ['draft' => t('Filled in, to sign here'), 'submitted' => t('Signed, being checked'), 'accepted' => t('Accepted'), 'returned' => t('Sent back to them'), 'replaced' => t('Replaced by a newer one')];
$drafts = array_filter($forms, static fn($f) => $f && $f['row']['status'] === 'draft');
$docs = tf_i9_documents(); ?>
<div class="page-head"><div><h1><?= te('Forms to sign') ?> · <?= e($person['full_name']) ?></h1>
  <p class="sub"><?= te('With the person in front of you: check their forms with them, open the signature, then examine their original documents for Section 2.') ?></p></div>
  <div class="actions"><a class="btn ghost" href="/change-requests#tax-forms"><?= te('Back') ?></a></div></div>

<div class="grid g2">
<?php foreach (['w4' => 'W-4', 'i9' => 'I-9'] as $f => $label): $x = $forms[$f]; ?>
  <div class="card" data-person-form="<?= e($f) ?>"><h2><?= e($label) ?></h2>
  <?php if (! $x): ?><p class="muted"><?= te('Not filled in yet. They fill it from their onboarding tasks, on their phone.') ?></p>
  <?php else: $d = $x['data']; ?>
    <p><span class="tag <?= $x['row']['status'] === 'accepted' ? 'green' : ($x['row']['status'] === 'draft' ? 'amber' : '') ?>"><?= e($word[$x['row']['status']] ?? $x['row']['status']) ?></span></p>
    <table>
      <tr><td class="muted"><?= te('Name') ?></td><td><?= e(trim(($d['first_name'] ?? '') . ' ' . ($d['middle_initial'] ?? '') . ' ' . ($d['last_name'] ?? ''))) ?></td></tr>
      <tr><td class="muted"><?= te('Address') ?></td><td><?= e(trim(($d['address'] ?? '') . ', ' . ($d['city'] ?? '') . ', ' . ($d['state'] ?? '') . ' ' . ($d['zip'] ?? ''))) ?></td></tr>
      <tr><td class="muted"><?= te('Social Security number') ?></td><td><?= ! empty($d['ssn']) ? e('•••••' . substr((string) $d['ssn'], -4)) : (! empty($d['ssn_applied']) ? 'Applied for' : '—') ?></td></tr>
      <?php if ($f === 'i9'): ?>
      <tr><td class="muted"><?= te('Status attested') ?></td><td><?= e(tf_i9_statuses()[$d['status'] ?? ''] ?? '—') ?></td></tr>
      <?php if (! empty($d['preparer'])): ?><tr><td class="muted"><?= te('Preparer or translator') ?></td><td><?= e($d['preparer']['first_name'] . ' ' . $d['preparer']['last_name']) ?> · <?= te('signs Supplement A in ink') ?></td></tr><?php endif; ?>
      <?php else: ?>
      <tr><td class="muted">1(c)</td><td><?= e(tf_filing_statuses()[$d['filing_status'] ?? ''] ?? '—') ?></td></tr>
      <?php endif; ?>
    </table>
    <?php if ($f === 'i9'): ?><p class="small"><a href="/tax-forms?id=<?= (int) $x['row']['id'] ?>"><?= $x['row']['status'] === 'submitted' ? te('Open it and complete Section 2') : te('Open it') ?></a></p><?php endif; ?>
  <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>

<?php
// P5-M65b: the two forms must say the same thing: a difference is shown before the signature, to correct it with them.
$differ = [];
if ($forms['w4'] && $forms['i9']) {
    foreach (['first_name' => t('First name'), 'middle_initial' => t('Middle initial'), 'last_name' => t('Last name'), 'address' => t('Address'), 'apartment' => t('Apartment'), 'city' => t('City'), 'state' => t('State'), 'zip' => t('ZIP code'), 'ssn' => t('Social Security number')] as $k => $label) {
        $a = mb_strtolower(trim((string) ($forms['w4']['data'][$k] ?? ''))); $b = mb_strtolower(trim((string) ($forms['i9']['data'][$k] ?? '')));
        if ($a !== $b && ! ($k === 'ssn' && ($a === '' || $b === ''))) {
            $differ[] = $label;
        }
    }
}
$wholeAddress = array_filter($forms, static fn($f) => $f && preg_match('/\b\d{5}(-\d{4})?\b/', (string) ($f['data']['address'] ?? '')));
if ($differ || $wholeAddress): ?><div class="card" id="forms-differ">
  <?php if ($differ): ?><p class="t-red"><strong><?= te('The W-4 and the I-9 differ: :fields. Correct it with them before they sign ("Something is wrong? Correct it first" on their phone).', ['fields' => implode(', ', $differ)]) ?></strong></p><?php endif; ?>
  <?php if ($wholeAddress): ?><p class="t-red" data-whole-address="1"><strong><?= te('The street field holds a ZIP code: the whole address was typed in it. Correct it with them: street in Address, then city, state and ZIP code.') ?></strong></p><?php endif; ?>
</div><?php endif; ?>
<div class="card" id="sign-code"><h2><?= te('Signature, in person') ?></h2>
<?php if ($signCode): ?>
  <p><?= te(':name scans this code with their own phone, or opens their portal and enters it. It works once, until :time.', ['name' => $person['full_name'], 'time' => date('g:i a', strtotime($signCode['expires']))]) ?></p>
  <p class="big-figure mono" id="forms-code"><?= e(substr($signCode['code'], 0, 4) . ' ' . substr($signCode['code'], 4)) ?></p>
  <div class="signing-qr" data-code="<?= e($signCode['code']) ?>"></div>
  <script src="/assets/qrcode.min.js"></script>
  <script>document.querySelectorAll('.signing-qr').forEach(el=>new QRCode(el,{text:location.origin+'/tax-forms?sign='+el.dataset.code,width:192,height:192,correctLevel:QRCode.CorrectLevel.M}));</script>
<?php elseif ($drafts): ?>
  <p class="small muted"><?= te('Check the forms above with them. When they are ready, open the signature: a code valid 15 minutes, for them only, signed on their own phone.') ?></p>
  <form method="post" action="/tax-forms"><?= csrf_field() ?><input type="hidden" name="do" value="authorize"><input type="hidden" name="candidate_id" value="<?= (int) $person['id'] ?>"><button class="btn"><?= te('Open the signature') ?></button></form>
<?php else: ?>
  <p class="muted"><?= te('Nothing to sign: no form filled in and waiting.') ?></p>
<?php endif; ?>
</div>

<div class="card" id="copies"><h2><?= te('Copies of their documents') ?></h2>
  <p class="small muted"><?= te('RSS keeps a copy of the documents of everyone. The person chooses which documents to present (List A, or List B and List C); never ask for a particular one.') ?></p>
  <?php foreach ($copies as $k): ?><p class="small" data-copy="<?= (int) $k['id'] ?>">List <?= e($k['list']) ?> · <?= e($k['title']) ?> · <?= $k['by_office'] ? te('photographed by the office') : te('sent by the person') ?> · <?php if (doc_can_open(['candidate_id' => $person['id'], 'restricted' => $k['restricted'], 'status' => $k['doc_status'], 'id' => $k['document_id'], 'document_type' => 'I-9 copy'], false, null)): ?><a href="/tax-forms?copy=<?= (int) $k['document_id'] ?>" target="_blank" rel="noopener"><?= te('Open') ?></a><?php else: ?><?= te('restricted') ?><?php endif; ?></p><?php endforeach; ?>
  <?php if (! $copies): ?><p class="muted"><?= te('No copy yet.') ?></p><?php endif; ?>
  <?php foreach (['A', 'B', 'C'] as $l): ?><datalist id="pdocs-<?= $l ?>"><?php foreach ($docs[$l] as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist><?php endforeach; ?>
  <form method="post" action="/tax-forms" enctype="multipart/form-data" class="grid g3 mt-s"><?= csrf_field() ?><input type="hidden" name="do" value="add_copy"><input type="hidden" name="candidate_id" value="<?= (int) $person['id'] ?>"><input type="hidden" name="back" value="/tax-forms?person=<?= (int) $person['id'] ?>">
    <div><label for="pc-l"><?= te('List') ?></label><select id="pc-l" name="list" onchange="document.getElementById('pc-t').setAttribute('list','pdocs-'+this.value)"><option>A</option><option>B</option><option>C</option></select></div>
    <div><label for="pc-t"><?= te('Document') ?></label><input id="pc-t" name="title" list="pdocs-A" maxlength="150" required></div>
    <div><label for="pc-f"><?= te('Photograph the original') ?></label><input id="pc-f" name="copy" type="file" accept=".pdf,.jpg,.jpeg,.png,image/*" capture="environment" required></div>
    <button class="btn ghost"><?= te('Keep the copy') ?></button>
  </form>
</div>
