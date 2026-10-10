<?php
$sum = static fn(array $entries, string $side): float => round(array_sum(array_map(fn($e) => array_sum(array_column($e['lines'], $side)), $entries)), 2);
?>
<h1><?= te('QuickBooks export') ?></h1>
<p class="sub"><?= te('Drawn from the Crewvia ledger: each journal goes to QuickBooks Online once, so nothing is keyed twice. A batch is never edited: a mistake is cancelled by a reversal.') ?> <a href="/ledger"><?= te('Ledger') ?></a></p>

<div class="card" id="export-pending">
  <h2><?= te('Waiting to be exported') ?></h2>
  <form method="get" class="row"><label><?= te('Up to') ?></label><input type="date" name="through" value="<?= e($through) ?>" max="<?= e(date('Y-m-d')) ?>"><button class="btn ghost"><?= te('Show') ?></button></form>
  <p><span data-pending="<?= count($pending) ?>"><?= te(':n entries', ['n' => count($pending)]) ?></span> · <?= te('debits') ?> <span class="mono"><?= e(money($sum($pending, 'debit'))) ?></span> · <?= te('credits') ?> <span class="mono"><?= e(money($sum($pending, 'credit'))) ?></span>
    · <?= $payroll ? te('payroll included') : te('payroll not included: ADP posts it') ?></p>
  <?php if ($blockers): ?><div class="err" id="export-blockers"><?php foreach ($blockers as $b): ?><div><?= e($b) ?></div><?php endforeach; ?></div><?php endif; ?>
  <?php if ($pending): ?>
  <div class="scroll"><table><tr><th><?= te('Date') ?></th><th><?= te('Record') ?></th><th><?= te('Account') ?></th><th class="num"><?= te('Debit') ?></th><th class="num"><?= te('Credit') ?></th><th><?= te('Name') ?></th><th><?= te('Class') ?></th></tr>
  <?php foreach (array_slice($pending, 0, 100) as $en): foreach ($en['lines'] as $k => $l): ?>
    <tr data-source="<?= e($en['source']) ?>"><td><?= $k === 0 ? e(d($en['date'])) : '' ?></td><td class="small"><?= $k === 0 ? e($en['memo']) : '' ?></td>
      <td><?= e(isset($accounts[$l['account']]) ? $accounts[$l['account']]['qb_account'] : $l['account']) ?></td>
      <td class="num mono"><?= $l['debit'] > 0 ? e(money($l['debit'])) : '' ?></td><td class="num mono"><?= $l['credit'] > 0 ? e(money($l['credit'])) : '' ?></td>
      <td><?= e((string) $l['party']) ?></td><td><?= e((string) $l['class']) ?></td></tr>
  <?php endforeach; endforeach; ?></table></div>
  <?php if (count($pending) > 100): ?><p class="muted"><?= te('The first 100 are shown; the export takes them all.') ?></p><?php endif; ?>
  <?php if (! $blockers): ?>
  <form method="post" id="export-form"><?= csrf_field() ?><input type="hidden" name="do" value="export"><input type="hidden" name="through" value="<?= e($through) ?>">
    <button class="btn"><?= te('Export these :n entries', ['n' => count($pending)]) ?></button></form>
  <?php endif; endif; ?>
</div>

<div class="card scroll" id="export-batches"><h2><?= te('Exports') ?></h2>
  <?php if (! $batches): ?><p class="muted"><?= te('Nothing exported yet.') ?></p><?php else: ?>
  <table><tr><th>#</th><th><?= te('Kind') ?></th><th><?= te('Up to') ?></th><th class="num"><?= te('Entries') ?></th><th class="num"><?= te('Total') ?></th><th><?= te('By') ?></th><th><?= te('State') ?></th><th></th></tr>
  <?php foreach ($batches as $b): ?>
    <tr data-batch="<?= (int) $b['id'] ?>" data-kind="<?= e($b['kind']) ?>" data-status="<?= e($b['status']) ?>">
      <td class="mono"><?= (int) $b['id'] ?></td>
      <td><?= $b['kind'] === 'reversal' ? te('Reversal of :n', ['n' => (int) $b['reverses_batch_id']]) : te('Export') ?><?= $b['reason'] ? '<div class="small muted">' . e($b['reason']) . '</div>' : '' ?></td>
      <td><?= e(d((string) $b['through_date'])) ?></td><td class="num mono"><?= (int) $b['journal_count'] ?></td><td class="num mono"><?= e(money($b['total'])) ?></td>
      <td><?= e(($b['by_name'] ?? '') . ' · ' . d(substr((string) $b['created_at'], 0, 10))) ?></td>
      <td><?= $b['status'] === 'reversed' ? '<span class="tag grey">' . te('Reversed by :n', ['n' => (int) $b['reversed_by_batch_id']]) . '</span>' : '<span class="tag green">' . te('Standing') . '</span>' ?></td>
      <td><a class="btn sm ghost" href="/accounting?download=<?= (int) $b['id'] ?>"><?= te('Download') ?></a> <span class="small muted mono" title="SHA-256"><?= e(substr((string) $b['file_sha256'], 0, 12)) ?></span>
        <?php if ($b['kind'] === 'export' && $b['status'] === 'exported'): ?>
        <form method="post" class="row" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="do" value="reverse"><input type="hidden" name="batch_id" value="<?= (int) $b['id'] ?>">
          <input type="date" name="date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>"><input name="reason" placeholder="<?= te('Why it is reversed') ?>" required minlength="3" maxlength="500"><button class="btn sm ghost"><?= te('Reverse') ?></button></form>
        <?php endif; ?></td></tr>
  <?php endforeach; ?></table><?php endif; ?>
</div>

<?php if (can('admin')): ?>
<div class="grid g2">
  <form class="card" method="post" id="mapping-form"><?= csrf_field() ?><input type="hidden" name="do" value="mapping">
    <h2><?= te('Chart of accounts') ?></h2>
    <p class="muted"><?= te('Type each QuickBooks account exactly as your chart spells it, sub-accounts as Parent:Child, and tick it once checked. Nothing goes to an unchecked account.') ?></p>
    <table><tr><th><?= te('In Crewvia') ?></th><th><?= te('QuickBooks account') ?></th><th><?= te('Checked') ?></th></tr>
    <?php foreach ($accounts as $key => $a): ?>
      <tr data-account="<?= e($key) ?>"><td><?= te($a['label']) ?></td><td><input name="qb_account[<?= e($key) ?>]" value="<?= e($a['qb_account']) ?>" required maxlength="190"></td>
        <td><input type="checkbox" name="confirmed[<?= e($key) ?>]" value="1"<?= (int) $a['confirmed'] === 1 ? ' checked' : '' ?>></td></tr>
    <?php endforeach; ?></table>
    <button class="btn"><?= te('Save') ?></button>
  </form>
  <form class="card" method="post" id="payroll-form"><?= csrf_field() ?><input type="hidden" name="do" value="payroll">
    <h2><?= te('Payroll') ?></h2>
    <p class="muted"><?= te('RSS runs payroll through ADP, and ADP can post it to QuickBooks itself. Turn this on only if it does not: both would count the same pay.') ?></p>
    <label><input type="radio" name="payroll" value="0"<?= $payroll ? '' : ' checked' ?>> <?= te('ADP posts payroll; Crewvia does not') ?></label>
    <label><input type="radio" name="payroll" value="1"<?= $payroll ? ' checked' : '' ?>> <?= te('Crewvia exports approved payroll periods') ?></label>
    <label><?= te('Reason') ?></label><input name="reason" maxlength="500">
    <button class="btn"><?= te('Save') ?></button>
  </form>
</div>
<?php endif; ?>
