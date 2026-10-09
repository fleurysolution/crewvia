<?php
$subjects = approval_subjects();
$roles    = roles();
?>

<div class="page-heading">
  <div>
    <span class="eyebrow"><?= te('ADMINISTRATION') ?></span>
    <h1><?= te('Approval chains') ?></h1>
    <p class="sub">
      <?= te('Who is asked before something goes ahead, and in what order. A step in order waits for the steps before it; a step marked any time can be decided whenever. One rejection closes the rest.') ?>
    </p>
  </div>
  <a class="btn ghost" href="/approvals"><?= te('Open approvals') ?></a>
</div>

<?php foreach ($chains as $chain): ?>
<section class="card">
  <div class="page-heading" style="margin:0 0 14px">
    <div>
      <span class="eyebrow"><?= te($subjects[$chain['applies_to']] ?? $chain['applies_to']) ?></span>
      <h2 style="margin:4px 0 0">
        <?= e($chain['name']) ?>
        <?php if ($chain['is_default']): ?><span class="tag blue"><?= te('Default') ?></span><?php endif; ?>
        <?php if (! $chain['is_active']): ?><span class="tag grey"><?= te('Off') ?></span><?php endif; ?>
      </h2>
      <?php if ($chain['in_use']): ?>
        <p class="hint" style="margin:6px 0 0">
          <?= te(':n record(s) are running through this chain. Editing it does not change them.', ['n' => (int) $chain['in_use']]) ?>
        </p>
      <?php endif; ?>
    </div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="toggle_chain">
      <input type="hidden" name="chain_id" value="<?= (int) $chain['id'] ?>">
      <button class="btn ghost sm" type="submit">
        <?= $chain['is_active'] ? te('Switch off') : te('Switch on') ?>
      </button>
    </form>
  </div>

  <?php if (! $chain['steps']): ?>
    <div class="empty"><?= te('No steps yet, so nothing is routed through this chain.') ?></div>
  <?php else: ?>
    <ol class="chain">
      <?php foreach ($chain['steps'] as $step): ?>
        <li>
          <span class="chain-order"><?= (int) $step['step_order'] ?></span>
          <span class="chain-body">
            <strong><?= te($step['label']) ?></strong>
            <span class="muted small">
              <?= te($roles[$step['role_slug']] ?? $step['role_slug']) ?>
              &middot; <?= te($step['gate_type'] === 'parallel' ? 'Any time' : 'In order') ?>
              &middot; <?= te($step['is_required'] ? 'Required' : 'Optional') ?>
            </span>
          </span>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="remove_step">
            <input type="hidden" name="step_id" value="<?= (int) $step['id'] ?>">
            <button class="btn ghost sm" type="submit"><?= te('Remove') ?></button>
          </form>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>

  <form method="post" class="row" style="margin-top:16px">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="add_step">
    <input type="hidden" name="chain_id" value="<?= (int) $chain['id'] ?>">

    <div style="flex:2">
      <label for="l-<?= (int) $chain['id'] ?>"><?= te('Step') ?></label>
      <input id="l-<?= (int) $chain['id'] ?>" name="label" maxlength="120" required
             placeholder="<?= te('e.g. Operations sign-off') ?>">
    </div>
    <div>
      <label for="r-<?= (int) $chain['id'] ?>"><?= te('Decided by') ?></label>
      <select id="r-<?= (int) $chain['id'] ?>" name="role_slug">
        <?php foreach (['recruiter', 'hotels', 'payroll', 'admin'] as $slug): ?>
          <option value="<?= e($slug) ?>"><?= te($roles[$slug] ?? $slug) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="g-<?= (int) $chain['id'] ?>"><?= te('When') ?></label>
      <select id="g-<?= (int) $chain['id'] ?>" name="gate_type">
        <option value="sequential"><?= te('In order') ?></option>
        <option value="parallel"><?= te('Any time') ?></option>
      </select>
    </div>
    <label class="own-room">
      <input type="checkbox" name="is_required" value="1" checked>
      <?= te('Required') ?>
    </label>
    <button class="btn" type="submit"><?= te('Add step') ?></button>
  </form>
</section>
<?php endforeach; ?>

<form class="card" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="create_chain">
  <span class="eyebrow"><?= te('NEW CHAIN') ?></span>
  <h2><?= te('Add an approval chain') ?></h2>

  <div class="row">
    <div style="flex:2">
      <label for="chain-name"><?= te('Name') ?></label>
      <input id="chain-name" name="name" maxlength="120" required
             placeholder="<?= te('e.g. Client order sign-off') ?>">
    </div>
    <div style="flex:2">
      <label for="chain-subject"><?= te('What it approves') ?></label>
      <select id="chain-subject" name="applies_to" required>
        <?php foreach ($subjects as $key => $label): ?>
          <option value="<?= e($key) ?>"><?= te($label) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="hint"><?= te('The newest chain for a kind of record becomes the one used.') ?></span>
    </div>
  </div>

  <button class="btn" type="submit"><?= te('Create chain') ?></button>
</form>
