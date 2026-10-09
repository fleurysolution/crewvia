<h1><?= te('Billing') ?></h1>
<p class="sub">
  <?= e($job['client_name'] ?? 'Client') ?> &middot; <?= e($job['title'] ?? '') ?>
  <?php if (empty($job['bill_rate'])): ?>
    &middot; <span class="tag amber"><?= te('no client rate set &mdash; revenue cannot be computed') ?></span>
  <?php else: ?>
    &middot; billing at <?= money($job['bill_rate']) ?>/h against <?= money($job['pay_rate']) ?>/h paid
  <?php endif; ?>
</p>

<form class="card" method="get" action="/billing">
  <div class="row">
    <div><label for="from"><?= te('From') ?></label><input id="from" type="date" name="from" value="<?= e($from) ?>"></div>
    <div><label for="to">To</label><input id="to" type="date" name="to" value="<?= e($to) ?>"></div>
    <div class="row tight"><button class="btn" type="submit"><?= te('Show') ?></button></div>
  </div>
</form>

<div class="grid g4" style="margin-bottom:16px">
  <div class="stat"><div class="n"><?= money($grand['bill']) ?></div><div class="l"><?= te('Billable') ?></div>
    <div class="h"><?= rtrim(rtrim(number_format($grand['hours'], 1), '0'), '.') ?> hours worked</div></div>
  <div class="stat"><div class="n"><?= money($grand['pay']) ?></div><div class="l"><?= te('Payroll cost') ?></div>
    <div class="h">includes <?= money($grand['perdiem']) ?> per diem</div></div>
  <div class="stat">
    <div class="n" style="<?= $grand['margin'] < 0 ? 'color:var(--red)' : 'color:var(--green)' ?>">
      <?= $grand['bill'] > 0 ? money($grand['margin']) : '—' ?>
    </div>
    <div class="l"><?= te('Gross margin') ?></div>
    <div class="h"><?= te('labour only') ?></div>
  </div>
  <div class="stat"><div class="n"><?= money($bedNights + $travelCost) ?></div>
    <div class="l"><?= te('Hotel &amp; travel') ?></div>
    <div class="h"><?= money($bedNights) ?> rooms, <?= money($travelCost) ?> travel</div></div>
</div>

<?php if ($grand['bill'] > 0): ?>
<div class="card" style="background:#FAFBFD">
  <h2><?= te('Where the money lands') ?></h2>
  <table style="max-width:460px">
    <tr><td><?= te('Billed to client') ?></td><td class="num mono"><?= money($grand['bill']) ?></td></tr>
    <tr><td><?= te('Wages and per diem') ?></td><td class="num mono">(<?= money($grand['pay']) ?>)</td></tr>
    <tr><td><?= te('Hotels and travel') ?></td><td class="num mono">(<?= money($bedNights + $travelCost) ?>)</td></tr>
    <tr style="font-weight:700;border-top:2px solid var(--line)">
      <td><?= te('Left before overhead') ?></td>
      <td class="num mono" style="<?= ($grand['margin'] - $bedNights - $travelCost) < 0 ? 'color:var(--red)' : 'color:var(--green)' ?>">
        <?= money($grand['margin'] - $bedNights - $travelCost) ?>
      </td>
    </tr>
  </table>
  <?php if ($grand['short'] > 0): ?>
    <p class="small muted" style="margin:12px 0 0">
      <?= rtrim(rtrim(number_format($grand['short'], 1), '0'), '.') ?> hours of that payroll were the
      guarantee &mdash; paid but not worked. At <?= money($job['pay_rate'] ?? 50) ?>/h that is
      <strong><?= money($grand['short'] * (float) ($job['pay_rate'] ?? 50)) ?></strong> <?= te('RSS carried.') ?>
    </p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (! $weeks): ?>
  <div class="card"><div class="empty"><?= te('No timesheets in that range. Enter hours on the') ?> <a href="/hours"><?= te('Hours') ?></a> <?= te('screen.') ?></div></div>
<?php endif; ?>

<?php foreach ($weeks as $wk => $w): ?>
<div class="card tight">
  <div style="padding:13px 18px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0">Week ending <?= e(date('j F Y', strtotime($wk))) ?></h2>
    <div class="small muted">
      <?= $w['count'] ?> engineer<?= $w['count'] === 1 ? '' : 's' ?>
      &middot; <?= $w['approved'] ?> approved
      &middot; pay <strong><?= money($w['pay']) ?></strong>
      <?php if ($w['bill'] > 0): ?>&middot; bill <strong><?= money($w['bill']) ?></strong><?php endif; ?>
    </div>
  </div>
  <div class="scroll">
  <table>
    <thead><tr><th><?= te('Engineer') ?></th><th class="num"><?= te('Worked') ?></th><th class="num"><?= te('Paid') ?></th>
      <th class="num"><?= te('Per diem') ?></th><th class="num"><?= te('Expenses') ?></th><th class="num"><?= te('Pay') ?></th>
      <th class="num"><?= te('Bill') ?></th><th><?= te('State') ?></th></tr></thead>
    <tbody>
    <?php foreach ($w['lines'] as $l): $m = $l['m']; ?>
      <tr>
        <td><?= e($l['full_name']) ?><div class="small muted"><?= e(ucfirst($l['discipline'])) ?></div></td>
        <td class="num mono"><?= rtrim(rtrim(number_format($m['worked'], 1), '0'), '.') ?></td>
        <td class="num mono"><?= rtrim(rtrim(number_format($m['paid_hours'], 1), '0'), '.') ?>
          <?php if ($m['short_by'] > 0): ?>
            <div class="small" style="color:var(--amber)">+<?= rtrim(rtrim(number_format($m['short_by'], 1), '0'), '.') ?> gtd</div>
          <?php endif; ?>
        </td>
        <td class="num mono"><?= money($m['per_diem']) ?></td>
        <td class="num mono"><?= $m['expenses'] > 0 ? money($m['expenses']) : '—' ?></td>
        <td class="num mono"><?= money($m['pay_total']) ?></td>
        <td class="num mono"><?= $m['bill_total'] > 0 ? money($m['bill_total']) : '—' ?></td>
        <td><span class="tag <?= in_array($l['status'], ['approved','paid'], true) ? 'green' : 'amber' ?>">
          <?= e(ucfirst($l['status'])) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
