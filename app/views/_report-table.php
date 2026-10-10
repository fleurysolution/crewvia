<?php
/** One report as a table, the same on screen and on paper. Needs $result; $tableId names it when a page holds several. */
$tid = $tableId ?? 'report-table';
$cell = static function ($v, string $type): string {
    if ($v === null || $v === '') {
        return '';
    }
    return match ($type) {
        'money' => money($v),
        'int'   => (string) (int) $v,
        'num'   => rtrim(rtrim(number_format((float) $v, 2), '0'), '.'),
        'pct'   => number_format((float) $v, 1) . ' %',
        'date'  => d((string) $v),
        'label' => t(ucfirst(str_replace('_', ' ', (string) $v))),
        default => (string) $v,
    };
};
$raw = static fn($v): string => $v === null ? '' : (is_float($v) ? number_format($v, 2, '.', '') : (string) $v);
?>
<table class="report" id="<?= e($tid) ?>" data-rows="<?= count($result['rows']) ?>">
  <tr><?php foreach ($result['columns'] as $c => [$label, $type]): ?><th<?= in_array($type, ['money', 'int', 'num', 'pct'], true) ? ' class="num"' : '' ?>><?= te($label) ?></th><?php endforeach; ?></tr>
  <?php foreach ($result['rows'] as $r): ?>
  <tr data-row="<?= e($r['key']) ?>"><?php foreach ($result['columns'] as $c => [$label, $type]): ?><td data-c="<?= e($c) ?>" data-v="<?= e($raw($r[$c] ?? null)) ?>"<?= in_array($type, ['money', 'int', 'num', 'pct'], true) ? ' class="num mono"' : '' ?>><?= e($cell($r[$c] ?? null, $type)) ?></td><?php endforeach; ?></tr>
  <?php endforeach; ?>
  <?php if (! $result['rows']): ?><tr><td colspan="<?= count($result['columns']) ?>" class="muted"><?= te('Nothing to report for these filters.') ?></td></tr><?php endif; ?>
  <?php if ($result['totals']): ?>
  <tr class="total" id="<?= e($tid === 'report-table' ? 'report-totals' : $tid . '-totals') ?>"><?php $first = true; foreach ($result['columns'] as $c => [$label, $type]): $v = $result['totals'][$c] ?? null; ?>
    <th data-c="<?= e($c) ?>" data-v="<?= e($raw($v)) ?>"<?= in_array($type, ['money', 'int', 'num', 'pct'], true) ? ' class="num mono"' : '' ?>><?= $first ? te('Total: :n', ['n' => (string) ($v ?? 0)]) : e($type === 'label' || $type === 'text' ? ($v === null ? '' : (string) $v) : $cell($v, $type)) ?></th>
  <?php $first = false; endforeach; ?></tr>
  <?php endif; ?>
</table>
<?php foreach ($result['notes'] as $n): ?><p class="small muted"><?= e($n) ?></p><?php endforeach; ?>
