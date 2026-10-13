<?php $healthTone = ['ok' => 'green', 'warn' => 'amber', 'fail' => 'red']; $healthLabel = ['ok' => t('OK'), 'warn' => t('Look at it'), 'fail' => t('Failing')]; ?>
<div class="page-head"><div><h1><?= te('System health') ?></h1>
<p class="sub"><?= te('The checks cron runs every hour, the backups, the scheduled jobs and the errors people met. A failing line here is also mailed by cron, if the health check is scheduled.') ?></p></div></div>
<div class="card" id="health-checks">
  <h2><?= te('Checks') ?></h2>
  <table><tr><th><?= te('Check') ?></th><th><?= te('State') ?></th><th><?= te('Found') ?></th></tr>
  <?php foreach ($checks as $name => [$status, $found]): ?><tr data-check="<?= e($name) ?>" data-status="<?= e($status) ?>"><td><?= e(str_replace('_', ' ', $name)) ?></td><td><span class="tag <?= $healthTone[$status] ?>"><?= e($healthLabel[$status]) ?></span></td><td class="small"><?= e($found) ?></td></tr><?php endforeach; ?>
  </table>
</div>
<div class="card" id="health-backups">
  <h2><?= te('Backups') ?></h2>
  <p class="small muted"><?= $backupDir ? te('Kept in :dir on this server. A copy must also leave the server: a backup on the same machine does not survive losing the machine.', ['dir' => $backupDir]) : te('No backup folder is configured.') ?></p>
  <?php if (! $backups): ?><p class="muted"><?= te('No backup found.') ?></p><?php else: ?>
  <table><tr><th><?= te('Taken') ?></th><th><?= te('Database') ?></th><th><?= te('Documents') ?></th><th><?= te('Tables') ?></th><th><?= te('Code') ?></th></tr>
  <?php foreach ($backups as $b): $m = $b['manifest']; ?><tr data-backup="<?= e(basename($b['path'])) ?>"><td><?= e((string) $m['created_at']) ?></td>
    <td class="small"><?= e(number_format(((int) ($m['files']['database.sql.gz']['bytes'] ?? 0)) / 1048576, 1)) ?> MB</td>
    <td class="small"><?= e(number_format(((int) ($m['files']['storage.tar.gz']['bytes'] ?? 0)) / 1048576, 1)) ?> MB · <?= (int) ($m['storage_files'] ?? 0) ?> <?= te('files') ?></td>
    <td class="small"><?= count($m['tables'] ?? []) ?></td><td class="small mono"><?= e((string) ($m['commit'] ?? '')) ?></td></tr><?php endforeach; ?>
  </table><?php endif; ?>
</div>
<div class="card" id="health-runs">
  <h2><?= te('Backups, drills and scheduled jobs') ?></h2>
  <?php if (! $runs): ?><p class="muted"><?= te('Nothing recorded yet.') ?></p><?php else: ?>
  <table><tr><th><?= te('Finished') ?></th><th><?= te('What') ?></th><th><?= te('State') ?></th><th><?= te('Seconds') ?></th><th><?= te('Detail') ?></th></tr>
  <?php foreach ($runs as $r): ?><tr data-run="<?= e($r['kind']) ?>" data-status="<?= e($r['status']) ?>"><td class="small"><?= e((string) $r['finished_at']) ?></td><td><?= e(str_replace('_', ' ', $r['kind'])) ?></td>
    <td><span class="tag <?= ['ok' => 'green', 'warning' => 'amber', 'failed' => 'red'][$r['status']] ?? 'grey' ?>"><?= e($r['status']) ?></span></td><td class="small"><?= e((string) $r['seconds']) ?></td>
    <td class="small" style="overflow-wrap:anywhere"><?= e(mb_substr((string) $r['detail'], 0, 300)) ?></td></tr><?php endforeach; ?>
  </table><?php endif; ?>
</div>
<div class="card" id="health-errors" data-count="<?= count($errors) ?>">
  <h2><?= te('Errors in the last 7 days') ?></h2>
  <?php if (! $groups): ?><p class="muted"><?= te('No error recorded.') ?></p><?php else: ?>
  <table><tr><th><?= te('Times') ?></th><th><?= te('Last') ?></th><th><?= te('Error') ?></th><th><?= te('Where') ?></th><th><?= te('Pages') ?></th></tr>
  <?php foreach ($groups as $g): ?><tr data-error="<?= e($g['fingerprint']) ?>" data-times="<?= (int) $g['count'] ?>"><td><?= (int) $g['count'] ?></td><td class="small"><?= e((string) $g['last']) ?><br><span class="mono"><?= e((string) $g['last_request']) ?></span></td>
    <td class="small" style="overflow-wrap:anywhere"><?= e($g['class'] . ': ' . $g['message']) ?></td><td class="small mono"><?= e($g['where']) ?></td><td class="small mono"><?= e(implode(' ', array_slice(array_keys($g['paths']), 0, 5))) ?></td></tr><?php endforeach; ?>
  </table><?php endif; ?>
</div>
