<?php
/**
 * A candidate's portal activation (P5-M48): a page of its own, never inside
 * an office desk. If somebody else is signed in on this browser, the page
 * says so: activating signs them out and the candidate into their portal.
 */
$agency = agency_name();
?>
<form class="card" method="post" id="activate-portal"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
  <h1><?= te('Welcome, :name', ['name' => $inv['full_name']]) ?></h1>
  <p><?= te('Create your candidate portal with :agency: follow your application, say when you are available, see your interviews and, once hired, your offer, your papers and your pay.', ['agency' => $agency]) ?></p>
  <?php if (! empty($signedInAs)): ?>
    <p class="t-amber" id="signed-in-warning"><?= te('You are signed in as :name on this browser. Activating this portal signs you out of that account.', ['name' => $signedInAs]) ?></p>
  <?php endif; ?>
  <label for="act-password"><?= te('Password · at least 12 characters') ?></label>
  <input id="act-password" type="password" name="password" minlength="12" required autocomplete="new-password">
  <button class="btn mt"><?= te('Activate my portal') ?></button>
</form>
