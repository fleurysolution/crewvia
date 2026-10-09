<div class="page-heading">
  <div>
    <span class="eyebrow"><?= te('YOUR ACCOUNT') ?></span>
    <h1><?= te('Your crew file is not linked yet.') ?></h1>
    <p class="sub">
      <?= te('You can sign in, but this account is not attached to a crew record yet, so there is nothing to show you here. Recruiting links the two when your assignment is created.') ?>
    </p>
  </div>
</div>

<div class="card">
  <h2><?= te('What to do') ?></h2>
  <p><?= te('Tell recruiting the email address you signed in with, :email, and ask them to link it to your candidate record. It takes them a moment.', ['email' => (string) (user()['email'] ?? '')]) ?></p>
  <p class="hint"><?= te('Nothing is wrong with your password — this is not a sign-in problem.') ?></p>
  <p>
    <a class="btn" href="/comms"><?= te('Message recruiting') ?></a>
    <a class="btn ghost" href="/account"><?= te('Account') ?></a>
  </p>
</div>
