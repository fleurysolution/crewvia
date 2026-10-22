<?php
/**
 * P5-M102b: what an old link to a closed position opens. The person is told plainly, then shown what is open now -
 * the same positions as the careers page, with the same terms - so the link still leads somewhere useful.
 */
?>
<div class="page-head" id="position-closed"><div>
  <h1><?= $closedTitle ? te(':title is no longer open', ['title' => $closedTitle]) : te('This position is not available') ?></h1>
  <p class="sub m0"><?= te('It has been filled or closed. Here is what is open now.') ?></p>
</div></div>
<?php if ($openNow): ?>
<div class="grid g2" id="open-now">
  <?php foreach ($openNow as $o): $t = public_opening_terms($o); ?>
  <article class="card">
    <h2 class="m0"><?= e($o['title']) ?></h2>
    <?php if ($t['where'] !== ''): ?><p class="small muted m0"><?= e($t['where']) ?></p><?php endif; ?>
    <p class="mt-s m0">
      <?php if ($t['pay'] !== null): ?><span class="tag green"><?= te(':rate an hour', ['rate' => money((float) $t['pay'])]) ?></span><?php endif; ?>
      <?php if (! empty($t['guarantee'])): ?><span class="tag"><?= te(':n hours guaranteed a week', ['n' => (int) $t['guarantee']]) ?></span><?php endif; ?>
      <?php if ($t['diem'] !== null && (float) $t['diem'] > 0): ?><span class="tag"><?= te('per diem :rate', ['rate' => money((float) $t['diem'])]) ?></span><?php endif; ?>
      <?php if ($t['travel']): ?><span class="tag"><?= te('Travel to and from site paid') ?></span><?php endif; ?>
      <?php if ($t['hotel']): ?><span class="tag"><?= te('Hotel paid') ?></span><?php endif; ?>
    </p>
    <p class="mt-s m0"><a class="btn sm" href="/apply?id=<?= (int) $o['id'] ?>"><?= te('Apply for this') ?></a></p>
  </article>
  <?php endforeach; ?>
</div>
<p><a class="btn ghost" href="/careers"><?= te('See all open positions') ?></a></p>
<?php else: ?>
<div class="card"><p class="m0"><?= te('No positions are open right now. These jobs appear at short notice, so it is worth checking again.') ?></p>
  <p class="mt-s m0"><a class="btn ghost" href="/careers"><?= te('See all open positions') ?></a></p></div>
<?php endif; ?>
