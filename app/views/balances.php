<?php
$buckets = bal_buckets();
$sideLabel = $side === 'ar' ? t('Client') : t('Vendor');
$url = static fn(string $s, $p = null): string => '/balances?side=' . $s . ($p !== null ? '&party=' . rawurlencode((string) $p) : '');
$totals = array_fill_keys(array_merge(array_keys($buckets), ['open', 'on_account', 'net']), 0.0);
foreach ($aging as $a) { foreach ($totals as $k => $_) { $totals[$k] += $a[$k]; } }
?>
<h1><?= $side === 'ar' ? te('What clients owe') : te('What we owe vendors') ?></h1>
<p class="sub"><a href="<?= e($url('ar')) ?>"<?= $side === 'ar' ? ' class="tag blue"' : '' ?>><?= te('Receivables') ?></a> · <a href="<?= e($url('ap')) ?>"<?= $side === 'ap' ? ' class="tag blue"' : '' ?>><?= te('Payables') ?></a>
  · <?= te('A payment is recorded once and applied to invoices; what is not applied stays on account. Nothing is deleted: it is reversed, with a reason.') ?></p>

<div class="card scroll" id="aging"><h2><?= te('Aging') ?></h2>
  <?php if (! $aging): ?><p class="muted"><?= te('Nothing open.') ?></p><?php else: ?>
  <table><tr><th><?= e($sideLabel) ?></th><?php foreach ($buckets as $b): ?><th class="num"><?= e($b) ?></th><?php endforeach; ?><th class="num"><?= te('Open') ?></th><th class="num"><?= te('On account') ?></th><th class="num"><?= te('Net') ?></th></tr>
  <?php foreach ($aging as $key => $a): ?>
    <tr data-party="<?= e((string) $key) ?>"><td><a href="<?= e($url($side, $key)) ?>"><?= e($a['label']) ?></a></td>
      <?php foreach ($buckets as $k => $_): ?><td class="num mono" data-bucket="<?= e($k) ?>"><?= $a[$k] > 0 ? e(money($a[$k])) : '' ?></td><?php endforeach; ?>
      <td class="num mono" data-open="<?= e(number_format($a['open'], 2, '.', '')) ?>"><?= e(money($a['open'])) ?></td>
      <td class="num mono" data-on-account="<?= e(number_format($a['on_account'], 2, '.', '')) ?>"><?= $a['on_account'] > 0 ? e(money($a['on_account'])) : '' ?></td>
      <td class="num mono"><?= e(money($a['net'])) ?></td></tr>
  <?php endforeach; ?>
    <tr><th><?= te('Total') ?></th><?php foreach ($buckets as $k => $_): ?><th class="num mono"><?= e(money($totals[$k])) ?></th><?php endforeach; ?><th class="num mono"><?= e(money($totals['open'])) ?></th><th class="num mono"><?= e(money($totals['on_account'])) ?></th><th class="num mono"><?= e(money($totals['net'])) ?></th></tr>
  </table><?php endif; ?>
  <form method="get" class="row"><input type="hidden" name="side" value="<?= e($side) ?>"><select name="party"><option value=""><?= e($sideLabel) ?>…</option><?php foreach ($parties as $p): ?><option value="<?= e((string) $p['id']) ?>"<?= (string) $party === (string) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select><button class="btn ghost"><?= te('Open the statement') ?></button></form>
</div>

<div class="card" id="exceptions"><h2><?= te('To reconcile') ?></h2>
  <?php if (! $exceptions): ?><p class="muted"><?= te('Every invoice\'s status matches its balance, and no payment has sat on account for over 30 days.') ?></p>
  <?php else: foreach ($exceptions as $x): ?><div class="err" data-exception="1"><?= e($x) ?></div><?php endforeach; endif; ?>
</div>

<?php if ($detail): ?>
<h2 id="party-name"><?= e($detail['label']) ?></h2>
<?php if ($side === 'ar'): ?>
<div class="sub"><?= te('Pays within :n days', ['n' => (int) $detail['terms']]) ?><?php if (can('admin')): ?>
  <form method="post" class="row" style="display:inline-flex"><?= csrf_field() ?><input type="hidden" name="do" value="terms"><input type="hidden" name="side" value="ar"><input type="hidden" name="party" value="<?= e((string) $party) ?>"><input name="payment_terms_days" type="number" min="0" max="365" value="<?= (int) $detail['terms'] ?>" style="width:90px"><button class="btn sm ghost"><?= te('Change terms') ?></button></form><?php endif; ?></div>
<?php endif; ?>

<div class="card scroll" id="statement"><h2><?= te('Statement') ?></h2>
  <?php if (! $detail['statement']): ?><p class="muted"><?= te('Nothing yet.') ?></p><?php else: ?>
  <table><tr><th><?= te('Date') ?></th><th><?= te('What') ?></th><th class="num"><?= te('Amount') ?></th><th class="num"><?= te('Balance') ?></th></tr>
  <?php foreach ($detail['statement'] as $ev): ?><tr><td><?= e(d($ev['date'])) ?></td><td><?= e($ev['what']) ?></td><td class="num mono"><?= e(($ev['amount'] < 0 ? '−' : '') . money(abs($ev['amount']))) ?></td><td class="num mono" data-running="<?= e(number_format($ev['balance'], 2, '.', '')) ?>"><?= e(money($ev['balance'])) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>

<form class="card" method="post" id="record-form"><?= csrf_field() ?><input type="hidden" name="do" value="record"><input type="hidden" name="side" value="<?= e($side) ?>"><input type="hidden" name="party" value="<?= e((string) $party) ?>">
  <h2><?= $side === 'ar' ? te('Record a payment received') : te('Record a payment made') ?></h2>
  <div class="grid g4">
    <div><label><?= te('Date') ?></label><input type="date" name="received_on" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>"></div>
    <div><label><?= te('Amount') ?></label><input name="amount" type="number" min="0.01" step="0.01" required></div>
    <div><label><?= te('How') ?></label><select name="method"><?php foreach (bal_methods() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label><?= te('Reference') ?></label><input name="reference" required minlength="2" maxlength="190"></div>
  </div>
  <label><?= te('Note') ?></label><input name="note" maxlength="500">
  <?php if ($detail['open']): ?>
  <table><tr><th><?= te('Apply to') ?></th><th><?= te('Due') ?></th><th class="num"><?= te('Open') ?></th><th class="num"><?= te('Apply') ?></th></tr>
  <?php foreach ($detail['open'] as $i): ?><tr data-open-invoice="<?= (int) $i['id'] ?>"><td><?= e($i['reference'] . ' · ' . $i['project']) ?></td><td<?= $i['days_late'] > 0 ? ' class="err"' : '' ?>><?= $i['due_on'] ? e(d((string) $i['due_on'])) : '—' ?></td><td class="num mono"><?= e(money($i['balance'])) ?></td><td><input name="apply[<?= (int) $i['id'] ?>]" type="number" min="0" step="0.01" max="<?= e(number_format($i['balance'], 2, '.', '')) ?>"></td></tr><?php endforeach; ?></table>
  <p class="muted"><?= te('Leave an invoice empty to keep that part on account.') ?></p><?php endif; ?>
  <button class="btn"><?= te('Record') ?></button>
</form>

<div class="card scroll" id="payments"><h2><?= te('Payments') ?></h2>
  <?php if (! $detail['payments']): ?><p class="muted"><?= te('No payment recorded.') ?></p><?php else: ?>
  <table><tr><th><?= te('Date') ?></th><th><?= te('Reference') ?></th><th class="num"><?= te('Amount') ?></th><th class="num"><?= te('On account') ?></th><th></th></tr>
  <?php foreach ($detail['payments'] as $p): ?>
    <tr data-payment="<?= (int) $p['id'] ?>"<?= $p['reversed_at'] ? ' class="muted"' : '' ?>><td><?= e(d((string) $p['received_on'])) ?></td><td><?= e($p['reference'] . ' · ' . (bal_methods()[$p['method']] ?? $p['method'])) ?><?= $p['reversed_at'] ? ' <span class="tag grey">' . te('Reversed') . '</span> ' . e((string) $p['reversal_reason']) : '' ?></td>
      <td class="num mono"><?= e(money($p['amount'])) ?></td><td class="num mono"><?= $p['unapplied'] > 0 ? e(money($p['unapplied'])) : '' ?></td>
      <td><?php if (! $p['reversed_at']): ?>
        <?php if ($p['unapplied'] > 0 && $detail['open']): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="apply"><input type="hidden" name="side" value="<?= e($side) ?>"><input type="hidden" name="party" value="<?= e((string) $party) ?>"><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
          <select name="invoice_id"><?php foreach ($detail['open'] as $i): ?><option value="<?= (int) $i['id'] ?>"><?= e($i['reference'] . ' · ' . money($i['balance'])) ?></option><?php endforeach; ?></select><input name="amount" type="number" min="0.01" step="0.01" required style="width:110px"><button class="btn sm"><?= te('Apply') ?></button></form><?php endif; ?>
        <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="reverse_payment"><input type="hidden" name="side" value="<?= e($side) ?>"><input type="hidden" name="party" value="<?= e((string) $party) ?>"><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>"><input name="reason" required minlength="3" maxlength="500" placeholder="<?= te('Why it is reversed') ?>"><button class="btn sm ghost"><?= te('Reverse') ?></button></form>
      <?php endif; ?></td></tr>
  <?php endforeach; ?></table><?php endif; ?>
</div>

<div class="grid g2">
  <div class="card scroll" id="applications"><h2><?= te('Applications') ?></h2>
    <?php if (! $detail['applied']): ?><p class="muted"><?= te('Nothing applied yet.') ?></p><?php else: ?>
    <table><?php foreach ($detail['applied'] as $a): ?><tr<?= $a['reversed_at'] ? ' class="muted"' : '' ?>><td><?= e($a['payment_ref'] . ' → ' . $a['invoice_ref']) ?></td><td class="num mono"><?= e(money($a['amount'])) ?></td>
      <td><?php if (! $a['reversed_at']): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="unapply"><input type="hidden" name="side" value="<?= e($side) ?>"><input type="hidden" name="party" value="<?= e((string) $party) ?>"><input type="hidden" name="allocation_id" value="<?= (int) $a['id'] ?>"><input name="reason" required minlength="3" maxlength="500" placeholder="<?= te('Why') ?>"><button class="btn sm ghost"><?= te('Take back') ?></button></form>
      <?php else: ?><span class="small"><?= te('Taken back') ?> · <?= e((string) $a['reversal_reason']) ?></span><?php endif; ?></td></tr><?php endforeach; ?></table><?php endif; ?>
  </div>
  <div class="card scroll" id="credits"><h2><?= te('Credit notes') ?></h2>
    <?php if (can('admin') && $detail['open']): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="credit"><input type="hidden" name="side" value="<?= e($side) ?>"><input type="hidden" name="party" value="<?= e((string) $party) ?>">
      <div class="grid g2"><div><label><?= te('Invoice') ?></label><select name="invoice_id"><?php foreach ($detail['open'] as $i): ?><option value="<?= (int) $i['id'] ?>"><?= e($i['reference'] . ' · ' . money($i['balance'])) ?></option><?php endforeach; ?></select></div>
        <div><label><?= te('Amount') ?></label><input name="amount" type="number" min="0.01" step="0.01" required></div></div>
      <label><?= te('Date') ?></label><input type="date" name="issued_on" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required>
      <label><?= te('Reason') ?></label><input name="reason" required minlength="3" maxlength="500"><button class="btn"><?= te('Issue the credit note') ?></button></form>
    <?php endif; ?>
    <?php if ($detail['credits']): ?><table><?php foreach ($detail['credits'] as $c): ?><tr data-credit="<?= (int) $c['id'] ?>"<?= $c['reversed_at'] ? ' class="muted"' : '' ?>><td><?= e(d((string) $c['issued_on']) . ' · ' . $c['invoice_ref'] . ' · ' . $c['reason']) ?></td><td class="num mono"><?= e(money($c['amount'])) ?></td>
      <td><?php if (! $c['reversed_at'] && can('admin')): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="reverse_credit"><input type="hidden" name="side" value="<?= e($side) ?>"><input type="hidden" name="party" value="<?= e((string) $party) ?>"><input type="hidden" name="credit_id" value="<?= (int) $c['id'] ?>"><input name="reason" required minlength="3" maxlength="500" placeholder="<?= te('Why') ?>"><button class="btn sm ghost"><?= te('Reverse') ?></button></form><?php elseif ($c['reversed_at']): ?><span class="small"><?= te('Reversed') ?></span><?php endif; ?></td></tr><?php endforeach; ?></table>
    <?php elseif (! can('admin')): ?><p class="muted"><?= te('Only an administrator issues a credit note.') ?></p><?php endif; ?>
  </div>
</div>
<?php endif; ?>
