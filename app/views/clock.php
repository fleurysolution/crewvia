<?php // P5-M67: one button, after scanning the supervisor's code. ?>
<div class="page-head"><div><h1><?= $open ? te('Clock out') : te('Clock in') ?><?= $job ? ' · ' . e($job['title']) : '' ?></h1></div></div>
<div class="card">
<?php if (! $qr): ?>
  <p class="t-red"><?= te('This code has expired. Scan the code on your supervisor’s phone again: it changes every 30 seconds.') ?></p>
<?php elseif (! $pid): ?>
  <p class="t-red"><?= te('You have no assignment on this project. Ask your supervisor.') ?></p>
<?php else: ?>
  <?php if ($open): ?><p><?= te('Clocked in at :time.', ['time' => date('g:i a', strtotime($open['clocked_in_at']))]) ?></p><?php endif; ?>
  <form method="post" action="/clock" data-geo data-wait="<?= te('Finding where you are…') ?>"><?= csrf_field() ?>
    <input type="hidden" name="t" value="<?= e($token) ?>"><input type="hidden" name="lat"><input type="hidden" name="lng"><input type="hidden" name="accuracy">
    <button class="btn big"><?= $open ? te('Clock out now') : te('Clock in now') ?></button>
  </form>
  <p class="small muted"><?= te('Your phone tells where you are: if it is away from the site, your supervisor sees it.') ?></p>
<?php endif; ?>
</div>
<script src="/assets/clock.js"></script>
