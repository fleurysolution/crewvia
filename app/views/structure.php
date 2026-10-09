<?php
$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
?>

<div class="page-heading">
  <div>
    <span class="eyebrow"><?= te('DEPLOYMENT VIEW') ?></span>
    <h1><?= e($job['title'] ?? t('Select a project')) ?></h1>
    <p class="sub">
      <?= te('Who is on the site today, what the crew is made of, and the calendar it runs on. The agreement and its rates are on the project itself.') ?>
    </p>
  </div>
  <a class="btn ghost" href="/organization"><?= te('Organization') ?></a>
</div>

<!-- ── the roll call ────────────────────────────────────────────────── -->
<?php if ($job): ?>
  <?php
  $unmarked = array_filter($rollCall, static fn ($r) => $r['present'] === null);
  // Not marked and marked absent are different answers. (int) null is
  // 0 in PHP, so without the null test a crew nobody has called yet is
  // reported as a crew that did not turn up.
  $absent   = array_filter($rollCall,
      static fn ($r) => $r['present'] !== null && (int) $r['present'] === 0);
  ?>
  <section class="card tight" id="rollcall">
    <div style="padding:16px 18px;border-bottom:1px solid var(--line)">
      <span class="eyebrow"><?= te('TODAY ON SITE') ?></span>
      <h2 style="margin:4px 0 2px">
        <?= te(':n of :total on site', ['n' => $onSiteToday, 'total' => count($rollCall)]) ?>
      </h2>
      <p class="small muted" style="margin:0">
        <?= te('Marked at the gate. This is presence, not hours: hours are recorded on Attendance and go to payroll.') ?>
        <?php if ($unmarked): ?>
          &middot; <span class="tag amber"><?= te(':n not marked yet', ['n' => count($unmarked)]) ?></span>
        <?php endif; ?>
        <?php if ($absent): ?>
          &middot; <span class="tag red"><?= te(':n absent', ['n' => count($absent)]) ?></span>
        <?php endif; ?>
      </p>
    </div>

    <?php if (! $rollCall): ?>
      <div class="empty">
        <?= te('Nobody is deployed on this project yet, so there is nobody to call.') ?>
      </div>
    <?php else: ?>
      <div class="scroll">
        <table class="wide">
          <thead><tr>
            <th><?= te('Person') ?></th><th><?= te('Trade') ?></th><th><?= te('Shift') ?></th>
            <th><?= te('Supervisor') ?></th><th><?= te('Today') ?></th>
            <th class="right nowrap"><?= te('Mark') ?></th>
          </tr></thead>
          <tbody>
            <?php foreach ($rollCall as $r): ?>
              <tr>
                <td><strong><?= e($r['full_name']) ?></strong></td>
                <td class="small"><?= e($r['trade'] ?: '—') ?></td>
                <td class="small muted"><?= e($r['shift_label'] ?: '—') ?></td>
                <td class="small"><?= e($r['supervisor'] ?: '—') ?></td>
                <td>
                  <?php if ($r['present'] === null): ?>
                    <span class="tag grey"><?= te('Not marked') ?></span>
                  <?php elseif ((int) $r['present'] === 1): ?>
                    <span class="tag green"><?= te('On site') ?></span>
                    <div class="muted small">
                      <?= te('by :who at :time', [
                          'who'  => $r['marked_by'] ?: t('somebody'),
                          'time' => date('H:i', strtotime((string) $r['marked_at']))]) ?>
                    </div>
                  <?php else: ?>
                    <span class="tag red"><?= te('Absent') ?></span>
                  <?php endif; ?>
                </td>
                <td class="right nowrap">
                  <form method="post" action="/structure" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="checkin">
                    <input type="hidden" name="placement_id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="present" value="1">
                    <button class="btn sm" type="submit"><?= te('On site') ?></button>
                  </form>
                  <form method="post" action="/structure" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="checkin">
                    <input type="hidden" name="placement_id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="present" value="0">
                    <button class="btn ghost sm" type="submit"><?= te('Absent') ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>


<?php if (! $job): ?>
  <div class="card"><div class="empty">
    <?= te('No project selected yet. Pick one from the switcher above.') ?>
  </div></div>
<?php else: ?>

<!-- ── what the site is actually made of ───────────────────────────── -->
<div class="grid g2">
  <section class="card">
    <span class="eyebrow"><?= te('THE SITE') ?></span>
    <h2><?= te('Crew by trade') ?></h2>

    <?php if (! $crewTotal): ?>
      <div class="empty"><?= te('Nobody is on this job yet, so there is nothing to divide up.') ?></div>
    <?php else: ?>
      <table>
        <tbody>
        <?php foreach ($trades as $trade): ?>
          <tr>
            <td>
              <?= e($trade['trade']) ?>
              <div class="headcount-bar" style="margin-top:5px;max-width:200px">
                <span style="width:<?= (int) round($trade['crew'] / $crewTotal * 100) ?>%"></span>
              </div>
            </td>
            <td class="right">
              <strong><?= (int) $trade['crew'] ?></strong>
              <div class="muted small"><?= te(':n on site', ['n' => (int) $trade['on_site']]) ?></div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <section class="card">
    <span class="eyebrow"><?= te('THE SITE') ?></span>
    <h2><?= te('Crew by shift') ?></h2>

    <?php if (! $crewTotal): ?>
      <div class="empty">
        <?= te('Shifts appear once people are assigned one in Organization.') ?>
      </div>
    <?php else: ?>
      <table>
        <tbody>
        <?php foreach ($shifts as $shift): ?>
          <tr>
            <td>
              <?= e($shift['shift']) ?>
              <div class="headcount-bar" style="margin-top:5px;max-width:200px">
                <span style="width:<?= (int) round($shift['crew'] / $crewTotal * 100) ?>%"></span>
              </div>
            </td>
            <td class="right"><strong><?= (int) $shift['crew'] ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="hint" style="margin-top:12px">
        <?= te('Trades and shifts come from each person\'s assignment, not from a second list — so this is always what is actually on site.') ?>
      </p>
    <?php endif; ?>
  </section>
</div>

<!-- ── departments ─────────────────────────────────────────────────── -->
<section class="card">
  <span class="eyebrow"><?= te('DEPARTMENTS') ?></span>
  <h2><?= te('How the client divides the plant') ?></h2>
  <p class="hint" style="margin:-4px 0 16px">
    <?= te('The client\'s own names for the areas of the site, so a conversation about where somebody is working uses their words.') ?>
  </p>

  <?php if ($departments): ?>
    <ul class="org-crew">
      <?php foreach ($departments as $department): ?>
        <li>
          <span class="org-person"><strong><?= e($department['name']) ?></strong></span>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="remove_department">
            <input type="hidden" name="department_id" value="<?= (int) $department['id'] ?>">
            <button class="btn ghost sm" type="submit"><?= te('Remove') ?></button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <div class="empty"><?= te('No departments recorded for this site.') ?></div>
  <?php endif; ?>

  <form method="post" class="row" style="margin-top:16px">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="department">
    <div style="flex:2">
      <label for="dept"><?= te('Department name') ?></label>
      <input id="dept" name="name" required maxlength="190"
             placeholder="<?= te('e.g. Boiler house, Unit 4, Tank farm') ?>">
    </div>
    <button class="btn" type="submit"><?= te('Add department') ?></button>
  </form>
</section>

<!-- ── the calendar ────────────────────────────────────────────────── -->
<form class="card" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="calendar">
  <span class="eyebrow"><?= te('CALENDAR') ?></span>
  <h2><?= te('The week this project runs on') ?></h2>

  <div class="row">
    <div>
      <label for="tz"><?= te('Project timezone') ?></label>
      <select id="tz" name="timezone">
        <?php foreach (['America/New_York', 'America/Chicago', 'America/Denver',
                        'America/Los_Angeles', 'America/Phoenix', 'America/Anchorage',
                        'Pacific/Honolulu'] as $timezone): ?>
          <option <?= ($policy['timezone'] ?? '') === $timezone ? 'selected' : '' ?>>
            <?= e($timezone) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <span class="hint"><?= te('The site\'s clock, not the office\'s.') ?></span>
    </div>
    <div>
      <label for="cycle"><?= te('Pay cycle') ?></label>
      <input id="cycle" readonly value="<?= te('Weekly') ?>">
    </div>
    <div>
      <label for="payday"><?= te('Scheduled payday') ?></label>
      <select id="payday" name="pay_day">
        <?php foreach ($days as $number => $name): ?>
          <option value="<?= $number ?>" <?= (int) ($policy['pay_day'] ?? 5) === $number ? 'selected' : '' ?>>
            <?= te($name) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <p class="hint" style="margin-top:14px">
    <?= te('This records what the crew was told. It does not move money or change a statutory deadline — payroll runs weekly and ADP pays.') ?>
  </p>

  <button class="btn" type="submit"><?= te('Save calendar') ?></button>
</form>
<?php endif; ?>
