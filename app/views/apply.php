<?php require_once __DIR__ . '/../screening.php'; ?>
<h1><?= e($vacancy['title']) ?></h1><p><?= e($vacancy['project']) ?></p><div class="card"><?= nl2br(e($vacancy['description'])) ?></div><form class="card" method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="vacancy_id" value="<?= (int)$vacancy['id'] ?>"><label><?= te('Full name') ?></label><input name="name" maxlength="190" required><label><?= te('Email') ?></label><input type="email" name="email" maxlength="190" required><label><?= te('Phone') ?></label><input name="phone" maxlength="40"><label><?= te('Resume · PDF or DOCX, maximum 5 MB') ?><input name="resume" type="file" accept=".pdf,.docx"></label>
<?php if ($screening): ?>
  <h2 style="margin-top:24px"><?= te('A few questions') ?></h2>
  <p class="hint" style="margin:-4px 0 16px">
    <?= te('These decide whether this job is a fit, so answer them as they are.') ?>
  </p>

  <?php foreach ($screening as $question): ?>
    <?php $name = 'screening[' . (int) $question['id'] . ']'; ?>
    <div class="field">
      <label for="sq-<?= (int) $question['id'] ?>">
        <?= e($question['question']) ?>
      </label>

      <?php if ($question['answer_type'] === 'yes_no'): ?>
        <div class="answer-choices">
          <?php foreach (['Yes', 'No'] as $option): ?>
            <label class="own-room">
              <input type="radio" name="<?= e($name) ?>" value="<?= e($option) ?>"
                     <?= $question['required'] ? 'required' : '' ?>>
              <?= te($option) ?>
            </label>
          <?php endforeach; ?>
        </div>

      <?php elseif ($question['answer_type'] === 'choice'): ?>
        <select id="sq-<?= (int) $question['id'] ?>" name="<?= e($name) ?>"
                <?= $question['required'] ? 'required' : '' ?>>
          <option value=""><?= te('Choose') ?></option>
          <?php foreach (screening_choices($question) as $option): ?>
            <option value="<?= e($option) ?>"><?= e($option) ?></option>
          <?php endforeach; ?>
        </select>

      <?php elseif ($question['answer_type'] === 'number'): ?>
        <input id="sq-<?= (int) $question['id'] ?>" name="<?= e($name) ?>"
               type="number" step="any" <?= $question['required'] ? 'required' : '' ?>>

      <?php else: ?>
        <input id="sq-<?= (int) $question['id'] ?>" name="<?= e($name) ?>"
               maxlength="4000" <?= $question['required'] ? 'required' : '' ?>>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<label><input type="checkbox" name="consent" required style="width:auto"> <?= te('I authorize the hiring agency to review my application and contact me about opportunities.') ?></label><button class="btn"><?= te('Apply') ?></button></form>
<?php if($jobSchema): ?><script type="application/ld+json"><?= json_encode($jobSchema,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES) ?></script><?php endif; ?>
