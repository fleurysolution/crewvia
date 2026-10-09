<?php
// What this person was signed at, or the rate agreed on the line of the
// scope they were recruited against. A null is reported as not agreed,
// because showing it as $0.00 reads as a decision somebody made.
$pay     = $p['pay_rate']      ?? $p['job_pay'];
$perDiem = $p['per_diem_rate'] ?? $p['job_per_diem'];
$gtd     = (int) ($p['strike_live'] && $p['strike_hours'] !== null
                      ? $p['strike_hours']
                      : (int) ($p['guarantee_hours'] ?? 0));
?>

<p class="small"><a href="/roster"><?= te('← Roster') ?></a></p>
<h1><?= e($p['full_name']) ?></h1>
<p class="sub">
  <span class="tag <?= $p['status'] === 'on_site' ? 'green' : ($p['status'] === 'cancelled' ? 'red' : 'blue') ?>">
    <?= e(ucfirst(str_replace('_', ' ', $p['status']))) ?></span>
  &middot; <?= e($p['line_role'] ?: ucfirst((string) $p['discipline'])) ?>
  &middot; <?= e($p['job_title']) ?>
  <?php if ($p['phone']): ?>
    &middot; <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $p['phone'])) ?>"><?= e($p['phone']) ?></a>
  <?php endif; ?>
</p>

<div class="grid g4" style="margin-bottom:16px">
  <div class="stat">
    <div class="n"><?= $pay !== null ? money((float) $pay) : '&mdash;' ?></div>
    <div class="l"><?= te('Per hour') ?></div>
    <div class="h">
      <?= $pay === null
          ? te('No rate agreed - price the line of the scope')
          : ($gtd > 0 ? te(':n h guaranteed', ['n' => $gtd]) : te('No guaranteed week')) ?>
    </div>
  </div>
  <div class="stat">
    <div class="n"><?= $perDiem !== null ? money((float) $perDiem) : '&mdash;' ?></div>
    <div class="l"><?= te('Per diem a day') ?></div>
    <div class="h"><?= $perDiem !== null ? te('on the weekly cheque') : te('None agreed') ?></div>
  </div>
  <div class="stat"><div class="n"><?= rtrim(rtrim(number_format($totals['hours'], 1), '0'), '.') ?></div>
    <div class="l"><?= te('Hours to date') ?></div></div>
  <div class="stat"><div class="n"><?= money($totals['pay']) ?></div><div class="l"><?= te('Paid to date') ?></div></div>
</div>

<div class="grid g2">
  <div class="card">
    <h2><?= te('Bed') ?></h2>
    <?php if ($lodge): ?>
      <p style="margin:0 0 6px"><strong><?= e($lodge['hotel']) ?></strong>
        <?php if ((int) $lodge['private_room'] === 1): ?>
          <span class="tag green"><?= te('own room') ?></span>
        <?php else: ?>
          <span class="tag red"><?= te('sharing &mdash; we promised otherwise') ?></span>
        <?php endif; ?>
      </p>
      <p class="small muted" style="margin:0 0 8px"><?= e($lodge['address'] ?? '') ?>
        <?php if ($lodge['hotel_phone']): ?>&middot; <?= e($lodge['hotel_phone']) ?><?php endif; ?></p>
      <table style="max-width:340px">
        <tr><td class="small muted"><?= te('Room') ?></td><td class="mono"><?= e($lodge['room_number'] ?? '—') ?></td></tr>
        <tr><td class="small muted"><?= te('Confirmation') ?></td><td class="mono"><?= e($lodge['confirmation'] ?? '—') ?></td></tr>
        <tr><td class="small muted"><?= te('Check in') ?></td><td><?= $lodge['check_in'] ? e(date('j M Y', strtotime($lodge['check_in']))) : '—' ?></td></tr>
        <tr><td class="small muted"><?= te('Check out') ?></td><td><?= $lodge['check_out'] ? e(date('j M Y', strtotime($lodge['check_out']))) : '—' ?></td></tr>
        <tr><td class="small muted"><?= te('Nightly') ?></td><td class="mono"><?= $lodge['nightly_rate'] ? money($lodge['nightly_rate']) : '—' ?></td></tr>
      </table>
    <?php else: ?>
      <p class="small" style="color:var(--red);margin:0">
        No room booked. <?= can('hotels') ? '<a href="/hotels">Book one</a>.' : 'Hotels can book one.' ?>
      </p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2><?= te('Travel') ?></h2>
    <?php if (! $legs): ?>
      <p class="small" style="color:var(--amber);margin:0">
        Nothing booked. <?= can('hotels') ? '<a href="/travel">Book a flight</a>.' : '' ?>
      </p>
    <?php else: ?>
      <?php foreach ($legs as $t): ?>
        <div style="padding:8px 0;border-bottom:1px solid var(--line)">
          <div><span class="tag <?= $t['direction'] === 'inbound' ? 'blue' : 'grey' ?>">
            <?= e(ucfirst($t['direction'])) ?></span>
            <strong><?= e(trim(($t['carrier'] ?? '') . ' ' . ($t['reference'] ?? ''))) ?: 'Booked' ?></strong>
          </div>
          <div class="small muted" style="margin-top:3px">
            <?= e($t['depart_from'] ?? '?') ?> &rarr; <?= e($t['arrive_at'] ?? '?') ?>
            <?php if ($t['arrive_time']): ?>&middot; <?= e(date('D j M, g:ia', strtotime($t['arrive_time']))) ?><?php endif; ?>
            <?php if ($t['pickup_needed']): ?>&middot; <span class="tag amber"><?= te('pickup needed') ?></span><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php if (can('payroll') && $sheets): ?>
<div class="card tight">
  <div style="padding:14px 18px;border-bottom:1px solid var(--line)"><h2 style="margin:0"><?= te('Weeks') ?></h2></div>
  <div class="scroll">
  <table>
    <thead><tr><th><?= te('Week ending') ?></th><th class="num"><?= te('Worked') ?></th><th class="num"><?= te('Paid') ?></th>
      <th class="num"><?= te('Per diem') ?></th><th class="num"><?= te('Total') ?></th><th><?= te('State') ?></th></tr></thead>
    <tbody>
    <?php foreach ($sheets as $s): $m = week_money($s, $p, $job); ?>
      <tr>
        <td><?= e(date('j M Y', strtotime($s['week_ending']))) ?></td>
        <td class="num mono"><?= rtrim(rtrim(number_format($m['worked'], 1), '0'), '.') ?></td>
        <td class="num mono"><?= rtrim(rtrim(number_format($m['paid_hours'], 1), '0'), '.') ?>
          <?php if ($m['short_by'] > 0): ?><span class="small" style="color:var(--amber)">+gtd</span><?php endif; ?></td>
        <td class="num mono"><?= money($m['per_diem']) ?></td>
        <td class="num mono"><?= money($m['pay_total']) ?></td>
        <td><span class="tag <?= in_array($s['status'], ['approved','paid'], true) ? 'green' : 'amber' ?>">
          <?= e(ucfirst($s['status'])) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>
