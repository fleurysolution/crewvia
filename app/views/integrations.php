<?php
$names = ['gmail' => 'Gmail', 'docusign' => 'DocuSign', 'stripe' => 'Stripe', 'adp' => 'ADP', 'ats' => t('Applicant tracking'), 'twilio' => 'Twilio'];
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

<div class="card" id="twilio" data-configured="<?= $twilio['configured'] ? 1 : 0 ?>" data-mode="<?= e($twilio['mode']) ?>">
  <h2>Twilio <span class="tag <?= $twilio['mode'] === 'live' ? 'green' : ($twilio['mode'] === 'sandbox' ? 'amber' : 'grey') ?>"><?= te(ucfirst($twilio['mode'])) ?></span></h2>
  <p class="muted"><?= te('Texts and calls from Crewvia itself, through the agency\'s Twilio account. Until it is live, nothing leaves Crewvia: texts and calls are recorded as sandbox.') ?></p>
  <p class="small"><?= $twilio['configured'] ? te('Credentials entered: account ending :end, sending from :from.', ['end' => (string) $twilio['sid_end'], 'from' => $twilio['service'] ? t('a messaging service') . ($twilio['from'] ? ' / ' . $twilio['from'] : '') : (string) $twilio['from']]) : te('No credentials entered yet.') ?></p>
  <form method="post" class="grid g2" id="twilio-form"><?= csrf_field() ?><input type="hidden" name="do" value="twilio_save">
    <label><?= te('Account SID') ?><input name="account_sid" required pattern="AC[0-9a-f]{32}" autocomplete="off"></label>
    <label><?= te('Auth token (left empty: keep the one saved)') ?><input name="auth_token" type="password" autocomplete="new-password"></label>
    <label><?= te('Twilio number') ?><input name="from_number" placeholder="+15551234567"></label>
    <label><?= te('Messaging service SID (optional)') ?><input name="messaging_service_sid" placeholder="MG…"></label>
    <button class="btn sm"><?= te('Save the credentials') ?></button></form>
  <?php if ($twilio['configured']): ?><form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="do" value="twilio_check"><button class="btn sm ghost"><?= te('Check the account (the sandbox trial)') ?></button></form><?php endif; ?>
  <p class="small muted"><?= te('In Twilio, set the number\'s incoming messages to :inbound and the status callback is sent with each text. Texting US numbers from a business also needs A2P 10DLC registration (brand and campaign) in Twilio.', ['inbound' => $twilio['inbound_url']]) ?></p>
  <?php if ($twilio['recent']): ?><table id="twilio-recent"><?php foreach ($twilio['recent'] as $m): ?><tr data-sms="<?= (int) $m['id'] ?>" data-status="<?= e($m['status']) ?>"><td class="small"><?= e(substr((string) $m['created_at'], 0, 16)) ?></td><td class="small"><?= $m['direction'] === 'in' ? '←' : '→' ?> <?= e((string) ($m['full_name'] ?? $m['phone'])) ?></td><td class="small"><?= e($m['mode']) ?> · <?= e($m['status']) ?><?= $m['error_code'] ? ' · ' . e($m['error_code']) : '' ?></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>

<div class="card scroll" id="connectors"><h2><?= te('Payroll, applicant tracking and screening connectors') ?></h2>
  <p class="muted"><?= te('Each connector runs in sandbox before it goes live: test files and test events, nothing made real. Live needs a sandbox trial that passed and the reference of who at RSS authorized it.') ?></p>
  <table><tr><th><?= te('Connector') ?></th><th><?= te('Mode') ?></th><th><?= te('Sandbox trial') ?></th><th><?= te('Authorized') ?></th><th></th></tr>
  <?php foreach ($connectors as $k => $c): ?><tr data-connector="<?= e($k) ?>" data-mode="<?= e($c['mode']) ?>" data-sandbox="<?= $c['sandbox_passed_at'] ? '1' : '0' ?>">
    <td><?= te($c['name']) ?><?= $c['configured'] ? '' : ' <span class="tag grey">' . te('not configured on the server') . '</span>' ?><?= $c['note'] ? '<div class="small muted">' . e($c['note']) . '</div>' : '' ?></td>
    <td><span class="tag <?= $c['mode'] === 'live' ? 'green' : ($c['mode'] === 'sandbox' ? 'amber' : 'grey') ?>"><?= te(ucfirst($c['mode'])) ?></span></td>
    <td class="small"><?= $c['sandbox_passed_at'] ? e($c['sandbox_passed_at'] . ' · ' . (string) $c['sandbox_note']) : te('not yet') ?></td>
    <td class="small"><?= $c['authorization_ref'] ? e($c['authorization_ref'] . ' · ' . (string) $c['authorized_name'] . ' · ' . (string) $c['authorized_at']) : '–' ?></td>
    <td><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="mode"><input type="hidden" name="connector" value="<?= e($k) ?>">
      <select name="mode"><?php foreach (['disabled', 'sandbox', 'live'] as $mo): ?><option value="<?= $mo ?>"<?= $c['mode'] === $mo ? ' selected' : '' ?>><?= te(ucfirst($mo)) ?></option><?php endforeach; ?></select>
      <input name="authorization" maxlength="190" placeholder="<?= te('Authorized by (for live)') ?>"><input name="note" maxlength="500" placeholder="<?= te('Note') ?>"><button class="btn sm ghost"><?= te('Save') ?></button></form></td></tr>
  <?php endforeach; ?>
    <tr data-connector="screening"><td><?= te('Background screening') ?></td><td><span class="tag grey"><?= te('Not connected') ?></span></td>
      <td colspan="3" class="small muted"><?= te('No screening provider is authorized. Cases are run by hand on Checks; a provider is connected only once RSS chooses and authorizes one.') ?></td></tr>
  </table>
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
