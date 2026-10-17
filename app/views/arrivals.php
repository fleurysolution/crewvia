<?php // P5-M85: who lands when and where. ?>
<div class="page-heading"><div><span class="eyebrow"><?= te('DEPLOYMENT') ?></span><h1><?= te('Flight arrivals') ?></h1>
  <p class="sub"><?= te('Who arrives, when and where, for the airport pickups.') ?></p></div>
  <div class="flex gap-s"><?php foreach ([7, 14, 30] as $n): ?><a class="btn <?= $days === $n ? '' : 'ghost' ?> sm" href="?days=<?= $n ?><?= $all ? '&all=1' : '' ?>"><?= te(':n days', ['n' => $n]) ?></a><?php endforeach; ?>
    <?php if ($jobId): ?><a class="btn ghost sm" href="?days=<?= $days ?><?= $all ? '' : '&all=1' ?>"><?= $all ? te('This project only') : te('All projects') ?></a><?php endif; ?></div></div>
<?php if (! $byDay): ?><div class="card"><div class="empty"><?= te('No arrival in this period.') ?></div></div><?php endif; ?>
<?php foreach ($byDay as $day => $list): ?>
<div class="card tight" data-arrival-day="<?= e($day) ?>">
  <div class="card-bar"><h2 class="m0"><?= e(d($day, 'l j M Y')) ?> <span class="muted small"><?= tn(count($list), ':n arrival', ':n arrivals') ?></span></h2></div>
  <div class="scroll"><table>
    <thead><tr><th><?= te('Time') ?></th><th><?= te('Person') ?></th><th><?= te('Flight') ?></th><th><?= te('Arrives at') ?></th><th><?= te('Pickup') ?></th><th><?= te('Hotel') ?></th><th><?= te('Status') ?></th></tr></thead>
    <tbody>
    <?php foreach ($list as $a): $when = (string) ($a['arrive_time'] ?? $a['depart_time']); ?>
      <tr data-arrival="<?= (int) $a['id'] ?>">
        <td class="nowrap"><?= e(substr($when, 11, 5)) ?></td>
        <td><a href="/placements/<?= (int) $a['placement_id'] ?>"><?= e($a['full_name']) ?></a><?= $a['phone'] ? '<div class="small"><a href="tel:' . e(preg_replace('/[^0-9+]/', '', (string) $a['phone'])) . '">' . e($a['phone']) . '</a></div>' : '' ?><div class="small muted"><?= e($a['job']) ?></div></td>
        <td class="small"><?= e(trim(($a['carrier'] ?? '') . ' ' . ($a['reference'] ?? ''))) ?: '&mdash;' ?><div class="muted"><?= e((string) ($a['depart_from'] ?? '')) ?></div></td>
        <td class="small"><?= e((string) ($a['arrive_at'] ?? '')) ?: '&mdash;' ?></td>
        <td class="small"><?= (int) $a['pickup_needed'] ? ($a['pickup_by'] ? e($a['pickup_by']) : '<span class="tag amber">' . te('to arrange') . '</span>') : te('not needed') ?></td>
        <td class="small"><?= $a['hotel'] ? e($a['hotel']) : '<span class="tag amber">' . te('no hotel') . '</span>' ?></td>
        <td><?php if ($a['arrived_at']): ?><span class="tag green" data-arrived><?= te('Arrived') ?></span><div class="small muted"><?= te('signed :time', ['time' => d(substr((string) $a['arrived_at'], 0, 10))]) ?></div><?php elseif ($when < date('Y-m-d H:i:s')): ?><span class="tag amber"><?= te('Landed, not signed in yet') ?></span><?php else: ?><span class="tag grey"><?= te('Expected') ?></span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php endforeach; ?>
