<?php // P5-M67: the code the crew scans to clock in and out, on the supervisor's phone. ?>
<div class="page-head"><div><h1><?= te('Clock-in code') ?><?= $job ? ' · ' . e($job['title']) : '' ?></h1>
  <p class="sub"><?= te('Your crew scans it with their own phone to clock in, and again to clock out. It changes every 30 seconds: a photo sent to someone else no longer works.') ?></p></div>
  <div class="actions"><a class="btn ghost" href="/my-team#clock-ins"><?= te('My team') ?></a></div></div>
<?php if (! $job): ?>
<div class="card"><p><?= te('You supervise no active project.') ?></p></div>
<?php else: ?>
<div class="card clock-qr-card">
  <div id="clock-qr" data-url="<?= e($now['url']) ?>" data-feed="/clock-qr?feed=1"></div>
  <p class="small muted"><?= te('New code in') ?> <strong id="clock-qr-left" data-until="0"><?= (int) $now['expires_in'] ?></strong> s</p>
  <?php if ($job['site_lat'] === null): ?><p class="small t-red"><?= te('The site position is not set: positions are recorded but not compared. Set it in the project, from the site.') ?></p><?php endif; ?>
</div>
<div class="card"><h2><?= te('Scanned today') ?></h2>
  <ul id="clock-qr-scans" class="small"><?php foreach ($now['scans'] as $s): ?><li class="<?= $s['flag'] ? 't-red' : '' ?>"><?= e($s['text']) ?></li><?php endforeach; ?></ul>
</div>
<script src="/assets/qrcode.min.js"></script><script src="/assets/clock.js"></script>
<?php endif; ?>
