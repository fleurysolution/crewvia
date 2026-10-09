<h1><?= te('Not here') ?></h1>
<p class="sub">
  <?= isset($path) ? e($path) . ' does not exist.' : 'That page does not exist.' ?>
</p>
<p><a class="btn" href="/"><?= te('Back to the dashboard') ?></a></p>
