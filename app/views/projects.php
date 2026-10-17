<div class="page-heading">
  <div>
    <h1><?= te('Projects') ?></h1>
    <p class="sub">
      <?= te('Your active and planned projects.') ?>
    </p>
  </div>
  <div class="flex gap-s"><a class="btn" href="#new-project" data-new-project><?= te('+ New project') ?></a></div>
</div>



<div class="card tight">
  <div class="card-bar">
    <h2 class="m0"><?= te('Projects') ?></h2>
  </div>

  <?php if (! $projects): ?>
    <div class="empty"><?= te('No projects yet. Create one above, then write its scope of work.') ?></div>
  <?php else: ?>
  <div class="scroll">
    <table>
      <thead><tr>
        <th><?= te('Project') ?></th><th><?= te('Client') ?></th><th><?= te('Where') ?></th>
        <th><?= te('Status') ?></th><th><?= te('Scope') ?></th><th><?= te('Tasks') ?></th><th class="right"><?= te('Open') ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($projects as $p): ?>
        <tr>
          <td>
            <strong><?= e($p['title']) ?></strong>
            <?php if ($p['order_reference']): ?>
              <div class="muted small"><?= te('Order') ?> <?= e($p['order_reference']) ?></div>
            <?php endif; ?>
          </td>
          <td class="small"><?= e($p['client_name']) ?></td>
          <td class="small muted">
            <?= e(trim(($p['site_city'] ?? '') . ' ' . ($p['site_state'] ?? ''))) ?: '&mdash;' ?>
          </td>
          <td>
            <span class="tag <?= $p['status'] === 'active' ? 'green' : ($p['status'] === 'closed' ? 'grey' : 'blue') ?>">
              <?= te(ucfirst((string) $p['status'])) ?>
            </span>
          </td>
          <td class="small">
            <?php if (! (int) $p['scope_lines']): ?>
              <span class="tag amber"><?= te('No scope written') ?></span>
            <?php else: ?>
              <?= te(':placed of :asked placed', [
                  'placed' => (int) $p['placed'], 'asked' => (int) $p['asked_for']]) ?>
              <div class="muted"><?= ten((int) $p['scope_lines'], ':n line on the order', ':n lines on the order') ?></div>
            <?php endif; ?>
          </td>
          <td class="small" data-task-progress="<?= (int) $p['id'] ?>">
            <?php $tp = $taskProgress[(int) $p['id']] ?? null; ?>
            <?php if (! $tp || ! $tp['live']): ?>
              <span class="muted">&mdash;</span>
            <?php else: ?>
              <?= te(':done of :live done (:percent%)', ['done' => $tp['done'], 'live' => $tp['live'], 'percent' => $tp['percent']]) ?>
              <?php if ($tp['overdue']): ?><div><span class="tag red"><?= te(':n overdue', ['n' => $tp['overdue']]) ?></span></div><?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="right">
            <form method="post" action="/select-project">
              <?= csrf_field() ?>
              <input type="hidden" name="job_id" value="<?= (int) $p['id'] ?>">
              <input type="hidden" name="then" value="/job">
              <button class="btn ghost sm" type="submit"><?= te('Open') ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php // P5-M73: Projects shows the projects first; a new one is a button that opens the form (RSS: "when I click projects, I want to see my projects"). ?>
<details class="card fold" id="new-project" data-fold-forget<?= ! $projects || $chosenClient || ! empty($_GET['new']) ? ' open' : '' ?>>
  <summary><span><span class="eyebrow"><?= te('NEW PROJECT') ?></span><h2><?= te('Take on a project') ?></h2></span></summary>
<form method="post">
  <?= csrf_field() ?>

  <div class="row">
    <div class="f2">
      <label for="p-client"><?= te('Client') ?></label>
      <?php // P5-M69: the client is created first, on Clients, and chosen here. ?>
      <select id="p-client" name="client_id" required>
        <option value=""><?= $clientChoices ? te('Choose the client…') : te('No client yet: add one first') ?></option>
        <?php foreach ($clientChoices as $cc): ?><option value="<?= (int) $cc['id'] ?>" <?= (int) $cc['id'] === $chosenClient ? 'selected' : '' ?>><?= e($cc['name']) ?></option><?php endforeach; ?>
      </select>
      <p class="small m0"><a href="/clients#new"><?= te('Add a client') ?></a></p>
    </div>
    <div class="f2">
      <label for="p-title"><?= te('Project name') ?></label>
      <input id="p-title" name="title" required maxlength="190"
             placeholder="<?= te('e.g. Strike coverage - plant engineers') ?>">
    </div>
    <div>
      <label for="p-ref"><?= te('Client order reference') ?></label>
      <input id="p-ref" name="order_reference" maxlength="120"
             placeholder="<?= te('Their PO or contract number') ?>">
    </div>
  </div>

  <div class="field mt-l">
    <label for="p-desc"><?= te('What the work is') ?></label>
    <textarea id="p-desc" name="description" rows="4" maxlength="8000"
              placeholder="<?= te('e.g. Provide skilled labour support to the client plant for the duration of the work stoppage, in accordance with the attached scope of work.') ?>"></textarea>
    <span class="hint"><?= te('Shown to applicants.') ?></span>
  </div>

  <?php // P5-M77: a site already kept on the chosen client fills the fields below. ?>
  <div class="row" id="saved-site-row" hidden>
    <div class="f2"><label for="p-saved-site"><?= te('A site of this client') ?></label>
      <select id="p-saved-site" data-sites="<?= e(json_encode($clientSites, JSON_UNESCAPED_SLASHES)) ?>"><option value=""><?= te('A new site') ?></option></select></div>
  </div>
  <?php // P5-M71: the site address finds the position, city, state and ZIP (app/geocode.php). ?>
  <div class="row" data-geo data-lat="#p-lat" data-lng="#p-lng" data-city="#p-city" data-state="#p-state" data-zip="#p-zip">
    <div class="f2"><label for="p-address"><?= te('Site address') ?></label><input id="p-address" name="site_address" maxlength="190" autocomplete="off" data-geo-address value="" placeholder="<?= te('e.g. 1 N Broadway, Gary, IN') ?>">
      <span class="hint" data-geo-status data-searching="<?= te('Finding the address...') ?>" data-found="<?= te('Using :address - position :lat, :lng. Save to keep it.') ?>" data-use="<?= te('Use this address') ?>" data-other="<?= te('Not this one') ?>" data-hint="<?= te('Type the address more fully (number, street, city, state), or type the latitude and longitude.') ?>"><?= te('City, state, ZIP and position are filled from the address.') ?></span></div>
    <div><label>&nbsp;</label><button type="button" class="btn ghost sm" data-geo-find><?= te('Find the address') ?></button></div>
  </div>
  <div class="row">
    <div class="f2">
      <label for="p-site"><?= te('Site') ?></label>
      <input id="p-site" name="site_name" maxlength="190"
             placeholder="<?= te('Plant or facility name') ?>">
    </div>
    <div>
      <label for="p-city"><?= te('City') ?></label>
      <input id="p-city" name="site_city" maxlength="120">
    </div>
    <div class="in-s">
      <label for="p-state"><?= te('State') ?></label>
      <input id="p-state" name="site_state" maxlength="2" placeholder="IN">
    </div>
    <div class="in-s">
      <label for="p-zip"><?= te('ZIP code') ?></label>
      <input id="p-zip" name="site_zip" maxlength="10" inputmode="numeric" pattern="\d{5}(-\d{4})?">
    </div>
  </div>
  <?php // P5-M70: the site's exact position from the start, for the clock-in (P5-M67); filled from the address (P5-M71). ?>
  <div class="row">
    <div><label for="p-lat"><?= te('Site latitude') ?></label><input id="p-lat" name="site_lat" inputmode="decimal" maxlength="12" placeholder="41.6036"></div>
    <div><label for="p-lng"><?= te('Site longitude') ?></label><input id="p-lng" name="site_lng" inputmode="decimal" maxlength="12" placeholder="-87.3378"></div>
    <div><label for="p-radius"><?= te('Clock-in radius (m)') ?></label><input id="p-radius" name="site_radius_m" type="number" min="50" max="5000" value="300"><span class="hint"><?= te('In metres (300 m is about 1,000 ft). Clock-ins outside it are flagged to the supervisor.') ?></span></div>
    <div><label>&nbsp;</label><button type="button" class="btn ghost sm" onclick="var b=this;navigator.geolocation&&navigator.geolocation.getCurrentPosition(function(p){document.getElementById('p-lat').value=p.coords.latitude.toFixed(6);document.getElementById('p-lng').value=p.coords.longitude.toFixed(6);b.textContent=b.dataset.done;},function(){b.textContent=b.dataset.fail;},{enableHighAccuracy:true,timeout:15000})" data-done="<?= te('Position taken') ?>" data-fail="<?= te('Position unavailable') ?>"><?= te('My position here') ?></button></div>
    <div>
      <label for="p-start"><?= te('Starts') ?></label>
      <input id="p-start" type="date" name="starts_on">
    </div>
    <div>
      <label for="p-end"><?= te('Ends') ?></label>
      <input id="p-end" type="date" name="ends_on">
      <?php // P5-M71: many projects have no end date - they run until the client stops them; billing carries on. ?>
      <label class="d-block small"><input type="checkbox" name="ends_unknown" value="1" onchange="var d=document.getElementById('p-end');d.disabled=this.checked;if(this.checked)d.value=''"> <?= te('End date unknown (ongoing)') ?></label>
    </div>
  </div>

  <h3 class="mt-xl"><?= te('What the contract covers') ?></h3>
  <p class="hint m0 pull-up mb">
    <?= te('Provided to the crew and billed to the client.') ?>
  </p>

  <div class="row">
    <label class="own-room">
      <input type="checkbox" name="lodging_provided" value="1" checked>
      <?= te('Lodging paid') ?>
    </label>
    <label class="own-room">
      <input type="checkbox" name="travel_provided" value="1" checked>
      <?= te('Travel to and from site paid') ?>
    </label>
    <label class="own-room">
      <input type="checkbox" name="transport_provided" value="1" checked>
      <?= te('Transport on site provided') ?>
    </label>
  </div>

  <button class="btn mt-l" type="submit"><?= te('Create the project') ?></button> <button type="button" class="btn ghost" data-fold-close><?= te('Cancel') ?></button>
  <span class="hint inline-block ml">
    <?= te('You will land on its scope of work, where the trades and their rates are written.') ?>
  </span>
</form>
</details>
<script src="/assets/site-address.js"></script>
<script src="/assets/fold.js"></script>
<script src="/assets/client-sites.js"></script>
