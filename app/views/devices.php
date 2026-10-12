<?php
$dvState = ['active' => t('Trusted'), 'expired' => t('Expired'), 'ended' => t('Removed')];
$dvTone = ['active' => 'green', 'expired' => 'grey', 'ended' => 'grey'];
$here = $current && (int) $current['user_id'] === uid();
?>
<h1><?= te('Trusted phones') ?></h1>
<p class="sub"><?= te('A trusted phone keeps your hours (and, for a supervisor, gate marks) when there is no signal, and sends them when there is. It opens nothing else: no page, record, document or pay. It stays trusted for :n days, until you sign out on it, change your password, or remove it here.', ['n' => os_trust_days()]) ?></p>

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
    <td class="small" style="overflow-wrap:anywhere"><?= e($g['label']) ?></td><td class="small"><?= e(d(substr((string) $g['created_at'], 0, 10))) ?></td>
    <td class="small"><?= $g['last_used_at'] ? e(d(substr((string) $g['last_used_at'], 0, 10))) : '' ?></td><td class="small"><?= e(d(substr((string) $g['expires_at'], 0, 10))) ?></td>
    <td><span class="tag <?= $dvTone[$st] ?>"><?= e($dvState[$st]) ?></span></td>
    <td><?php if ($st === 'active'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="revoke"><input type="hidden" name="grant_id" value="<?= (int) $g['id'] ?>"><button class="btn sm ghost"><?= te('Remove') ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>

<?php if ($admin): ?>
<div class="card scroll" id="all-phones" data-count="<?= count($everyone) ?>"><h2><?= te('Every trusted phone') ?></h2>
  <?php if (! $everyone): ?><p class="muted"><?= te('None.') ?></p><?php else: ?><table>
  <?php foreach ($everyone as $g): ?><tr data-any-grant="<?= (int) $g['id'] ?>"><td><?= e($g['name']) ?></td><td class="small" style="overflow-wrap:anywhere"><?= e($g['label']) ?></td>
    <td class="small"><?= $g['last_used_at'] ? e(d(substr((string) $g['last_used_at'], 0, 10))) : '' ?></td><td class="small"><?= e(d(substr((string) $g['expires_at'], 0, 10))) ?></td>
    <td><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="revoke"><input type="hidden" name="grant_id" value="<?= (int) $g['id'] ?>"><button class="btn sm ghost"><?= te('Remove') ?></button></form></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>
<?php endif; ?>
<?php if ($here): ?><script src="/assets/offline-sync.js?v=1"></script><script>CrewviaSync.refresh().catch(function(){});</script><?php endif; ?>
