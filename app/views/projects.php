<div class="page-heading">
  <div>
    <span class="eyebrow"><?= te('AGREEMENTS') ?></span>
    <h1><?= te('Projects') ?></h1>
    <p class="sub">
      <?= te('Each one is an agreement with a client: what the work is, where, for how long, and what it covers. How many of each trade and what they are paid is the scope of work, written on the project itself.') ?>
    </p>
  </div>
</div>

<form class="card" method="post">
  <?= csrf_field() ?>
  <span class="eyebrow"><?= te('NEW AGREEMENT') ?></span>
  <h2><?= te('Take on a project') ?></h2>

  <div class="row">
    <div style="flex:2">
      <label for="p-client"><?= te('Client') ?></label>
      <input id="p-client" name="client" required maxlength="190"
             placeholder="<?= te('Company the crew works for') ?>">
    </div>
    <div style="flex:2">
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

  <div class="field" style="margin-top:16px">
    <label for="p-desc"><?= te('Scope of the agreement') ?></label>
    <textarea id="p-desc" name="description" rows="4" maxlength="8000"
              placeholder="<?= te('e.g. Provide skilled labour support to the client plant for the duration of the work stoppage, in accordance with the attached scope of work.') ?>"></textarea>
    <span class="hint"><?= te('What this agreement commits the agency to, in the words the client would recognise.') ?></span>
  </div>

  <div class="row">
    <div style="flex:2">
      <label for="p-site"><?= te('Site') ?></label>
      <input id="p-site" name="site_name" maxlength="190"
             placeholder="<?= te('Plant or facility name') ?>">
    </div>
    <div>
      <label for="p-city"><?= te('City') ?></label>
      <input id="p-city" name="site_city" maxlength="120">
    </div>
    <div style="max-width:110px">
      <label for="p-state"><?= te('State') ?></label>
      <input id="p-state" name="site_state" maxlength="2" placeholder="IN">
    </div>
    <div>
      <label for="p-start"><?= te('Starts') ?></label>
      <input id="p-start" type="date" name="starts_on">
    </div>
    <div>
      <label for="p-end"><?= te('Ends') ?></label>
      <input id="p-end" type="date" name="ends_on">
    </div>
  </div>

  <h3 style="margin-top:22px"><?= te('What the agreement covers') ?></h3>
  <p class="hint" style="margin:-4px 0 12px">
    <?= te('Beyond the hourly rate. These are what the crew is told and what the client is invoiced for, so they are recorded on the agreement rather than remembered.') ?>
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

  <button class="btn" type="submit" style="margin-top:20px"><?= te('Create the agreement') ?></button>
  <span class="hint" style="display:inline-block;margin-left:12px">
    <?= te('You will land on its scope of work, where the trades and their rates are written.') ?>
  </span>
</form>

<div class="card tight">
  <div style="padding:14px 18px;border-bottom:1px solid var(--line)">
    <h2 style="margin:0"><?= te('Agreements') ?></h2>
  </div>

  <?php if (! $projects): ?>
    <div class="empty"><?= te('No agreements yet. Create one above, then write its scope of work.') ?></div>
  <?php else: ?>
  <div class="scroll">
    <table>
      <thead><tr>
        <th><?= te('Project') ?></th><th><?= te('Client') ?></th><th><?= te('Where') ?></th>
        <th><?= te('Status') ?></th><th><?= te('Scope') ?></th><th class="right"><?= te('Open') ?></th>
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
              <div class="muted"><?= te(':n lines on the order', ['n' => (int) $p['scope_lines']]) ?></div>
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
