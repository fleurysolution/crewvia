<?php /* One job's screening questions as form fields, named screening[<question id>]. Expects $screening; $sqPrefix keeps ids apart when several forms share a page. */ ?>
  <?php foreach ($screening as $question): ?>
    <?php $name = 'screening[' . (int) $question['id'] . ']'; ?>
    <div class="field">
      <?php $sqId = 'sq-' . ($sqPrefix ?? '') . (int) $question['id']; ?>
      <?php // Walkthrough finding: a yes/no question's label pointed at an id no radio had; it now names the group. ?>
      <label <?= $question['answer_type'] === 'yes_no' ? 'id="' . e($sqId) . '-label"' : 'for="' . e($sqId) . '"' ?>>
        <?= e($question['question']) ?>
      </label>

      <?php if ($question['answer_type'] === 'yes_no'): ?>
        <div class="answer-choices" role="radiogroup" aria-labelledby="<?= e($sqId) ?>-label">
          <?php foreach (['Yes', 'No'] as $option): ?>
            <label class="own-room">
              <input type="radio" name="<?= e($name) ?>" value="<?= e($option) ?>"
                     <?= $question['required'] ? 'required' : '' ?>>
              <?= te($option) ?>
            </label>
          <?php endforeach; ?>
        </div>

      <?php elseif ($question['answer_type'] === 'choice'): ?>
        <select id="sq-<?= e($sqPrefix ?? '') ?><?= (int) $question['id'] ?>" name="<?= e($name) ?>"
                <?= $question['required'] ? 'required' : '' ?>>
          <option value=""><?= te('Choose') ?></option>
          <?php foreach (screening_choices($question) as $option): ?>
            <option value="<?= e($option) ?>"><?= e($option) ?></option>
          <?php endforeach; ?>
        </select>

      <?php elseif ($question['answer_type'] === 'number'): ?>
        <input id="sq-<?= e($sqPrefix ?? '') ?><?= (int) $question['id'] ?>" name="<?= e($name) ?>"
               type="number" step="any" <?= $question['required'] ? 'required' : '' ?>>

      <?php else: ?>
        <input id="sq-<?= e($sqPrefix ?? '') ?><?= (int) $question['id'] ?>" name="<?= e($name) ?>"
               maxlength="4000" <?= $question['required'] ? 'required' : '' ?>>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
