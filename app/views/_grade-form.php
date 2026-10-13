<?php /* How an assignment went: one placement's grade form, posted to the page that includes it (Roster, My team). Expects $p. */ ?>
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

      <?php if (assignment_review_criteria()): ?>
        <div class="row" style="margin-top:12px">
          <?php foreach (assignment_review_criteria() as $slug => $label): ?>
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
