<?php
// P5-M69: the clients - created first, then chosen for each project.
// P5-M71: each client's directory of contacts - the main point of contact, who receives the invoices.
$form = static function (array $c, string $do, string $button, array $invoiceTo = []) { $v = static fn(string $k, $d = '') => e((string) ($c[$k] ?? $d)); ?>
  <form method="post" action="/clients" class="grid g3"><?= csrf_field() ?><input type="hidden" name="do" value="<?= e($do) ?>"><?php if (! empty($c['id'])): ?><input type="hidden" name="client_id" value="<?= (int) $c['id'] ?>"><?php endif; ?>
    <div><label for="c-name-<?= e($do) ?>"><?= te('Client (company)') ?></label><input id="c-name-<?= e($do) ?>" name="name" required maxlength="190" value="<?= $v('name') ?>"></div>
    <div><label><?= te('Address') ?></label><input name="address" maxlength="190" value="<?= $v('address') ?>"></div>
    <div class="row gap-s"><div><label><?= te('City') ?></label><input name="city" maxlength="120" value="<?= $v('city') ?>"></div><div class="in-xs"><label><?= te('State') ?></label><input name="state" maxlength="2" value="<?= $v('state') ?>"></div><div class="in-s"><label><?= te('ZIP code') ?></label><input name="zip" maxlength="10" inputmode="numeric" value="<?= $v('zip') ?>"></div></div>
    <?php if ($do === 'create'): ?>
    <div><label><?= te('Main point of contact') ?></label><input name="contact_name" maxlength="120" value="<?= $v('contact_name') ?>" placeholder="<?= te('Full name') ?>"></div>
    <div><label><?= te('Their title') ?></label><input name="contact_title" maxlength="120" value="<?= $v('contact_title') ?>" placeholder="<?= te('e.g. Hiring manager') ?>"></div>
    <div><label><?= te('Contact email') ?></label><input name="contact_email" type="email" maxlength="190" value="<?= $v('contact_email') ?>"></div>
    <div><label><?= te('Contact phone') ?></label><input name="contact_phone" maxlength="40" value="<?= $v('contact_phone') ?>"></div>
    <div><label>&nbsp;</label><label class="d-block"><input type="checkbox" name="contact_invoices" value="1"<?= ! empty($c['contact_invoices']) ? ' checked' : '' ?>> <?= te('They also receive the invoices') ?></label></div>
    <?php endif; ?>
    <?php if ($invoiceTo): ?>
    <div><label><?= te('Invoices go to') ?></label><p class="small" data-invoice-to><?= e(implode(', ', array_map(static fn($x) => $x['name'] . ' <' . $x['email'] . '>', $invoiceTo))) ?><br><span class="muted"><?= te('Chosen in the contacts below.') ?></span></p></div>
    <?php else: ?>
    <div><label><?= te('Email for invoices') ?></label><input name="billing_email" type="email" maxlength="190" value="<?= $v('billing_email') ?>"><?php if ($do !== 'create'): ?><span class="small muted"><?= te('Or tick a contact below as receiving the invoices.') ?></span><?php endif; ?></div>
    <?php endif; ?>
    <div><label><?= te('Payment terms (days)') ?></label><input name="payment_terms_days" type="number" min="0" max="180" value="<?= $v('payment_terms_days', 30) ?>"></div>
    <div><label><?= te('Notes') ?></label><input name="notes" maxlength="2000" value="<?= $v('notes') ?>"></div>
    <div><button class="btn"><?= e($button) ?></button></div>
  </form>
<?php };
$contactForm = static function (int $clientId, ?array $x) { $v = static fn(string $k) => e((string) ($x[$k] ?? '')); $id = (int) ($x['id'] ?? 0); ?>
  <form method="post" action="/clients" class="grid g3" id="<?= $id ? 'edit-contact' : 'new-contact' ?>"><?= csrf_field() ?>
    <input type="hidden" name="do" value="<?= $id ? 'contact_update' : 'contact_add' ?>"><input type="hidden" name="client_id" value="<?= $clientId ?>"><?php if ($id): ?><input type="hidden" name="contact_id" value="<?= $id ?>"><?php endif; ?>
    <div><label for="cc-name-<?= $id ?>"><?= te('Name') ?></label><input id="cc-name-<?= $id ?>" name="cc_name" required maxlength="120" value="<?= $v('name') ?>"></div>
    <div><label for="cc-title-<?= $id ?>"><?= te('Title or role') ?></label><input id="cc-title-<?= $id ?>" name="cc_title" maxlength="120" value="<?= $v('title') ?>" placeholder="<?= te('e.g. Site manager, Accounts payable') ?>"></div>
    <div><label for="cc-email-<?= $id ?>"><?= te('Email') ?></label><input id="cc-email-<?= $id ?>" name="cc_email" type="email" maxlength="190" value="<?= $v('email') ?>"></div>
    <div><label for="cc-phone-<?= $id ?>"><?= te('Phone') ?></label><input id="cc-phone-<?= $id ?>" name="cc_phone" maxlength="40" value="<?= $v('phone') ?>"></div>
    <div><label for="cc-notes-<?= $id ?>"><?= te('Notes') ?></label><input id="cc-notes-<?= $id ?>" name="cc_notes" maxlength="500" value="<?= $v('notes') ?>"></div>
    <div><label>&nbsp;</label>
      <label class="d-block"><input type="checkbox" name="cc_main" value="1"<?= ! empty($x['is_main']) ? ' checked' : '' ?>> <?= te('Main point of contact') ?></label>
      <label class="d-block"><input type="checkbox" name="cc_invoices" value="1"<?= ! empty($x['receives_invoices']) ? ' checked' : '' ?>> <?= te('Receives the invoices') ?></label></div>
    <div><button class="btn<?= $id ? '' : ' ghost' ?>"><?= $id ? te('Save the contact') : te('Add the contact') ?></button><?php if ($id): ?> <a class="btn ghost" href="/clients?id=<?= $clientId ?>#contacts"><?= te('Cancel') ?></a><?php endif; ?></div>
  </form>
<?php }; ?>
<div class="page-head"><div><h1><?= te('Clients') ?></h1>
  <p class="sub"><?= te('The companies the crews work for. Add a client first, then take on their projects; invoices use their terms and billing email.') ?></p></div>
  <div class="actions"><a class="btn" href="#new" data-new-client><?= te('+ Add a client') ?></a><a class="btn ghost" href="/projects"><?= te('Projects') ?></a><a class="btn ghost" href="/client-access"><?= te('Client access') ?></a></div></div>

<?php if ($open): ?>
<div class="card" id="client" data-client="<?= (int) $open['id'] ?>"><h2><?= e($open['name']) ?></h2>
  <?php $form($open, 'update', t('Save'), $invoiceTo); ?>
</div>

<div class="card" id="contacts"><h2><?= te('Contacts at :client', ['client' => $open['name']]) ?></h2>
  <p class="small muted"><?= te('Everyone you deal with at this client. One is the main point of contact; those ticked as receiving the invoices are where invoices go. Each project then chooses its contacts and the one in charge.') ?></p>
  <?php if (! $contacts): ?><p class="muted"><?= te('No contact yet. Add the first one below: they become the main point of contact.') ?></p><?php else: ?>
  <table><thead><tr><th><?= te('Name') ?></th><th><?= te('Title or role') ?></th><th><?= te('Email') ?></th><th><?= te('Phone') ?></th><th><?= te('Projects') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($contacts as $x): ?>
    <tr data-contact="<?= (int) $x['id'] ?>"><td><strong><?= e($x['name']) ?></strong>
        <?php if ($x['is_main']): ?><br><span class="tag green" data-main-contact><?= te('Main contact') ?></span><?php endif; ?>
        <?php if ($x['receives_invoices']): ?><br><span class="tag blue" data-invoice-contact><?= te('Receives the invoices') ?></span><?php endif; ?></td>
      <td><?= e($x['title'] ?? '') ?: '—' ?></td>
      <td><?= $x['email'] ? '<a href="mailto:' . e($x['email']) . '">' . e($x['email']) . '</a>' : '—' ?></td>
      <td><?= $x['phone'] ? '<a href="tel:' . e(preg_replace('/[^\d+]/', '', $x['phone'])) . '">' . e($x['phone']) . '</a>' : '—' ?></td>
      <td><?= (int) $x['on_projects'] ?></td>
      <td class="right nowrap"><a class="btn ghost sm" href="/clients?id=<?= (int) $open['id'] ?>&contact=<?= (int) $x['id'] ?>#edit-contact"><?= te('Edit') ?></a>
        <?php if (! $x['is_main']): ?><form method="post" action="/clients" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="contact_main"><input type="hidden" name="client_id" value="<?= (int) $open['id'] ?>"><input type="hidden" name="contact_id" value="<?= (int) $x['id'] ?>"><button class="btn ghost sm"><?= te('Make main') ?></button></form><?php endif; ?>
        <form method="post" action="/clients" class="inline" onsubmit="return confirm(this.dataset.ask)" data-ask="<?= te('Remove :name from this client and from its projects?', ['name' => $x['name']]) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="contact_remove"><input type="hidden" name="client_id" value="<?= (int) $open['id'] ?>"><input type="hidden" name="contact_id" value="<?= (int) $x['id'] ?>"><button class="btn ghost sm"><?= te('Remove') ?></button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
  <?php if ($editContact): ?><h3 class="mt"><?= te('Change :name', ['name' => $editContact['name']]) ?></h3><?php $contactForm((int) $open['id'], $editContact); ?><?php endif; ?>
  <?php // P5-M73: once the client has a contact, adding another is a button that opens the form (RSS: "it should go away after I added a contact"). ?>
  <details class="mt" id="add-contact"<?= $contacts ? '' : ' open' ?>><summary class="btn<?= $contacts ? ' ghost' : '' ?>" data-add-contact><?= te('+ Add a contact') ?></summary>
  <?php $contactForm((int) $open['id'], null); ?>
  </details>
</div>

<div class="card" id="client-projects"><h2><?= te('Projects') ?></h2>
  <?php if (! $openProjects): ?><p class="muted"><?= te('No project yet.') ?></p><?php endif; ?>
  <?php foreach ($openProjects as $j): ?><p class="small" data-client-project="<?= (int) $j['id'] ?>"><strong><?= e($j['title']) ?></strong> · <?= e($j['status']) ?><?= $j['site_city'] ? ' · ' . e($j['site_city'] . ', ' . $j['site_state']) : '' ?><?= $j['in_charge'] ? ' · ' . te('in charge: :name', ['name' => $j['in_charge']]) : '' ?></p><?php endforeach; ?>
  <p class="mt-s"><a class="btn" href="/projects?client_id=<?= (int) $open['id'] ?>#new-project"><?= te('Take on a project for this client') ?></a></p>
</div>
<?php endif; ?>


<div class="card"><h2><?= tn(count($clients), ':n client', ':n clients') ?></h2>
  <table><thead><tr><th><?= te('Client') ?></th><th><?= te('Main contact') ?></th><th><?= te('Terms') ?></th><th><?= te('Projects') ?></th><th><?= te('Portal accounts') ?></th></tr></thead><tbody>
  <?php foreach ($clients as $c): ?>
    <tr data-client-row="<?= (int) $c['id'] ?>"><td><a href="/clients?id=<?= (int) $c['id'] ?>#client"><?= e($c['name']) ?></a><?= $c['city'] ? '<br><span class="small muted">' . e($c['city'] . ', ' . $c['state']) . '</span>' : '' ?></td>
      <td class="small"><?= e(trim(($c['contact_name'] ?? '') . ' ' . ($c['contact_email'] ?? '') . ' ' . ($c['contact_phone'] ?? ''))) ?: '—' ?><?= (int) $c['contacts'] > 1 ? '<br><span class="muted">' . te('+:n more contacts', ['n' => (int) $c['contacts'] - 1]) . '</span>' : '' ?></td>
      <td><?= te(':n days', ['n' => (int) $c['payment_terms_days']]) ?></td>
      <td><?= (int) $c['projects'] ?><?= (int) $c['live'] ? ' · ' . te(':n live', ['n' => (int) $c['live']]) : '' ?></td>
      <td><?= (int) $c['portal_users'] ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
</div>

<?php // P5-M73: the clients first; adding one is a button that opens the form. ?>
<details class="card fold" id="new" data-fold-forget<?= ! $clients || $old ? ' open' : '' ?>><summary><h2><?= te('Add a client') ?></h2></summary>
  <?php $form($old, 'create', t('Add the client')); ?>
  <p class="small muted"><?= te('More contacts - the site manager, accounts payable - are added on the client once it is created.') ?></p>
</details>
<script src="/assets/fold.js"></script>
