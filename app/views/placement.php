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

<p class="back-link"><a href="/roster"><?= te('← Roster') ?></a></p>
<div class="page-head"><div><h1><?= e($p['full_name']) ?></h1>
<p class="sub">
  <span class="tag <?= $p['status'] === 'on_site' ? 'green' : ($p['status'] === 'cancelled' ? 'red' : 'blue') ?>">
    <?= te(placement_word((string) $p['status'])) ?></span>
  &middot; <?= e($p['line_role'] ?: ucfirst((string) $p['discipline'])) ?>
  &middot; <?= e($p['job_title']) ?>
  <?php if ($p['phone'] && $textPanel['preferences']['no_calls_since'] === null): ?>
    &middot; <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $p['phone'])) ?>"><?= e($p['phone']) ?></a>
  <?php endif; ?>
</p></div></div>
<?php require __DIR__ . '/_text-panel.php'; ?>

<div class="grid g4 mb-l">
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

<?php if (can('recruiter', 'hotels')): ?>
<form method="post" class="card row items-end mb-l" id="placement-dates"><?= csrf_field() ?><input type="hidden" name="do" value="dates">
  <div><label for="pl-start"><?= te('Starts') ?></label><input id="pl-start" type="date" name="start_date" value="<?= e((string) ($p['start_date'] ?? '')) ?>"></div>
  <div><label for="pl-end"><?= te('Ends') ?></label><input id="pl-end" type="date" name="end_date" value="<?= e((string) ($p['end_date'] ?? '')) ?>"></div>
  <button class="btn" type="submit"><?= te('Save the dates') ?></button>
  <span class="hint"><?= te('Arrivals, roll call and pay read these dates.') ?></span>
</form>
<?php endif; ?>

<div class="grid g2">
  <div class="card">
    <h2><?= te('Bed') ?></h2>
    <?php if ($lodge): ?>
      <p class="m0 mb-s"><strong><?= e($lodge['hotel']) ?></strong>
        <?php if ((int) $lodge['private_room'] === 1): ?>
          <span class="tag green"><?= te('own room') ?></span>
        <?php else: ?>
          <span class="tag red"><?= te('sharing &mdash; we promised otherwise') ?></span>
        <?php endif; ?>
      </p>
      <p class="small muted m0 mb-s"><?= e($lodge['address'] ?? '') ?>
        <?php if ($lodge['hotel_phone']): ?>&middot; <?= e($lodge['hotel_phone']) ?><?php endif; ?></p>
      <table class="in-xl">
        <tr><td class="small muted"><?= te('Room') ?></td><td class="mono"><?= e($lodge['room_number'] ?? '—') ?></td></tr>
        <tr><td class="small muted"><?= te('Confirmation') ?></td><td class="mono"><?= e($lodge['confirmation'] ?? '—') ?></td></tr>
        <tr><td class="small muted"><?= te('Check in') ?></td><td><?= $lodge['check_in'] ? e(date('j M Y', strtotime($lodge['check_in']))) : '—' ?></td></tr>
        <tr><td class="small muted"><?= te('Check out') ?></td><td><?= $lodge['check_out'] ? e(date('j M Y', strtotime($lodge['check_out']))) : '—' ?></td></tr>
        <tr><td class="small muted"><?= te('Nightly') ?></td><td class="mono"><?= $lodge['nightly_rate'] ? money($lodge['nightly_rate']) : '—' ?></td></tr>
      </table>
    <?php else: ?>
      <p class="small t-red m0">
        No room booked. <?= can('hotels') ? '<a href="/hotels">Book one</a>.' : 'Hotels can book one.' ?>
      </p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2><?= te('Travel') ?></h2>
    <?php if (! $legs): ?>
      <p class="small t-amber m0">
        Nothing booked. <?= can('hotels') ? '<a href="/travel">Book a flight</a>.' : '' ?>
      </p>
    <?php else: ?>
      <?php foreach ($legs as $t): ?>
        <div class="list-row">
          <div><span class="tag <?= $t['direction'] === 'inbound' ? 'blue' : 'grey' ?>">
            <?= e(ucfirst($t['direction'])) ?></span>
            <strong><?= e(trim(($t['carrier'] ?? '') . ' ' . ($t['reference'] ?? ''))) ?: 'Booked' ?></strong>
          </div>
          <div class="small muted mt-xs">
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
  <div class="card-bar"><h2 class="m0"><?= te('Weeks') ?></h2></div>
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
          <?php if ($m['short_by'] > 0): ?><span class="small t-amber">+gtd</span><?php endif; ?></td>
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

<?php if (can('payroll')): ?>
<div class="card" id="terms-origin">
  <h2><?= te('Where these terms come from') ?></h2>
  <?php if ($p['line_recorded'] && $origin): ?>
    <p class="small m0"><?= te('Recorded when the assignment was made: requisition :req, line :line of the scope of work.',
        ['req' => $origin['title'], 'line' => $line['role_title']]) ?></p>
  <?php elseif ($line): ?>
    <p class="small m0 t-amber"><?= te('Not recorded on the assignment. Inferred from the latest application on this project: line :line. Check it if the person applied for two trades.',
        ['line' => $line['role_title']]) ?></p>
  <?php else: ?>
    <p class="small muted m0"><?= te('No requisition or line of the scope is linked to this assignment. Its rates were set on the assignment itself.') ?></p>
  <?php endif; ?>

  <h3 class="m0 mt mb-s"><?= te('Rate changes') ?></h3>
  <?php if (! $rateChanges): ?>
    <p class="small muted m0"><?= te('No change since the assignment was made. It is on the terms it started with.') ?></p>
  <?php else: ?>
    <div class="scroll"><table>
      <thead><tr><th><?= te('When') ?></th><th><?= te('By') ?></th><th class="num"><?= te('Pay') ?></th>
        <th class="num"><?= te('Bill') ?></th><th class="num"><?= te('Per diem') ?></th><th class="num"><?= te('Guarantee') ?></th></tr></thead>
      <tbody>
      <?php
      $was = static fn($old, $new, bool $money): string =>
          ($old === null ? '—' : ($money ? money((float) $old) : (string) (int) $old))
          . ' → '
          . ($new === null ? '—' : ($money ? money((float) $new) : (string) (int) $new));
      foreach ($rateChanges as $r): ?>
        <tr>
          <td><?= e(d(substr((string) $r['changed_at'], 0, 10))) ?></td>
          <td><?= e($r['changed_by_name'] ?? '—') ?></td>
          <td class="num mono"><?= e($was($r['old_pay_rate'], $r['new_pay_rate'], true)) ?></td>
          <td class="num mono"><?= e($was($r['old_bill_rate'], $r['new_bill_rate'], true)) ?></td>
          <td class="num mono"><?= e($was($r['old_per_diem_rate'], $r['new_per_diem_rate'], true)) ?></td>
          <td class="num mono"><?= e($was($r['old_guarantee_hours'], $r['new_guarantee_hours'], false)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php // P5-M84: HR sets the person's group and supervisor - or marks them the one leading the group. ?>
<form method="post" class="card" id="group"><?= csrf_field() ?><input type="hidden" name="do" value="group">
  <h2><?= te('Group and supervisor') ?></h2>
  <div class="row">
    <div><label for="g-name"><?= te('Group') ?></label><input id="g-name" name="crew_group" maxlength="60" list="g-names" value="<?= e((string) ($groupRow['crew_group'] ?? '')) ?>" placeholder="<?= te('e.g. Group 1, Zone A') ?>"><datalist id="g-names"><?php foreach ($groupNames as $gn): ?><option value="<?= e($gn) ?>"><?php endforeach; ?></datalist></div>
    <div><label for="g-sup"><?= te('Supervisor') ?></label><select id="g-sup" name="supervisor_id"><option value=""><?= te('None yet') ?></option><?php foreach ($supervisorChoices as $sc): ?><option value="<?= (int) $sc['id'] ?>"<?= (int) ($groupRow['supervisor_id'] ?? 0) === (int) $sc['id'] ? ' selected' : '' ?>><?= e($sc['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>&nbsp;</label><label class="d-block"><input type="checkbox" name="leads_group" value="1"<?= ! empty($groupRow['leads_group']) ? ' checked' : '' ?>> <?= te('Leads this group (a vehicle is reserved for them)') ?></label></div>
  </div>
  <button class="btn mt-s"><?= te('Save') ?></button>
</form>
