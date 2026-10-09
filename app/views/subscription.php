<h1><?= te('Subscription &amp; workspace') ?></h1>
<p class="sub"><?= te('One company workspace, multiple operational projects. Employee email addresses remain personal identities.') ?></p>
<?php if($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
<div class="grid g2"><section class="card"><h2><?= te('Commercial configuration') ?></h2>
<p>Workspace: <?= e($config['tenant_id'] ?? 'Dedicated installation') ?></p>
<p>Model: <?= e($config['commercial_mode'] ?? 'demo') ?></p>
<p>Stripe: <?= saas_configured()?'Configured · customer acceptance still required':'Not configured' ?></p>
<p>Subscription: <?= e($subscription['status']) ?> · <?= (int)$subscription['quantity'] ?> contracted seats</p>
<p class="muted"><?= te('Checkout return does not confirm payment. Signed webhooks synchronize the verified subscription.') ?></p>
<?php if(saas_configured()): ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="<?= $subscription['stripe_subscription_id']?'portal':'checkout' ?>">
<?php if(!$subscription['stripe_subscription_id']): ?><label><?= te('Contracted employee seats · confirm your agreed plan') ?></label><input name="quantity" type="number" min="1" max="100000" value="<?= max(1,$usage['count']) ?>" required><?php endif; ?>
<button class="btn"><?= $subscription['stripe_subscription_id']?'Manage billing with Stripe':'Continue to Stripe Checkout' ?></button></form>
<?php endif; ?></section>
<section class="card"><h2><?= te('Monthly usage review') ?></h2><form method="get"><label><?= te('Month') ?></label><input type="month" name="month" value="<?= e($month) ?>"><button class="btn ghost"><?= te('Review usage') ?></button></form>
<p><strong><?= (int)$usage['count'] ?></strong> <?= te('distinct employees with on-site/completed assignment dates overlapping the month.') ?></p>
<p class="muted"><?= te('A worker on several projects counts once. This is assignment-based usage, not attendance-derived daily metering. Pending candidates are excluded.') ?></p>
<p class="muted"><?= te('Stripe currently bills contracted seats. This review does not automatically modify a subscription or charge a customer.') ?></p>
<?php if($usage['month']<date('Y-m-01')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="snapshot"><input type="hidden" name="month" value="<?= e($month) ?>"><button class="btn"><?= te('Freeze closed-month usage') ?></button></form><?php endif; ?></section></div>
<section class="card"><h2><?= te('Frozen usage history') ?></h2><?php foreach($snapshots as $snapshot): ?><p><?= e($snapshot['period_month']) ?> · <?= (int)$snapshot['unique_workers'] ?> employees · <?= e($snapshot['created_at']) ?></p><?php endforeach; ?></section>
