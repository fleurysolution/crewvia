<?php
$closed = static fn(string $m): bool => ($statuses[$m]['status'] ?? 'open') === 'closed';
$amount = static fn(float $v): string => abs($v) < 0.005 ? '' : money($v);
?>
<h1><?= te('Financial periods') ?></h1>
<p class="sub"><?= te('A closed month takes nothing new dated in it: no payment, credit note, issued invoice, approved bill, paid claim, journal or QuickBooks entry. Corrections go in an open month.') ?> <a href="/statements"><?= te('Financial statements') ?></a></p>

<div class="grid g2">
  <div class="card scroll" id="period-list"><h2><?= te('Months') ?></h2>
    <table><tr><th><?= te('Month') ?></th><th><?= te('State') ?></th><th></th></tr>
    <?php foreach ($months as $m): ?>
      <tr data-period="<?= e($m) ?>" data-status="<?= $closed($m) ? 'closed' : 'open' ?>"><td><a href="/periods?period=<?= e($m) ?>"><?= e($m) ?></a></td>
        <td><?= $closed($m) ? '<span class="tag grey">' . te('Closed') . '</span>' : '<span class="tag green">' . te('Open') . '</span>' ?></td>
        <td class="small muted"><?= isset($statuses[$m]['changed_at']) ? e(d(substr((string) $statuses[$m]['changed_at'], 0, 10))) : '' ?></td></tr>
    <?php endforeach; ?></table>
  </div>

  <div class="card" id="period-detail"><h2><?= e($selected) ?> · <?= $closed($selected) ? te('Closed') : te('Open') ?></h2>
    <p id="period-pending" data-pending="<?= count($pending) ?>"><?= $pending ? te(':n entries dated in this month are still waiting to be exported.', ['n' => count($pending)]) . ' <a href="/accounting?through=' . e(min(date('Y-m-t', strtotime($selected . '-01')), date('Y-m-d'))) . '">' . te('Export them') . '</a>'
                                                                 : te('Nothing dated in this month is waiting to be exported.') ?></p>
    <?php if (can('admin')): ?>
    <form method="post" id="period-form"><?= csrf_field() ?><input type="hidden" name="period" value="<?= e($selected) ?>"><input type="hidden" name="do" value="<?= $closed($selected) ? 'reopen' : 'close' ?>">
      <label><?= te('Reason') ?></label><input name="reason" required minlength="3" maxlength="500" placeholder="<?= $closed($selected) ? te('Why it is reopened') : te('for example: reconciled with QuickBooks') ?>">
      <button class="btn<?= $closed($selected) ? ' ghost' : '' ?>"><?= $closed($selected) ? te('Reopen the month') : te('Close the month') ?></button>
    </form><?php endif; ?>
  </div>
</div>

<div class="card scroll" id="trial-balance"><h2><?= te('Trial balance of what was exported') ?> · <?= e($selected) ?></h2>
  <p class="muted"><?= te('Each account as Crewvia\'s exports and reversals left it. Compare it with the same accounts in QuickBooks: a difference is an entry missing on one side.') ?></p>
  <?php if (! $trial['rows']): ?><p class="muted"><?= te('Nothing exported up to the end of this month.') ?></p><?php else: ?>
  <table><tr><th><?= te('Account') ?></th><th><?= te('QuickBooks account') ?></th><th class="num"><?= te('Opening') ?></th><th class="num"><?= te('Debits') ?></th><th class="num"><?= te('Credits') ?></th><th class="num"><?= te('Closing debit') ?></th><th class="num"><?= te('Closing credit') ?></th></tr>
  <?php foreach ($trial['rows'] as $r): ?>
    <tr data-account="<?= e($r['key']) ?>" data-closing="<?= e(number_format($r['closing'], 2, '.', '')) ?>" data-debit="<?= e(number_format($r['debit'], 2, '.', '')) ?>" data-credit="<?= e(number_format($r['credit'], 2, '.', '')) ?>">
      <td><?= te($r['label']) ?></td><td><?= e($r['qb_account']) ?></td><td class="num mono"><?= e($amount($r['opening'])) ?></td>
      <td class="num mono"><?= e($amount($r['debit'])) ?></td><td class="num mono"><?= e($amount($r['credit'])) ?></td>
      <td class="num mono"><?= e($amount(max(0, $r['closing']))) ?></td><td class="num mono"><?= e($amount(max(0, -$r['closing']))) ?></td></tr>
  <?php endforeach; ?>
    <tr><th colspan="3"><?= te('Total') ?></th><th class="num mono" id="tb-debit"><?= e(money($trial['debit'])) ?></th><th class="num mono" id="tb-credit"><?= e(money($trial['credit'])) ?></th>
      <th class="num mono" id="tb-closing-debit"><?= e(money($trial['closing_debit'])) ?></th><th class="num mono" id="tb-closing-credit"><?= e(money($trial['closing_credit'])) ?></th></tr>
  </table>
  <p id="tb-balanced" data-balanced="<?= $trial['balanced'] ? '1' : '0' ?>" class="<?= $trial['balanced'] ? 'muted' : 'err' ?>"><?= $trial['balanced'] ? te('Debits equal credits.') : te('Debits and credits differ: an export is incomplete. Do not close the month.') ?></p>
  <?php endif; ?>
</div>

<div class="card scroll" id="period-history"><h2><?= te('Closes and reopens') ?></h2>
  <?php if (! $events): ?><p class="muted"><?= te('No month closed yet.') ?></p><?php else: ?>
  <table><?php foreach ($events as $ev): ?><tr data-event="<?= e($ev['action']) ?>"><td><?= e(d(substr((string) $ev['created_at'], 0, 10))) ?></td><td><?= e($ev['period']) ?></td><td><?= $ev['action'] === 'close' ? te('Closed') : te('Reopened') ?></td><td><?= e($ev['reason']) ?></td><td><?= e($ev['by_name'] ?? '') ?></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>
