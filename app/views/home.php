<?php
$hd = $homeData;
$tile = static function (string $id, int $n, string $label, string $href, string $tone = ''): void { ?>
  <a class="card home-tile<?= $tone !== '' && $n ? ' ' . $tone : '' ?>" href="<?= e($href) ?>" data-tile="<?= e($id) ?>"><div class="n"><?= $n ?></div><div class="l"><?= e($label) ?></div></a>
<?php };
$who = (string) (user()['name'] ?? '');
?>
<div class="page-head"><div>
  <?php $first = strtok($who, ' ') ?: $who; $hour = (int) date('G'); ?>
  <h1><?= $hour < 12 ? te('Good morning, :name', ['name' => $first]) : ($hour < 18 ? te('Good afternoon, :name', ['name' => $first]) : te('Good evening, :name', ['name' => $first])) ?></h1>
  <p class="sub"><?= $homeRole === 'recruiter' ? te('Hiring today, across every running project: who applied, who waits for you, what is still short.')
                  : ($homeRole === 'hotels' ? te('Logistics today, across every running project: who arrives, who has no bed or no travel, what waits on you.')
                  : te('The pay week, across every running project: what to approve, what to settle, what is due.')) ?></p></div>
  <div class="actions">
    <?php if ($homeRole === 'recruiter'): ?><a class="btn" href="/hiring"><?= te('Open Hiring') ?></a><a class="btn ghost" href="/candidates"><?= te('Candidates') ?></a>
    <?php elseif ($homeRole === 'hotels'): ?><a class="btn" href="/hotels"><?= te('Hotels') ?></a><a class="btn ghost" href="/travel"><?= te('Travel') ?></a>
    <?php else: ?><a class="btn" href="/hours"><?= te('Hours') ?></a><a class="btn ghost" href="/payroll-runs"><?= te('Payroll runs') ?></a><?php endif; ?>
  </div>
</div>

<?php if ($homeRole === 'recruiter'): $c = $hd['counts']; ?>
<div class="home-tiles" id="home-tiles">
  <?php $tile('new_week', $c['new_week'] ?? 0, t('applied this week'), '/hiring'); ?>
  <?php $tile('waiting', $c['waiting'] ?? 0, t('new applicants waiting for review'), '/hiring', 'amber'); ?>
  <?php $tile('reviewing', $c['reviewing'] ?? 0, t('in screening or interview'), '/hiring'); ?>
  <?php $tile('offered', $c['offered'] ?? 0, t('offers waiting for an answer'), '/hiring'); ?>
</div>
<div class="grid g2">
  <section class="card" id="home-short"><h2><?= te('Requisitions still short of people') ?></h2>
    <?php if (! $hd['short']): ?><p class="small t-green"><?= te('Every open requisition has its people.') ?></p><?php else: ?>
    <table><tr><th><?= te('Requisition') ?></th><th class="num"><?= te('Hired') ?></th><th class="num"><?= te('New') ?></th></tr>
      <?php foreach ($hd['short'] as $v): ?><tr data-short="<?= (int) $v['id'] ?>"><td><a href="/hiring?v=<?= (int) $v['id'] ?>"><?= e($v['title']) ?></a><div class="small muted"><?= e($v['project']) ?></div></td><td class="num"><?= (int) $v['hired'] ?> / <?= (int) $v['openings'] ?></td><td class="num"><?= (int) $v['new_count'] ?></td></tr><?php endforeach; ?>
    </table><?php endif; ?></section>
  <section class="card" id="home-reach"><h2><?= te('Reaching the pool') ?></h2>
    <ul class="attention-list">
      <li><a href="/campaigns"><strong data-figure="sending"><?= (int) $hd['campaigns']['sending'] ?></strong> <?= ten((int) $hd['campaigns']['sending'], 'campaign going out', 'campaigns going out') ?></a></li>
      <li><a href="/campaigns"><strong data-figure="approval"><?= (int) $hd['campaigns']['waiting'] ?></strong> <?= ten((int) $hd['campaigns']['waiting'], 'campaign waiting for an administrator', 'campaigns waiting for an administrator') ?></a></li>
      <li><a href="/campaigns"><strong><?= (int) $hd['campaigns']['drafts'] ?></strong> <?= ten((int) $hd['campaigns']['drafts'], 'draft not sent', 'drafts not sent') ?></a></li>
      <li><a href="/candidates?stage=new"><strong data-figure="never_called"><?= (int) $hd['never_called'] ?></strong> <?= ten((int) $hd['never_called'], 'new person nobody has contacted', 'new people nobody has contacted') ?></a></li>
    </ul>
    <p class="mt"><a class="btn ghost sm" href="/campaigns"><?= te('Campaign email') ?></a> <a class="btn ghost sm" href="/requisitions"><?= te('Requisitions') ?></a></p></section>
</div>

<?php elseif ($homeRole === 'hotels'): $p = $hd['problems']; ?>
<div class="home-tiles" id="home-tiles">
  <?php $tile('arriving', count($hd['arrivals']), t('arriving in the next 7 days'), '/roster'); ?>
  <?php $tile('beds', $p['beds'], t('placed with no bed'), '/hotels', 'red'); ?>
  <?php $tile('sharing', $p['sharing'], t('sharing a room'), '/hotels', 'red'); ?>
  <?php $tile('travel', $p['travel'], t('travel not booked'), '/travel', 'amber'); ?>
</div>
<div class="grid g2">
  <section class="card" id="home-arrivals"><h2><?= te('Arriving this week') ?></h2>
    <?php if (! $hd['arrivals']): ?><p class="small muted"><?= te('Nobody starts in the next 7 days.') ?></p><?php else: ?>
    <table><tr><th><?= te('Person') ?></th><th><?= te('Starts') ?></th><th><?= te('Bed') ?></th><th><?= te('Travel') ?></th></tr>
      <?php foreach ($hd['arrivals'] as $a): ?><tr data-arrival="<?= (int) $a['id'] ?>"><td><a href="/placements/<?= (int) $a['id'] ?>"><?= e($a['full_name']) ?></a><div class="small muted"><?= e($a['project']) ?></div></td><td><?= e(d((string) $a['start_date'])) ?></td>
        <td><?= (int) $a['has_bed'] ? '<span class="tag green">' . te('booked') . '</span>' : '<span class="tag red">' . te('none') . '</span>' ?></td><td><?= (int) $a['has_travel'] ? '<span class="tag green">' . te('booked') . '</span>' : '<span class="tag amber">' . te('none') . '</span>' ?></td></tr><?php endforeach; ?>
    </table><?php endif; ?></section>
  <section class="card" id="home-waiting"><h2><?= te('Waiting on Logistics') ?></h2>
    <ul class="attention-list">
      <li><a href="/folios"><strong data-figure="folios"><?= (int) $hd['folios_open'] ?></strong> <?= ten((int) $hd['folios_open'], 'hotel folio to finish', 'hotel folios to finish') ?></a></li>
      <li><a href="/procurement"><strong data-figure="purchases"><?= (int) $hd['purchases'] ?></strong> <?= ten((int) $hd['purchases'], 'purchase request to order', 'purchase requests to order') ?></a></li>
      <li><a href="/roster"><strong><?= (int) $p['blocked'] ?></strong> <?= ten((int) $p['blocked'], 'person blocked from deploying', 'people blocked from deploying') ?></a></li>
      <li><a href="/roster"><strong><?= (int) $p['onsite'] ?></strong> <?= te('people on site now') ?></a></li>
    </ul></section>
</div>

<?php else: ?>
<div class="home-tiles" id="home-tiles">
  <?php $tile('hours_waiting', $hd['hours_waiting'], t('days of hours waiting for approval'), '/attendance', 'amber'); ?>
  <?php $tile('sheets_open', $hd['sheets_open'], t('weekly sheets not approved for :date', ['date' => d($hd['last_week'])]), '/hours?week=' . $hd['last_week'], 'amber'); ?>
  <?php $tile('disputes', $hd['disputes'], t('lateness disputes to settle'), '/lateness', 'red'); ?>
  <?php $tile('bills_due', $hd['bills_due'], t('bills past due'), '/accounts-payable', 'red'); ?>
</div>
<div class="grid g2">
  <section class="card" id="home-checks"><h2><?= te('To check') ?></h2>
    <ul class="attention-list">
      <li><a href="/change-requests"><strong data-figure="changes"><?= (int) $hd['changes'] ?></strong> <?= ten((int) $hd['changes'], 'detail or bank change waiting for a check', 'detail or bank changes waiting for a check') ?></a></li>
      <li><a href="/timeoff"><strong data-figure="timeoff"><?= (int) $hd['timeoff'] ?></strong> <?= ten((int) $hd['timeoff'], 'time-off request to decide', 'time-off requests to decide') ?></a></li>
      <li><a href="/accounts-payable"><strong data-figure="bills_week"><?= (int) $hd['bills_week'] ?></strong> <?= ten((int) $hd['bills_week'], 'bill due in the next 7 days', 'bills due in the next 7 days') ?></a></li>
    </ul></section>
  <section class="card" id="home-run"><h2><?= te('Run the week') ?></h2>
    <ol class="small steps">
      <li><a href="/attendance"><?= te('Approve the days') ?></a> · <a href="/lateness"><?= te('settle lateness') ?></a></li>
      <li><a href="/hours?week=<?= e($hd['last_week']) ?>"><?= te('Import the approved hours and approve the week') ?></a></li>
      <li><a href="/payroll-runs"><?= te('Close the pay period') ?></a> · <a href="/payroll-export"><?= te('export to ADP') ?></a></li>
      <li><a href="/billing"><?= te('Bill the clients') ?></a></li>
    </ol></section>
</div>
<?php endif; ?>
