<?php
$tone = static fn (string $t): string => match ($t) {
    'red'   => 'red',
    'amber' => 'amber',
    'grey'  => 'grey',
    default => 'blue',
};

$remaining = max(0, $target - $filled);
$progress  = $target > 0 ? min(100, (int) round($filled / $target * 100)) : 0;
?>

<div class="page-heading">
  <div>
    <h1>
      <?php if ($total === 0): ?>
        <?= te('Nothing is waiting on you.') ?>
      <?php else: ?>
        <?= ten($total, ':n thing is waiting on you', ':n things are waiting on you') ?>
      <?php endif; ?>
    </h1>
    <p class="sub">
      <?php if (in_array(user()['role'] ?? '', ['worker', 'client'], true)): ?>
      <?= te('What needs you. Each line opens the screen where you do it.') ?>
      <?php else: ?>
      <?= te('Only what your desk can act on. Every number is a count of real records in a state that needs a person — the line under it says which. Each one opens the screen that clears it, and the number falls when the work is done, never because it was dismissed here.') ?>
      <?php endif; ?>
    </p>
  </div>
</div>

<?php if (! empty($wf)) { require __DIR__ . '/_workforce-overview.php'; } ?>

<?php if ($job && $target > 0): ?>
<section class="card headcount">
  <div class="headcount-top">
    <div>
      <span class="eyebrow"><?= te('THIS PROJECT') ?></span>
      <h2 class="m0 mt-xs"><?= e($job['title']) ?></h2>
    </div>
    <div class="headcount-figure">
      <strong><?= $remaining ?></strong>
      <span><?= te('still to place') ?></span>
    </div>
  </div>

  <div class="headcount-bar" role="img"
       aria-label="<?= te(':filled of :target placed', ['filled' => $filled, 'target' => $target]) ?>">
    <span style="width:<?= $progress ?>%"></span>
  </div>

  <p class="small muted m0 mt-s">
    <?= te(':filled of :target on the job', ['filled' => $filled, 'target' => $target]) ?>
    &middot; <?= $progress ?>%
  </p>
</section>
<?php endif; ?>

<?php if (! $items): ?>
  <div class="card">
    <div class="empty">
      <?php if (in_array(user()['role'] ?? '', ['worker', 'client'], true)): ?>
      <?= te('When something needs you, it appears here.') ?>
      <?php else: ?>
      <?= te('Your queue is empty. When an application arrives, a contract needs approving or a timesheet needs a decision, it appears here.') ?>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>
  <div class="card tight">
    <ul class="queue">
      <?php foreach ($items as $item): ?>
        <li>
          <a href="<?= e($item['where']) ?>">
            <span class="queue-count tag <?= $tone($item['tone']) ?>"><?= (int) $item['count'] ?></span>
            <span class="queue-label">
              <?= te($item['label']) ?>
              <?php if (! empty($item['source'])): ?>
                <span class="queue-source"><?= te($item['source']) ?></span>
              <?php endif; ?>
            </span>
            <span class="queue-go" aria-hidden="true">&rarr;</span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<section class="card">
  <span class="eyebrow"><?= te('RECENT') ?></span>
  <h2><?= te('What has come in') ?></h2>

  <?php if (! $recent): ?>
    <div class="empty"><?= te('Nothing has been sent to you yet.') ?></div>
  <?php else: ?>
    <ul class="notice-list">
      <?php foreach ($recent as $n): ?>
        <li class="<?= $n['read_at'] ? 'read' : 'unread' ?>">
          <a href="<?= e($n['target'] ?: '/') ?>"><?= te($n['message']) ?></a>
          <span class="muted small"><?= e(date('j M, H:i', strtotime($n['created_at']))) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <p><a class="btn ghost" href="/notifications"><?= te('All notifications') ?></a></p>
  <?php endif; ?>
</section>
