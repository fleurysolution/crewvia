<div class="page-head"><div><h1><?= te('Subscription &amp; workspace') ?></h1>
<p class="sub"><?= te('One company workspace, multiple operational projects. Employee email addresses remain personal identities.') ?></p></div></div>
<?php if($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
<div class="grid g2"><section class="card"><h2><?= te('Commercial configuration') ?></h2>
<p>Workspace: <?= e($config['tenant_id'] ?? 'Dedicated installation') ?></p>
<p>Model: <?= e($config['commercial_mode'] ?? 'demo') ?></p>
<p>Stripe: <?= saas_configured()?'Configured · customer acceptance still required':'Not configured' ?></p>
<p>Subscription: <?= e($subscription['status']) ?> · <?= (int)$subscription['quantity'] ?> contracted seats</p>
<p class="muted"><?= te('Checkout return does not confirm payment. Signed webhooks synchronize the verified subscription.') ?></p>
<?php if($notice): ?><p class="flash ok" role="status"><?= e($notice) ?></p><?php endif; ?>
<?php if(saas_configured() && $subscription['stripe_subscription_id']): ?>
<?php /* P5-M114: what will happen at the end of the period, and the way to change it, on the customer's own screen. */ ?>
<?php $ends=$subscription['period_end']?saas_date_in(strtotime($subscription['period_end'].' UTC'),locale()):'—'; $amount=$price?saas_money_in((int)$subscription['quantity']*$price['unit_amount'],$price['currency'],locale()):null; ?>
<?php if((int)$subscription['cancel_at_period_end']===1): ?>
<p data-renewal="cancelled"><strong><?= te('Renewal cancelled.') ?></strong> <?= te('Access continues until :date. No further charge is made.',['date'=>$ends]) ?></p>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="keep_renewal"><button class="btn"><?= te('Keep my subscription') ?></button></form>
<?php elseif($subscription['status']==='canceled'): ?>
<p data-renewal="ended"><?= te('This subscription has ended.') ?></p>
<?php else: ?>
<p data-renewal="on"><?= $amount?te('Renews automatically on :date at :amount.',['date'=>$ends,'amount'=>$amount]):te('Renews automatically on :date.',['date'=>$ends]) ?></p>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="cancel_renewal"><button class="btn ghost"><?= te('Cancel the renewal') ?></button>
<p class="small muted"><?= te('You keep access until the end of the period already paid for, and no further charge is made.') ?></p></form>
<?php endif; ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="portal"><button class="btn ghost sm"><?= te('Payment method and invoices (Stripe)') ?></button></form>
<?php elseif(saas_configured() && $review): ?>
<?php /* P5-M114: the approved renewal terms, then an unticked box the customer must tick, before Stripe. */ ?>
<form method="post" data-renewal-terms><?= csrf_field() ?><input type="hidden" name="do" value="checkout"><input type="hidden" name="quantity" value="<?= (int)$review['quantity'] ?>"><input type="hidden" name="terms_hash" value="<?= e($review['hash']) ?>">
<p><?= te(':n contracted seats',['n'=>(int)$review['quantity']]) ?></p>
<p class="renewal-terms" data-terms><?= e($review['terms']) ?></p>
<label class="check"><input type="checkbox" name="consent" value="1" required> <?= e($review['consent']) ?></label>
<button class="btn"><?= te('Continue to Stripe Checkout') ?></button></form>
<form method="post"><?= csrf_field() ?><button class="link small"><?= te('Change the number of seats') ?></button></form>
<?php elseif(saas_configured()): ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="review">
<label><?= te('Contracted employee seats · confirm your agreed plan') ?></label><input name="quantity" type="number" min="1" max="100000" value="<?= max(1,$usage['count']) ?>" required>
<button class="btn"><?= te('Review the terms') ?></button></form>
<?php endif; ?></section>
<section class="card"><h2><?= te('Monthly usage review') ?></h2><form method="get"><label><?= te('Month') ?></label><input type="month" name="month" value="<?= e($month) ?>"><button class="btn ghost"><?= te('Review usage') ?></button></form>
<p><strong><?= (int)$usage['count'] ?></strong> <?= te('distinct employees with on-site/completed assignment dates overlapping the month.') ?></p>
<p class="muted"><?= te('A worker on several projects counts once. This is assignment-based usage, not attendance-derived daily metering. Pending candidates are excluded.') ?></p>
<p class="muted"><?= te('Stripe currently bills contracted seats. This review does not automatically modify a subscription or charge a customer.') ?></p>
<?php if($usage['month']<date('Y-m-01')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="snapshot"><input type="hidden" name="month" value="<?= e($month) ?>"><button class="btn"><?= te('Freeze closed-month usage') ?></button></form><?php endif; ?></section></div>
<section class="card"><h2><?= te('Frozen usage history') ?></h2><?php foreach($snapshots as $snapshot): ?><p><?= e($snapshot['period_month']) ?> · <?= (int)$snapshot['unique_workers'] ?> employees · <?= e($snapshot['created_at']) ?></p><?php endforeach; ?></section>
<?php if($invoices): ?><section class="card"><h2><?= te('Payments') ?></h2><?php foreach($invoices as $invoice): ?><p data-invoice="<?= e($invoice['outcome']) ?>"><?= e(d($invoice['recorded_at'])) ?> · <?= e(saas_money_in((int)$invoice['amount_cents'],$invoice['currency'],locale())) ?> · <?= (int)$invoice['quantity'] ?> <?= te('seats') ?> · <?= $invoice['outcome']==='paid'?te('Paid'):te('Payment failed') ?></p><?php endforeach; ?></section><?php endif; ?>
