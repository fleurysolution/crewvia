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
  <?php // P5-M105b: a rehearsal on the live workspace emails no real colleague. ?>
  <label class="own-room"><input type="checkbox" name="office_mail_test" value="1"<?= $routing['office'] ? ' checked' : '' ?>> <?= te('Rehearsal: the office\'s emails to the test inbox as well') ?></label>
  <label class="own-room"><input type="checkbox" name="office_notice_test" value="1"<?= $routing['notices'] ? ' checked' : '' ?>> <?= te('Rehearsal: the in-app notices only to the test accounts as well') ?></label>
  <?php if ($routing['office']): ?><p class="m0 mt-xs"><span class="tag amber" id="office-mode"><?= te('Rehearsal') ?></span> <?= te('Every email - candidates and office staff - goes to the test inbox. Switch it off after the run-through, or your colleagues receive nothing.') ?></p><?php endif; ?>
  <div class="actions mt"><button class="btn"><?= te('Save') ?></button></div>
</form>

<div class="card scroll"><table id="outbox"><tr><th><?= te('Recipient') ?></th><th><?= te('Delivered to') ?></th><th><?= te('Queue status') ?></th><th><?= te('Attempts') ?></th><th><?= te('Created') ?></th><th><?= te('Action') ?></th></tr><?php foreach ($messages as $message): ?><tr><td><?= e($message['recipient']) ?></td><td class="small"><?= $message['route']['redirected'] ? '<span class="tag amber">' . te('test') . '</span> ' . e($message['route']['to']) : '<span class="muted">' . te('the recipient') . '</span>' ?></td><td><?= e($message['status']) ?></td><td><?= (int) $message['attempts'] ?></td><td><?= e($message['created_at']) ?></td><td><?php if ($message['status'] === 'failed' && $message['attempts'] < 5): ?><form method="post"><?= csrf_field() ?><input name="id" type="hidden" value="<?= (int) $message['id'] ?>"><button class="btn ghost sm"><?= te('Retry') ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></table></div>

<section class="card scroll" id="staff-mail">
  <h2 class="m0"><?= te('Sent emails') ?></h2>
  <?php if ((int) $waiting['n'] > 0): ?>
  <div class="flash <?= (int) $waiting['minutes'] >= 15 ? 'err' : 'ok' ?> mt-s" id="mail-waiting" data-waiting="<?= (int) $waiting['n'] ?>" data-minutes="<?= (int) $waiting['minutes'] ?>">
    <?= (int) $waiting['minutes'] >= 15
        ? tn((int) $waiting['n'], ':n email has been waiting :m minutes: the delivery job may be stopped.', ':n emails have been waiting, the oldest for :m minutes: the delivery job may be stopped.', ['m' => (int) $waiting['minutes']])
        : tn((int) $waiting['n'], ':n email is waiting; the delivery job sends every 5 minutes.', ':n emails are waiting; the delivery job sends every 5 minutes.') ?>
    <form method="post" class="inline-block ml"><?= csrf_field() ?><input type="hidden" name="do" value="send_now"><button class="btn sm" data-send-now><?= te('Send now') ?></button></form>
  </div>
  <?php else: ?><p class="small m0 mt-s" id="mail-waiting" data-waiting="0"><span class="tag green"><?= te('Nothing waiting') ?></span></p><?php endif; ?>
  <?php if ($refusals['held'] > 0): ?><div class="flash err mt-s" id="refusals-held" data-held="<?= (int) $refusals['held'] ?>"><?= tn($refusals['held'], ':n refusal to an applicant is held: nothing leaves until you decide.', ':n refusals to applicants are held: nothing leaves until you decide.') ?> <a href="/candidate-emails#held"><?= te('Decide') ?></a></div><?php endif; ?>
  <?php if ($refusals['scheduled'] > 0): ?><p class="small m0 mt-xs" id="refusals-scheduled" data-scheduled="<?= (int) $refusals['scheduled'] ?>"><?= tn($refusals['scheduled'], ':n refusal to an applicant leaves within 24 hours.', ':n refusals to applicants leave within 24 hours.') ?> <a href="/candidate-emails"><?= te('Emails to applicants') ?></a></p><?php endif; ?>
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

<?php if ($held['window']): ?>
<section class="card" id="rehearsal-held">
  <h2 class="m0"><?= $held['window'][2] ? te('Kept from real people during this rehearsal') : te('Kept from real people during the last rehearsal') ?></h2>
  <p class="small muted"><?= te('From :from to :to. Notices held back are never shown or pushed; emails went to the test inbox instead of their recipient.', ['from' => substr($held['window'][0], 0, 16), 'to' => substr($held['window'][1], 0, 16)]) ?></p>
  <p class="m0" data-held-notices="<?= count($held['notices']) ?>" data-held-emails="<?= count($held['emails']) ?>"><strong><?= tn(count($held['notices']), ':n notice held back', ':n notices held back') ?></strong> &middot; <strong><?= tn(count($held['emails']), ':n email sent to the test inbox instead', ':n emails sent to the test inbox instead') ?></strong></p>
  <?php if ($held['notices'] || $held['emails']): ?><div class="scroll"><table class="mt-s"><thead><tr><th><?= te('When') ?></th><th><?= te('For') ?></th><th><?= te('What') ?></th></tr></thead><tbody>
  <?php foreach ($held['notices'] as $n): ?><tr><td class="small"><?= e(substr((string) $n['created_at'], 0, 16)) ?></td><td class="small"><?= e($n['name']) ?></td><td class="small"><span class="tag grey"><?= te('Notice') ?></span> <?= e($n['message']) ?></td></tr><?php endforeach; ?>
  <?php foreach ($held['emails'] as $m): ?><tr><td class="small"><?= e(substr((string) $m['created_at'], 0, 16)) ?></td><td class="small"><?= e($m['recipient']) ?></td><td class="small"><span class="tag grey"><?= te('Email') ?></span> <?= e($m['purpose']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
<?php endif; ?>
