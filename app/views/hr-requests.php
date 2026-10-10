<?php
$tone = ['open' => 'amber', 'answered' => 'blue', 'closed' => 'grey', 'withdrawn' => 'grey'];
?>
<h1><?= $role === 'worker' ? te('My HR requests') : te('HR requests') ?></h1>
<p class="sub"><?= te('Ask HR or payroll for a letter, a document, an answer about your pay or your schedule. Pay questions go to payroll; the rest to recruiting.') ?><?php if ($role === 'worker'): ?> <a href="/self-service"><?= te('My self-service') ?></a><?php endif; ?></p>

<?php if ($mode === 'one'): ?>
<div class="card" id="request" data-request="<?= e($r['reference']) ?>" data-request-status="<?= e($r['status']) ?>">
  <h2><?= e($r['reference']) ?> · <?= e($r['subject']) ?> <span class="tag <?= $tone[$r['status']] ?>"><?= e(hr_request_statuses()[$r['status']]) ?></span></h2>
  <p class="small muted"><?= e(hr_request_kinds()[$r['kind']]) ?><?= $role !== 'worker' ? ' · ' . e($r['full_name']) : '' ?> · <?= e(d(substr((string) $r['created_at'], 0, 10))) ?> · <?= $r['desk'] === 'payroll' ? te('Payroll') : te('Recruiting') ?><?= (int) $r['include_pay'] === 1 ? ' · ' . te('the letter is to state the pay rate') : '' ?></p>
  <p style="white-space:pre-wrap"><?= e($r['detail']) ?></p>
  <?php foreach ($replies as $x): ?><div class="small" data-reply="<?= (int) $x['by_worker'] ?>" style="border-top:1px solid var(--line);padding:6px 0"><strong><?= (int) $x['by_worker'] === 1 ? te('You') . ($role !== 'worker' ? ' (' . e($r['full_name']) . ')' : '') : e($x['by_name'] ?? t('HR')) ?></strong> · <?= e(d(substr((string) $x['created_at'], 0, 10))) ?><div style="white-space:pre-wrap"><?= e($x['message']) ?></div></div><?php endforeach; ?>
  <?php if ($r['letter_text'] !== null): ?><p><a class="btn" href="/hr-requests?letter=<?= (int) $r['id'] ?>" target="_blank" id="letter-link"><?= te('Open the letter') ?></a> <span class="small muted mono" title="SHA-256"><?= e(substr((string) $r['letter_sha256'], 0, 12)) ?></span></p><?php endif; ?>
</div>
<?php if (! in_array($r['status'], ['closed', 'withdrawn'], true)): ?>
<div class="grid g2">
  <form class="card" method="post" id="reply-form"><?= csrf_field() ?><input type="hidden" name="do" value="reply"><input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>"><h3><?= te('Reply') ?></h3><textarea name="message" required minlength="2" rows="3"></textarea><button class="btn"><?= te('Send') ?></button></form>
  <div class="card">
    <?php if ($role !== 'worker' && $r['kind'] === 'employment_letter' && $r['letter_text'] === null): ?>
    <form method="post" id="letter-form"><?= csrf_field() ?><input type="hidden" name="do" value="letter"><input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>"><p class="muted"><?= te('The letter states the dates, the trade and the kind of employment from the records, and the pay rate only if the person asked for it.') ?></p><button class="btn"><?= te('Issue the letter') ?></button></form>
    <?php endif; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>"><button class="btn ghost" name="do" value="close"><?= te('Close the request') ?></button><?php if ($role === 'worker'): ?> <button class="btn ghost" name="do" value="withdraw"><?= te('Withdraw it') ?></button><?php endif; ?></form>
  </div>
</div>
<?php endif; ?>

<?php else: ?>
<?php if ($role === 'worker'): ?>
<form class="card" method="post" id="request-form"><?= csrf_field() ?><input type="hidden" name="do" value="create">
  <h2><?= te('New request') ?></h2>
  <div class="grid g2"><div><label><?= te('About') ?></label><select name="kind"><?php foreach (hr_request_kinds() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label><?= te('Subject') ?></label><input name="subject" required minlength="3" maxlength="190"></div></div>
  <label><?= te('What you need') ?></label><textarea name="detail" required minlength="10" rows="4"></textarea>
  <label><input type="checkbox" name="include_pay" value="1"> <?= te('For a letter: state my pay rate (only if, for example, a landlord or a bank asks for it)') ?></label>
  <button class="btn"><?= te('Send the request') ?></button>
</form>
<?php endif; ?>
<div class="card scroll" id="request-list">
  <?php if (! $list): ?><p class="muted"><?= te('No request.') ?></p><?php else: ?>
  <table><tr><th><?= te('Request') ?></th><?php if ($role !== 'worker'): ?><th><?= te('From') ?></th><?php endif; ?><th><?= te('About') ?></th><th><?= te('Sent') ?></th><th><?= te('State') ?></th></tr>
  <?php foreach ($list as $x): ?><tr data-list-request="<?= e($x['reference']) ?>" data-list-status="<?= e($x['status']) ?>"><td><a href="/hr-requests?id=<?= (int) $x['id'] ?>"><?= e($x['reference']) ?></a> · <?= e($x['subject']) ?></td><?php if ($role !== 'worker'): ?><td><?= e($x['full_name']) ?></td><?php endif; ?><td><?= e(hr_request_kinds()[$x['kind']]) ?></td><td><?= e(d(substr((string) $x['created_at'], 0, 10))) ?></td><td><span class="tag <?= $tone[$x['status']] ?>"><?= e(hr_request_statuses()[$x['status']]) ?></span></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>
<?php endif; ?>
