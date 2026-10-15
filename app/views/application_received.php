<?php
/**
 * After a public application (P5-M45): what happens next, and the person's
 * portal offered there and then, so everything that follows reaches them
 * there rather than by phone.
 */
$portal = $portal ?? ['kind' => 'none', 'url' => null];
?>
<div class="card" id="application-received">
  <h1><?= te('Application received') ?></h1>
  <p><?= te('RSS recruiting will review your application and contact you about next steps.') ?></p>
</div>
<?php if ($portal['kind'] === 'activate'): ?>
<div class="card" id="portal-offer">
  <h2><?= te('Create your candidate portal now') ?></h2>
  <p><?= te('Follow your application, say when you are available, see your interviews and, if you are hired, your offer, your papers and your pay: everything comes to your portal, so nobody needs to call you to ask.') ?></p>
  <p><a class="btn" href="<?= e($portal['url']) ?>"><?= te('Create my portal') ?></a></p>
  <p class="small muted m0"><?= te('Only you can use this link; it works for 7 days. You choose your password on the next page.') ?></p>
</div>
<?php elseif ($portal['kind'] === 'signin'): ?>
<div class="card" id="portal-offer">
  <h2><?= te('You already have a portal') ?></h2>
  <p><?= te('Sign in to follow this application and to say when you are available.') ?></p>
  <p><a class="btn" href="/login"><?= te('Sign in') ?></a></p>
</div>
<?php elseif ($portal['kind'] === 'portal'): ?>
<div class="card" id="portal-offer"><p class="m0"><a class="btn" href="/my-jobs"><?= te('Back to my portal') ?></a></p></div>
<?php endif; ?>
