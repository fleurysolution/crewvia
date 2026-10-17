<div class="page-head"><div><h1><?= te('Hotels') ?></h1>
<p class="sub">
  <?= te(':count rooms booked',['count'=>count($booked)]) ?>
  &middot; <?= te(':count without a bed',['count'=>count($needBed)]) ?>
  <?php if ($sharing): ?>
    &middot; <span class="t-red semibold"><?= te(':count sharing a room',['count'=>count($sharing)]) ?></span>
  <?php endif; ?>
</p></div></div>

<?php if ($sharing): ?>
<div class="card tone-alert">
  <h2 class="t-red"><?= te('Sharing a room') ?></h2>
  <p class="small m0 mb"><?= te('Every engineer was told in writing they would not have a roommate. These are the ones who do.') ?></p>
  <?php foreach ($sharing as $s): ?>
    <div class="small"><strong><?= e($s['full_name']) ?></strong> &mdash;
      <?= e($s['hotel']) ?>, room <?= e($s['room_number'] ?? '?') ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>


<?php // P5-M76: the hotels of this project, each with its block of rooms, its dates, its cost and its price to the client. ?>
<div class="card tight" id="project-hotels">
  <div class="card-bar flex gap-s">
    <h2 class="m0"><?= tn(count($projectHotels), ':n hotel on this project', ':n hotels on this project') ?></h2>
    <?php if ($job): ?><span class="ml-auto"><a class="btn sm" href="#link-hotel" data-link-hotel><?= te('+ Add a hotel to this project') ?></a></span><?php endif; ?>
  </div>
  <?php if (! $projectHotels): ?>
    <div class="empty"><?= te('No hotel on this project yet.') ?></div>
  <?php else: ?>
  <div class="scroll"><table>
    <thead><tr><th><?= te('Hotel') ?></th><th class="num"><?= te('Rooms held') ?></th><th class="num"><?= te('Taken') ?></th><th class="num"><?= te('Left') ?></th><th><?= te('Held for') ?></th><th class="num"><?= te('Cost per night') ?></th><th class="num"><?= te('Billed per night') ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($projectHotels as $ph): $left = $ph['rooms_held'] === null ? null : max(0, (int) $ph['rooms_held'] - (int) $ph['taken']); ?>
      <tr data-project-hotel="<?= (int) $ph['hotel_id'] ?>">
        <td><strong><?= e($ph['name']) ?></strong><div class="small muted"><?= e(trim(($ph['city'] ?? '') . ' ' . ($ph['state'] ?? ''))) ?><?= $ph['phone'] ? ' &middot; ' . e($ph['phone']) : '' ?></div></td>
        <td class="num"><?= $ph['rooms_held'] === null ? '&mdash;' : (int) $ph['rooms_held'] ?></td>
        <td class="num"><?= (int) $ph['taken'] ?></td>
        <td class="num"><?= $left === null ? '<span class="muted small">' . te('no block') . '</span>' : '<span class="tag ' . ($left > 0 ? 'green' : 'red') . '">' . $left . '</span>' ?></td>
        <td class="small muted"><?= $ph['block_starts'] || $ph['block_ends'] ? ($ph['block_starts'] ? e(d($ph['block_starts'])) : '&mdash;') . ' &rarr; ' . ($ph['block_ends'] ? e(d($ph['block_ends'])) : '&mdash;') : '&mdash;' ?></td>
        <td class="num small"><?= ($r = $ph['nightly_rate'] ?? $ph['hotel_rate']) !== null ? e(money((float) $r)) : '&mdash;' ?></td>
        <td class="num small"><?= $ph['client_night_rate'] !== null ? e(money((float) $ph['client_night_rate'])) : '&mdash;' ?></td>
        <td class="right nowrap">
          <details class="req-more inline-block"><summary class="btn ghost sm"><?= te('Edit') ?></summary>
            <div class="req-pop" style="width:320px">
              <form method="post" action="/hotels"><?= csrf_field() ?><input type="hidden" name="do" value="update_link"><input type="hidden" name="hotel_id" value="<?= (int) $ph['hotel_id'] ?>">
                <div class="grid g2">
      <div class="in-s"><label><?= te('Rooms held') ?></label><input name="rooms_held" type="number" min="0" max="2000" value="<?= $ph['rooms_held'] === null ? '' : (int) $ph['rooms_held'] ?>" placeholder="20"></div>
      <div><label><?= te('Held from') ?></label><input name="block_starts" type="date" value="<?= e((string) $ph['block_starts']) ?>"></div>
      <div><label><?= te('Held until') ?></label><input name="block_ends" type="date" value="<?= e((string) $ph['block_ends']) ?>"></div>
      <div class="in-s"><label><?= te('Cost per night') ?></label><input name="nightly_rate" type="number" min="0" step="0.01" value="<?= $ph['nightly_rate'] === null ? '' : e((string) (float) $ph['nightly_rate']) ?>" placeholder="129.00"></div>
      <div class="in-s"><label><?= te('Billed per night') ?></label><input name="client_night_rate" type="number" min="0" step="0.01" value="<?= $ph['client_night_rate'] === null ? '' : e((string) (float) $ph['client_night_rate']) ?>" placeholder="<?= te('optional') ?>"></div>
                </div>
                <button class="btn sm mt-s"><?= te('Save') ?></button> <button type="button" class="btn ghost sm mt-s" data-fold-close><?= te('Cancel') ?></button>
              </form>
              <?php if (! (int) $ph['taken']): ?>
              <form method="post" action="/hotels" class="mt-s" onsubmit="return confirm(this.dataset.ask)" data-ask="<?= te('Remove :hotel from this project?', ['hotel' => $ph['name']]) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="unlink_hotel"><input type="hidden" name="hotel_id" value="<?= (int) $ph['hotel_id'] ?>"><button class="btn ghost sm"><?= te('Remove from this project') ?></button></form>
              <?php endif; ?>
            </div>
          </details>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php if ($job): ?>
<details class="card fold" id="link-hotel" data-fold-forget<?= ! $projectHotels && ! $hotels ? ' open' : '' ?>>
  <summary><h2><?= te('Add a hotel to this project') ?></h2></summary>
  <?php if ($otherHotels): ?>
  <form method="post" action="/hotels" class="mb-l"><?= csrf_field() ?><input type="hidden" name="do" value="link_hotel">
    <div class="row">
      <div class="f2"><label for="lh-hotel"><?= te('A hotel already in the directory') ?></label><select id="lh-hotel" name="hotel_id" required><option value=""><?= te('Choose') ?></option>
        <?php foreach ($otherHotels as $h): ?><option value="<?= (int) $h['id'] ?>"><?= e($h['name'] . ($h['city'] ? ' · ' . $h['city'] . ', ' . $h['state'] : '')) ?></option><?php endforeach; ?></select></div>
      <div class="in-s"><label><?= te('Rooms held') ?></label><input name="rooms_held" type="number" min="0" max="2000" value="" placeholder="20"></div>
      <div><label><?= te('Held from') ?></label><input name="block_starts" type="date" value=""></div>
      <div><label><?= te('Held until') ?></label><input name="block_ends" type="date" value=""></div>
      <div class="in-s"><label><?= te('Cost per night') ?></label><input name="nightly_rate" type="number" min="0" step="0.01" value="" placeholder="129.00"></div>
      <div class="in-s"><label><?= te('Billed per night') ?></label><input name="client_night_rate" type="number" min="0" step="0.01" value="" placeholder="<?= te('optional') ?>"></div>
    </div>
    <button class="btn mt-s"><?= te('Add to this project') ?></button> <button type="button" class="btn ghost mt-s" data-fold-close><?= te('Cancel') ?></button>
  </form>
  <h3><?= te('Or a new hotel') ?></h3>
  <?php endif; ?>
  <form method="post" action="/hotels">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="add_hotel">
    <div class="row">
      <div class="f2"><label><?= te('Name') ?></label><input name="name" required placeholder="<?= te('Hampton Inn') ?>"></div>
      <div class="f2"><label><?= te('Address') ?></label><input name="address"></div>
      <div><label><?= te('City') ?></label><input name="city"></div>
      <div class="in-xs"><label><?= te('State') ?></label><input name="state" maxlength="2"></div>
    </div>
    <div class="row mt">
      <div><label><?= te('Phone') ?></label><input name="phone"></div>
      <div><label><?= te('Cost per night') ?></label><input name="nightly_rate" type="number" step="0.01" placeholder="129.00"></div>
      <div><label><?= te('Billed per night') ?></label><input name="client_night_rate" type="number" min="0" step="0.01" placeholder="<?= te('optional') ?>"></div>
      <div class="f2"><label><?= te('Who we book through') ?></label><input name="confirmation_contact"></div>
    </div>
    <div class="row mt">
      <div>
        <label for="h-held"><?= te('Rooms held') ?></label>
        <input id="h-held" name="rooms_held" type="number" min="0" max="2000" placeholder="20">
        <span class="hint"><?= te('For this project') ?></span>
      </div>
      <div><label for="h-from"><?= te('Held from') ?></label>
        <input id="h-from" name="block_starts" type="date"></div>
      <div><label for="h-to"><?= te('Held until') ?></label>
        <input id="h-to" name="block_ends" type="date"></div>
      <div class="row tight"><button class="btn" type="submit"><?= te('Add hotel') ?></button> <button type="button" class="btn ghost" data-fold-close><?= te('Cancel') ?></button></div>
    </div>
  </form>
</details>
<?php endif; ?>

<?php if ($needBed): ?>
<div class="card tight">
  <div class="card-bar">
    <h2 class="m0"><?= te('Needs a bed') ?></h2>
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
                <?php if ($projectHotels): ?><optgroup label="<?= te('This project\'s hotels') ?>">
                  <?php foreach ($projectHotels as $h): ?><option value="<?= (int) $h['hotel_id'] ?>"><?= e($h['name']) ?><?= $h['rooms_held'] !== null ? ' (' . te(':n left', ['n' => max(0, (int) $h['rooms_held'] - (int) $h['taken'])]) . ')' : '' ?></option><?php endforeach; ?>
                </optgroup><optgroup label="<?= te('Other hotels') ?>"><?php endif; ?>
                <?php foreach ($projectHotels ? $otherHotels : $hotels as $h): ?>
                  <option value="<?= (int) $h['id'] ?>"><?= e($h['name']) ?></option>
                <?php endforeach; ?>
                <?php if ($projectHotels): ?></optgroup><?php endif; ?>
              </select>
            </div>

            <div class="in-xs">
              <label for="h-room-<?= (int) $n['id'] ?>"><?= te('Room') ?></label>
              <input id="h-room-<?= (int) $n['id'] ?>" name="room_number"
                     maxlength="12" placeholder="<?= te('At check-in') ?>">
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

            <div class="in-m">
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
  <div class="card-bar">
    <h2 class="m0"><?= te('Rooms booked') ?></h2>
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



<?php if ($hotels): ?>
<details class="card fold tight" id="hotel-directory">
  <summary><h2><?= te('All hotels') ?></h2><span class="fold-note"><?= tn(count($hotels), ':n hotel', ':n hotels') ?></span></summary>
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
</details>
<?php endif; ?>
<script src="/assets/fold.js"></script>
