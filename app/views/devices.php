<?php
$dvState = ['active' => t('Trusted'), 'expired' => t('Expired'), 'ended' => t('Removed')];
$dvTone = ['active' => 'green', 'expired' => 'grey', 'ended' => 'grey'];
$here = $current && (int) $current['user_id'] === uid();
?>
<div class="page-head"><div><h1><?= te('Trusted phones') ?></h1>
<p class="sub"><?= te('A trusted phone keeps your hours (and, for a supervisor, gate marks) when there is no signal, and sends them when there is. It opens nothing else: no page, record, document or pay. It stays trusted for :n days, until you sign out on it, change your password, or remove it here.', ['n' => os_trust_days()]) ?></p></div></div>

<div class="card" id="this-phone" data-trusted="<?= $here ? 1 : 0 ?>">
  <h2><?= te('This phone') ?></h2>
  <?php if ($here): ?>
    <p><?= te('Trusted until :date.', ['date' => d(substr((string) $current['expires_at'], 0, 10))]) ?></p>
    <p><a class="btn" href="/offline-entry.html"><?= te('Attendance without signal') ?></a></p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="forget"><button class="btn ghost sm"><?= te('Stop trusting this phone') ?></button></form>
  <?php elseif ($mayTrust): ?>
    <p><?= te('Only on your own phone. Anyone holding it can send hours in your name until it is removed.') ?></p>
    <form method="post" id="trust-form"><?= csrf_field() ?><input type="hidden" name="do" value="trust"><button class="btn"><?= te('Trust this phone') ?></button></form>
  <?php else: ?>
    <p class="muted"><?= te('Offline attendance is for workers and supervisors.') ?></p>
  <?php endif; ?>
</div>

<div class="card scroll" id="my-phones" data-count="<?= count($mine) ?>"><h2><?= te('My trusted phones') ?></h2>
  <?php if (! $mine): ?><p class="muted"><?= te('None.') ?></p><?php else: ?><table>
  <tr><th><?= te('Phone') ?></th><th><?= te('Trusted on') ?></th><th><?= te('Last used') ?></th><th><?= te('Until') ?></th><th><?= te('State') ?></th><th></th></tr>
  <?php foreach ($mine as $g): $st = os_grant_state($g); ?><tr data-grant="<?= (int) $g['id'] ?>" data-state="<?= e($st) ?>">
    <td class="small wrap-any"><?= e($g['label']) ?></td><td class="small"><?= e(d(substr((string) $g['created_at'], 0, 10))) ?></td>
    <td class="small"><?= $g['last_used_at'] ? e(d(substr((string) $g['last_used_at'], 0, 10))) : '' ?></td><td class="small"><?= e(d(substr((string) $g['expires_at'], 0, 10))) ?></td>
    <td><span class="tag <?= $dvTone[$st] ?>"><?= e($dvState[$st]) ?></span></td>
    <td><?php if ($st === 'active'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="revoke"><input type="hidden" name="grant_id" value="<?= (int) $g['id'] ?>"><button class="btn sm ghost"><?= te('Remove') ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>

<div class="card" id="push" data-on="<?= $push['on'] ? 1 : 0 ?>">
  <h2><?= te('Push notifications') ?></h2>
  <p class="small muted"><?= te('A push says only that there is something new in Crewvia; what it is shows once you open it. Google, Apple, Mozilla and Microsoft, who carry it, see no name and no message.') ?></p>
  <?php if (! $push['on']): ?>
    <p id="push-off"><?= te('Push notifications are off. Nothing is sent.') ?></p>
  <?php elseif ($push['here']): ?>
    <p id="push-here"><?= te('On for this phone, for: :kinds.', ['kinds' => implode(', ', array_map(fn($k) => push_kinds()[$k] ?? $k, explode(',', (string) $push['here']['kinds'])))]) ?></p>
    <div class="row"><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="push_test"><button class="btn sm"><?= te('Send me a test') ?></button></form>
      <form method="post" id="push-off-form"><?= csrf_field() ?><input type="hidden" name="do" value="push_unsubscribe"><button class="btn sm ghost"><?= te('Turn off for this phone') ?></button></form></div>
  <?php else: ?>
    <form method="post" id="push-form" data-key="<?= e((string) $push['key']) ?>" data-title="Crewvia" data-body="<?= te('New in Crewvia. Open it to read.') ?>"
          data-unsupported="<?= te('This browser cannot receive push notifications. On an iPhone, add Crewvia to the Home Screen first (iOS 16.4 or later).') ?>"
          data-denied="<?= te('Notifications are blocked for Crewvia in the settings of this browser.') ?>">
      <?= csrf_field() ?><input type="hidden" name="do" value="push_subscribe"><input type="hidden" name="endpoint" value="">
      <p><?= te('Send to this phone when there is something new about:') ?></p>
      <?php foreach (push_kinds() as $k => $l): ?><label style="display:block"><input type="checkbox" name="kinds[]" value="<?= e($k) ?>" checked> <?= e($l) ?></label><?php endforeach; ?>
      <button type="button" class="btn" id="push-on"><?= te('Turn on for this phone') ?></button> <span class="small" id="push-status" role="status"></span>
    </form>
  <?php endif; ?>
  <?php $live = array_filter($push['mine'], fn($s) => $s['ended_at'] === null); if ($live): ?>
  <table id="my-push"><?php foreach ($live as $s): ?><tr data-subscription="<?= (int) $s['id'] ?>"><td class="small wrap-any"><?= e($s['label']) ?></td>
    <td class="small"><?= $s['last_sent_at'] ? e(d(substr((string) $s['last_sent_at'], 0, 10))) : '' ?></td>
    <td><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="push_remove"><input type="hidden" name="subscription_id" value="<?= (int) $s['id'] ?>"><button class="btn sm ghost"><?= te('Remove') ?></button></form></td></tr><?php endforeach; ?></table>
  <?php endif; ?>
  <?php if ($admin): ?>
  <form class="mt" method="post" id="push-switch"><?= csrf_field() ?><input type="hidden" name="do" value="push_switch"><input type="hidden" name="state" value="<?= $push['on'] ? 'off' : 'on' ?>">
    <?php if (! $push['key']): ?><p class="small" id="push-no-key"><?= te('The key pair is not made yet. On the server: php install/push-keys.php') ?></p><?php endif; ?>
    <p class="small muted"><?= te('Turning push on lets Crewvia call the push services of Google, Apple, Mozilla and Microsoft. Pushes go out each time the scheduled workflows run, or every minute with php install/push-dispatch.php in cron.') ?></p>
    <button class="btn sm<?= $push['on'] ? ' ghost' : '' ?>"><?= $push['on'] ? te('Turn push notifications off') : te('Turn push notifications on') ?></button></form>
  <?php if ($push['all']): ?><table id="all-push"><?php foreach ($push['all'] as $s): ?><tr data-any-subscription="<?= (int) $s['id'] ?>"><td><?= e($s['name']) ?></td><td class="small wrap-any"><?= e($s['label']) ?></td>
    <td><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="push_remove"><input type="hidden" name="subscription_id" value="<?= (int) $s['id'] ?>"><button class="btn sm ghost"><?= te('Remove') ?></button></form></td></tr><?php endforeach; ?></table><?php endif; ?>
  <?php endif; ?>
</div>
<?php if ($push['on'] && ! $push['here']): ?><script src="/assets/offline-sync.js?v=2"></script><script src="/assets/push.js?v=1"></script><?php endif; ?>

<?php if ($admin): ?>
<div class="card scroll" id="all-phones" data-count="<?= count($everyone) ?>"><h2><?= te('Every trusted phone') ?></h2>
  <?php if (! $everyone): ?><p class="muted"><?= te('None.') ?></p><?php else: ?><table>
  <?php foreach ($everyone as $g): ?><tr data-any-grant="<?= (int) $g['id'] ?>"><td><?= e($g['name']) ?></td><td class="small wrap-any"><?= e($g['label']) ?></td>
    <td class="small"><?= $g['last_used_at'] ? e(d(substr((string) $g['last_used_at'], 0, 10))) : '' ?></td><td class="small"><?= e(d(substr((string) $g['expires_at'], 0, 10))) ?></td>
    <td><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="revoke"><input type="hidden" name="grant_id" value="<?= (int) $g['id'] ?>"><button class="btn sm ghost"><?= te('Remove') ?></button></form></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>
<?php endif; ?>
<?php if ($here): ?><script src="/assets/offline-sync.js?v=2"></script><script>CrewviaSync.refresh().catch(function(){});</script><?php endif; ?>
