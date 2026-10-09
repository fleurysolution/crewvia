<?php
/**
 * What somebody sees when they are sent the link. No account, no jargon:
 * the work, where it is, what it pays and what is provided.
 */
?>

<div class="page-heading">
  <div>
    <span class="eyebrow"><?= te('OPEN POSITIONS') ?></span>
    <h1>
      <?= $openings
          ? te('We are hiring now')
          : te('Nothing open at the moment') ?>
    </h1>
    <p class="sub">
      <?= te('Short-notice industrial assignments. Travel, lodging and a daily allowance are provided — the detail is on each position.') ?>
    </p>
  </div>
</div>

<?php if (! $openings): ?>
  <div class="card">
    <div class="empty">
      <?= te('No positions are open right now. These jobs appear at short notice, so it is worth checking again.') ?>
    </div>
  </div>
<?php endif; ?>

<div class="grid g2">
<?php foreach ($openings as $o): ?>
  <?php
  // What this trade was actually agreed at: the requisition's own number, or
  // the one on its line of the scope of work. No project-wide fallback, so
  // nobody is ever quoted another trade's rate.
  $pay  = $o['pay_rate']      ?? $o['line_pay'];
  $diem = $o['per_diem_rate'] ?? $o['line_diem'];

  $lineGuarantee = $o['strike_live'] && $o['line_strike_guarantee'] !== null
      ? $o['line_strike_guarantee']
      : $o['line_guarantee'];

  $guar  = $o['guarantee_hours'] ?? $lineGuarantee;
  $where = trim(($o['site_city'] ?? '') . ', ' . ($o['site_state'] ?? ''), ' ,');
  $start = $o['starts_on'] ?: $o['job_starts'];

  // Only what the agreement actually covers is promised. This line used to
  // read "Hotel and travel paid" on every advert whether or not it was true.
  $covers = [];

  if (! empty($o['lodging_provided']))   { $covers[] = t('Hotel paid'); }
  if (! empty($o['travel_provided']))    { $covers[] = t('Travel to and from site paid'); }
  if (! empty($o['transport_provided'])) { $covers[] = t('Transport on site provided'); }
  ?>
  <article class="card opening">
    <h2><?= e($o['title']) ?></h2>

    <p class="muted small" style="margin:-4px 0 14px">
      <?= te(ucfirst((string) ($o['discipline'] ?: 'other'))) ?>
      <?php if ($where !== ''): ?>&middot; <?= e($where) ?><?php endif; ?>
      <?php if ($o['shift']): ?>&middot; <?= e($o['shift']) ?><?php endif; ?>
      <?php if ($start): ?>&middot; <?= te('starts :date', ['date' => d($start)]) ?><?php endif; ?>
    </p>

    <div class="opening-terms">
      <?php if ($pay !== null): ?>
        <span><strong><?= e(money((float) $pay)) ?></strong> <?= te('an hour') ?></span>
      <?php endif; ?>
      <?php if ($guar): ?>
        <span><strong><?= (int) $guar ?></strong> <?= te('hours guaranteed a week') ?></span>
      <?php endif; ?>
      <?php if ($diem !== null): ?>
        <span><strong><?= e(money((float) $diem)) ?></strong> <?= te('a day, on top') ?></span>
      <?php endif; ?>
      <?php foreach ($covers as $covered): ?>
        <span><?= e($covered) ?></span>
      <?php endforeach; ?>
    </div>

    <?php if ($pay === null): ?>
      <p class="small muted" style="margin:0 0 12px">
        <?= te('The rate for this position is confirmed when we speak to you.') ?>
      </p>
    <?php endif; ?>

    <?php if ($o['description']): ?>
      <p class="opening-text"><?= e($o['description']) ?></p>
    <?php endif; ?>

    <?php if ($o['job_description']): ?>
      <h3><?= te('About the assignment') ?></h3>
      <p class="opening-text"><?= e($o['job_description']) ?></p>
    <?php endif; ?>

    <?php if ($o['degree'] || $o['years_experience'] || $o['requirements']): ?>
      <h3><?= te('What you need') ?></h3>
      <ul class="plain">
        <?php if ($o['degree']): ?>
          <li><?= e($o['degree']) ?></li>
        <?php endif; ?>
        <?php if ($o['years_experience']): ?>
          <li><?= te(':n years of experience', ['n' => (int) $o['years_experience']]) ?></li>
        <?php endif; ?>
        <?php foreach (array_filter(array_map('trim', explode("\n", (string) $o['requirements']))) as $need): ?>
          <li><?= e($need) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <p style="margin-top:18px">
      <a class="btn" href="/apply?id=<?= (int) $o['id'] ?>"><?= te('Apply for this') ?></a>
      <span class="muted small">
        <?= te(':n needed', ['n' => (int) $o['openings']]) ?>
      </span>
    </p>
  </article>
<?php endforeach; ?>
</div>

<section class="card">
  <h2><?= te('How it works') ?></h2>
  <ol class="journey-list">
    <li><?= te('Apply with a personal email address — it takes a minute.') ?></li>
    <li><?= te('Somebody from recruiting calls you to go through the work and the terms.') ?></li>
    <li><?= te('If it is a fit, you get an offer in writing with the rate, the guaranteed hours and the allowance.') ?></li>
    <li><?= te('You sign the contract online, and we book your travel and your room.') ?></li>
    <li><?= te('Transport runs from the airport, to the site, and for food and errands.') ?></li>
  </ol>
</section>
