<?php // P5-M79: new hires to ADP. ?>
<div class="page-heading"><div><span class="eyebrow"><?= te('PAYROLL') ?></span><h1><?= te('ADP') ?></h1>
  <p class="sub"><?= te('New hires ready to be created in ADP.') ?></p></div>
  <div class="flex gap-s"><a class="btn ghost" href="/payroll-export"><?= te('Weekly payroll file') ?></a></div></div>

<?php $readyCount = count(array_filter($hires, static fn ($h) => $h['ready'])); ?>
<form method="post" action="/adp" class="card tight" id="new-hires"><?= csrf_field() ?>
  <div class="card-bar flex gap-s"><h2 class="m0"><?= tn(count($hires), ':n new hire', ':n new hires') ?> &middot; <?= te(':n ready', ['n' => $readyCount]) ?></h2>
    <span class="ml-auto flex gap-s">
      <button class="btn ghost sm" name="do" value="file"<?= $readyCount ? '' : ' disabled' ?> data-adp-file><?= te('Download the new-hire file') ?></button>
      <button class="btn sm" name="do" value="send"<?= $readyCount && ! $apiMissing ? '' : ' disabled' ?> data-adp-send title="<?= $apiMissing ? te('Set up the ADP connection first.') : '' ?>"><?= te('Send to ADP') ?></button>
    </span></div>
  <?php if (! $hires): ?><div class="empty"><?= te('Nobody to create in ADP.') ?></div><?php else: ?>
  <div class="scroll"><table>
    <thead><tr><th><input type="checkbox" aria-label="<?= te('All ready') ?>" onclick="document.querySelectorAll('#new-hires input[name=\'people[]\']:not(:disabled)').forEach(c=>c.checked=this.checked)"></th><th><?= te('Person') ?></th><th><?= te('Job') ?></th><th><?= te('Starts') ?></th><th><?= te('Status') ?></th><th><?= te('ADP employee ID') ?></th></tr></thead>
    <tbody>
    <?php foreach ($hires as $h): ?>
      <tr data-adp-hire="<?= (int) $h['candidate_id'] ?>">
        <td><input type="checkbox" name="people[]" value="<?= (int) $h['candidate_id'] ?>"<?= $h['ready'] ? '' : ' disabled' ?> aria-label="<?= e($h['name']) ?>"></td>
        <td><a href="/candidates/<?= (int) $h['candidate_id'] ?>"><?= e($h['name']) ?></a></td>
        <td class="small"><?= e($h['job']) ?></td>
        <td class="small"><?= $h['start'] ? e(d($h['start'])) : '&mdash;' ?></td>
        <td class="small"><?php if ($h['ready']): ?><span class="tag green"><?= te('Ready') ?></span><?php else: ?><span class="tag amber"><?= te('Missing: :list', ['list' => implode(', ', $h['missing'])]) ?></span><?php endif; ?>
          <?php if ($h['sent']): ?><div class="muted"><?= $h['sent']['mode'] === 'api' ? ($h['sent']['status'] === 'sent' ? te('Sent :date (:ref)', ['date' => d(substr((string) $h['sent']['created_at'], 0, 10)), 'ref' => (string) $h['sent']['adp_reference']]) : te('Send failed :date', ['date' => d(substr((string) $h['sent']['created_at'], 0, 10))])) : te('In a file :date', ['date' => d(substr((string) $h['sent']['created_at'], 0, 10))]) ?></div><?php endif; ?></td>
        <td><input form="adp-id-<?= (int) $h['candidate_id'] ?>" name="adp_employee_id" class="in-s" maxlength="40" placeholder="<?= te('from ADP') ?>" aria-label="<?= te('ADP employee ID') ?>"> <button form="adp-id-<?= (int) $h['candidate_id'] ?>" class="btn ghost sm"><?= te('Save') ?></button></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <p class="small muted m0 mt-s"><?= te('The file holds Social Security and bank numbers. Import it in ADP, then delete it.') ?></p>
  <?php endif; ?>
</form>
<?php foreach ($hires as $h): ?><form method="post" action="/adp" id="adp-id-<?= (int) $h['candidate_id'] ?>" hidden><?= csrf_field() ?><input type="hidden" name="do" value="employee_id"><input type="hidden" name="candidate_id" value="<?= (int) $h['candidate_id'] ?>"></form><?php endforeach; ?>

<details class="card fold" id="connection"<?= $apiMissing ? '' : ' open' ?>>
  <summary><h2><?= te('ADP connection') ?></h2><span class="fold-note"><?= $apiMissing ? te('Not set up: the new-hire file is used') : te('Set up') ?></span></summary>
  <?php if ($apiMissing): ?>
    <p class="small"><?= te('To send new hires directly, RSS requests API access from ADP (ADP Marketplace or API Central). ADP provides:') ?></p>
    <ul class="small"><li><?= te('a client ID and a client secret') ?></li><li><?= te('a certificate and its private key') ?></li></ul>
    <p class="small"><?= te('Fleury Solutions installs them on the server. Missing now: :list.', ['list' => implode(', ', $apiMissing)]) ?></p>
  <?php else: ?>
    <form method="post" action="/adp"><?= csrf_field() ?><input type="hidden" name="do" value="test"><button class="btn ghost"><?= te('Test the connection') ?></button></form>
  <?php endif; ?>
</details>

<details class="card fold tight" id="adp-history">
  <summary><h2><?= te('History') ?></h2><span class="fold-note"><?= tn(count($history), ':n entry', ':n entries') ?></span></summary>
  <?php if (! $history): ?><div class="empty"><?= te('Nothing sent yet.') ?></div><?php else: ?>
  <div class="scroll"><table><thead><tr><th><?= te('When') ?></th><th><?= te('Person') ?></th><th><?= te('What') ?></th><th><?= te('How') ?></th><th><?= te('Result') ?></th><th><?= te('By') ?></th></tr></thead><tbody>
  <?php foreach ($history as $x): ?><tr><td class="small"><?= e(substr((string) $x['created_at'], 0, 16)) ?></td><td class="small"><?= e((string) ($x['full_name'] ?? '')) ?: '&mdash;' ?></td><td class="small"><?= e($x['kind']) ?></td><td class="small"><?= $x['mode'] === 'api' ? te('API') : te('File') ?></td>
    <td class="small"><span class="tag <?= $x['status'] === 'failed' ? 'red' : 'green' ?>"><?= e($x['status']) ?></span> <?= e((string) ($x['adp_reference'] ?? '')) ?> <span class="muted"><?= e((string) ($x['message'] ?? '')) ?></span></td><td class="small"><?= e((string) ($x['by_name'] ?? '')) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</details>
<script src="/assets/fold.js"></script>
