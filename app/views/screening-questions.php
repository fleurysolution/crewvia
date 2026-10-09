<?php
$types = screening_answer_types();

$scope = $vacancyId
    ? (function () use ($requisitions, $vacancyId) {
        foreach ($requisitions as $r) {
            if ((int) $r['id'] === $vacancyId) {
                return $r['title'];
            }
        }
        return null;
    })()
    : null;
?>

<div class="page-heading">
  <div>
    <span class="eyebrow"><?= te('RECRUITING') ?></span>
    <h1><?= te('Screening questions') ?></h1>
    <p class="sub">
      <?= $scope
          ? e($scope)
          : te('Asked of everybody who applies to this project.') ?>
    </p>
  </div>
  <a class="btn ghost" href="/recruitment"><?= te('Application screening') ?></a>
</div>

<?php if (! $jobId): ?>
  <div class="card"><div class="empty">
    <?= te('No project selected yet. Pick one from the switcher above.') ?>
  </div></div>
<?php else: ?>

<!-- ── are these the right questions? ──────────────────────────────── -->
<div class="grid g4" style="margin-bottom:18px">
  <div class="stat">
    <div class="n"><?= $stats['applications'] ?></div>
    <div class="l"><?= te('Applications') ?></div>
  </div>
  <div class="stat">
    <div class="n" style="color:<?= $stats['screened_out'] ? 'var(--red)' : 'var(--ink)' ?>">
      <?= $stats['screened_out'] ?>
    </div>
    <div class="l"><?= te('Screened out') ?></div>
    <div class="h"><?= te('Flagged, not rejected.') ?></div>
  </div>
  <div class="stat">
    <div class="n"><?= $stats['average'] !== null ? $stats['average'] . '%' : '&mdash;' ?></div>
    <div class="l"><?= te('Average score') ?></div>
  </div>
  <div class="stat">
    <div class="n"><?= $stats['unscored'] ?></div>
    <div class="l"><?= te('Applied before the questions') ?></div>
    <div class="h"><?= te('Left for a human to read.') ?></div>
  </div>
</div>

<!-- ── where they came from ────────────────────────────────────────── -->
<?php if ($channels): ?>
<section class="card tight">
  <div style="padding:14px 18px;border-bottom:1px solid var(--line)">
    <h2 style="margin:0"><?= te('Where these applicants came from') ?></h2>
  </div>
  <div class="scroll">
    <table>
      <thead><tr>
        <th><?= te('Channel') ?></th>
        <th class="right"><?= te('Applications') ?></th>
        <th class="right"><?= te('Screened out') ?></th>
        <th class="right"><?= te('Average score') ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($channels as $ch): ?>
        <tr>
          <td><?= e($ch['channel']) ?></td>
          <td class="right mono"><?= (int) $ch['applications'] ?></td>
          <td class="right mono"><?= (int) $ch['screened_out'] ?></td>
          <td class="right mono">
            <?= $ch['average_score'] !== null ? round((float) $ch['average_score']) . '%' : '&mdash;' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="hint" style="padding:12px 18px;margin:0;border-top:1px solid var(--line)">
    <?= te('Counted from where each candidate came in, not from what they picked in a dropdown. A high count with a low score is a channel sending the wrong people.') ?>
  </p>
</section>
<?php endif; ?>

<!-- ── how it works, said once ─────────────────────────────────────── -->
<section class="card" style="background:#F6F9FF">
  <h2><?= te('How this works') ?></h2>
  <p class="small" style="margin:0">
    <?= te('A disqualifying answer flags the applicant and sorts them to the bottom with the reason shown. Nobody is rejected automatically — a person still presses the button. The weight decides how much a good answer is worth in the score, which is a percentage of what was available, so roles with different numbers of questions stay comparable. Changing the questions re-ranks the applications already received.') ?>
  </p>
</section>

<!-- ── which role ──────────────────────────────────────────────────── -->
<?php if ($requisitions): ?>
<form class="card row" method="get" action="/screening-questions">
  <div style="flex:2">
    <label for="scope"><?= te('Questions for') ?></label>
    <select id="scope" name="vacancy" onchange="this.form.submit()">
      <option value="0"><?= te('Every role on this project') ?></option>
      <?php foreach ($requisitions as $r): ?>
        <option value="<?= (int) $r['id'] ?>" <?= $vacancyId === (int) $r['id'] ? 'selected' : '' ?>>
          <?= e($r['title']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <span class="hint">
      <?= te('A role shows its own questions and the project-wide ones together, because that is what its applicants are asked.') ?>
    </span>
  </div>
  <noscript><button class="btn ghost sm"><?= te('Show') ?></button></noscript>
</form>
<?php endif; ?>

<!-- ── the questions ───────────────────────────────────────────────── -->
<section class="card tight">
  <div style="padding:14px 18px;border-bottom:1px solid var(--line)">
    <h2 style="margin:0"><?= te('The questionnaire') ?></h2>
  </div>

  <?php if (! $questions): ?>
    <div class="empty">
      <?= te('No questions yet, so every application arrives unscored and somebody reads all of them.') ?>
    </div>
  <?php else: ?>
    <div class="scroll">
      <table class="questions">
        <thead><tr>
          <th><?= te('Question') ?></th>
          <th><?= te('Answer') ?></th>
          <th class="right"><?= te('Weight') ?></th>
          <th><?= te('Disqualifies on') ?></th>
          <th><?= te('Applies to') ?></th>
          <th class="right"><?= te('Order') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($questions as $question): ?>
          <tr>
            <td>
              <strong><?= e($question['question']) ?></strong>
              <div class="muted small">
                <?= te(':n answered', ['n' => (int) $question['answered']]) ?>
                <?php if ($question['required']): ?>
                  &middot; <?= te('required') ?>
                <?php endif; ?>
              </div>
            </td>
            <td class="small">
              <?= te($types[$question['answer_type']] ?? $question['answer_type']) ?>
              <?php if ($question['answer_type'] === 'choice'): ?>
                <div class="muted small"><?= e(str_replace("\n", ' / ', (string) $question['choices'])) ?></div>
              <?php endif; ?>
            </td>
            <td class="right mono"><?= (int) $question['weight'] ?></td>
            <td class="small">
              <?php if ($question['knockout_answer']): ?>
                <span class="tag red"><?= e($question['knockout_answer']) ?></span>
              <?php else: ?>
                <span class="muted">&mdash;</span>
              <?php endif; ?>
            </td>
            <td class="small muted">
              <?= $question['role'] ? e($question['role']) : te('Every role') ?>
            </td>
            <td class="right">
              <div class="question-actions">
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="move">
                  <input type="hidden" name="direction" value="up">
                  <input type="hidden" name="question_id" value="<?= (int) $question['id'] ?>">
                  <input type="hidden" name="vacancy_id" value="<?= $vacancyId ?>">
                  <button class="btn ghost sm" type="submit"
                          aria-label="<?= te('Move up') ?>">&uarr;</button>
                </form>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="move">
                  <input type="hidden" name="direction" value="down">
                  <input type="hidden" name="question_id" value="<?= (int) $question['id'] ?>">
                  <input type="hidden" name="vacancy_id" value="<?= $vacancyId ?>">
                  <button class="btn ghost sm" type="submit"
                          aria-label="<?= te('Move down') ?>">&darr;</button>
                </form>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="remove">
                  <input type="hidden" name="question_id" value="<?= (int) $question['id'] ?>">
                  <input type="hidden" name="vacancy_id" value="<?= $vacancyId ?>">
                  <button class="btn ghost sm" type="submit"><?= te('Remove') ?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<!-- ── add one ─────────────────────────────────────────────────────── -->
<form class="card" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="add">
  <input type="hidden" name="vacancy_id" value="<?= $vacancyId ?>">
  <span class="eyebrow"><?= te('NEW QUESTION') ?></span>
  <h2>
    <?= $scope
        ? te('Ask this of :role applicants', ['role' => $scope])
        : te('Ask this of everybody') ?>
  </h2>

  <div class="field">
    <label for="q-text"><?= te('Question') ?></label>
    <input id="q-text" name="question" required maxlength="500"
           placeholder="<?= te('e.g. Are you legally authorised to work in the United States without sponsorship?') ?>">
    <span class="hint"><?= te('Write it the way you would ask it on the phone.') ?></span>
  </div>

  <div class="row">
    <div>
      <label for="q-type"><?= te('Answer') ?></label>
      <select id="q-type" name="answer_type">
        <?php foreach ($types as $key => $label): ?>
          <option value="<?= e($key) ?>"><?= te($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="q-weight"><?= te('Weight') ?></label>
      <input id="q-weight" name="weight" type="number" min="0" max="100" value="5">
      <span class="hint"><?= te('0 asks without scoring') ?></span>
    </div>
    <div style="flex:2">
      <label for="q-knock"><?= te('Disqualifies on this answer') ?></label>
      <input id="q-knock" name="knockout_answer" maxlength="190"
             placeholder="<?= te('e.g. No — leave blank if nothing disqualifies') ?>">
    </div>
    <label class="own-room">
      <input type="checkbox" name="required" value="1" checked>
      <?= te('Required') ?>
    </label>
  </div>

  <div class="field" style="margin-top:16px">
    <label for="q-choices"><?= te('Choices, one per line') ?></label>
    <textarea id="q-choices" name="choices" rows="3" maxlength="500"
              placeholder="<?= te('Only for a question answered from a list') ?>"></textarea>
  </div>

  <button class="btn" type="submit"><?= te('Add the question') ?></button>
</form>
<?php endif; ?>
