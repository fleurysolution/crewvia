<?php /** P5-M104: the four emails every applicant receives, as the agency writes them. */ ?>
<div class="page-head"><div>
  <h1><?= te('Emails to applicants') ?></h1>
  <p class="sub"><?= te('Every applicant receives these, whatever the way they applied: once each per application. What was sent is on the applicant\'s record.') ?></p>
</div>
<form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="do" value="locale">
  <label class="small" for="ce-locale"><?= te('Written in') ?></label>
  <select id="ce-locale" name="locale" onchange="this.form.submit()"><?php foreach (['en' => 'English', 'fr' => 'Français', 'es' => 'Español'] as $k => $l): ?><option value="<?= $k ?>"<?= $k === $locale ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
</form></div>

<?php if ($testMode): ?><div class="flash err" id="ce-test-mode"><?= te('Test mode is on: these emails go to the test inbox, not to the applicants.') ?> <a href="/email-delivery"><?= te('Email delivery') ?></a></div><?php endif; ?>

<?php if ($held > 0): ?>
<section class="card" id="held">
  <div class="flash err m0" role="alert" data-held="<?= (int) $held ?>"><?= tn($held, ':n refusal is held: it was one of many rejected together. Nothing is sent until you decide.', ':n refusals are held: they were rejected together, many at once. Nothing is sent until you decide.') ?></div>
  <div class="actions mt-s">
    <form method="post" class="inline-block"><?= csrf_field() ?><input type="hidden" name="do" value="held_cancel"><button class="btn" data-held-cancel><?= tn($held, 'Do not send it', 'Do not send them') ?></button></form>
    <form method="post" class="inline-block"><?= csrf_field() ?><input type="hidden" name="do" value="held_send"><button class="btn ghost" data-held-send><?= tn($held, 'Send it', 'Send them') ?></button></form>
  </div>
  <p class="small muted m0 mt-xs"><?= te('Sent, each leaves 24 hours after its rejection. For a clean-up of old applications, choose Do not send them.') ?></p>
</section>
<?php endif; ?>

<p class="small muted"><?= te('In the text, {first_name}, {job_title} and {agency} are replaced for each applicant.') ?></p>

<?php foreach ($templates as $key => $t): ?>
<section class="card" id="ce-<?= e($key) ?>" data-template="<?= e($key) ?>">
  <div class="row between">
    <div><h2 class="m0"><?= e(ce_labels()[$key]) ?></h2><p class="small muted m0"><?= e(ce_when()[$key]) ?></p></div>
    <p class="small m0 nowrap"><?= $t['enabled'] ? '<span class="tag green">' . te('On') . '</span>' : '<span class="tag grey">' . te('Off') . '</span>' ?>
      &middot; <?= tn($t['sent_30d'], ':n sent in 30 days', ':n sent in 30 days') ?><?php if ($t['waiting']): ?> &middot; <?= tn($t['waiting'], ':n waiting its 24 hours', ':n waiting their 24 hours') ?><?php endif; ?></p>
  </div>
  <div class="grid g2 mt-s">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="template_key" value="<?= e($key) ?>">
      <label for="ce-s-<?= e($key) ?>"><?= te('Subject') ?></label><input id="ce-s-<?= e($key) ?>" name="subject" maxlength="255" required value="<?= e($t['subject']) ?>">
      <label class="mt-s" for="ce-b-<?= e($key) ?>"><?= te('Message') ?></label><textarea id="ce-b-<?= e($key) ?>" name="body" rows="9" maxlength="5000" required><?= e($t['body']) ?></textarea>
      <label class="own-room mt-s"><input class="w-auto" type="checkbox" name="enabled" value="1"<?= $t['enabled'] ? ' checked' : '' ?>> <?= te('Send this email') ?></label>
      <div class="actions mt-s"><button class="btn sm"><?= te('Save') ?></button></div>
    </form>
    <div>
      <p class="small muted m0"><?= te('Preview') ?></p>
      <div class="card tight mt-xs" data-preview="<?= e($key) ?>"><strong><?= e($t['preview_subject']) ?></strong><div class="small mt-xs"><?= nl2br(e($t['preview_body'])) ?></div></div>
      <?php if ($t['edited']): ?><form method="post" class="mt-s"><?= csrf_field() ?><input type="hidden" name="do" value="reset"><input type="hidden" name="template_key" value="<?= e($key) ?>"><button class="btn ghost sm"><?= te('Back to the default text') ?></button></form><?php endif; ?>
    </div>
  </div>
</section>
<?php endforeach; ?>
