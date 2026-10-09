<h1><?= te('My account') ?></h1>
<p class="sub"><?= e($me['name'] ?? '') ?> &middot; <?= e(roles()[$me['role'] ?? ''] ?? '') ?>
  &middot; <?= e($me['email'] ?? '') ?></p>

<?php if ((int) ($me['must_change_pw'] ?? 0) === 1): ?>
  <div class="flash err"><?= te('You are still on the temporary password you were given. Change it now.') ?></div>
<?php endif; ?>

<div class="card" style="max-width:420px">
  <h2><?= te('Change password') ?></h2>
  <form method="post" action="/account">
    <?= csrf_field() ?>
    <div class="field"><label><?= te('Current password') ?></label>
      <input type="password" name="current" required autocomplete="current-password"></div>
    <div class="field"><label><?= te('New password') ?></label>
      <input type="password" name="new" required minlength="10" autocomplete="new-password"></div>
    <div class="field"><label><?= te('New password again') ?></label>
      <input type="password" name="repeat" required minlength="10" autocomplete="new-password"></div>
    <button class="btn" type="submit"><?= te('Change it') ?></button>
    <p class="small muted" style="margin:10px 0 0"><?= te('At least 10 characters. A short phrase you will remember beats a short password you will not.') ?></p>
  </form>
</div>

<p><a class="btn ghost" href="/account-security"><?= te('Account security') ?></a></p>
