<?php
$purposes = ['site'=>'Job site','food'=>'Food','walmart'=>'Walmart','laundry'=>'Laundry',
             'haircut'=>'Haircuts','airport'=>'Airport','other'=>'Other'];
?>

<h1><?= te('Travel') ?></h1>
<p class="sub"><?= count($booked) ?> legs booked &middot; <?= count($needTravel) ?> still to book</p>

<?php if ($needTravel): ?>
<div class="card tight">
  <div style="padding:14px 18px;border-bottom:1px solid var(--line)">
    <h2 style="margin:0"><?= te('Needs a flight in') ?></h2>
  </div>
  <div class="scroll">
  <table>
    <thead><tr><th><?= te('Engineer') ?></th><th><?= te('Coming from') ?></th><th><?= te('Starts') ?></th><th class="right" style="width:52%"><?= te('Book it') ?></th></tr></thead>
    <tbody>
    <?php foreach ($needTravel as $n): ?>
      <tr>
        <td><strong><?= e($n['full_name']) ?></strong>
          <div class="small muted"><?= e(ucfirst($n['discipline'])) ?></div></td>
        <td class="small muted"><?= e(trim(($n['city'] ?? '') . ', ' . ($n['state'] ?? ''), ' ,')) ?: '—' ?></td>
        <td class="small muted"><?= $n['start_date'] ? e(date('j M', strtotime($n['start_date']))) : '—' ?></td>
        <td>
          <form method="post" action="/travel" class="row tight" style="justify-content:flex-end;gap:6px">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="book_travel">
            <input type="hidden" name="direction" value="inbound">
            <input type="hidden" name="placement_id" value="<?= (int) $n['id'] ?>">
            <label class="sr-only" for="mode-<?= (int) $n['id'] ?>"><?= te('How they travel') ?></label>

            <select id="mode-<?= (int) $n['id'] ?>" name="mode" style="width:auto;padding:4px 8px;font-size:13px">

              <?php foreach (['flight' => 'Flight', 'drive' => 'Drives themselves',

                              'bus' => 'Bus', 'rail' => 'Rail',

                              'agency_vehicle' => 'Agency vehicle'] as $key => $label): ?>

                <option value="<?= e($key) ?>"><?= te($label) ?></option>

              <?php endforeach; ?>

            </select>

            <input name="carrier" placeholder="<?= te('Airline') ?>" style="width:95px;padding:4px 8px;font-size:13px">
            <input name="reference" placeholder="<?= te('Conf.') ?>" style="width:85px;padding:4px 8px;font-size:13px">
            <input name="depart_from" placeholder="<?= te('From') ?>" style="width:85px;padding:4px 8px;font-size:13px">

            <input name="arrive_at" placeholder="<?= te('Airport') ?>" style="width:90px;padding:4px 8px;font-size:13px">
            <input type="datetime-local" name="arrive_time" style="width:auto;padding:4px 6px;font-size:12.5px">
            <input name="cost" type="number" step="0.01" placeholder="$" style="width:75px;padding:4px 8px;font-size:13px">
            <label style="display:flex;align-items:center;gap:4px;margin:0;font-weight:400;color:var(--ink);font-size:12px">
              <input type="checkbox" name="pickup_needed" value="1" checked style="width:auto"> <?= te('pickup') ?>
            </label>
            <button class="btn sm" type="submit"><?= te('Save') ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<div class="card tight">
  <div style="padding:14px 18px;border-bottom:1px solid var(--line)"><h2 style="margin:0"><?= te('Booked') ?></h2></div>
  <?php if (! $booked): ?>
    <div class="empty"><?= te('Nothing booked yet.') ?></div>
  <?php else: ?>
  <div class="scroll">
  <table>
    <thead><tr><th><?= te('Engineer') ?></th><th><?= te('Leg') ?></th><th><?= te('Carrier') ?></th><th><?= te('Ref') ?></th>
      <th><?= te('From') ?></th><th>To</th><th><?= te('Arrives') ?></th><th><?= te('Pickup') ?></th><th class="num"><?= te('Cost') ?></th></tr></thead>
    <tbody>
    <?php foreach ($booked as $t): ?>
      <tr>
        <td><?= e($t['full_name']) ?></td>
        <td><span class="tag <?= $t['direction'] === 'inbound' ? 'blue' : 'grey' ?>"><?= e(ucfirst($t['direction'])) ?></span></td>
        <td class="small"><?= e($t['carrier'] ?? '—') ?></td>
        <td class="small mono"><?= e($t['reference'] ?? '—') ?></td>
        <td class="small muted"><?= e($t['depart_from'] ?? '—') ?></td>
        <td class="small muted"><?= e($t['arrive_at'] ?? '—') ?></td>
        <td class="small"><?= $t['arrive_time'] ? e(date('D j M, g:ia', strtotime($t['arrive_time']))) : '—' ?></td>
        <td><?= $t['pickup_needed'] ? '<span class="tag amber">needed</span>' : '<span class="muted">—</span>' ?></td>
        <td class="num mono"><?= $t['cost'] ? money($t['cost']) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2><?= te('Scheduled runs') ?></h2>
  <p class="small muted" style="margin:-6px 0 12px">
    <?= te('Site transport and the runs for food, Walmart, laundry and haircuts.') ?>
  </p>
  <form method="post" action="/travel">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="add_run">
    <div class="row">
      <div><label><?= te('Purpose') ?></label>
        <select name="purpose">
          <?php foreach ($purposes as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
        </select></div>
      <div><label><?= te('Date') ?></label><input type="date" name="runs_on" value="<?= e(date('Y-m-d')) ?>" required></div>
      <div><label><?= te('Departs') ?></label><input type="time" name="depart_time"></div>
      <div><label><?= te('Returns') ?></label><input type="time" name="return_time"></div>
      <div style="flex:2"><label><?= te('Pick-up point') ?></label><input name="pickup_point" placeholder="<?= te('Hotel lobby') ?>"></div>
      <div><label><?= te('Driver') ?></label><input name="driver"></div>
      <div style="max-width:90px"><label><?= te('Seats') ?></label><input name="seats" type="number" min="1"></div>
      <div style="flex:2"><label><?= te('Note for the crew') ?></label><input name="notes" maxlength="500" placeholder="<?= te('e.g. back by 20:00, ask for Marco') ?>"></div>
      <div class="row tight"><button class="btn" type="submit"><?= te('Add run') ?></button></div>
    </div>
  </form>
</div>

<?php if ($runs): ?>
<div class="card tight">
  <div class="scroll">
  <table>
    <thead><tr><th><?= te('Date') ?></th><th><?= te('Purpose') ?></th><th><?= te('Departs') ?></th><th><?= te('Returns') ?></th>
      <th><?= te('From') ?></th><th><?= te('Driver') ?></th><th class="num"><?= te('Seats') ?></th></tr></thead>
    <tbody>
    <?php foreach ($runs as $r): ?>
      <tr>
        <td class="small"><?= e(date('D j M', strtotime($r['runs_on']))) ?></td>
        <td><span class="tag blue"><?= e($purposes[$r['purpose']] ?? $r['purpose']) ?></span></td>
        <td class="small mono"><?= $r['depart_time'] ? e(date('g:ia', strtotime($r['depart_time']))) : '—' ?></td>
        <td class="small mono"><?= $r['return_time'] ? e(date('g:ia', strtotime($r['return_time']))) : '—' ?></td>
        <td class="small muted"><?= e($r['pickup_point'] ?? '—') ?></td>
        <td class="small"><?= e($r['driver'] ?? '—') ?></td>
        <td class="num"><?= $r['seats'] !== null ? (int) $r['seats'] : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>
