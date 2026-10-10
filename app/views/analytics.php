<?php
$kpiValue = static function ($v, string $type): string {
    if ($v === null) {
        return '–';
    }
    return match ($type) {
        'money' => money($v),
        'pct'   => number_format((float) $v, 1) . ' %',
        'num'   => rtrim(rtrim(number_format((float) $v, 2), '0'), '.'),
        default => (string) (int) $v,
    };
};
?>
<?php if ($dashboard === null): ?>
<h1><?= te('Dashboards') ?></h1>
<p class="sub"><?= te('Measures read from the records and the ledger each time they are opened. Nothing here is entered or stored. Each dashboard you open is logged.') ?></p>
<div class="grid g2" id="dashboard-list">
  <?php foreach ($catalog as $k => $d): ?>
  <a class="card" href="/analytics?d=<?= e($k) ?>" data-dashboard="<?= e($k) ?>"><h2><?= te($d['title']) ?></h2><p class="muted"><?= te($d['about']) ?></p></a>
  <?php endforeach; ?>
</div>
<?php else: ?>
<style>
  .kpis { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 10px; margin: 12px 0; }
  .kpi { padding: 12px; }
  .kpi .v { font-size: 1.35em; font-weight: 600; }
  table.report th, table.report td { white-space: nowrap; }
</style>
<p class="small"><a href="/analytics"><?= te('Dashboards') ?></a> · <a href="/reports"><?= te('Reports') ?></a></p>
<h1><?= te($def['title']) ?></h1>
<p class="sub"><?= te($def['about']) ?></p>
<?php if ($def['filters']): ?>
<form method="get" class="row" id="dashboard-filters"><input type="hidden" name="d" value="<?= e($dashboard) ?>">
  <?php if (in_array('period', $def['filters'], true)): ?><label><?= te('From') ?></label><input type="date" name="from" value="<?= e($filters['from']) ?>"><label><?= te('To') ?></label><input type="date" name="to" value="<?= e($filters['to']) ?>"><?php endif; ?>
  <?php if (in_array('asof', $def['filters'], true)): ?><label><?= te('At') ?></label><input type="date" name="asof" value="<?= e($filters['asof']) ?>"><?php endif; ?>
  <?php if (in_array('project', $def['filters'], true)): ?><select name="job_id"><option value="0"><?= te('Every project') ?></option><?php foreach ($jobs as $j): ?><option value="<?= (int) $j['id'] ?>"<?= (int) $j['id'] === $filters['job_id'] ? ' selected' : '' ?>><?= e($j['title']) ?></option><?php endforeach; ?></select><?php endif; ?>
  <button class="btn ghost"><?= te('Show') ?></button>
</form>
<?php endif; ?>
<div class="kpis" id="kpis" data-dashboard="<?= e($dashboard) ?>">
  <?php foreach ($result['kpis'] as [$k, $label, $v, $type]): ?>
  <div class="card kpi" data-kpi="<?= e($k) ?>" data-v="<?= e($v === null ? '' : (is_float($v) ? number_format($v, 2, '.', '') : (string) $v)) ?>"><div class="small muted"><?= te($label) ?></div><div class="v mono"><?= e($kpiValue($v, $type)) ?></div></div>
  <?php endforeach; ?>
</div>
<?php foreach ($result['tables'] as $result_table): $result = $result_table; $tableId = $result_table['id']; ?>
<div class="card scroll" data-table="<?= e($tableId) ?>"><h2><?= te($result_table['title']) ?></h2>
  <?php require __DIR__ . '/_report-table.php'; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>
