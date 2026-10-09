<div class="page-heading">
  <div>
    <span class="eyebrow"><?= te('NOT AVAILABLE') ?></span>
    <h1><?= e($message) ?></h1>
    <p class="sub">
      <?= $code === 404
          ? te('Either it was removed, or it belongs to a project that is not the one you have open.')
          : te('The request was refused. Nothing was changed.') ?>
    </p>
  </div>
</div>

<div class="card">
  <p><a class="btn" href="/"><?= te('Back to the dashboard') ?></a></p>
</div>
