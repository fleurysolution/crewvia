<?php
$names = ['gmail' => 'Gmail', 'docusign' => 'DocuSign', 'stripe' => 'Stripe'];
$tones = ['ok' => 'green', 'duplicate' => 'blue', 'ignored' => 'grey', 'retry' => 'amber', 'failed' => 'red', 'refused' => 'red'];
?>
<h1><?= te('Integrations') ?></h1>
<p class="sub"><?= te('Gmail, DocuSign and Stripe: whether each is on, what waits, what failed, and every outcome kept. Settings and tokens are never shown here.') ?></p>

<div class="grid g2" id="integration-status">
  <?php foreach ($status as $k => $s): ?>
  <div class="card" data-provider="<?= e($k) ?>" data-enabled="<?= $s['enabled'] ? '1' : '0' ?>"><h2><?= e($names[$k]) ?> <span class="tag <?= $s['enabled'] ? 'green' : 'grey' ?>"><?= $s['enabled'] ? te('On') : te('Off') ?></span></h2>
    <p class="muted"><?= te($s['what']) ?></p>
    <?php if ($k === 'docusign'): ?><p class="small"><?= te('Authentication: :how', ['how' => t($s['auth'])]) ?></p><?php endif; ?>
    <?php if ($k === 'gmail'): ?><p class="small" id="gmail-counts" data-total="<?= (int) $gmail['total'] ?>" data-reconnect="<?= (int) $gmail['reconnect'] ?>"><?= te(':n connected mailbox(es), :r to reconnect', ['n' => (int) $gmail['total'], 'r' => (int) $gmail['reconnect']]) ?></p><?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($reconnect): ?>
<div class="card scroll" id="gmail-reconnect"><h2><?= te('Gmail connections to reconnect') ?></h2>
  <p class="muted"><?= te('Google no longer accepts these. Each person reconnects from their inbox; nothing is sent or read until then.') ?></p>
  <table><?php foreach ($reconnect as $r): ?><tr data-user="<?= (int) $r['user_id'] ?>"><td><?= e($r['name']) ?></td><td class="small"><?= e((string) $r['last_error']) ?></td><td class="small muted"><?= e((string) $r['updated_at']) ?></td></tr><?php endforeach; ?></table>
</div>
<?php endif; ?>

<div class="card scroll" id="connect"><h2><?= te('DocuSign Connect queue') ?></h2>
  <p class="muted"><?= te('Each notification is checked against DocuSign itself. A failure waits 2, 4, 8 and 16 minutes; after five attempts it is marked failed for you to requeue.') ?></p>
  <?php if (! $connect): ?><p class="muted"><?= te('Nothing queued.') ?></p><?php else: ?>
  <table><tr><th><?= te('Envelope') ?></th><th><?= te('State') ?></th><th class="num"><?= te('Attempts') ?></th><th><?= te('Next attempt') ?></th><th><?= te('Last error') ?></th><th></th></tr>
  <?php foreach ($connect as $c): ?><tr data-connect="<?= e($c['payload_hash']) ?>" data-status="<?= e($c['status']) ?>"><td class="mono small"><?= e($c['envelope_id']) ?></td>
    <td><span class="tag <?= $c['status'] === 'failed' ? 'red' : ($c['status'] === 'pending' ? 'amber' : 'green') ?>"><?= te(ucfirst($c['status'])) ?></span></td><td class="num"><?= (int) $c['attempts'] ?></td>
    <td class="small"><?= e((string) ($c['next_attempt_at'] ?? '')) ?></td><td class="small"><?= e((string) ($c['last_error'] ?? '')) ?></td>
    <td><?php if ($c['status'] === 'failed'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="requeue"><input type="hidden" name="event" value="<?= e($c['payload_hash']) ?>"><button class="btn sm ghost"><?= te('Requeue') ?></button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></table><?php endif; ?>
</div>

<div class="card scroll" id="stripe-events"><h2><?= te('Stripe events processed') ?></h2>
  <?php if (! $stripe): ?><p class="muted"><?= te('None received.') ?></p><?php else: ?>
  <table><?php foreach ($stripe as $s): ?><tr><td class="mono small"><?= e($s['event_id']) ?></td><td><?= e($s['event_type']) ?></td><td class="small muted"><?= e((string) $s['processed_at']) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>

<div class="card scroll" id="audit"><h2><?= te('Audit trail') ?></h2>
  <form method="get" class="row"><select name="provider"><option value=""><?= te('Every provider') ?></option><?php foreach ($names as $k => $n): ?><option value="<?= e($k) ?>"<?= $provider === $k ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
    <select name="outcome"><option value=""><?= te('Every outcome') ?></option><?php foreach ($tones as $k => $tone): ?><option value="<?= e($k) ?>"<?= $outcome === $k ? ' selected' : '' ?>><?= te(ucfirst($k)) ?></option><?php endforeach; ?></select>
    <button class="btn ghost"><?= te('Show') ?></button></form>
  <?php if (! $events): ?><p class="muted"><?= te('Nothing recorded yet.') ?></p><?php else: ?>
  <table><tr><th><?= te('When') ?></th><th><?= te('Provider') ?></th><th><?= te('What') ?></th><th><?= te('Outcome') ?></th><th><?= te('Reference') ?></th><th><?= te('Detail') ?></th><th><?= te('By') ?></th></tr>
  <?php foreach ($events as $ev): ?><tr data-event="<?= (int) $ev['id'] ?>" data-provider="<?= e($ev['provider']) ?>" data-outcome="<?= e($ev['outcome']) ?>">
    <td class="small"><?= e((string) $ev['created_at']) ?></td><td><?= e($names[$ev['provider']]) ?></td><td><?= e($ev['action']) ?></td>
    <td><span class="tag <?= $tones[$ev['outcome']] ?>"><?= te(ucfirst($ev['outcome'])) ?></span></td><td class="mono small"><?= e((string) $ev['reference']) ?></td>
    <td class="small"><?= e((string) $ev['detail']) ?></td><td class="small"><?= e((string) ($ev['by_name'] ?? '')) ?></td></tr>
  <?php endforeach; ?></table><?php endif; ?>
</div>
