<?php
$cmStatus = ['draft' => t('Draft'), 'awaiting_approval' => t('Waiting for an administrator'), 'sending' => t('Sending'), 'sent' => t('Sent'), 'cancelled' => t('Cancelled')];
$cmTone = ['draft' => 'grey', 'awaiting_approval' => 'amber', 'sending' => 'blue', 'sent' => 'green', 'cancelled' => 'grey'];
$cmOne = ['queued' => t('Waiting'), 'sent' => t('Sent'), 'failed' => t('Failed'), 'skipped' => t('Skipped')];
?>
<div class="page-head"><div><h1><?= te('Campaign email') ?></h1>
  <p class="sub"><?= te('One email about one requisition, with its pay, per diem and start, to a filtered list of the talent pool. People answer through their portal or the job\'s application page; first come, first served, and their interest arrives in Hiring.') ?></p></div></div>

<?php if ($cmNotReady): ?><div class="card" id="campaign-not-ready" style="border-color:#e0b36b"><strong><?= te('Campaigns cannot go out yet.') ?></strong> <?= e($cmNotReady) ?></div><?php endif; ?>

<?php if (can('admin')): ?>
<form method="post" action="/campaigns" class="card" id="campaign-address"><?= csrf_field() ?><input type="hidden" name="do" value="address">
  <h2><?= te('Agency postal address') ?></h2><p class="small muted"><?= te('Printed at the foot of every campaign email, with the unsubscribe link, as the law on commercial email expects.') ?></p>
  <textarea name="address" rows="2" maxlength="300" required><?= e($cmAddress) ?></textarea><button class="btn ghost mt-s"><?= te('Save the address') ?></button>
</form>
<?php endif; ?>

<?php if (! $cmCampaign): ?>
<form method="get" action="/campaigns" class="card" id="campaign-new">
  <h2><?= te('New campaign') ?></h2>
  <div class="row">
    <div class="f2"><label for="cm-v"><?= te('Requisition') ?></label><select id="cm-v" name="vacancy_id" required><option value=""><?= te('Choose') ?></option><?php foreach ($cmVacancies as $v): ?><option value="<?= (int) $v['id'] ?>"<?= $cmForm['vacancy_id'] === (int) $v['id'] ? ' selected' : '' ?>><?= e($v['project'] . ' · ' . $v['title']) ?></option><?php endforeach; ?></select></div>
    <div style="max-width:160px"><label for="cm-l"><?= te('Language') ?></label><select id="cm-l" name="language"><option value="en"<?= $cmForm['language'] === 'en' ? ' selected' : '' ?>>English</option><option value="es"<?= $cmForm['language'] === 'es' ? ' selected' : '' ?>>Español</option></select></div>
  </div>
  <h3 class="small" style="margin:12px 0 4px"><?= te('Who it goes to') ?></h3>
  <div class="row">
    <div><label for="cm-d"><?= te('Trade') ?></label><select id="cm-d" name="discipline"><option value=""><?= te('Any trade') ?></option><?php foreach (disciplines() as $k => $label): ?><option value="<?= e($k) ?>"<?= $cmForm['discipline'] === $k ? ' selected' : '' ?>><?= te($label) ?></option><?php endforeach; ?></select></div>
    <div><label for="cm-sk"><?= te('Skill') ?></label><select id="cm-sk" name="skill"><option value=""><?= te('Any skill') ?></option><?php foreach (skills_list() as $k => $label): ?><option value="<?= e($k) ?>"<?= $cmForm['skill'] === $k ? ' selected' : '' ?>><?= te($label) ?></option><?php endforeach; ?></select></div>
    <div style="max-width:180px"><label for="cm-st"><?= te('State') ?></label><select id="cm-st" name="state"><option value=""><?= te('Any state') ?></option><?php foreach (geo_states() as $code => $name): ?><option value="<?= e($code) ?>"<?= $cmForm['state'] === $code ? ' selected' : '' ?>><?= e($code . ' - ' . $name) ?></option><?php endforeach; ?></select></div>
    <div style="max-width:200px"><label for="cm-mi"><?= te('Distance from the site') ?></label><select id="cm-mi" name="miles"><option value=""><?= te('Any distance') ?></option><?php foreach (geo_radii() as $r): ?><option value="<?= $r ?>"<?= $cmForm['miles'] === $r ? ' selected' : '' ?>><?= te('Within :n miles', ['n' => $r]) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="row tight" style="gap:14px;margin-top:6px">
    <label class="tick"><input type="checkbox" name="available" value="1"<?= $cmForm['available'] ? ' checked' : '' ?>> <?= te('Marked available') ?></label>
    <label class="tick"><input type="checkbox" name="worked" value="1"<?= $cmForm['worked'] ? ' checked' : '' ?>> <?= te('Worked for the agency before') ?></label>
  </div>
  <p class="small muted"><?= te('Never included: people who unsubscribed, people on the do-not-rehire register, switched-off accounts, people with no valid email, and people already on this requisition.') ?></p>
  <h3 class="small" style="margin:12px 0 4px"><?= te('The email') ?></h3>
  <label for="cm-s"><?= te('Subject') ?></label><input id="cm-s" name="subject" maxlength="190" value="<?= e($cmForm['subject']) ?>" required>
  <label for="cm-b"><?= te('Message') ?></label><textarea id="cm-b" name="body" rows="11" maxlength="8000" required><?= e($cmForm['body']) ?></textarea>
  <p class="small muted"><?= te('Filled in for each person: :list. The unsubscribe link and the postal address are added at the foot.', ['list' => implode(' ', cm_placeholders())]) ?></p>
  <button class="btn ghost" name="preview" value="1"><?= te('See who it goes to') ?></button>
  <?php if ($cmPreview !== null): ?>
    <div class="mt" id="campaign-preview" data-count="<?= (int) $cmPreview['count'] ?>">
      <strong><?= te(':n people match.', ['n' => $cmPreview['count']]) ?></strong>
      <?php if ($cmForm['miles'] && ! $cmPreview['site']): ?><span class="small" style="color:#b42318"><?= te('The distance is ignored: the project\'s site has no city and state or ZIP code.') ?></span><?php endif; ?>
      <?php if ($cmPreview['count'] > CAMPAIGN_DIRECT_LIMIT): ?><div class="small" style="color:#b7791f"><?= te('More than :n people: an administrator approves it before it goes.', ['n' => CAMPAIGN_DIRECT_LIMIT]) ?></div><?php endif; ?>
      <div class="small muted"><?php foreach ($cmPreview['people'] as $p): ?><span data-person="<?= (int) $p['id'] ?>"><?= e($p['full_name']) ?></span> · <?php endforeach; ?><?= $cmPreview['count'] > 25 ? te('and :n more', ['n' => $cmPreview['count'] - 25]) : '' ?></div>
    </div>
  <?php endif; ?>
</form>
<?php if ($cmPreview !== null && $cmPreview['count']): ?>
<!-- A separate POST form: the preview above is a GET, and a form token must never travel in an address. -->
<form method="post" action="/campaigns" id="campaign-create-form"><?= csrf_field() ?><input type="hidden" name="do" value="create">
  <?php foreach (['vacancy_id', 'language', 'subject', 'body', 'discipline', 'skill', 'state', 'miles'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= e((string) $cmForm[$k]) ?>"><?php endforeach; ?>
  <?php if ($cmForm['available']): ?><input type="hidden" name="available" value="1"><?php endif; ?><?php if ($cmForm['worked']): ?><input type="hidden" name="worked" value="1"><?php endif; ?>
  <button class="btn" id="campaign-create" style="margin:-6px 0 14px"><?= te('Create the draft for these :n people', ['n' => $cmPreview['count']]) ?></button>
</form>
<?php endif; ?>

<?php if ($cmList): ?>
<div class="card tight" id="campaigns"><table>
  <tr><th><?= te('Campaign') ?></th><th><?= te('Requisition') ?></th><th class="num"><?= te('People') ?></th><th class="num"><?= te('Sent') ?></th><th><?= te('Status') ?></th></tr>
  <?php foreach ($cmList as $m): ?><tr data-campaign="<?= (int) $m['id'] ?>"><td><a href="/campaigns?c=<?= (int) $m['id'] ?>"><?= e($m['subject']) ?></a><div class="small muted"><?= e($m['created_by_name']) ?> · <?= e(d(substr((string) $m['created_at'], 0, 10))) ?></div></td>
    <td><?= e($m['vacancy_title']) ?></td><td class="num"><?= (int) $m['recipients'] ?></td><td class="num"><?= (int) $m['sent'] ?></td><td><span class="tag <?= $cmTone[$m['status']] ?>"><?= e($cmStatus[$m['status']]) ?></span></td></tr><?php endforeach; ?>
</table></div>
<?php endif; ?>

<?php else: $m = $cmCampaign; ?>
<p class="back-link"><a href="/campaigns">← <?= te('All campaigns') ?></a></p>
<div class="page-head"><div><h1 id="campaign-title"><?= e($cmSample['subject'] ?? $m['subject']) ?></h1>
  <div class="meta"><?php if ($cmSample && $cmSample['subject'] !== $m['subject']): ?><span class="mono small"><?= e($m['subject']) ?></span> · <?php endif; ?><?= e($m['vacancy_title']) ?> · <?= te(':n people', ['n' => (int) $m['recipients']]) ?> · <?= te('by :name', ['name' => $m['created_by_name']]) ?>
    · <span class="tag <?= $cmTone[$m['status']] ?>" id="campaign-status" data-status="<?= e($m['status']) ?>"><?= e($cmStatus[$m['status']]) ?></span></div></div>
  <div class="actions">
    <?php $act = static function (string $do, string $label, string $class = 'btn ghost') use ($m): void { ?><form class="inline" method="post" action="/campaigns"><?= csrf_field() ?><input type="hidden" name="do" value="<?= e($do) ?>"><input type="hidden" name="campaign_id" value="<?= (int) $m['id'] ?>"><button class="<?= e($class) ?>" data-action="<?= e($do) ?>"><?= e($label) ?></button></form><?php }; ?>
    <?php if (in_array($m['status'], ['draft', 'awaiting_approval', 'sending'], true)): ?><?php $act('test', t('Send me a test')); ?><?php endif; ?>
    <?php if ($m['status'] === 'draft'): ?><?php $act('send', (int) $m['recipients'] > CAMPAIGN_DIRECT_LIMIT && ! can('admin') ? t('Ask for approval') : t('Send'), 'btn'); ?><?php endif; ?>
    <?php if ($m['status'] === 'awaiting_approval' && can('admin')): ?><?php $act('approve', t('Approve and send'), 'btn'); ?><?php endif; ?>
    <?php if (in_array($m['status'], ['draft', 'awaiting_approval', 'sending'], true)): ?><?php $act('cancel', t('Cancel')); ?><?php endif; ?>
  </div>
</div>
<div class="grid g4 mb" id="campaign-counts"><?php foreach ($cmCounts as $k => $n): ?><div class="card"><div class="small muted"><?= e($cmOne[$k]) ?></div><div style="font-size:22px;font-weight:700" data-count="<?= e($k) ?>"><?= (int) $n ?></div></div><?php endforeach; ?></div>
<?php if ($cmSample): ?><div class="card" id="campaign-sample"><h2><?= te('As the first person will read it') ?></h2><div class="small muted"><?= te('Subject') ?>: <strong><?= e($cmSample['subject']) ?></strong></div><pre style="white-space:pre-wrap;font:inherit;margin:10px 0 0"><?= e($cmSample['body']) ?></pre></div><?php endif; ?>
<div class="card tight" id="campaign-recipients"><table><tr><th><?= te('Person') ?></th><th><?= te('Email') ?></th><th><?= te('Status') ?></th></tr>
  <?php foreach ($cmRecipients as $r): ?><tr data-recipient="<?= (int) $r['candidate_id'] ?>" data-status="<?= e($r['status']) ?>"><td><a href="/candidates/<?= (int) $r['candidate_id'] ?>"><?= e($r['full_name']) ?></a></td><td class="small"><?= e($r['email']) ?></td><td><?= e($cmOne[$r['status']]) ?><?= $r['error'] ? ' <span class="small muted">(' . e($r['error']) . ')</span>' : '' ?></td></tr><?php endforeach; ?>
</table></div>
<?php endif; ?>
