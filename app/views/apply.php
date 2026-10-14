<?php require_once __DIR__ . '/../screening.php'; ?>
<div class="page-head"><div><h1><?= e($vacancy['title']) ?></h1></div></div><p><?= e($vacancy['project']) ?></p><div class="card"><?= nl2br(e($vacancy['description'])) ?></div><form class="card" method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="vacancy_id" value="<?= (int)$vacancy['id'] ?>"><label><?= te('Full name') ?></label><input name="name" maxlength="190" required><label><?= te('Email') ?></label><input type="email" name="email" maxlength="190" required><label><?= te('Phone') ?></label><input name="phone" maxlength="40"><div class="row"><div><label for="ap-city"><?= te('City') ?></label><input id="ap-city" name="city" maxlength="120" autocomplete="address-level2"></div><div><label for="ap-state"><?= te('State') ?></label><input id="ap-state" class="in-s" name="state" maxlength="60" autocomplete="address-level1"></div><div><label for="ap-zip"><?= te('ZIP code') ?></label><input id="ap-zip" class="in-s" name="zip" maxlength="10" inputmode="numeric" autocomplete="postal-code"></div></div><p class="hint m0"><?= te('Where you live, so we can tell you about work near you. Optional.') ?></p><label><?= te('Resume · PDF or DOCX, maximum 5 MB') ?><input name="resume" type="file" accept=".pdf,.docx"></label>
<?php if ($screening): ?>
  <h2 class="mt-xl"><?= te('A few questions') ?></h2>
  <p class="hint m0 pull-up mb-l">
    <?= te('These decide whether this job is a fit, so answer them as they are.') ?>
  </p>

  <?php require __DIR__ . '/_screening-fields.php'; ?>
<?php endif; ?>

<label><input class="w-auto" type="checkbox" name="consent" required> <?= te('I authorize the hiring agency to review my application and contact me about opportunities.') ?></label> <a href="/privacy" target="_blank" rel="noopener"><?= te('Read the privacy notice') ?></a><button class="btn"><?= te('Apply') ?></button></form>
<?php if($jobSchema): ?><script type="application/ld+json"><?= json_encode($jobSchema,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES) ?></script><?php endif; ?>
