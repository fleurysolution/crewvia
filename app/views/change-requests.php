<div class="page-head"><div><h1><?= te('Change requests') ?></h1>
<p class="sub"><?= te('What workers asked to change from their own folder. Nothing changes until it is accepted here; a refusal tells the worker why.') ?></p></div></div>

<?php if (can('payroll')): ?>
<div class="card" id="bank-requests">
  <h2><?= te('Bank details to check') ?></h2>
  <p class="muted"><?= te('Show the full numbers and the proof the person sent, compare them digit by digit, then accept. Showing them is recorded.') ?></p>
  <?php if (! $bank): ?><p class="muted"><?= te('No bank details waiting.') ?></p><?php endif; ?>
  <?php foreach ($bank as $r): ?>
  <div class="row list-row" data-bank-request="<?= (int) $r['id'] ?>">
    <div class="min-w"><strong><?= e($r['full_name']) ?></strong>
      <div class="small"><?= te(':bank, account ending :last', ['bank' => $r['bank_label'], 'last' => $r['last_four']]) ?><?= $r['current_last_four'] ? ' · ' . te('replaces the account ending :last', ['last' => $r['current_last_four']]) : '' ?></div>
      <?php if ($revealed && (int) $revealed['id'] === (int) $r['id'] && empty($revealed['unreadable'])): ?>
        <div class="small mono" data-revealed="1"><?= te('Routing :routing · account :account · name :name', ['routing' => $revealed['details']['routing_number'] ?? '', 'account' => $revealed['details']['account_number'] ?? '', 'name' => $revealed['details']['account_holder'] ?? '']) ?></div>
        <?php if (! empty($r['proof_mime'])): ?>
          <div class="mt-s bank-proof" data-proof="<?= (int) $r['id'] ?>">
            <?php if ($r['proof_mime'] === 'application/pdf'): ?><a class="btn ghost sm" href="/change-requests?proof=<?= (int) $r['id'] ?>" target="_blank" rel="noopener"><?= te('Open the proof (PDF)') ?></a>
            <?php else: ?><a href="/change-requests?proof=<?= (int) $r['id'] ?>" target="_blank" rel="noopener"><img src="/change-requests?proof=<?= (int) $r['id'] ?>" alt="<?= te('Proof sent with the bank details') ?>" class="bank-proof-img"></a><?php endif; ?>
          </div>
        <?php else: ?><p class="small t-red m0 mt-s" data-no-proof="<?= (int) $r['id'] ?>"><?= te('No proof was sent with these details (sent before proofs were asked for): ask for a voided cheque or a bank letter before accepting.') ?></p><?php endif; ?>
      <?php else: ?>
        <a class="small" href="/change-requests?reveal=<?= (int) $r['id'] ?>"><?= te('Show the full numbers') ?></a>
      <?php endif; ?>
    </div>
    <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="bank"><input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
      <input name="note" maxlength="500" placeholder="<?= te('Note (needed to refuse)') ?>" aria-label="<?= te('Note') ?>">
      <label class="small nowrap"><input class="w-auto" type="checkbox" name="matched" value="1"> <?= te('I compared the numbers with the proof') ?></label>
      <button class="btn sm" name="decision" value="approve"><?= te('Accept') ?></button><button class="btn ghost sm" name="decision" value="reject"><?= te('Refuse') ?></button></form>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (can('recruiter')): ?>
<div class="card" id="detail-requests">
  <h2><?= te('Personal details to check') ?></h2>
  <?php if (! $details): ?><p class="muted"><?= te('No change to personal details waiting.') ?></p><?php endif; ?>
  <?php foreach ($details as $r): ?>
  <div class="row list-row" data-detail-request="<?= (int) $r['id'] ?>">
    <div class="min-w"><strong><a href="/employee-folder?id=<?= (int) $r['candidate_id'] ?>"><?= e($r['full_name']) ?></a></strong>
      <div class="small"><?= e($fields[$r['field']] ?? $r['field']) ?>: <?= e((string) ($r['old_value'] ?? '—')) ?> → <strong><?= e($r['new_value']) ?></strong></div>
      <?php if ($r['reason']): ?><div class="small muted"><?= e($r['reason']) ?></div><?php endif; ?>
    </div>
    <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="detail"><input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
      <input name="note" maxlength="500" placeholder="<?= te('Note (needed to refuse)') ?>" aria-label="<?= te('Note') ?>">
      <button class="btn sm" name="decision" value="approve"><?= te('Accept') ?></button><button class="btn ghost sm" name="decision" value="reject"><?= te('Refuse') ?></button></form>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
