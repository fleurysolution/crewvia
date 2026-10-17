<?php /* One placement for Recruiting and Logistics: no pay. Walkthrough findings 22-23: the dates can be set here, the status reads in words. */ ?>
<p class="back-link"><a href="/roster"><?= te('← Roster') ?></a></p>
<div class="page-head"><div><h1><?= e($p['full_name']) ?></h1><p class="sub"><span class="tag <?= $p['status'] === 'on_site' ? 'green' : ($p['status'] === 'cancelled' ? 'red' : 'blue') ?>"><?= te(placement_word((string) $p['status'])) ?></span> &middot; <?= e($p['job_title']) ?></p></div></div>
<?php require __DIR__ . '/_text-panel.php'; ?>
<?php if (can('recruiter', 'hotels')): ?>
<form method="post" class="card row items-end mb-l" id="placement-dates"><?= csrf_field() ?><input type="hidden" name="do" value="dates">
  <div><label for="pl-start"><?= te('Starts') ?></label><input id="pl-start" type="date" name="start_date" value="<?= e((string) ($p['start_date'] ?? '')) ?>"></div>
  <div><label for="pl-end"><?= te('Ends') ?></label><input id="pl-end" type="date" name="end_date" value="<?= e((string) ($p['end_date'] ?? '')) ?>"></div>
  <button class="btn" type="submit"><?= te('Save the dates') ?></button>
  <span class="hint"><?= te('Arrivals, roll call and pay read these dates.') ?></span>
</form>
<?php endif; ?>
<div class="grid g2">
  <div class="card"><h2><?= te('Accommodation') ?></h2>
    <?php if ($lodge): ?><p><?= e($lodge['hotel']) ?> &middot; <?= te('Room :n', ['n' => $lodge['room_number']]) ?> &middot; <?= e(d((string) $lodge['check_in'])) ?> &rarr; <?= e(d((string) $lodge['check_out'])) ?></p>
    <?php else: ?><p class="muted"><?= te('Accommodation not booked.') ?></p><?php endif; ?>
  </div>
  <div class="card"><h2><?= te('Travel') ?></h2>
    <?php if (! $legs): ?><p class="muted"><?= te('Travel not booked.') ?></p><?php endif; ?>
    <?php foreach ($legs as $t): ?><p><strong><?= $t['direction'] === 'inbound' ? te('Going') : te('Coming home') ?></strong> &middot; <?= e($t['carrier']) ?> &middot; <?= e($t['depart_from']) ?> &rarr; <?= e($t['arrive_at']) ?> <?php if (! empty($t['depart_time'])): ?>&middot; <?= te('leaves :time', ['time' => (string) $t['depart_time']]) ?><?php endif; ?><?php if (! empty($t['arrive_time'])): ?> &middot; <?= te('arrives :time', ['time' => (string) $t['arrive_time']]) ?><?php endif; ?></p><?php endforeach; ?>
  </div>
</div>
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
