<div class="page-head"><div><h1><?= te('My supervised team') ?></h1><p class="sub"><?= te('Workers currently assigned to you. Coordinate through your private channels and review time-off requests.') ?></p></div></div><div class="grid g2"><?php foreach($team as $t): ?><div class="card"><h2><?= e($t['full_name']) ?></h2><p><?= e($t['title'].' · '.$t['trade'].' · '.$t['shift_label'].' · '.$t['status']) ?></p></div><?php endforeach; ?></div>
<?php if ($toGrade): ?>
<section class="card" id="grading" style="margin-top:14px">
  <span class="eyebrow"><?= te('HOW IT WENT') ?></span>
  <h2 style="margin:4px 0 2px"><?= te(':n finished assignments to grade', ['n' => count($toGrade)]) ?></h2>
  <p class="small muted"><?= te('Asked now, while somebody still remembers. A year from now this is the only thing that answers whether this person is worth calling back.') ?></p>
  <?php foreach ($toGrade as $p): ?><?php require __DIR__ . '/_grade-form.php'; ?><?php endforeach; ?>
</section>
<?php endif; ?>
