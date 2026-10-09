<?php
/**
 * Application screening: every application on this project, and the one
 * decision each of them is waiting for.
 *
 * The stage menu had no selected option, so it always opened on "new". A
 * recruiter opening an application that was at interview, typing a
 * decision note and pressing Update sent that person back to the start of
 * the pipeline - silently, because "new" is a valid stage and the handler
 * had no reason to refuse it. The menu now opens on where the application
 * actually is, and a settled application is not offered a move at all.
 *
 * Stages were also printed as the database stores them, so this screen
 * said "new" and "withdrawn" in lower case while every other screen in
 * the application says them in words.
 */

$appStages = ['new' => 'New', 'screening' => 'Screening', 'interview' => 'Interview',
              'offered' => 'Offered', 'accepted' => 'Accepted',
              'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn'];

$stageTag = static fn (string $s): string => match ($s) {
    'accepted'              => 'green',
    'offered', 'interview'  => 'amber',
    'screening'             => 'blue',
    'rejected', 'withdrawn' => 'red',
    default                 => 'grey',
};

// An application that has been settled is not waiting for a decision.
$settled = ['accepted', 'rejected', 'withdrawn'];
?>

<div class="page-heading">
  <div>
    <span class="eyebrow"><?= te('RECRUITING') ?></span>
    <h1><?= te('Application screening') ?></h1>
    <p class="sub">
      <?= te('Every application on this project. A person and an application are separate records: the same person can apply twice, and each application carries its own stage and its own screening.') ?>
    </p>
  </div>
  <div class="heading-actions">
    <a class="btn ghost" href="/screening-workflow"><?= te('Questionnaire & contacts') ?></a>
    <a class="btn ghost" href="/contracts"><?= te('Contracts & signatures') ?></a>
  </div>
</div>

<?php if (! $applications): ?>
  <div class="card"><div class="empty">
    <?= te('No applications yet. Create a requisition and share its QR code or public link, or invite somebody from the talent pool.') ?>
    <p style="margin-top:10px"><a class="btn" href="/requisitions"><?= te('Create a requisition') ?></a></p>
  </div></div>
<?php endif; ?>

<?php foreach ($applications as $a): ?>
  <?php $isSettled = in_array($a['stage'], $settled, true); ?>
  <article class="card">
    <div class="page-heading" style="margin:0 0 12px">
      <div>
        <h2 style="margin:0">
          <a href="/candidates/<?= (int) $a['candidate_id'] ?>"><?= e($a['full_name']) ?></a>
        </h2>
        <p class="small muted" style="margin:4px 0 0">
          <?= te('Applied for :role', ['role' => e($a['title'])]) ?>
          <?php if (! empty($a['created_at'])): ?>
            &middot; <?= te('applied :when', ['when' => d($a['created_at'])]) ?>
          <?php endif; ?>
          <?php if (isset($a['rate']) && $a['rate'] !== null): ?>
            &middot; <?= te(':amount an hour agreed', ['amount' => money((float) $a['rate'])]) ?>
          <?php else: ?>
            &middot; <span style="color:var(--amber)"><?= te('no agreed rate behind this order') ?></span>
          <?php endif; ?>
        </p>
      </div>
      <div class="heading-actions">
        <span class="tag <?= $stageTag((string) $a['stage']) ?>">
          <?= te($appStages[$a['stage']] ?? $a['stage']) ?>
        </span>
        <?php if ($a['screening_score'] !== null): ?>
          <span class="tag <?= (float) $a['screening_score'] >= 70 ? 'green'
                              : ((float) $a['screening_score'] >= 40 ? 'amber' : 'grey') ?>">
            <?= (int) round((float) $a['screening_score']) ?>%
          </span>
        <?php endif; ?>
        <?php if (! empty($a['screened_out'])): ?>
          <span class="tag red"><?= te('Screened out') ?></span>
        <?php endif; ?>
        <a class="btn ghost sm" href="/candidates/<?= (int) $a['candidate_id'] ?>">
          <?= te('Everything about this person') ?>
        </a>
      </div>
    </div>

    <?php if (! empty($a['screened_out'])): ?>
      <p class="small" style="color:var(--red);margin:0 0 12px"><?= e($a['screened_out_why']) ?></p>
    <?php endif; ?>

    <?php foreach ($identityMatches as $match): ?>
      <?php if ((int) $match['applicant_id'] !== (int) $a['candidate_id']) { continue; } ?>
      <p class="flash err">
        <?= te('Possible existing candidate:') ?>
        <a href="/employee-folder?id=<?= (int) $match['id'] ?>">
          <?= e($match['full_name'] . ' · ' . $match['stage']) ?>
        </a>
        <?= te('. Verify identity and prior decisions before continuing. A matching contact or name does not prove identity.') ?>
      </p>
    <?php endforeach; ?>

    <div class="grid g2">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="stage">
        <input type="hidden" name="application_id" value="<?= (int) $a['id'] ?>">

        <label for="st-<?= (int) $a['id'] ?>"><?= te('Stage') ?></label>
        <select id="st-<?= (int) $a['id'] ?>" name="stage" <?= $isSettled ? 'disabled' : '' ?>>
          <?php foreach (['new', 'screening', 'interview', 'offered', 'rejected', 'withdrawn'] as $stage): ?>
            <option value="<?= e($stage) ?>" <?= $a['stage'] === $stage ? 'selected' : '' ?>>
              <?= te($appStages[$stage]) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <label for="nt-<?= (int) $a['id'] ?>"><?= te('Decision note') ?></label>
        <input id="nt-<?= (int) $a['id'] ?>" name="note" maxlength="2000"
               placeholder="<?= te('Why. Recorded against the application.') ?>">

        <?php if ($isSettled): ?>
          <p class="hint" style="margin:4px 0 0">
            <?= te('This application is settled, so its stage no longer moves. Raise a new application for the same person if they come back.') ?>
          </p>
        <?php else: ?>
          <button class="btn" type="submit"><?= te('Update application') ?></button>
        <?php endif; ?>
      </form>

      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="check">
        <input type="hidden" name="application_id" value="<?= (int) $a['id'] ?>">
        <label for="ck-<?= (int) $a['id'] ?>"><?= te('Screening requirement') ?></label>
        <input id="ck-<?= (int) $a['id'] ?>" name="title" required maxlength="190"
               placeholder="<?= te('e.g. Confined space certificate seen') ?>">
        <span class="hint"><?= te('Every requirement must pass before an offer can be made.') ?></span>
        <button class="btn ghost" type="submit"><?= te('Add check') ?></button>
      </form>
    </div>

    <?php
    $mine = array_values(array_filter($checks,
        static fn ($s) => (int) $s['application_id'] === (int) $a['id']));
    ?>
    <?php if ($mine): ?>
      <h3 style="margin:18px 0 8px"><?= te('Screening requirements') ?></h3>
      <?php foreach ($mine as $s): ?>
        <form class="row check-row" method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="review">
          <input type="hidden" name="application_id" value="<?= (int) $a['id'] ?>">
          <input type="hidden" name="check_id" value="<?= (int) $s['id'] ?>">
          <span class="tag <?= $s['status'] === 'passed' ? 'green'
                              : ($s['status'] === 'failed' ? 'red' : 'grey') ?>">
            <?= te(ucfirst((string) $s['status'])) ?>
          </span>
          <span class="check-title"><?= e($s['title']) ?></span>
          <label class="sr-only" for="cs-<?= (int) $s['id'] ?>"><?= te('Outcome') ?></label>
          <select id="cs-<?= (int) $s['id'] ?>" name="status" class="move-select">
            <?php foreach (['pending' => 'Pending', 'passed' => 'Passed', 'failed' => 'Failed'] as $k => $v): ?>
              <option value="<?= e($k) ?>" <?= $s['status'] === $k ? 'selected' : '' ?>><?= te($v) ?></option>
            <?php endforeach; ?>
          </select>
          <input name="note" maxlength="500" placeholder="<?= te('Review notes') ?>"
                 value="<?= e($s['note'] ?? '') ?>">
          <button class="btn sm" type="submit"><?= te('Review') ?></button>
        </form>
      <?php endforeach; ?>
    <?php endif; ?>
  </article>
<?php endforeach; ?>

<?= page_controls($page, count($applications)) ?>
