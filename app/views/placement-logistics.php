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
