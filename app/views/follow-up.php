<?php /** P5-M100: approved people waiting for their next step - counters and a list, no judgement. */ ?>
<div class="page-head"><div>
  <h1><?= te('Approved, next step') ?></h1>
  <p class="sub"><?= te('People a hiring manager approved who are not deployed yet. Those with no contact since the approval are ready for a call, on this job or the next one.') ?></p>
</div>
<form method="get" class="actions"><label class="small" for="fu-days"><?= te('No contact for') ?></label>
  <select id="fu-days" name="days" onchange="this.form.submit()"><?php foreach ([7, 14, 30, 60] as $d): ?><option value="<?= $d ?>"<?= $d === $days ? ' selected' : '' ?>><?= tn($d, ':n day', ':n days') ?></option><?php endforeach; ?></select></form>
</div>

<section class="card" id="follow-up-requisitions">
  <?php if (! $byReq): ?><p class="muted"><?= te('No approval yet.') ?></p><?php else: ?>
  <div class="scroll"><table>
    <thead><tr><th><?= te('Requisition') ?></th><th class="right"><?= te('Approved') ?></th><th class="right"><?= te('Deployed') ?></th><th class="right"><?= te('Waiting') ?></th><th class="right"><?= te('No contact since approval') ?></th></tr></thead>
    <tbody><?php foreach ($byReq as $vid => $r): ?><tr data-follow-req="<?= (int) $vid ?>">
      <td><strong><?= e($r['requisition']) ?></strong><div class="small muted"><?= e($r['project']) ?></div></td>
      <td class="right"><?= (int) $r['approved'] ?></td><td class="right"><?= (int) $r['deployed'] ?></td>
      <td class="right"><?= (int) $r['waiting'] ?></td><td class="right" data-quiet="<?= (int) $r['quiet'] ?>"><?= (int) $r['quiet'] ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</section>

<?php if ($waiting): ?>
<section class="card" id="follow-up-people">
  <h2 class="m0"><?= tn(count($waiting), ':n person ready for a call', ':n people ready for a call') ?></h2>
  <div class="scroll"><table class="mt-s">
    <thead><tr><th><?= te('Person') ?></th><th><?= te('Requisition') ?></th><th><?= te('Approved on') ?></th><th></th></tr></thead>
    <tbody><?php foreach ($waiting as $w): ?><tr data-follow-person="<?= (int) $w['candidate_id'] ?>">
      <td><?= e($w['full_name']) ?></td><td class="small"><?= e($w['requisition']) ?> &middot; <?= e($w['project']) ?></td>
      <td class="small"><?= e(d(substr((string) $w['decided_at'], 0, 10))) ?></td>
      <td><a class="btn ghost sm" href="/candidates/<?= (int) $w['candidate_id'] ?>"><?= te('Open') ?></a></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
</section>
<?php endif; ?>
