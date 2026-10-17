<?php
// P5-M69: the clients - created first, then chosen for each project.
$form = static function (array $c, string $do, string $button) { $v = static fn(string $k, $d = '') => e((string) ($c[$k] ?? $d)); ?>
  <form method="post" action="/clients" class="grid g3"><?= csrf_field() ?><input type="hidden" name="do" value="<?= e($do) ?>"><?php if (! empty($c['id'])): ?><input type="hidden" name="client_id" value="<?= (int) $c['id'] ?>"><?php endif; ?>
    <div><label for="c-name-<?= e($do) ?>"><?= te('Client (company)') ?></label><input id="c-name-<?= e($do) ?>" name="name" required maxlength="190" value="<?= $v('name') ?>"></div>
    <div><label><?= te('Address') ?></label><input name="address" maxlength="190" value="<?= $v('address') ?>"></div>
    <div class="row gap-s"><div><label><?= te('City') ?></label><input name="city" maxlength="120" value="<?= $v('city') ?>"></div><div class="in-xs"><label><?= te('State') ?></label><input name="state" maxlength="2" value="<?= $v('state') ?>"></div><div class="in-s"><label><?= te('ZIP code') ?></label><input name="zip" maxlength="10" inputmode="numeric" value="<?= $v('zip') ?>"></div></div>
    <div><label><?= te('Contact person') ?></label><input name="contact_name" maxlength="120" value="<?= $v('contact_name') ?>"></div>
    <div><label><?= te('Contact email') ?></label><input name="contact_email" type="email" maxlength="190" value="<?= $v('contact_email') ?>"></div>
    <div><label><?= te('Contact phone') ?></label><input name="contact_phone" maxlength="40" value="<?= $v('contact_phone') ?>"></div>
    <div><label><?= te('Email for invoices') ?></label><input name="billing_email" type="email" maxlength="190" value="<?= $v('billing_email') ?>"></div>
    <div><label><?= te('Payment terms (days)') ?></label><input name="payment_terms_days" type="number" min="0" max="180" value="<?= $v('payment_terms_days', 30) ?>"></div>
    <div><label><?= te('Notes') ?></label><input name="notes" maxlength="2000" value="<?= $v('notes') ?>"></div>
    <div><button class="btn"><?= e($button) ?></button></div>
  </form>
<?php }; ?>
<div class="page-head"><div><h1><?= te('Clients') ?></h1>
  <p class="sub"><?= te('The companies the crews work for. Add a client first, then take on their projects; invoices use their terms and billing email.') ?></p></div>
  <div class="actions"><a class="btn ghost" href="/projects"><?= te('Projects') ?></a><a class="btn ghost" href="/client-access"><?= te('Client access') ?></a></div></div>

<?php if ($open): ?>
<div class="card" id="client" data-client="<?= (int) $open['id'] ?>"><h2><?= e($open['name']) ?></h2>
  <?php $form($open, 'update', t('Save')); ?>
  <h3 class="mt"><?= te('Projects') ?></h3>
  <?php if (! $openProjects): ?><p class="muted"><?= te('No project yet.') ?></p><?php endif; ?>
  <?php foreach ($openProjects as $j): ?><p class="small" data-client-project="<?= (int) $j['id'] ?>"><strong><?= e($j['title']) ?></strong> · <?= e($j['status']) ?><?= $j['site_city'] ? ' · ' . e($j['site_city'] . ', ' . $j['site_state']) : '' ?></p><?php endforeach; ?>
  <p class="mt-s"><a class="btn" href="/projects?client_id=<?= (int) $open['id'] ?>#new-project"><?= te('Take on a project for this client') ?></a></p>
</div>
<?php endif; ?>

<div class="card" id="new"><h2><?= te('Add a client') ?></h2>
  <?php $form($old, 'create', t('Add the client')); ?>
</div>

<div class="card"><h2><?= tn(count($clients), ':n client', ':n clients') ?></h2>
  <table><thead><tr><th><?= te('Client') ?></th><th><?= te('Contact') ?></th><th><?= te('Terms') ?></th><th><?= te('Projects') ?></th><th><?= te('Portal accounts') ?></th></tr></thead><tbody>
  <?php foreach ($clients as $c): ?>
    <tr data-client-row="<?= (int) $c['id'] ?>"><td><a href="/clients?id=<?= (int) $c['id'] ?>#client"><?= e($c['name']) ?></a><?= $c['city'] ? '<br><span class="small muted">' . e($c['city'] . ', ' . $c['state']) . '</span>' : '' ?></td>
      <td class="small"><?= e(trim(($c['contact_name'] ?? '') . ' ' . ($c['contact_email'] ?? '') . ' ' . ($c['contact_phone'] ?? ''))) ?: '—' ?></td>
      <td><?= te(':n days', ['n' => (int) $c['payment_terms_days']]) ?></td>
      <td><?= (int) $c['projects'] ?><?= (int) $c['live'] ? ' · ' . te(':n live', ['n' => (int) $c['live']]) : '' ?></td>
      <td><?= (int) $c['portal_users'] ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
</div>
