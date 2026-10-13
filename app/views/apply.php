<?php require_once __DIR__ . '/../screening.php'; ?>
<h1><?= e($vacancy['title']) ?></h1><p><?= e($vacancy['project']) ?></p><div class="card"><?= nl2br(e($vacancy['description'])) ?></div><form class="card" method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="vacancy_id" value="<?= (int)$vacancy['id'] ?>"><label><?= te('Full name') ?></label><input name="name" maxlength="190" required><label><?= te('Email') ?></label><input type="email" name="email" maxlength="190" required><label><?= te('Phone') ?></label><input name="phone" maxlength="40"><label><?= te('Resume · PDF or DOCX, maximum 5 MB') ?><input name="resume" type="file" accept=".pdf,.docx"></label>
<?php if ($screening): ?>
  <h2 style="margin-top:24px"><?= te('A few questions') ?></h2>
  <p class="hint" style="margin:-4px 0 16px">
    <?= te('These decide whether this job is a fit, so answer them as they are.') ?>
  </p>

  <?php require __DIR__ . '/_screening-fields.php'; ?>
<?php endif; ?>

<label><input type="checkbox" name="consent" required style="width:auto"> <?= te('I authorize the hiring agency to review my application and contact me about opportunities.') ?></label> <a href="/privacy" target="_blank" rel="noopener"><?= te('Read the privacy notice') ?></a><button class="btn"><?= te('Apply') ?></button></form>
<?php if($jobSchema): ?><script type="application/ld+json"><?= json_encode($jobSchema,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES) ?></script><?php endif; ?>
