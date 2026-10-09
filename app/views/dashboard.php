<?php
/** The front page. Four questions, answered above the fold. */
$pct = $target > 0 ? min(100, (int) round($placed / $target * 100)) : 0;
?>

<h1><?= e($job['title'] ?? 'No active job') ?></h1>
<p class="sub">
  <?php if ($job): ?>
    <?= e($job['client_name'] ?? 'Client') ?>
    &middot; <?= e(trim(($job['site_city'] ?? '') . ', ' . ($job['site_state'] ?? ''), ', ')) ?>
    <?php if (! empty($job['starts_on'])): ?>
      &middot; starts <?= e(date('j M Y', strtotime($job['starts_on']))) ?>
    <?php endif; ?>
    &middot;
    <?php if ((int) $job['strike_live'] === 1): ?>
      <span class="tag red">Strike live &mdash; <?= (int) $job['strike_hours'] ?>h guarantee</span>
    <?php else: ?>
      <span class="tag blue">Pre-strike &mdash; <?= (int) $job['guarantee_hours'] ?>h guarantee</span>
    <?php endif; ?>
  <?php else: ?>
    <?= te('Set a job up to begin.') ?>
    <?php if (can('admin')): ?>
      <a class="btn" href="/projects" style="margin-left:8px"><?= te('Create the first project') ?></a>
    <?php endif; ?>
  <?php endif; ?>
</p>

<?php
/* What this product is for, in one line of the screen. Five steps in the
   order they happen, each with the number of people standing at it and a way
   in. A manager reads it as a status; somebody new reads it as an
   explanation of the business. */
$steps = [
  ['/candidates',     'Applied',      $journey['applied'], 'people in the pipeline'],
  ['/recruitment',    'Being hired',  $journey['hiring'],  'offer and paperwork'],
  ['/roster',         'Ready to go',  $journey['ready'],   'confirmed, travelling'],
  ['/roster',         'On site',      $journey['onsite'],  'working now'],
  ['/hours',          'Paid',         $journey['paid'],    'this week approved'],
];
?>
<div class="card" style="padding:0;overflow:hidden">
  <div style="display:flex;flex-wrap:wrap">
    <?php foreach ($steps as $i => [$href, $label, $count, $hint]): ?>
      <a href="<?= e($href) ?>" style="flex:1;min-width:150px;padding:14px 16px;text-decoration:none;color:inherit;
         border-right:1px solid var(--line);<?= $i === 0 ? '' : '' ?>">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted)">
          <?= $i + 1 ?>. <?= te($label) ?>
        </div>
        <div style="font-size:24px;font-weight:700;line-height:1.2"><?= (int) $count ?></div>
        <div style="font-size:11.5px;color:var(--muted)"><?= te($hint) ?></div>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="grid g4" style="margin-bottom:16px">
  <div class="stat">
    <div class="n"><?= $placed ?><span class="muted" style="font-size:17px">/<?= $target ?></span></div>
    <div class="l"><?= te('Crew secured') ?></div>
    <div class="h"><?= $pct ?>% of target &middot; <?= $onSite ?> on site</div>
  </div>
  <div class="stat">
    <div class="n"><?= $pool ?></div>
    <div class="l"><?= te('Candidate pool, everywhere') ?></div>
    <div class="h"><?= $uncalled ?> never contacted</div>
  </div>
  <div class="stat">
    <div class="n" style="<?= ($noBed + $sharedRoom) > 0 ? 'color:var(--red)' : 'color:var(--green)' ?>">
      <?= $noBed ?>
    </div>
    <div class="l"><?= te('Without a bed') ?></div>
    <div class="h">
      <?php if ($sharedRoom > 0): ?>
        <span style="color:var(--red)"><?= $sharedRoom ?> sharing &mdash; promised private</span>
      <?php else: ?>
        every room private
      <?php endif; ?>
    </div>
  </div>
  <div class="stat">
    <div class="n" style="<?= $noFlight > 0 ? 'color:var(--amber)' : '' ?>"><?= $noFlight ?></div>
    <div class="l"><?= te('Travel unbooked') ?></div>
    <div class="h"><?= $offered ?> offers outstanding</div>
  </div>
</div>

<?php if (can('payroll')): ?>
<div class="grid g4" style="margin-bottom:16px">
  <div class="stat">
    <div class="n"><?= money($payTotal) ?></div>
    <div class="l"><?= te('Week\'s payroll') ?></div>
    <div class="h">week ending <?= e(date('j M', strtotime($week))) ?></div>
  </div>
  <div class="stat">
    <div class="n"><?= money($billTotal) ?></div>
    <div class="l"><?= te('Billable this week') ?></div>
    <div class="h"><?= $billTotal > 0 ? 'at the client rate' : 'no bill rate set' ?></div>
  </div>
  <div class="stat">
    <div class="n" style="<?= ($billTotal - $payTotal) < 0 ? 'color:var(--red)' : 'color:var(--green)' ?>">
      <?= $billTotal > 0 ? money($billTotal - $payTotal) : '—' ?>
    </div>
    <div class="l"><?= te('Margin') ?></div>
    <div class="h"><?= te('before overhead') ?></div>
  </div>
  <div class="stat">
    <div class="n" style="<?= $shortHours > 0 ? 'color:var(--amber)' : '' ?>">
      <?= rtrim(rtrim(number_format($shortHours, 1), '0'), '.') ?>h
    </div>
    <div class="l"><?= te('Guarantee gap') ?></div>
    <div class="h"><?= te('hours paid but not worked') ?></div>
  </div>
</div>
<?php endif; ?>

<div class="card tight">
  <div style="padding:14px 18px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center">
    <h2 style="margin:0"><?= te('Arriving') ?></h2>
    <a class="btn ghost sm" href="/roster"><?= te('Full roster') ?></a>
  </div>
  <?php if (! $arrivals): ?>
    <div class="empty"><?= te('Nobody is booked to travel yet. Confirm a candidate and they appear here.') ?></div>
  <?php else: ?>
  <div class="scroll">
  <table>
    <thead><tr>
      <th><?= te('Engineer') ?></th><th><?= te('Field') ?></th><th><?= te('Arrives') ?></th><th><?= te('Flight') ?></th>
      <th><?= te('Pickup') ?></th><th><?= te('Hotel') ?></th><th><?= te('Room') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($arrivals as $a): ?>
      <tr>
        <td><a href="/placements/<?= (int) $a['id'] ?>"><?= e($a['full_name']) ?></a></td>
        <td class="small muted"><?= e(ucfirst($a['discipline'])) ?></td>
        <td class="small">
          <?= $a['arrive_time'] ? e(date('D j M, g:ia', strtotime($a['arrive_time']))) : '<span class="tag amber">not booked</span>' ?>
        </td>
        <td class="small muted">
          <?= $a['carrier'] ? e($a['carrier'] . ' ' . ($a['reference'] ?? '')) : '—' ?>
          <?= $a['arrive_at'] ? '<br>' . e($a['arrive_at']) : '' ?>
        </td>
        <td class="small"><?= $a['pickup_needed'] ? '<span class="tag blue">needed</span>' : '—' ?></td>
        <td class="small"><?= $a['hotel'] ? e($a['hotel']) : '<span class="tag red">no bed</span>' ?></td>
        <td class="small mono"><?= e($a['room_number'] ?? '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
