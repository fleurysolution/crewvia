<?php
/**
 * The public application page: what a job poster's QR code opens (P5-M45).
 * Read on a phone more than anywhere, so the facts a person decides on come
 * first (where, what it pays, when it starts), then a short form in plain
 * parts, ending with when they can start and their consent.
 */
require_once __DIR__ . '/../screening.php';
require_once __DIR__ . '/../availability.php';
$place = trim(($vacancy['site_city'] ?? '') . ', ' . ($vacancy['site_state'] ?? ''), ', ');
$starts = $vacancy['starts_on'] ?? null;
?>
<div class="page-head"><div><h1><?= e($vacancy['title']) ?></h1>
  <p class="sub m0"><?= e($vacancy['project']) ?><?= $place !== '' ? ' · ' . e($place) : '' ?></p></div></div>
<p id="job-facts">
  <?php if (isset($vacancy['pay_rate']) && $vacancy['pay_rate'] !== null): ?><span class="tag green"><?= te(':rate an hour', ['rate' => money((float) $vacancy['pay_rate'])]) ?></span><?php endif; ?>
  <?php if (isset($vacancy['per_diem_rate']) && (float) $vacancy['per_diem_rate'] > 0): ?><span class="tag"><?= te('per diem :rate', ['rate' => money((float) $vacancy['per_diem_rate'])]) ?></span><?php endif; ?>
  <?php if ($starts): ?><span class="tag"><?= e(starts_words((string) $starts)) ?></span><?php endif; ?>
  <?php if (! empty($vacancy['openings'])): ?><span class="tag"><?= ten((int) $vacancy['openings'], ':n opening', ':n openings') ?></span><?php endif; ?>
</p>
<?php if (trim((string) $vacancy['description']) !== ''): ?><div class="card"><?= nl2br(e($vacancy['description'])) ?></div><?php endif; ?>

<form class="card" method="post" enctype="multipart/form-data" id="apply-form"><?= csrf_field() ?><input type="hidden" name="vacancy_id" value="<?= (int) $vacancy['id'] ?>">
  <h2><?= te('Apply') ?></h2>
  <h3><?= te('About you') ?></h3>
  <div class="field"><label for="ap-name"><?= te('Full name') ?></label><input id="ap-name" name="name" maxlength="190" required autocomplete="name"></div>
  <div class="row">
    <div class="f2"><label for="ap-email"><?= te('Email') ?></label><input id="ap-email" type="email" name="email" maxlength="190" required autocomplete="email"></div>
    <div><label for="ap-phone"><?= te('Phone') ?></label><input id="ap-phone" type="tel" name="phone" maxlength="40" autocomplete="tel"></div>
  </div>
  <div class="row mt-s"><div class="f2"><label for="ap-city"><?= te('City') ?></label><input id="ap-city" name="city" maxlength="120" autocomplete="address-level2"></div><div><label for="ap-state"><?= te('State') ?></label><input id="ap-state" class="in-s" name="state" maxlength="60" autocomplete="address-level1"></div><div><label for="ap-zip"><?= te('ZIP code') ?></label><input id="ap-zip" class="in-s" name="zip" maxlength="10" inputmode="numeric" autocomplete="postal-code"></div></div>
  <p class="hint m0"><?= te('Where you live, so we can tell you about work near you. Optional.') ?></p>

  <h3 class="mt-l"><?= te('Your CV') ?></h3>
  <label><?= te('Resume · PDF or DOCX, maximum 5 MB') ?><input name="resume" type="file" accept=".pdf,.docx"></label>
<?php if ($screening): ?>
  <h3 class="mt-l"><?= te('A few questions') ?></h3>
  <p class="hint m0 pull-up mb-l"><?= te('These decide whether this job is a fit, so answer them as they are.') ?></p>
  <?php require __DIR__ . '/_screening-fields.php'; ?>
<?php endif; ?>

  <h3 class="mt-l"><?= te('When can you start?') ?></h3>
  <div class="row" id="apply-availability">
    <?php foreach (availability_choices() as $k => $l): ?><label class="own-room"><input class="w-auto" type="radio" name="availability" value="<?= e($k) ?>"<?= $k === 'available' ? ' checked' : '' ?>> <?= e($l) ?></label><?php endforeach; ?>
    <div><label for="ap-from"><?= te('From') ?></label><input id="ap-from" type="date" name="available_from" min="<?= e(date('Y-m-d')) ?>"></div>
  </div>

  <p class="mt-l"><label><input class="w-auto" type="checkbox" name="consent" required> <?= te('I authorize the hiring agency to review my application and contact me about opportunities.') ?></label> <a href="/privacy" target="_blank" rel="noopener"><?= te('Read the privacy notice') ?></a></p>
  <button class="btn"><?= te('Apply') ?></button>
</form>
<?php if ($jobSchema): ?><script type="application/ld+json"><?= json_encode($jobSchema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script><?php endif; ?>
