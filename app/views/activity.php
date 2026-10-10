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
    <span class="eyebrow"><?= te('YOUR DESK') ?></span>
    <h1>
      <?php if ($total === 0): ?>
        <?= te('Nothing is waiting on you.') ?>
      <?php else: ?>
        <?= te(':n things are waiting on you', ['n' => $total]) ?>
      <?php endif; ?>
    </h1>
    <p class="sub">
      <?= te('Only what your desk can act on. Every number is a count of real records in a state that needs a person — the line under it says which. Each one opens the screen that clears it, and the number falls when the work is done, never because it was dismissed here.') ?>
    </p>
  </div>
</div>

<?php if (! empty($wf)) { require __DIR__ . '/_workforce-overview.php'; } ?>

<?php if ($job && $target > 0): ?>
<section class="card headcount">
  <div class="headcount-top">
    <div>
      <span class="eyebrow"><?= te('THIS PROJECT') ?></span>
      <h2 style="margin:4px 0 0"><?= e($job['title']) ?></h2>
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

  <p class="small muted" style="margin:8px 0 0">
    <?= te(':filled of :target on the job', ['filled' => $filled, 'target' => $target]) ?>
    &middot; <?= $progress ?>%
  </p>
</section>
<?php endif; ?>

<?php if (! $items): ?>
  <div class="card">
    <div class="empty">
      <?= te('Your queue is empty. When an application arrives, a contract needs approving or a timesheet needs a decision, it appears here.') ?>
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
