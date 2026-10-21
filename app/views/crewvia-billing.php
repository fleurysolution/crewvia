<?php
/** P5-M100: the Crewvia subscription - the count Fleury Solutions bills, as RSS sees it before the invoice. */
$money = static fn(float $v): string => '$' . number_format($v, 2);
?>
<div class="print-only letterhead" aria-hidden="true">
  <img src="/assets/fleury-solutions-symbol.svg" alt="" class="letterhead-logo">
  <div><strong>Fleury Solutions LLC</strong><br><span class="small">1705 Hwy 138 SE, Suite 80003, Conyers, GA 30013 &middot; solutionsfleury@gmail.com</span></div>
</div>

<div class="page-head"><div>
  <h1><?= $month !== '' ? te('Crewvia usage statement · :month', ['month' => $month]) : te('Crewvia subscription') ?></h1>
  <p class="sub"><?= te('Billed per person paid in the month: at least one day validated by a supervisor, or a line in a pay cycle. Each person counts once, whatever the number of sites. :rate per person, minimum :min a month.', ['rate' => $money(bl_rate()), 'min' => $money(bl_minimum())]) ?></p>
</div>
<?php if ($month !== ''): ?><div class="actions no-print"><a class="btn ghost" href="/crewvia-billing"><?= te('Back') ?></a> <a class="btn ghost" href="/crewvia-billing?month=<?= e($month) ?>&amp;csv=1" data-csv><?= te('Download CSV') ?></a> <button class="btn" type="button" onclick="window.print()"><?= te('Print or save as PDF') ?></button></div><?php endif; ?>
</div>

<?php if ($month === ''): ?>
<div class="grid g2">
  <section class="card" id="billing-now">
    <span class="eyebrow"><?= te('THIS MONTH SO FAR') ?></span>
    <p class="kpi m0" data-people-now="<?= (int) $now['people'] ?>"><?= (int) $now['people'] ?></p>
    <p class="m0"><?= tn((int) $now['people'], ':n person paid so far in :month', ':n people paid so far in :month', ['month' => $current]) ?> &middot; <?= tn((int) $now['events'], ':n record', ':n records') ?></p>
    <p class="small muted"><?= te('At this count the month comes to :amount. The figure is sealed on the 1st of next month.', ['amount' => $money(bl_amount((int) $now['people']))]) ?></p>
    <a class="btn ghost sm" href="/crewvia-billing?month=<?= e($current) ?>"><?= te('See the people counted') ?></a>
  </section>
  <section class="card" id="billing-chain">
    <span class="eyebrow"><?= te('LEDGER') ?></span>
    <?php if ($check['ok']): ?>
      <p class="m0"><span class="tag green" data-chain="ok"><?= te('Chain intact') ?></span> <?= tn((int) $check['checked'], ':n record checked', ':n records checked') ?></p>
    <?php else: ?>
      <p class="m0"><span class="tag red" data-chain="broken"><?= te('Chain broken at record :n', ['n' => (int) $check['broken_at']]) ?></span></p>
    <?php endif; ?>
    <p class="small muted"><?= te('Every record is written once and carries the fingerprint of the one before. Records cannot be changed or deleted, and a sealed month is never recomputed.') ?></p>
    <?php if ($canSeal): ?>
      <form method="post" class="mt-s"><?= csrf_field() ?><input type="hidden" name="do" value="seal"><input type="hidden" name="month" value="<?= e($previous) ?>"><button class="btn sm" data-seal="<?= e($previous) ?>"><?= te('Seal :month now', ['month' => $previous]) ?></button></form>
    <?php endif; ?>
  </section>
</div>

<section class="card" id="billing-months">
  <h2 class="m0"><?= te('Sealed months') ?></h2>
  <?php if (! $closings): ?><p class="muted"><?= te('No month sealed yet. The first is sealed on the 1st of next month.') ?></p><?php else: ?>
  <div class="scroll"><table class="mt-s">
    <thead><tr><th><?= te('Month') ?></th><th class="right"><?= te('People') ?></th><th class="right"><?= te('Records') ?></th><th class="right"><?= te('Amount') ?></th><th><?= te('Sealed') ?></th><th><?= te('Fingerprint') ?></th><th></th></tr></thead>
    <tbody><?php foreach ($closings as $c): ?><tr data-closing="<?= e($c['period_month']) ?>">
      <td><strong><?= e($c['period_month']) ?></strong></td>
      <td class="right"><?= (int) $c['billable_people'] ?></td>
      <td class="right"><?= (int) $c['event_count'] ?></td>
      <td class="right"><?= e($money(bl_amount((int) $c['billable_people']))) ?></td>
      <td class="small"><?= e(substr((string) $c['sealed_at'], 0, 16)) ?> UTC</td>
      <td class="small mono" title="<?= e($c['ledger_hash']) ?>"><?= e(substr((string) $c['ledger_hash'], 0, 12)) ?>&hellip;</td>
      <td><a class="btn ghost sm" href="/crewvia-billing?month=<?= e($c['period_month']) ?>"><?= te('Statement') ?></a></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</section>

<?php else: ?>
<section class="card" id="billing-statement" data-month="<?= e($month) ?>">
  <div class="grid g3">
    <div><span class="eyebrow"><?= te('PEOPLE') ?></span><p class="kpi m0" data-statement-people="<?= (int) $monthFigures['people'] ?>"><?= (int) $monthFigures['people'] ?></p></div>
    <div><span class="eyebrow"><?= te('AMOUNT') ?></span><p class="kpi m0"><?= e($money(bl_amount((int) $monthFigures['people']))) ?></p></div>
    <div><span class="eyebrow"><?= te('STATUS') ?></span><p class="m0 mt-xs"><?php if ($monthClosing): ?><span class="tag green"><?= te('Sealed') ?></span> <span class="small"><?= e(substr((string) $monthClosing['sealed_at'], 0, 16)) ?> UTC</span><br><span class="small mono"><?= e($monthClosing['ledger_hash']) ?></span><?php else: ?><span class="tag amber"><?= te('Open: the count can still grow') ?></span><?php endif; ?></p></div>
  </div>
  <?php if ($lateLines): ?><p class="small muted mt-s" data-late="<?= (int) $lateLines ?>"><?= tn($lateLines, ':n record was validated after the month was sealed; it is kept in the ledger and not added to the sealed count.', ':n records were validated after the month was sealed; they are kept in the ledger and not added to the sealed count.') ?></p><?php endif; ?>
  <div class="scroll"><table class="mt-s">
    <thead><tr><th><?= te('Person') ?></th><th><?= te('Projects') ?></th><th class="right"><?= te('Days validated') ?></th><th class="right"><?= te('Pay-cycle lines') ?></th><th><?= te('From') ?></th><th><?= te('To') ?></th></tr></thead>
    <tbody><?php foreach ($statement as $r): ?><tr data-counted="<?= (int) $r['candidate_id'] ?>">
      <td><?= e($r['full_name']) ?></td><td class="small"><?= e($r['projects']) ?></td>
      <td class="right"><?= (int) $r['days'] ?></td><td class="right"><?= (int) $r['pay_lines'] ?></td>
      <td class="small"><?= e(d((string) $r['first_day'])) ?></td><td class="small"><?= e(d((string) $r['last_day'])) ?></td>
    </tr><?php endforeach; ?>
    <?php if (! $statement): ?><tr><td colspan="6" class="muted"><?= te('Nobody paid in this month yet.') ?></td></tr><?php endif; ?></tbody>
  </table></div>
</section>
<?php endif; ?>
