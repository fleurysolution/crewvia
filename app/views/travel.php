<?php
$purposes = ['site'=>'Job site','food'=>'Food','walmart'=>'Walmart','laundry'=>'Laundry',
             'haircut'=>'Haircuts','airport'=>'Airport','other'=>'Other'];
?>

<div class="page-head"><div><h1><?= te('Travel') ?></h1>
<p class="sub"><?= ten(count($booked), ':n leg booked', ':n legs booked') ?> &middot; <?= ten(count($needTravel), ':n still to book', ':n still to book') ?></p></div></div>

<?php if ($needTravel): ?>
<div class="card tight">
  <div class="card-bar">
    <h2 class="m0"><?= te('Needs a flight in') ?></h2>
  </div>
  <div class="scroll">
  <table>
    <thead><tr><th><?= te('Person') ?></th><th><?= te('Coming from') ?></th><th><?= te('Starts') ?></th><th class="right w-half"><?= te('Book it') ?></th></tr></thead>
    <tbody>
    <?php foreach ($needTravel as $n): ?>
      <tr>
        <td><strong><?= e($n['full_name']) ?></strong>
          <div class="small muted"><?= e(($n['trade'] ?? '') !== '' && ($n['trade'] ?? null) !== null ? $n['trade'] : (disciplines(true)[$n['discipline']] ?? ucfirst((string) $n['discipline']))) ?></div></td>
        <td class="small muted"><?= e(trim(($n['city'] ?? '') . ', ' . ($n['state'] ?? ''), ' ,')) ?: '—' ?></td>
        <td class="small muted"><?= $n['start_date'] ? e(date('j M', strtotime($n['start_date']))) : '—' ?></td>
        <td>
          <?php $tp = $n['profile']; ?>
          <div class="small" data-travel-details="<?= $tp ? 'entered' : 'missing' ?>"><?php if ($tp): ?><strong><?= e($tp['legal_name']) ?></strong> · <?= te('flies from :airport', ['airport' => $tp['airport']]) ?><div class="muted pre-wrap"><?= e($tp['address']) ?></div><?php if ($tp['notes']): ?><div class="muted"><?= e($tp['notes']) ?></div><?php endif; ?><?php else: ?><span class="tag amber"><?= te('Travel details not entered yet') ?></span><?php if (($n['home_airport'] ?? '') !== ''): ?> <?= te('flies from :airport', ['airport' => $n['home_airport']]) ?><?php endif; ?><?php endif; ?></div>
          <details class="mt-xs"><summary class="btn sm ghost"><?= te('Book it') ?></summary>
          <form method="post" action="/travel" class="mt-s" data-book="<?= (int) $n['id'] ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="book_travel">
            <input type="hidden" name="direction" value="inbound">
            <input type="hidden" name="placement_id" value="<?= (int) $n['id'] ?>">
            <div class="row">
              <div><label for="mode-<?= (int) $n['id'] ?>"><?= te('How they travel') ?></label>
                <select id="mode-<?= (int) $n['id'] ?>" name="mode"><?php foreach (TRAVEL_MODES as $key => $label): ?><option value="<?= e($key) ?>"><?= te($label) ?></option><?php endforeach; ?></select></div>
              <div><label><?= te('Airline') ?></label><input name="carrier"></div>
              <div><label><?= te('Confirmation') ?></label><input name="reference" maxlength="60"></div>
              <div><label><?= te('Cost') ?></label><input name="cost" type="number" step="0.01" min="0" placeholder="$"></div>
            </div>
            <div class="row">
              <div><label><?= te('From') ?></label><input name="depart_from" value="<?= e($tp['airport'] ?? ($n['home_airport'] ?? '')) ?>"></div>
              <div><label><?= te('Leaves') ?></label><input type="datetime-local" name="depart_time"></div>
              <div><label><?= te('To') ?></label><input name="arrive_at" placeholder="<?= te('Airport') ?>"></div>
              <div><label><?= te('Arrives') ?></label><input type="datetime-local" name="arrive_time"></div>
            </div>
            <label><?= te('The ticket, pasted') ?></label><textarea name="ticket_details" rows="4" maxlength="5000" placeholder="<?= te('Paste the airline\'s confirmation: flights, times, seat, baggage.') ?>"></textarea>
            <div class="row tight gap mt-s">
              <label class="inline-check"><input class="w-auto" type="checkbox" name="pickup_needed" value="1" checked> <?= te('pickup') ?></label>
              <label class="inline-check"><input class="w-auto" type="checkbox" name="send_itinerary" value="1" checked> <?= te('Email the itinerary to them') ?></label>
              <button class="btn sm" type="submit"><?= te('Save') ?></button>
            </div>
          </form>
          </details>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php // P5-M97: sent home - the flight home to book. ?>
<?php if ($needHome): ?>
<div class="card tight" id="needs-home">
  <div class="card-bar"><h2 class="m0"><?= te('Needs a flight home') ?></h2></div>
  <div class="scroll"><table>
    <thead><tr><th><?= te('Person') ?></th><th><?= te('Last day') ?></th><th class="right w-half"><?= te('Book it') ?></th></tr></thead>
    <tbody><?php foreach ($needHome as $h): ?><tr data-home="<?= (int) $h['id'] ?>">
      <td><strong><?= e($h['full_name']) ?></strong><div class="small muted"><?= $h['arrive_at'] ? te('flies to :airport', ['airport' => $h['arrive_at']]) : te('Home airport not given') ?></div></td>
      <td class="small"><?= $h['end_date'] ? e(d((string) $h['end_date'])) : '—' ?></td>
      <td><details><summary class="btn sm"><?= te('Book it') ?></summary>
        <form method="post" action="/travel" class="mt-s" data-book-home="<?= (int) $h['id'] ?>"><?= csrf_field() ?><input type="hidden" name="do" value="book_travel"><input type="hidden" name="direction" value="outbound"><input type="hidden" name="placement_id" value="<?= (int) $h['id'] ?>">
          <div class="row">
            <div><label><?= te('How they travel') ?></label><select name="mode"><?php foreach (TRAVEL_MODES as $key => $label): ?><option value="<?= e($key) ?>"><?= te($label) ?></option><?php endforeach; ?></select></div>
            <div><label><?= te('Airline') ?></label><input name="carrier"></div>
            <div><label><?= te('Confirmation') ?></label><input name="reference" maxlength="60"></div>
            <div><label><?= te('Cost') ?></label><input name="cost" type="number" step="0.01" min="0" placeholder="$"></div>
          </div>
          <div class="row">
            <div><label><?= te('From') ?></label><input name="depart_from" value="<?= e((string) ($h['depart_from'] ?? '')) ?>"></div>
            <div><label><?= te('Leaves') ?></label><input type="datetime-local" name="depart_time"></div>
            <div><label><?= te('To') ?></label><input name="arrive_at" value="<?= e((string) ($h['arrive_at'] ?? '')) ?>"></div>
            <div><label><?= te('Arrives') ?></label><input type="datetime-local" name="arrive_time"></div>
          </div>
          <label><?= te('The ticket, pasted') ?></label><textarea name="ticket_details" rows="3" maxlength="5000"></textarea>
          <div class="row tight gap mt-s"><label class="inline-check"><input class="w-auto" type="checkbox" name="send_itinerary" value="1" checked> <?= te('Email the itinerary to them') ?></label><button class="btn sm" type="submit"><?= te('Save') ?></button></div>
        </form></details></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
</div>
<?php endif; ?>

<div class="card tight">
  <div class="card-bar"><h2 class="m0"><?= te('Booked') ?></h2></div>
  <?php if (! $booked): ?>
    <div class="empty"><?= te('Nothing booked yet.') ?></div>
  <?php else: ?>
  <div class="scroll">
  <table>
    <thead><tr><th><?= te('Person') ?></th><th><?= te('Leg') ?></th><th><?= te('Carrier') ?></th><th><?= te('Ref') ?></th>
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

<div class="card" id="advice">
  <h2><?= te('Advice for travellers') ?></h2>
  <p class="small muted m0 pull-up mb"><?= $adviceIsOwn ? te('This project\'s advice. It goes with every itinerary, in the portal and by email.') : ($advice ? te('The agency\'s advice, until this project has its own. It goes with every itinerary.') : te('No advice yet. It goes with every itinerary, in the portal and by email.')) ?></p>
  <?php if ($advice): ?><ul class="m0" data-advice="<?= count($advice) ?>"><?php foreach ($advice as $a): ?><li><?= e($a['body']) ?><?php if (can('admin') && $adviceIsOwn): ?> <form method="post" action="/travel" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="advice_remove"><input type="hidden" name="advice_id" value="<?= (int) $a['id'] ?>"><button class="link small"><?= te('Remove') ?></button></form><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if (can('admin')): ?>
    <?php if (! $adviceIsOwn): ?><form method="post" action="/travel" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="advice_usual"><button class="btn ghost sm mt-s"><?= te('Start from the usual advice') ?></button></form><?php endif; ?>
    <form method="post" action="/travel" class="row tight gap-s mt-s"><?= csrf_field() ?><input type="hidden" name="do" value="advice_add"><input name="body" maxlength="300" required placeholder="<?= te('e.g. Keep your boarding pass: the agency reimburses checked bags.') ?>"><button class="btn sm"><?= te('Add') ?></button></form>
  <?php else: ?><p class="small muted"><?= te('The operations VP writes it.') ?></p><?php endif; ?>
</div>

<div class="card">
  <h2><?= te('Scheduled runs') ?></h2>
  <p class="small muted m0 pull-up mb">
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
      <div class="f2"><label><?= te('Pick-up point') ?></label><input name="pickup_point" placeholder="<?= te('Hotel lobby') ?>"></div>
      <div><label><?= te('Driver') ?></label><input name="driver"></div>
      <div class="in-xs"><label><?= te('Seats') ?></label><input name="seats" type="number" min="1"></div>
      <div class="f2"><label><?= te('Note for the crew') ?></label><input name="notes" maxlength="500" placeholder="<?= te('e.g. back by 20:00, ask for Marco') ?>"></div>
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
