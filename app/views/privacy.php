<div class="page-head"><div><h1><?= te('Privacy notice') ?></h1></div></div>
<?php if ($notice): ?>
<div class="card" id="privacy-notice" data-version="<?= (int) $notice['id'] ?>">
  <div style="white-space:pre-wrap;overflow-wrap:anywhere"><?= e($notice['body']) ?></div>
  <p class="small muted"><?= te('Version :n, published :d.', ['n' => (int) $notice['id'], 'd' => d(substr((string) $notice['published_at'], 0, 10))]) ?></p>
</div>
<?php else: ?>
<div class="card" id="privacy-notice" data-version="0"><p><?= te('The agency has not published its privacy notice here yet. Ask the agency how it uses and keeps your information before you apply.') ?></p></div>
<?php endif; ?>
