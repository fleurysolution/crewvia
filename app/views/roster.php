<?php
require_once __DIR__ . '/../hr.php';

/**
 * Who is on the job, and what is missing for each of them.
 *
 * Ten columns meant the screen scrolled sideways on a laptop and the one
 * column a recruiter opens this page for - what is still blocking somebody -
 * sat off the right edge. The facts are the same; they are grouped the way
 * somebody reads them: who, where they stand, what is booked, what is missing.
 */

$statuses = ['offered' => 'Offered', 'confirmed' => 'Confirmed', 'travelling' => 'Travelling',
             'on_site' => 'On site', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

$tagFor = static fn (string $s): string => match ($s) {
    'on_site'    => 'green',
    'travelling' => 'amber',
    'confirmed'  => 'blue',
    'offered'    => 'grey',
    'cancelled'  => 'red',
    default      => 'grey',
};

$deployed  = array_filter($crew, fn ($p) => in_array($p['status'], ['confirmed','travelling','on_site'], true));
$noBed     = array_filter($deployed, fn ($p) => ! $p['hotel']);
$sharing   = array_filter($deployed, fn ($p) => $p['hotel'] && (int) $p['private_room'] === 0);
$noFlight  = array_filter($crew, fn ($p) => ! $p['arrive_time']
                                           && in_array($p['status'], ['confirmed','travelling'], true));
$notClear  = array_filter($blockers ?? [], fn ($b) => $b !== []);
?>

<div class="page-heading">
  <div>
    <span class="eyebrow"><?= te('DEPLOYMENT') ?></span>
    <h1><?= te('Roster') ?></h1>
    <p class="sub">
      <?= te(':n on :project', ['n' => count($crew), 'project' => $job['title'] ?? t('the job')]) ?>
    </p>
  </div>
  <a class="btn ghost" href="/hotels"><?= te('Hotels & rooms') ?></a>
</div>

<?php if ($crew): ?>
<div class="grid g4" style="margin-bottom:18px">
  <div class="stat">
    <div class="n"><?= count($deployed) ?></div>
    <div class="l"><?= te('Deployed') ?></div>
  </div>
  <div class="stat">
    <div class="n" style="color:<?= $notClear ? 'var(--amber)' : 'var(--green)' ?>"><?= count($notClear) ?></div>
    <div class="l"><?= te('Blocked from deploying') ?></div>
  </div>
  <div class="stat">
    <div class="n" style="color:<?= $noBed ? 'var(--red)' : 'var(--green)' ?>"><?= count($noBed) ?></div>
    <div class="l"><?= te('Without a bed') ?></div>
    <?php if ($sharing): ?>
      <div class="h"><?= te(':n sharing a room', ['n' => count($sharing)]) ?></div>
    <?php endif; ?>
  </div>
  <div class="stat">
    <div class="n" style="color:<?= $noFlight ? 'var(--amber)' : 'var(--green)' ?>"><?= count($noFlight) ?></div>
    <div class="l"><?= te('Travel not booked') ?></div>
  </div>
  <?php
  // A finished assignment nobody graded is the gap RSS described: "we
  // don't always get that".
  $finished   = array_filter($crew, fn ($p) => $p['status'] === 'completed');
  $ungraded   = array_filter($finished, fn ($p) => ! isset($reviews[(int) $p['id']]));
  ?>
  <div class="stat">
    <div class="n" style="color:<?= $ungraded ? 'var(--amber)' : 'var(--green)' ?>"><?= count($ungraded) ?></div>
    <div class="l"><?= te('Finished, not graded') ?></div>
    <?php if ($finished): ?>
      <div class="h"><?= te(':n finished on this job', ['n' => count($finished)]) ?></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card tight">
  <?php if (! $crew): ?>
    <div class="empty">
      <?= te('Nobody placed yet. A candidate who accepts their offer lands here.') ?>
      <p><a class="btn ghost" href="/candidates"><?= te('Open the candidate board') ?></a></p>
    </div>
  <?php else: ?>
  <div class="scroll">
  <table class="roster-table wide">
    <thead><tr>
      <th><?= te('Person') ?></th>
      <th><?= te('Status') ?></th>
      <th><?= te('Ready to deploy') ?></th>
      <th><?= te('Bed and travel') ?></th>
      <?php if (can('recruiter')): ?><th class="right nowrap"><?= te('Move') ?></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($crew as $p): ?>
      <tr>
        <td>
          <a href="/placements/<?= (int) $p['id'] ?>"><strong><?= e($p['full_name']) ?></strong></a>
          <div class="muted small">
            <?php
            // What they were ordered as, which is not always their
            // discipline: a mechanical engineer can be on the order as a
            // millwright, and the roster should say what the client asked
            // for rather than what the person is filed under.
            $trade = trim((string) ($p['trade'] ?? ''));
            ?>
            <?= e($trade !== '' ? $trade : (disciplines(true)[$p['discipline']] ?? ucfirst((string) $p['discipline']))) ?>
          </div>
          <?php if ($p['phone']): ?>
            <div class="small mono">
              <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $p['phone'])) ?>"><?= e($p['phone']) ?></a>
            </div>
          <?php endif; ?>
        </td>

        <td>
          <span class="tag <?= $tagFor($p['status']) ?>"><?= te($statuses[$p['status']] ?? $p['status']) ?></span>
          <?php $review = $reviews[(int) $p['id']] ?? null; ?>
          <?php if ($review): ?>
            <div style="margin-top:5px">
              <span class="tag <?= review_grade_tone((string) $review['grade']) ?>">
                <?= e($review['grade']) ?>
              </span>
              <?php if (! (int) $review['would_rehire']): ?>
                <span class="tag red"><?= te('Would not rehire') ?></span>
              <?php endif; ?>
            </div>
            <?php if ($review['note']): ?>
              <div class="muted small"><?= e($review['note']) ?></div>
            <?php endif; ?>
            <div class="muted small">
              <?= te('by :who', ['who' => $review['reviewer'] ?: t('somebody')]) ?>
            </div>
          <?php elseif ($p['status'] === 'completed'): ?>
            <div style="margin-top:5px">
              <span class="tag amber"><?= te('Not graded') ?></span>
            </div>
          <?php endif; ?>
        </td>

        <td class="small">
          <?php $stops = $blockers[(int) $p['id']] ?? null; ?>
          <?php if ($stops === null): ?>
            <span class="muted">&mdash;</span>
          <?php elseif (! $stops): ?>
            <span class="tag green"><?= te('Clear') ?></span>
          <?php else: ?>
            <ul class="blocker-list">
            <?php foreach ($stops as $stop): ?>
              <li><a href="<?= e($stop['where']) ?>"><?= e(t($stop['label'], $stop['vars'])) ?></a></li>
            <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </td>

        <td class="small">
          <?php if ($p['hotel']): ?>
            <div>
              <?= e($p['hotel']) ?>
              <?php if ($p['room_number']): ?>
                <span class="muted mono"><?= e($p['room_number']) ?></span>
              <?php endif; ?>
              <?php if ((int) $p['private_room'] === 0): ?>
                <span class="tag red"><?= te('sharing') ?></span>
              <?php endif; ?>
            </div>
          <?php elseif (in_array($p['status'], ['confirmed','travelling','on_site'], true)): ?>
            <div><a class="tag red" href="/hotels"><?= te('no bed') ?></a></div>
          <?php endif; ?>

          <?php if ($p['arrive_time']): ?>
            <div class="muted">
              <?= te('Arrives') ?> <?= e(date('D j M, g:ia', strtotime($p['arrive_time']))) ?>
              <?= e(trim(($p['in_carrier'] ?? '') . ' ' . ($p['in_ref'] ?? ''))) ?>
            </div>
          <?php elseif (in_array($p['status'], ['confirmed','travelling'], true)): ?>
            <div><a class="tag amber" href="/travel"><?= te('travel not booked') ?></a></div>
          <?php endif; ?>

          <?php if ($p['depart_time']): ?>
            <div class="muted"><?= te('Leaves') ?> <?= e(date('j M', strtotime($p['depart_time']))) ?></div>
          <?php endif; ?>

          <?php if (! $p['hotel'] && ! $p['arrive_time'] && ! $p['depart_time']
                    && ! in_array($p['status'], ['confirmed','travelling','on_site'], true)): ?>
            <span class="muted">&mdash;</span>
          <?php endif; ?>
        </td>

        <?php if (can('recruiter')): ?>
        <td class="right">
          <form method="post" action="/roster" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="status">
            <input type="hidden" name="placement_id" value="<?= (int) $p['id'] ?>">
            <label class="sr-only" for="move-<?= (int) $p['id'] ?>">
              <?= te('Move :name to another status', ['name' => $p['full_name']]) ?>
            </label>
            <select id="move-<?= (int) $p['id'] ?>" name="status" class="move-select"
                    onchange="this.form.submit()">
              <option value="">&mdash;</option>
              <?php foreach ($statuses as $k => $v): ?>
                <?php if ($k !== $p['status']): ?>
                  <option value="<?= e($k) ?>"><?= te($v) ?></option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </form>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<!-- ── grading the assignments that finished ──────────────────────────── -->
<?php
$toGrade = array_filter($crew, fn ($p) => $p['status'] === 'completed'
                                          && ! isset($reviews[(int) $p['id']]));
?>
<?php if ($toGrade && (can('recruiter') || can('supervisor'))): ?>
<section class="card tight" id="grading">
  <div style="padding:16px 18px;border-bottom:1px solid var(--line)">
    <span class="eyebrow"><?= te('HOW IT WENT') ?></span>
    <h2 style="margin:4px 0 2px">
      <?= te(':n finished assignments to grade', ['n' => count($toGrade)]) ?>
    </h2>
    <p class="small muted" style="margin:0">
      <?= te('Asked now, while somebody still remembers. A year from now this is the only thing that answers whether this person is worth calling back.') ?>
    </p>
  </div>

  <?php foreach ($toGrade as $p): ?>
    <form method="post" class="grade-form">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="review">
      <input type="hidden" name="placement_id" value="<?= (int) $p['id'] ?>">

      <h3 style="margin:0 0 10px">
        <?= e($p['full_name']) ?>
        <span class="muted small">
          <?= e($p['trade'] ?: ucfirst((string) $p['discipline'])) ?>
        </span>
      </h3>

      <div class="row">
        <div>
          <label for="g-<?= (int) $p['id'] ?>"><?= te('Overall') ?></label>
          <select id="g-<?= (int) $p['id'] ?>" name="grade" required>
            <?php foreach (review_grades() as $letter => $meaning): ?>
              <option value="<?= e($letter) ?>" <?= $letter === 'B' ? 'selected' : '' ?>>
                <?= te($meaning) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="wr-<?= (int) $p['id'] ?>"><?= te('Have them back?') ?></label>
          <select id="wr-<?= (int) $p['id'] ?>" name="would_rehire">
            <option value="1"><?= te('Yes') ?></option>
            <option value="0"><?= te('No') ?></option>
          </select>
          <span class="hint"><?= te('A steady C who turns up is worth calling. Kept separate from the grade on purpose.') ?></span>
        </div>
      </div>

      <?php if (review_criteria()): ?>
        <div class="row" style="margin-top:12px">
          <?php foreach (review_criteria() as $slug => $label): ?>
            <div>
              <label for="s-<?= (int) $p['id'] ?>-<?= e($slug) ?>"><?= te($label) ?></label>
              <select id="s-<?= (int) $p['id'] ?>-<?= e($slug) ?>" name="score[<?= e($slug) ?>]">
                <option value=""><?= te('not scored') ?></option>
                <?php foreach ([5 => 'Very good', 4 => 'Good', 3 => 'Adequate',
                                2 => 'Poor', 1 => 'Bad'] as $n => $word): ?>
                  <option value="<?= $n ?>"><?= $n ?> &middot; <?= te($word) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="field" style="margin-top:12px">
        <label for="n-<?= (int) $p['id'] ?>"><?= te('What the supervisor said') ?></label>
        <input id="n-<?= (int) $p['id'] ?>" name="note" maxlength="2000"
               placeholder="<?= te('Required if you would not have them back.') ?>">
      </div>

      <button class="btn" type="submit" style="margin-top:12px"><?= te('Record the grade') ?></button>
    </form>
  <?php endforeach; ?>
</section>
<?php endif; ?>
