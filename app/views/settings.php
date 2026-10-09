<?php
$roleNames = roles();
$basis     = $settings['billing_basis'] ?? 'active_workers';
$price     = (float) ($settings['employee_monthly_price'] ?? 0);
$counted   = $basis === 'deployed_workers' ? $deployed : $active;
?>

<div class="page-heading">
  <div>
    <span class="eyebrow"><?= te('ADMINISTRATION') ?></span>
    <h1><?= te('Settings') ?></h1>
    <p class="sub"><?= te('Who can reach what, how this workspace is named, and how payroll and the subscription are counted.') ?></p>
  </div>
  <a class="btn ghost" href="/people"><?= te('Manage accounts') ?></a>
</div>

<nav class="settings-tabs" aria-label="<?= te('Settings sections') ?>">
  <a href="#permissions"><?= te('Roles & permissions') ?></a>
  <a href="#workspace"><?= te('Workspace') ?></a>
  <a href="#payroll"><?= te('Payroll export') ?></a>
  <a href="#subscription"><?= te('Subscription') ?></a>
  <a href="#audit"><?= te('Recent changes') ?></a>
</nav>

<!-- ── who can reach what ────────────────────────────────────────────── -->
<section class="card" id="permissions">
  <span class="eyebrow"><?= te('ACCESS CONTROL') ?></span>
  <h2><?= te('Roles and permissions') ?></h2>
  <p class="hint" style="margin:-4px 0 18px">
    <?= te('Everybody works their own desk. A tick here lends one desk to another — a recruiter who also books hotels, a payroll clerk who also reads screening. Administrators reach everything; workers, supervisors and clients are not desks and never appear here.') ?>
  </p>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="permissions">

    <div class="scroll">
      <table class="permission-grid">
        <thead>
          <tr>
            <th><?= te('Staff role') ?></th>
            <?php foreach ($desks as $desk => $meta): ?>
              <th class="center">
                <?= e(ucfirst($desk)) ?>
                <span class="muted small"><?= te(':n screens', ['n' => count($meta['screens'])]) ?></span>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($desks as $role => $meta): ?>
          <tr>
            <th scope="row">
              <?= e(ucfirst($role)) ?>
              <span class="muted small">
                <?= e(people((int) ($roleCounts[$role] ?? 0))) ?>
              </span>
            </th>
            <?php foreach ($desks as $desk => $ignored): ?>
              <td class="center">
                <?php if ($role === $desk): ?>
                  <span class="tag green"><?= te('Own desk') ?></span>
                <?php else: ?>
                  <label class="sr-only" for="g-<?= e($role) ?>-<?= e($desk) ?>">
                    <?= te('Give :role access to the :desk desk', ['role' => $role, 'desk' => $desk]) ?>
                  </label>
                  <input type="checkbox" id="g-<?= e($role) ?>-<?= e($desk) ?>"
                         name="grant[<?= e($role) ?>][<?= e($desk) ?>]" value="1"
                         <?= ! empty($granted[$role][$desk]) ? 'checked' : '' ?>>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <p class="hint" style="margin-top:12px">
      <?= te('Unticking a box removes the access when you save. Every change is recorded below.') ?>
    </p>
    <button class="btn" type="submit"><?= te('Save permissions') ?></button>
  </form>

  <h3 style="margin-top:26px"><?= te('What each desk opens') ?></h3>
  <div class="grid g3">
    <?php foreach ($desks as $desk => $meta): ?>
      <div class="desk-card">
        <strong><?= e(ucfirst($desk)) ?></strong>
        <span class="muted small"><?= e(people($meta['people'])) ?> <?= te('at this desk') ?></span>
        <ul>
          <?php foreach ($meta['screens'] as $screen): ?>
            <li><?= te($screen) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<div class="grid g2">
  <!-- ── identity ───────────────────────────────────────────────────── -->
  <form class="card" method="post" id="workspace">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="workspace">
    <span class="eyebrow"><?= te('IDENTITY') ?></span>
    <h2><?= te('Workspace') ?></h2>

    <div class="field">
      <label for="s-brand"><?= te('Platform name') ?></label>
      <input id="s-brand" name="brand_name" maxlength="190"
             value="<?= e($settings['brand_name'] ?? $config['app_name']) ?>">
      <span class="hint"><?= te('Shown in the header, the browser tab and the installed app.') ?></span>
    </div>

    <div class="field">
      <label for="s-legal"><?= te('Legal contact') ?></label>
      <input id="s-legal" name="legal_contact" maxlength="190"
             value="<?= e($settings['legal_contact'] ?? '') ?>">
      <span class="hint"><?= te('Who to reach about contracts and candidate records.') ?></span>
    </div>

    <h3 id="payroll" style="margin-top:22px"><?= te('Payroll export') ?></h3>
    <p class="hint" style="margin:-6px 0 14px">
      <?= te('ADP RUN takes these codes on every export. A wrong code is paid under the wrong earning.') ?>
    </p>

    <div class="row">
      <div>
        <label for="s-adpco"><?= te('Company code') ?></label>
        <input id="s-adpco" name="adp_company_code" maxlength="40"
               value="<?= e($settings['adp_company_code'] ?? '') ?>">
      </div>
      <div>
        <label for="s-adph"><?= te('Hours') ?></label>
        <input id="s-adph" name="adp_hours_code" maxlength="40"
               value="<?= e($settings['adp_hours_code'] ?? '') ?>">
      </div>
    </div>

    <div class="row" style="margin-top:14px">
      <div>
        <label for="s-adpd"><?= te('Per diem') ?></label>
        <input id="s-adpd" name="adp_perdiem_code" maxlength="40"
               value="<?= e($settings['adp_perdiem_code'] ?? '') ?>">
      </div>
      <div>
        <label for="s-adpo"><?= te('Overtime') ?></label>
        <input id="s-adpo" name="adp_overtime_code" maxlength="40"
               value="<?= e($settings['adp_overtime_code'] ?? '') ?>">
      </div>
    </div>

    <h3 id="subscription" style="margin-top:22px"><?= te('Subscription') ?></h3>

    <div class="row">
      <div>
        <label for="s-price"><?= te('Price per employee / month') ?></label>
        <input id="s-price" type="number" name="employee_monthly_price"
               min="0" max="100000" step="0.01"
               value="<?= e((string) $price) ?>">
      </div>
      <div style="flex:2">
        <label for="s-basis"><?= te('Counting basis') ?></label>
        <select id="s-basis" name="billing_basis">
          <option value="active_workers" <?= $basis === 'active_workers' ? 'selected' : '' ?>>
            <?= te('Active worker accounts') ?>
          </option>
          <option value="deployed_workers" <?= $basis === 'deployed_workers' ? 'selected' : '' ?>>
            <?= te('Distinct deployed workers') ?>
          </option>
        </select>
      </div>
    </div>

    <button class="btn" type="submit" style="margin-top:20px"><?= te('Save settings') ?></button>
  </form>

  <!-- ── what that adds up to ───────────────────────────────────────── -->
  <section class="card">
    <span class="eyebrow"><?= te('THIS WORKSPACE TODAY') ?></span>
    <h2><?= te('Where you stand') ?></h2>

    <div class="grid g2" style="margin-bottom:16px">
      <div class="stat">
        <div class="n"><?= $active ?></div>
        <div class="l"><?= te('Active worker accounts') ?></div>
      </div>
      <div class="stat">
        <div class="n"><?= $deployed ?></div>
        <div class="l"><?= te('Deployed right now') ?></div>
      </div>
    </div>

    <table>
      <tbody>
      <?php foreach ($roleNames as $key => $label): ?>
        <tr>
          <td><?= te($label) ?></td>
          <td class="right mono"><?= (int) ($roleCounts[$key] ?? 0) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <p style="margin-top:16px">
      <strong><?= te('Estimated monthly software fee:') ?></strong>
      <?= e(money($price * $counted)) ?>
      <span class="muted small">
        <?= te(':n counted', ['n' => $counted]) ?>
      </span>
    </p>
    <p class="hint">
      <?= te('An estimate. Subscription billing stays off until Stripe credentials are configured and the workspace is validated.') ?>
    </p>
  </section>
</div>

<!-- ── the skills ───────────────────────────────────────────────────── -->
<section class="card" id="skills">
  <span class="eyebrow"><?= te('SKILLS') ?></span>
  <h2><?= te('What people can do') ?></h2>
  <p class="small muted" style="margin:-6px 0 14px">
    <?= te('A trade is what an order asks for. A skill is what a person can do, and somebody can have several. Recruiters search the pool by these: this is how "find me the welders" is answered.') ?>
  </p>

  <form method="post" class="row" style="margin-bottom:18px">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="add_skill">
    <div style="flex:2">
      <label for="skill-label"><?= te('Add a skill') ?></label>
      <input id="skill-label" name="label" maxlength="90" required
             placeholder="<?= te('e.g. Tank welder, CNC operator, Heavy equipment operator') ?>">
    </div>
    <div class="row tight"><button class="btn" type="submit"><?= te('Add') ?></button></div>
  </form>

  <div class="scroll">
    <table>
      <thead><tr>
        <th><?= te('Skill') ?></th><th><?= te('Key') ?></th>
        <th class="num"><?= te('People') ?></th><th><?= te('State') ?></th>
        <th class="right"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($skillRows as $skill): ?>
        <tr>
          <td><strong><?= e($skill['label']) ?></strong></td>
          <td class="small mono muted"><?= e($skill['slug']) ?></td>
          <td class="num"><?= (int) $skill['people'] ?></td>
          <td>
            <span class="tag <?= $skill['active'] ? 'green' : 'grey' ?>">
              <?= $skill['active'] ? te('Offered') : te('Retired') ?>
            </span>
          </td>
          <td class="right nowrap">
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="<?= $skill['active'] ? 'retire_skill' : 'restore_skill' ?>">
              <input type="hidden" name="slug" value="<?= e($skill['slug']) ?>">
              <button class="btn ghost sm" type="submit">
                <?= $skill['active'] ? te('Retire') : te('Put back') ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- ── the trades ───────────────────────────────────────────────────── -->
<section class="card" id="trades">
  <span class="eyebrow"><?= te('TRADES') ?></span>
  <h2><?= te('What this agency staffs') ?></h2>
  <p class="small muted" style="margin:-6px 0 14px">
    <?= te('These are the trades a scope of work can ask for and a candidate can be filed under. A trade in use is retired rather than deleted, so past orders keep their meaning.') ?>
  </p>

  <form method="post" class="row" style="margin-bottom:18px">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="add_trade">
    <div style="flex:2">
      <label for="trade-label"><?= te('Add a trade') ?></label>
      <input id="trade-label" name="label" maxlength="90" required
             placeholder="<?= te('e.g. Boilermaker, Rigger, Welder') ?>">
    </div>
    <div class="row tight"><button class="btn" type="submit"><?= te('Add') ?></button></div>
  </form>

  <div class="scroll">
    <table>
      <thead><tr>
        <th><?= te('Trade') ?></th><th><?= te('Key') ?></th>
        <th class="num"><?= te('On record') ?></th><th><?= te('State') ?></th>
        <th class="right"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($trades as $trade): ?>
        <tr>
          <td><strong><?= e($trade['label']) ?></strong></td>
          <td class="small mono muted"><?= e($trade['slug']) ?></td>
          <td class="num"><?= (int) $trade['in_use'] ?></td>
          <td>
            <span class="tag <?= $trade['active'] ? 'green' : 'grey' ?>">
              <?= $trade['active'] ? te('Offered') : te('Retired') ?>
            </span>
          </td>
          <td class="right nowrap">
            <?php if ($trade['slug'] !== 'other'): ?>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="<?= $trade['active'] ? 'retire_trade' : 'restore_trade' ?>">
                <input type="hidden" name="slug" value="<?= e($trade['slug']) ?>">
                <button class="btn ghost sm" type="submit">
                  <?= $trade['active'] ? te('Retire') : te('Put back') ?>
                </button>
              </form>
            <?php else: ?>
              <span class="muted small"><?= te('always available') ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- ── the record ───────────────────────────────────────────────────── -->
<section class="card" id="audit">
  <span class="eyebrow"><?= te('RECORD') ?></span>
  <h2><?= te('Recent changes to settings and permissions') ?></h2>

  <?php if (! $recentChanges): ?>
    <div class="empty"><?= te('Nothing has been changed here yet.') ?></div>
  <?php else: ?>
    <table>
      <thead><tr>
        <th><?= te('When') ?></th><th><?= te('Who') ?></th><th><?= te('What') ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($recentChanges as $change): ?>
        <tr>
          <td class="small mono"><?= e(date('j M Y, H:i', strtotime($change['created_at']))) ?></td>
          <td class="small"><?= e($change['name'] ?? t('Removed account')) ?></td>
          <td class="small"><?= te($change['action']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
