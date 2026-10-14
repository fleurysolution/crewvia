<?php
$jmStage = ['new' => t('Received'), 'screening' => t('Screening'), 'interview' => t('Interview'), 'offered' => t('Offered'), 'accepted' => t('Hired'), 'rejected' => t('Not selected'), 'withdrawn' => t('You took it back')];
$jmOpen = array_filter($jmJobs, static fn($j) => ! $j['application'] || $j['application']['stage'] === 'withdrawn');
$jmMine = array_filter($jmJobs, static fn($j) => $j['application'] && $j['application']['stage'] !== 'withdrawn');
?>
<div class="page-head"><div><h1><?= te('Jobs for you') ?></h1>
  <p class="sub"><?= te('Open jobs that fit your file: your trade and your years of experience. Say you are interested and a recruiter will contact you.') ?></p></div></div>

<?php if ($jmMine): ?>
<div class="card" id="my-interests"><h2><?= te('Jobs you are interested in') ?></h2>
  <?php foreach ($jmMine as $j): $a = $j['application']; ?><div class="row-item cols-fill-auto" data-interested="<?= (int) $j['id'] ?>">
    <div><strong><?= e($j['title']) ?></strong> <span class="tag <?= $a['stage'] === 'rejected' ? 'grey' : 'blue' ?>" data-stage="<?= e($a['stage']) ?>"><?= e($jmStage[$a['stage']] ?? $a['stage']) ?></span>
      <div class="small muted"><?= e(trim(($j['site_city'] ?? '') . ', ' . ($j['site_state'] ?? ''), ', ')) ?> · <?= te('since :date', ['date' => d(substr((string) $a['created_at'], 0, 10))]) ?></div></div>
    <div><?php if ($a['stage'] === 'new'): ?><form method="post" action="/my-jobs"><?= csrf_field() ?><input type="hidden" name="do" value="withdraw"><input type="hidden" name="application_id" value="<?= (int) $a['id'] ?>"><button class="btn ghost sm"><?= te('No longer interested') ?></button></form><?php endif; ?></div>
  </div><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (! $jmOpen): ?>
<div class="card empty-state" id="no-jobs-for-me"><h3><?= te('No open job fits your file right now') ?></h3><p><?= te('New jobs appear here as soon as they open. Keep your trade and experience up to date with your recruiter.') ?></p></div>
<?php else: ?>
<div id="jobs-for-me">
<?php foreach ($jmOpen as $j): $screening = $j['questions']; $sqPrefix = 'v' . (int) $j['id'] . '-'; ?>
  <section class="card" data-job="<?= (int) $j['id'] ?>">
    <h2 class="mb-xs"><?= e($j['title']) ?></h2>
    <div class="small muted"><?= e(trim(($j['site_city'] ?? '') . ', ' . ($j['site_state'] ?? ''), ', ') ?: t('Location to be confirmed')) ?><?= $j['miles'] !== null ? ' · <span data-miles="' . (int) $j['id'] . '">' . te('≈ :n mi from you', ['n' => (int) $j['miles']]) . '</span>' : '' ?>
      <?php $starts = $j['starts_on'] ?: $j['job_starts']; ?><?= $starts ? ' · ' . e(starts_words((string) $starts)) : '' ?><?= $j['shift'] ? ' · ' . e($j['shift']) : '' ?></div>
    <p class="m0 mt-s mb-s">
      <?php if ($j['pay_rate'] !== null): ?><span class="tag green"><?= te(':rate an hour', ['rate' => money((float) $j['pay_rate'])]) ?></span><?php endif; ?>
      <?php if ($j['per_diem_rate'] !== null && (float) $j['per_diem_rate'] > 0): ?><span class="tag"><?= te('per diem :rate', ['rate' => money((float) $j['per_diem_rate'])]) ?></span><?php endif; ?>
      <?php if ((int) $j['lodging_provided']): ?><span class="tag"><?= te('Lodging provided') ?></span><?php endif; ?>
      <?php if ((int) $j['travel_provided']): ?><span class="tag"><?= te('Travel provided') ?></span><?php endif; ?>
    </p>
    <?php if (trim((string) $j['description']) !== ''): ?><p class="small"><?= nl2br(e(mb_strimwidth((string) $j['description'], 0, 600, '…'))) ?></p><?php endif; ?>
    <?php if (trim((string) $j['requirements']) !== ''): ?><p class="small"><strong><?= te('What it asks') ?>:</strong> <?= nl2br(e((string) $j['requirements'])) ?></p><?php endif; ?>
    <?php foreach ($j['notes'] as $note): ?><p class="small muted" data-note="<?= (int) $j['id'] ?>">ⓘ <?= e($note) ?></p><?php endforeach; ?>
    <form method="post" action="/my-jobs" data-interest-form="<?= (int) $j['id'] ?>"><?= csrf_field() ?><input type="hidden" name="do" value="interest"><input type="hidden" name="vacancy_id" value="<?= (int) $j['id'] ?>">
      <?php if ($screening): ?><h3 class="small m0 mt mb-xs"><?= te('A few questions for this job') ?></h3><?php require __DIR__ . '/_screening-fields.php'; ?><?php endif; ?>
      <button class="btn mt-s"><?= te('I\'m interested') ?></button>
    </form>
  </section>
<?php endforeach; ?>
</div>
<?php endif; ?>
