<?php if ($report === null): ?>
<h1><?= te('Reports') ?></h1>
<p class="sub"><?= te('Read from the records the desks keep: nothing here is entered or stored. Each report you open is logged.') ?></p>
<div class="grid g2" id="report-list">
  <?php foreach ($catalog as $k => $r): ?>
  <a class="card" href="/reports?r=<?= e($k) ?>" data-report="<?= e($k) ?>"><h2><?= te($r['title']) ?></h2><p class="muted"><?= te($r['about']) ?></p></a>
  <?php endforeach; ?>
</div>
<?php else: ?>
<style>table.report th, table.report td { white-space: nowrap; } table.report tr.total th { border-top: 1px solid var(--line, #ccc); }</style>
<p class="small"><a href="/reports"><?= te('Reports') ?></a></p>
<h1><?= te($def['title']) ?></h1>
<p class="sub"><?= te($def['about']) ?></p>
<div class="card scroll" id="report" data-report="<?= e($report) ?>">
  <form method="get" class="row"><input type="hidden" name="r" value="<?= e($report) ?>">
    <?php if (in_array('period', $def['filters'], true)): ?><label><?= te('From') ?></label><input type="date" name="from" value="<?= e($filters['from']) ?>"><label><?= te('To') ?></label><input type="date" name="to" value="<?= e($filters['to']) ?>"><?php endif; ?>
    <?php if (in_array('asof', $def['filters'], true)): ?><label><?= te('At') ?></label><input type="date" name="asof" value="<?= e($filters['asof']) ?>"><?php endif; ?>
    <?php if (in_array('year', $def['filters'], true)): ?><label><?= te('Year') ?></label><input type="number" name="year" min="2000" max="2100" value="<?= (int) $filters['year'] ?>"><?php endif; ?>
    <?php if (in_array('project', $def['filters'], true)): ?><select name="job_id"><option value="0"><?= te('Every project') ?></option><?php foreach ($jobs as $j): ?><option value="<?= (int) $j['id'] ?>"<?= (int) $j['id'] === $filters['job_id'] ? ' selected' : '' ?>><?= e($j['title']) ?></option><?php endforeach; ?></select><?php endif; ?>
    <button class="btn ghost"><?= te('Show') ?></button>
    <a class="btn ghost" href="/reports?<?= e($query) ?>&amp;print=1" target="_blank" rel="noopener"><?= te('Print') ?></a>
  </form>
  <?php require __DIR__ . '/_report-table.php'; ?>
</div>
<?php endif; ?>
