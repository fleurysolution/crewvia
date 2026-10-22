<div class="page-head"><div><h1><?= te('Email delivery') ?></h1><p class="sub"><?= te('Automatic activation invitations are encrypted in the queue. An approved mail relay and scheduled delivery worker are required.') ?></p></div></div>

<form method="post" action="/email-delivery" class="card" id="candidate-mail"><?= csrf_field() ?><input type="hidden" name="do" value="routing">
  <h2><?= te('Emails to candidates') ?></h2>
  <?php if ($routing['test']): ?>
    <p class="m0"><span class="tag amber" id="mail-mode"><?= te('Test mode') ?></span> <?= te('Every email to a candidate or a worker goes to the test inbox, marked with who it was for. Their real address stays on their record. Office staff always get their own emails.') ?></p>
  <?php else: ?>
    <p class="m0"><span class="tag green" id="mail-mode"><?= te('Live') ?></span> <?= te('Candidates and workers receive their emails at their own address.') ?></p>
  <?php endif; ?>
  <div class="row mt-l">
    <div class="f2"><label for="md-test-to"><?= te('Test inbox') ?></label><input id="md-test-to" name="test_to" maxlength="190" required value="<?= e($routing['test_to']) ?>"><span class="hint"><?= te('{first} becomes the candidate\'s first name (with the last name\'s initial when two share it): solutionsfleury+otis@gmail.com.') ?></span></div>
    <div class="f2"><label for="md-reply"><?= te('Replies go to') ?></label><input id="md-reply" type="email" name="reply_to" maxlength="190" value="<?= e($routing['reply_to']) ?>"><span class="hint"><?= te('The recruiting lead who answers candidates. A campaign answers to the recruiter who sent it.') ?></span></div>
  </div>
  <label class="own-room mt"><input type="checkbox" name="test_mode" value="1"<?= $routing['test'] ? ' checked' : '' ?>> <?= te('Test mode: send candidates\' emails to the test inbox') ?></label>
  <div class="actions mt"><button class="btn"><?= te('Save') ?></button></div>
</form>

<div class="card scroll"><table id="outbox"><tr><th><?= te('Recipient') ?></th><th><?= te('Delivered to') ?></th><th><?= te('Queue status') ?></th><th><?= te('Attempts') ?></th><th><?= te('Created') ?></th><th><?= te('Action') ?></th></tr><?php foreach ($messages as $message): ?><tr><td><?= e($message['recipient']) ?></td><td class="small"><?= $message['route']['redirected'] ? '<span class="tag amber">' . te('test') . '</span> ' . e($message['route']['to']) : '<span class="muted">' . te('the recipient') . '</span>' ?></td><td><?= e($message['status']) ?></td><td><?= (int) $message['attempts'] ?></td><td><?= e($message['created_at']) ?></td><td><?php if ($message['status'] === 'failed' && $message['attempts'] < 5): ?><form method="post"><?= csrf_field() ?><input name="id" type="hidden" value="<?= (int) $message['id'] ?>"><button class="btn ghost sm"><?= te('Retry') ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></table></div>

<section class="card scroll" id="staff-mail">
  <h2 class="m0"><?= te('Sent emails') ?></h2>
  <p class="small muted"><?= te('Emails to the office: a requisition sent to recruiting or published, an applicant to review, a decision. Sent means the mail server accepted it.') ?></p>
  <?php $kinds = ['requisition' => t('Requisition sent to recruiting'), 'published' => t('Requisition published'), 'review' => t('Applicant to review'), 'decision' => t('Decision on an applicant')]; ?>
  <?php if (! $staffMail): ?><p class="muted m0"><?= te('No email to the office yet.') ?></p><?php else: ?>
  <table><thead><tr><th><?= te('About') ?></th><th><?= te('To') ?></th><th><?= te('Status') ?></th><th><?= te('Queued') ?></th><th><?= te('Sent') ?></th></tr></thead><tbody>
  <?php foreach ($staffMail as $m): $kind = strtok((string) $m['purpose'], ':'); ?><tr data-staff-mail="<?= e($m['purpose']) ?>">
    <td><?= e($kinds[$kind] ?? $kind) ?><?php if (! empty($m['requisition'])): ?><div class="small muted"><?= e($m['requisition']) ?></div><?php endif; ?></td>
    <td class="small"><?= e($m['recipient']) ?></td>
    <td><span class="tag <?= $m['status'] === 'sent' ? 'green' : ($m['status'] === 'failed' ? 'red' : 'amber') ?>"><?= e(['sent' => t('Sent'), 'failed' => t('Failed'), 'pending' => t('Waiting to send'), 'sending' => t('Sending')][$m['status']] ?? $m['status']) ?></span></td>
    <td class="small"><?= e(substr((string) $m['created_at'], 0, 16)) ?></td><td class="small"><?= e($m['sent_at'] ? substr((string) $m['sent_at'], 0, 16) : '—') ?></td>
  </tr><?php endforeach; ?></tbody></table>
  <?php endif; ?>
</section>
