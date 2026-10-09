<h1><?= te('Hotels') ?></h1>
<p class="sub">
  <?= te(':count rooms booked',['count'=>count($booked)]) ?>
  &middot; <?= te(':count without a bed',['count'=>count($needBed)]) ?>
  <?php if ($sharing): ?>
    &middot; <span style="color:var(--red);font-weight:600"><?= te(':count sharing a room',['count'=>count($sharing)]) ?></span>
  <?php endif; ?>
</p>

<?php if ($sharing): ?>
<div class="card" style="border-color:#F0C4C1;background:#FDEEED">
  <h2 style="color:var(--red)"><?= te('Sharing a room') ?></h2>
  <p class="small" style="margin:0 0 10px"><?= te('Every engineer was told in writing they would not have a roommate. These are the ones who do.') ?></p>
  <?php foreach ($sharing as $s): ?>
    <div class="small"><strong><?= e($s['full_name']) ?></strong> &mdash;
      <?= e($s['hotel']) ?>, room <?= e($s['room_number'] ?? '?') ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($needBed): ?>
<div class="card tight">
  <div style="padding:14px 18px;border-bottom:1px solid var(--line)">
    <h2 style="margin:0"><?= te('Needs a bed') ?></h2>
  </div>
  <div class="scroll">
  <table>
    <thead><tr><th><?= te('Person') ?></th><th class="right"><?= te('Book a room') ?></th></tr></thead>
    <tbody>
    <?php foreach ($needBed as $n): ?>
      <tr>
        <td>
          <strong><?= e($n['full_name']) ?></strong>
          <div class="muted small">
            <?php $trade = trim((string) ($n['trade'] ?? '')); ?>
            <?= e($trade !== ''
                  ? $trade
                  : (disciplines(true)[$n['discipline']] ?? ucfirst((string) $n['discipline']))) ?>
          </div>
          <div class="small">
            <span class="tag amber"><?= te(ucfirst(str_replace('_', ' ', (string) $n['status']))) ?></span>
            <?php if ($n['start_date']): ?>
              <span class="muted"><?= te('Starts') ?> <?= e(date('j M', strtotime($n['start_date']))) ?></span>
            <?php endif; ?>
          </div>
        </td>
        <td class="right">
          <form method="post" action="/hotels" class="booking-form">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="book">
            <input type="hidden" name="placement_id" value="<?= (int) $n['id'] ?>">

            <div>
              <label for="h-hotel-<?= (int) $n['id'] ?>"><?= te('Hotel') ?></label>
              <select id="h-hotel-<?= (int) $n['id'] ?>" name="hotel_id" required>
                <option value=""><?= te('Choose') ?></option>
                <?php foreach ($hotels as $h): ?>
                  <option value="<?= (int) $h['id'] ?>"><?= e($h['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div style="max-width:92px">
              <label for="h-room-<?= (int) $n['id'] ?>"><?= te('Room') ?></label>
              <input id="h-room-<?= (int) $n['id'] ?>" name="room_number" required
                     maxlength="20" placeholder="412">
            </div>

            <div>
              <label for="h-in-<?= (int) $n['id'] ?>"><?= te('Checks in') ?></label>
              <input id="h-in-<?= (int) $n['id'] ?>" type="date" name="check_in" required
                     value="<?= e((string) ($n['start_date'] ?? $job['starts_on'] ?? '')) ?>">
            </div>

            <div>
              <label for="h-out-<?= (int) $n['id'] ?>"><?= te('Checks out') ?></label>
              <input id="h-out-<?= (int) $n['id'] ?>" type="date" name="check_out" required
                     value="<?= e((string) ($n['end_date'] ?? $job['ends_on'] ?? '')) ?>">
            </div>

            <div style="max-width:140px">
              <label for="h-conf-<?= (int) $n['id'] ?>"><?= te('Confirmation') ?></label>
              <input id="h-conf-<?= (int) $n['id'] ?>" name="confirmation" maxlength="60"
                     placeholder="<?= te('From the hotel') ?>">
            </div>

            <label class="own-room">
              <input type="checkbox" name="private_room" value="1" checked>
              <?= te('Own room') ?>
            </label>

            <button class="btn" type="submit"><?= te('Book') ?></button>
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
  <div style="padding:14px 18px;border-bottom:1px solid var(--line)">
    <h2 style="margin:0"><?= te('Rooms booked') ?></h2>
  </div>
  <?php if (! $booked): ?>
    <div class="empty"><?= te('No rooms booked yet.') ?></div>
  <?php else: ?>
  <div class="scroll">
  <table>
    <thead><tr><th><?= te('Hotel') ?></th><th><?= te('Room') ?></th><th><?= te('Person') ?></th><th><?= te('Check in') ?></th><th><?= te('Check out') ?></th>
      <th><?= te('Confirmation') ?></th><th><?= te('Rate') ?></th><th><?= te('Own room') ?></th></tr></thead>
    <tbody>
    <?php foreach ($booked as $b): ?>
      <tr>
        <td class="small"><?= e($b['hotel']) ?></td>
        <td class="mono"><?= e($b['room_number'] ?? '—') ?></td>
        <td><?= e($b['full_name']) ?></td>
        <td class="small muted"><?= $b['check_in'] ? e(date('j M', strtotime($b['check_in']))) : '—' ?></td>
        <td class="small muted"><?= $b['check_out'] ? e(date('j M', strtotime($b['check_out']))) : '—' ?></td>
        <td class="small mono muted"><?= e($b['confirmation'] ?? '—') ?></td>
        <td class="small num"><?= $b['nightly_rate'] ? money($b['nightly_rate']) : '—' ?></td>
        <td>
          <?php if ((int) $b['private_room'] === 1): ?>
            <span class="tag green"><?= te('yes') ?></span>
          <?php else: ?>
            <span class="tag red"><?= te('sharing') ?></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2><?= te('Add a hotel') ?></h2>
  <form method="post" action="/hotels">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="add_hotel">
    <div class="row">
      <div style="flex:2"><label><?= te('Name') ?></label><input name="name" required placeholder="<?= te('Hampton Inn') ?>"></div>
      <div style="flex:2"><label><?= te('Address') ?></label><input name="address"></div>
      <div><label><?= te('City') ?></label><input name="city"></div>
      <div style="max-width:90px"><label><?= te('State') ?></label><input name="state" maxlength="2"></div>
    </div>
    <div class="row" style="margin-top:10px">
      <div><label><?= te('Phone') ?></label><input name="phone"></div>
      <div><label><?= te('Nightly rate') ?></label><input name="nightly_rate" type="number" step="0.01" placeholder="129.00"></div>
      <div style="flex:2"><label><?= te('Who we book through') ?></label><input name="confirmation_contact"></div>
    </div>
    <div class="row" style="margin-top:10px">
      <div>
        <label for="h-held"><?= te('Rooms held') ?></label>
        <input id="h-held" name="rooms_held" type="number" min="0" max="2000" placeholder="20">
        <span class="hint"><?= te('How many the agency has blocked here') ?></span>
      </div>
      <div><label for="h-from"><?= te('Held from') ?></label>
        <input id="h-from" name="block_starts" type="date"></div>
      <div><label for="h-to"><?= te('Held until') ?></label>
        <input id="h-to" name="block_ends" type="date"></div>
      <div class="row tight"><button class="btn" type="submit"><?= te('Add hotel') ?></button></div>
    </div>
  </form>
</div>

<?php if ($hotels): ?>
<div class="card tight">
  <div class="scroll">
  <table>
    <thead><tr><th><?= te('Hotel') ?></th><th><?= te('Where') ?></th><th><?= te('Phone') ?></th>
      <th><?= te('Rate') ?></th><th class="num"><?= te('Block') ?></th>
      <th class="num"><?= te('Taken') ?></th><th class="num"><?= te('Left') ?></th>
      <th><?= te('Held for') ?></th></tr></thead>
    <tbody>
    <?php foreach ($hotels as $h): ?>
      <tr>
        <td><strong><?= e($h['name']) ?></strong>
          <?php if ($h['confirmation_contact']): ?>
            <div class="small muted">via <?= e($h['confirmation_contact']) ?></div>
          <?php endif; ?>
        </td>
        <td class="small muted"><?= e(trim(($h['address'] ?? '') . ' ' . ($h['city'] ?? '') . ' ' . ($h['state'] ?? ''))) ?: '—' ?></td>
        <td class="small mono"><?= e($h['phone'] ?? '—') ?></td>
        <td class="small num"><?= $h['nightly_rate'] ? money($h['nightly_rate']) : '—' ?></td>
        <td class="num"><?= $h['rooms_held'] === null ? '—' : (int) $h['rooms_held'] ?></td>
        <td class="num"><?= (int) $h['rooms'] ?></td>
        <td class="num">
          <?php if ($h['left'] === null): ?>
            <span class="muted small"><?= te('no block') ?></span>
          <?php else: ?>
            <span class="tag <?= $h['left'] > 0 ? 'green' : 'red' ?>"><?= (int) $h['left'] ?></span>
          <?php endif; ?>
        </td>
        <td class="small muted">
          <?php if ($h['block_starts'] || $h['block_ends']): ?>
            <?= $h['block_starts'] ? e(d($h['block_starts'])) : '—' ?>
            &rarr; <?= $h['block_ends'] ? e(d($h['block_ends'])) : '—' ?>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>
