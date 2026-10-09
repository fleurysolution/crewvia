<?php
$roleNames = roles();

/** A supervisor carrying more than this is worth looking at. */
const COMFORTABLE_CREW = 12;

$statusTag = static fn (string $s): string => match ($s) {
    'on_site'    => 'green',
    'travelling' => 'amber',
    'confirmed'  => 'blue',
    default      => 'grey',
};

$assignForm = static function (array $member, array $supervisors): void { ?>
  <form method="post" action="/operations" class="assign-form">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="assignment">
    <input type="hidden" name="placement_id" value="<?= (int) $member['id'] ?>">

    <label class="sr-only" for="sv-<?= (int) $member['id'] ?>">
      <?= te('Supervisor for :name', ['name' => $member['full_name']]) ?>
    </label>
    <select id="sv-<?= (int) $member['id'] ?>" name="supervisor_id">
      <option value=""><?= te('No supervisor') ?></option>
      <?php foreach ($supervisors as $sv): ?>
        <option value="<?= (int) $sv['id'] ?>"
          <?= (int) ($member['supervisor_id'] ?? 0) === (int) $sv['id'] ? 'selected' : '' ?>>
          <?= e($sv['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label class="sr-only" for="tr-<?= (int) $member['id'] ?>"><?= te('Trade') ?></label>
    <input id="tr-<?= (int) $member['id'] ?>" name="trade" maxlength="60" required
           value="<?= e($member['trade'] ?? '') ?>" placeholder="<?= te('Trade') ?>">

    <label class="sr-only" for="sh-<?= (int) $member['id'] ?>"><?= te('Shift') ?></label>
    <input id="sh-<?= (int) $member['id'] ?>" name="shift_label" maxlength="60"
           value="<?= e($member['shift_label'] ?? '') ?>" placeholder="<?= te('Shift') ?>">

    <button class="btn sm" type="submit"><?= te('Save') ?></button>
  </form>
<?php };
?>

<div class="page-heading">
  <div>
    <span class="eyebrow"><?= te('ORGANIZATION') ?></span>
    <h1><?= e($job['title'] ?? t('Select a project')) ?></h1>
    <p class="sub">
      <?= te(':crew on the job, :teams supervisors, :onsite on site right now', [
          'crew' => count($crew), 'teams' => count($teams), 'onsite' => $onSite]) ?>
    </p>
  </div>
  <a class="btn ghost" href="/manning"><?= te('Manning') ?></a>
</div>

<!-- ── the desks above the site ────────────────────────────────────── -->
<section class="card">
  <span class="eyebrow"><?= te('THE OFFICE') ?></span>
  <h2><?= te('Who to call') ?></h2>
  <p class="hint" style="margin:-4px 0 14px">
    <?= te('A crew with nobody at a desk has nobody to call when a room falls through at nine at night.') ?>
  </p>

  <div class="grid g4">
    <?php foreach (['admin' => 'admin', 'recruiter' => 'recruiter',
                    'hotels' => 'hotels', 'payroll' => 'payroll'] as $slug => $ignored): ?>
      <div class="desk-card">
        <strong><?= te($roleNames[$slug] ?? $slug) ?></strong>
        <?php if (empty($office[$slug])): ?>
          <span class="tag red"><?= te('Nobody') ?></span>
        <?php else: ?>
          <span class="muted small"><?= e($office[$slug]['who']) ?></span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ── nobody's responsibility ─────────────────────────────────────── -->
<?php if ($unassigned): ?>
<section class="card" style="border-color:#F0C4C1;background:#FDEEED">
  <span class="eyebrow" style="color:var(--red)"><?= te('NOBODY IS CARRYING THESE') ?></span>
  <h2 style="color:var(--red)">
    <?= te(':n with no supervisor', ['n' => count($unassigned)]) ?>
  </h2>
  <p class="small" style="margin:-4px 0 14px">
    <?= te('If something happens to one of them on site, there is no name against them. Give each one a supervisor and a trade.') ?>
  </p>

  <ul class="org-crew">
    <?php foreach ($unassigned as $member): ?>
      <li>
        <span class="org-person">
          <strong><?= e($member['full_name']) ?></strong>
          <span class="muted small">
            <?= te(ucfirst((string) ($member['discipline'] ?: 'other'))) ?>
            <?php if ($member['phone']): ?>
              &middot; <span class="mono"><?= e($member['phone']) ?></span>
            <?php endif; ?>
          </span>
        </span>
        <?php if (can('recruiter')): ?>
          <?php $assignForm($member, $possibleSupervisors); ?>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<!-- ── the crews ───────────────────────────────────────────────────── -->
<?php if (! $teams && ! $unassigned): ?>
  <div class="card">
    <div class="empty">
      <?= te('Nobody is on this job yet. People appear here once they accept an offer and a placement is created.') ?>
      <p><a class="btn ghost" href="/roster"><?= te('Open the roster') ?></a></p>
    </div>
  </div>
<?php endif; ?>

<?php foreach ($teams as $supervisorId => $team): ?>
  <?php $size = count($team['members']); ?>
  <section class="card">
    <div class="page-heading" style="margin:0 0 12px">
      <div>
        <span class="eyebrow"><?= te('CREW') ?></span>
        <h2 style="margin:4px 0 0"><?= e($team['supervisor']) ?></h2>
      </div>
      <span class="tag <?= $size > COMFORTABLE_CREW ? 'amber' : 'blue' ?>">
        <?= te(':n people', ['n' => $size]) ?>
        <?php if ($size > COMFORTABLE_CREW): ?>
          &middot; <?= te('heavy') ?>
        <?php endif; ?>
      </span>
    </div>

    <?php if ($size > COMFORTABLE_CREW): ?>
      <p class="hint" style="margin:-6px 0 12px;color:var(--amber)">
        <?= te('More than :n people under one supervisor. Consider a second crew before the next wave arrives.',
               ['n' => COMFORTABLE_CREW]) ?>
      </p>
    <?php endif; ?>

    <ul class="org-crew">
      <?php foreach ($team['members'] as $member): ?>
        <li>
          <span class="org-person">
            <strong><?= e($member['full_name']) ?></strong>
            <span class="muted small">
              <?= e($member['trade'] ?: t('Trade not set')) ?>
              <?php if ($member['shift_label']): ?>&middot; <?= e($member['shift_label']) ?><?php endif; ?>
            </span>
          </span>
          <span class="org-side">
            <span class="tag <?= $statusTag((string) $member['status']) ?>">
              <?= te(ucfirst(str_replace('_', ' ', (string) $member['status']))) ?>
            </span>
          </span>
          <?php if (can('recruiter')): ?>
            <?php $assignForm($member, $possibleSupervisors); ?>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endforeach; ?>
