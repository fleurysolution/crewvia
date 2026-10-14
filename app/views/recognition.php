<?php
$rcKinds = eng_reward_kinds();
$rcEligible = eng_eligibles();
$rcPeriods = eng_periods();
$rcRed = eng_redemption_statuses();
$rcNom = eng_nomination_statuses();
$rcTone = ['requested' => 'blue', 'approved' => 'amber', 'declined' => 'red', 'fulfilled' => 'green', 'cancelled' => 'grey',
           'nominated' => 'blue', 'won' => 'green', 'not_selected' => 'grey', 'withdrawn' => 'grey'];
$rcSource = ['award' => t('Award'), 'kudos' => t('Thanks'), 'grant' => t('Granted'), 'redemption' => t('Reward'), 'refund' => t('Given back')];
?>
<div class="page-head"><div><h1><?= te('Recognition') ?></h1>
<p class="sub"><?= te('Thank the people you work with, nominate them for awards, and exchange points for rewards. Awards won by workers go into their HR record; performance reviews stay under Performance reviews.') ?></p></div></div>

<div class="grid g2" id="thanks">
  <form method="post" class="card" id="kudos-form"><?= csrf_field() ?><input type="hidden" name="do" value="kudos">
    <h2><?= te('Say thanks') ?></h2>
    <?php if (! $colleagues): ?><p class="muted"><?= te('Nobody to thank yet: you see the people on your live projects.') ?></p><?php else: ?>
    <label><?= te('To') ?><select name="to_user_id" required><?php foreach ($colleagues as $cid => $cname): ?><option value="<?= (int) $cid ?>"><?= e($cname) ?></option><?php endforeach; ?></select></label>
    <label><?= te('What for') ?><input name="message" required maxlength="500"></label>
    <label><input type="checkbox" name="is_public" value="1" checked> <?= te('Show it on the wall') ?></label>
    <button class="btn"><?= te('Send thanks') ?></button><?php endif; ?>
  </form>
  <div class="card scroll" id="wall" data-count="<?= count($wall) ?>"><h2><?= te('Thanks') ?></h2>
    <?php if (! $wall): ?><p class="muted"><?= te('None yet.') ?></p><?php endif; ?>
    <?php foreach ($wall as $k): ?><div class="small m0 mt-s mb-s" data-kudos="<?= (int) $k['id'] ?>" data-public="<?= (int) $k['is_public'] ?>">
      <strong><?= e($k['from_name']) ?></strong> → <strong><?= e($k['to_name']) ?></strong><?= (int) $k['is_public'] ? '' : ' <span class="tag grey">' . te('Private') . '</span>' ?><?= (int) $k['points'] ? ' <span class="tag green">+' . (int) $k['points'] . '</span>' : '' ?>
      <div class="wrap-any"><?= e($k['message']) ?></div><span class="muted"><?= e(d(substr((string) $k['created_at'], 0, 10))) ?></span></div><?php endforeach; ?>
  </div>
</div>

<h2 id="awards"><?= te('Awards') ?></h2>
<div class="grid g2">
  <div class="card" id="programs">
    <?php if (! $programs): ?><p class="muted"><?= te('No award is given yet.') ?></p><?php endif; ?>
    <?php foreach ($programs as $pr): ?><div class="mb" data-program="<?= (int) $pr['id'] ?>">
      <strong><?= e($pr['name']) ?></strong> · <span class="small"><?= e($rcPeriods[$pr['period']]) ?> · <?= e($rcEligible[$pr['eligible']]) ?><?= (int) $pr['points'] ? ' · ' . te(':n points', ['n' => (int) $pr['points']]) : '' ?></span>
      <?php if ($pr['description']): ?><div class="small"><?= e($pr['description']) ?></div><?php endif; ?>
      <?php if ($pr['may_nominate'] && $pr['nominees']): ?>
      <form method="post" class="nominate-form"><?= csrf_field() ?><input type="hidden" name="do" value="nominate"><input type="hidden" name="program_id" value="<?= (int) $pr['id'] ?>">
        <div class="grid g2"><label><?= te('Nominate') ?><select name="nominee_user_id"><?php foreach ($pr['nominees'] as $nid => $nname): ?><option value="<?= (int) $nid ?>"><?= e($nname) ?></option><?php endforeach; ?></select></label>
        <label><?= te('Why') ?><input name="reason" required maxlength="1000"></label></div>
        <button class="btn sm"><?= te('Nominate') ?></button> <span class="small muted"><?= e(eng_period_label($pr['period_key'])) ?></span></form>
      <?php elseif (! $pr['may_nominate']): ?><div class="small muted"><?= te('Nominations come from staff.') ?></div><?php endif; ?>
    </div><?php endforeach; ?>
  </div>
  <div class="card scroll" id="winners" data-count="<?= count($winners) ?>"><h3><?= te('Winners') ?></h3>
    <?php if (! $winners): ?><p class="muted"><?= te('None yet.') ?></p><?php else: ?>
    <table><?php foreach ($winners as $w): ?><tr data-winner="<?= (int) $w['nominee_user_id'] ?>"><td><strong><?= e($w['name']) ?></strong><div class="small"><?= e($w['reasons']) ?></div></td><td class="small"><?= e($w['program']) ?></td><td class="small"><?= e(eng_period_label((string) $w['period_key'])) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
    <?php if ($myNominations): ?><h3><?= te('My nominations') ?></h3><table id="my-nominations">
    <?php foreach ($myNominations as $n): ?><tr data-nomination="<?= (int) $n['id'] ?>" data-status="<?= e($n['status']) ?>"><td><?= e($n['name']) ?></td><td class="small"><?= e($n['program']) ?> · <?= e(eng_period_label((string) $n['period_key'])) ?></td>
      <td><span class="tag <?= $rcTone[$n['status']] ?>"><?= e($rcNom[$n['status']]) ?></span></td>
      <td><?php if ($n['status'] === 'nominated'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="withdraw"><input type="hidden" name="nomination_id" value="<?= (int) $n['id'] ?>"><button class="btn sm ghost"><?= te('Withdraw') ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></table><?php endif; ?>
  </div>
</div>

<h2 id="rewards"><?= te('Rewards') ?></h2>
<div class="grid g2">
  <div class="card" id="catalog"><p id="points" data-balance="<?= (int) $balance ?>"><strong><?= te('Your points: :n', ['n' => (int) $balance]) ?></strong></p>
    <?php if (! $rewards): ?><p class="muted"><?= te('No reward is offered to you yet.') ?></p><?php endif; ?>
    <?php foreach ($rewards as $rw): ?><form class="mb" method="post" data-reward="<?= (int) $rw['id'] ?>"><?= csrf_field() ?><input type="hidden" name="do" value="redeem"><input type="hidden" name="reward_id" value="<?= (int) $rw['id'] ?>">
      <strong><?= e($rw['name']) ?></strong> · <span class="small"><?= e($rcKinds[$rw['kind']]) ?> · <?= te(':n points', ['n' => (int) $rw['points_cost']]) ?><?= $rw['stock'] !== null ? ' · ' . te(':n left', ['n' => (int) $rw['stock']]) : '' ?></span>
      <?php if ($rw['description']): ?><div class="small"><?= e($rw['description']) ?></div><?php endif; ?>
      <div class="row"><input name="note" maxlength="500" placeholder="<?= te('Note (optional)') ?>" aria-label="<?= te('Note (optional)') ?>"><button class="btn sm"<?= $balance < (int) $rw['points_cost'] ? ' disabled' : '' ?>><?= te('Ask for it') ?></button></div></form><?php endforeach; ?>
  </div>
  <div class="card scroll" id="my-rewards">
    <h3><?= te('My requests') ?></h3>
    <?php if (! $myRedemptions): ?><p class="muted"><?= te('None yet.') ?></p><?php else: ?><table>
    <?php foreach ($myRedemptions as $r): ?><tr data-redemption="<?= (int) $r['id'] ?>" data-status="<?= e($r['status']) ?>"><td><?= e($r['reward']) ?><?= $r['decision_note'] ? '<div class="small">' . e($r['decision_note']) . '</div>' : '' ?><?= $r['fulfil_note'] ? '<div class="small">' . e($r['fulfil_note']) . '</div>' : '' ?></td>
      <td class="small"><?= (int) $r['points'] ?></td><td><span class="tag <?= $rcTone[$r['status']] ?>"><?= e($rcRed[$r['status']]) ?></span></td>
      <td><?php if ($r['status'] === 'requested'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="redemption_id" value="<?= (int) $r['id'] ?>"><button class="btn sm ghost"><?= te('Cancel') ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></table><?php endif; ?>
    <h3><?= te('Points') ?></h3>
    <?php if (! $ledger): ?><p class="muted"><?= te('None yet.') ?></p><?php else: ?><table id="ledger">
    <?php foreach ($ledger as $l): ?><tr data-points="<?= (int) $l['points'] ?>" data-source="<?= e($l['source']) ?>"><td class="small"><?= e(d(substr((string) $l['created_at'], 0, 10))) ?></td><td class="small"><?= e($rcSource[$l['source']]) ?><?= $l['note'] ? ' · ' . e($l['note']) : '' ?></td><td class="num"><?= (int) $l['points'] > 0 ? '+' : '' ?><?= (int) $l['points'] ?></td></tr><?php endforeach; ?></table><?php endif; ?>
  </div>
</div>

<?php if ($manage): ?>
<h2 id="manage"><?= te('Running awards and rewards') ?></h2>
<div class="grid g2">
  <form method="post" class="card" id="settings-form"><?= csrf_field() ?><input type="hidden" name="do" value="settings_save">
    <h3><?= te('Thanks') ?></h3>
    <div class="grid g2"><label><?= te('Points for a thank-you') ?><input type="number" name="kudos_points" min="0" max="1000" value="<?= (int) $manage['settings']['kudos_points'] ?>"></label>
    <label><?= te('Thank-yous a person gives in a day') ?><input type="number" name="kudos_daily_limit" min="1" max="50" value="<?= (int) $manage['settings']['kudos_daily_limit'] ?>"></label></div>
    <p class="small muted"><?= te('Points come with the first thanks from the same person in a month, so thanks cannot be traded for points.') ?></p>
    <button class="btn sm"><?= te('Save') ?></button></form>
  <form method="post" class="card" id="grant-form"><?= csrf_field() ?><input type="hidden" name="do" value="grant">
    <h3><?= te('Grant points') ?></h3>
    <label><?= te('To') ?><select name="user_id"><?php foreach ($manage['people'] as $pe): ?><option value="<?= (int) $pe['id'] ?>"><?= e($pe['name'] . ' · ' . $pe['role']) ?></option><?php endforeach; ?></select></label>
    <div class="grid g2"><label><?= te('Points') ?><input type="number" name="points" min="1" max="10000" required></label><label><?= te('Why') ?><input name="reason" required maxlength="255"></label></div>
    <button class="btn sm"><?= te('Grant') ?></button></form>
</div>

<div class="grid g2">
  <div class="card scroll" id="manage-programs"><h3><?= te('Awards') ?></h3>
    <?php if ($manage['programs']): ?><table><?php foreach ($manage['programs'] as $pr): ?><tr data-program-row="<?= (int) $pr['id'] ?>"<?= (int) $pr['is_active'] ? '' : ' class="muted"' ?>><td><a href="/recognition?program=<?= (int) $pr['id'] ?>#manage"><?= e($pr['name']) ?></a></td><td class="small"><?= e($rcPeriods[$pr['period']]) ?></td><td class="small"><?= te(':n waiting', ['n' => (int) $pr['waiting']]) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
    <?php $pg = $manage['program'] ?? null; ?>
    <form method="post" id="program-form"><?= csrf_field() ?><input type="hidden" name="do" value="program_save"><input type="hidden" name="program_id" value="<?= (int) ($pg['id'] ?? 0) ?>">
      <h3><?= $pg ? e($pg['name']) : te('New award') ?></h3>
      <label><?= te('Name') ?><input name="name" required maxlength="190" value="<?= e((string) ($pg['name'] ?? '')) ?>"></label>
      <label><?= te('What it is for') ?><input name="description" maxlength="1000" value="<?= e((string) ($pg['description'] ?? '')) ?>"></label>
      <div class="grid g2">
        <label><?= te('Given') ?><select name="period"><?php foreach ($rcPeriods as $k => $l): ?><option value="<?= e($k) ?>"<?= ($pg['period'] ?? 'month') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label><?= te('Who can win it') ?><select name="eligible"><?php foreach ($rcEligible as $k => $l): ?><option value="<?= e($k) ?>"<?= ($pg['eligible'] ?? 'everyone') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label><?= te('Who nominates') ?><select name="nominated_by"><option value="everyone"><?= te('Anyone who works here') ?></option><option value="staff"<?= ($pg['nominated_by'] ?? '') === 'staff' ? ' selected' : '' ?>><?= te('Staff desks only') ?></option></select></label>
        <label><?= te('Points for the winner') ?><input type="number" name="points" min="0" max="100000" value="<?= (int) ($pg['points'] ?? 0) ?>"></label>
      </div>
      <?php if ($pg): ?><label><input type="checkbox" name="is_active" value="1"<?= (int) $pg['is_active'] ? ' checked' : '' ?>> <?= te('In use') ?></label><?php endif; ?>
      <button class="btn sm"><?= $pg ? te('Save') : te('Create') ?></button></form>
  </div>
  <div class="card scroll" id="decide-nominations" data-count="<?= count($manage['nominations']) ?>"><h3><?= te('Nominations to decide') ?></h3>
    <?php if (! $manage['nominations']): ?><p class="muted"><?= te('None waiting.') ?></p><?php else: ?><table>
    <?php foreach ($manage['nominations'] as $n): ?><tr data-open-nomination="<?= (int) $n['id'] ?>"><td><strong><?= e($n['nominee']) ?></strong><div class="small"><?= e($n['reason']) ?> — <?= e($n['by_name']) ?></div></td><td class="small"><?= e($n['program']) ?> · <?= e(eng_period_label((string) $n['period_key'])) ?></td>
      <td><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="award"><input type="hidden" name="nomination_id" value="<?= (int) $n['id'] ?>"><button class="btn sm"><?= te('Choose as winner') ?></button></form></td></tr><?php endforeach; ?></table><?php endif; ?>
  </div>
</div>

<div class="grid g2">
  <div class="card scroll" id="manage-catalog"><h3><?= te('Rewards offered') ?></h3>
    <?php if ($manage['catalog']): ?><table><?php foreach ($manage['catalog'] as $rw): ?><tr data-reward-row="<?= (int) $rw['id'] ?>"<?= (int) $rw['is_active'] ? '' : ' class="muted"' ?>><td><a href="/recognition?reward=<?= (int) $rw['id'] ?>#manage"><?= e($rw['name']) ?></a></td><td class="small"><?= e($rcKinds[$rw['kind']]) ?><?= $rw['cash_amount'] !== null ? ' · ' . e(money($rw['cash_amount'])) : '' ?><?= $rw['leave_days'] !== null ? ' · ' . ten((int) $rw['leave_days'], ':n day', ':n days') : '' ?></td><td class="small"><?= te(':n points', ['n' => (int) $rw['points_cost']]) ?></td><td class="small"><?= $rw['stock'] !== null ? te(':n left', ['n' => (int) $rw['stock']]) : '' ?></td></tr><?php endforeach; ?></table><?php endif; ?>
    <?php $rg = $manage['reward'] ?? null; ?>
    <form method="post" id="reward-form"><?= csrf_field() ?><input type="hidden" name="do" value="reward_save"><input type="hidden" name="reward_id" value="<?= (int) ($rg['id'] ?? 0) ?>">
      <h3><?= $rg ? e($rg['name']) : te('New reward') ?></h3>
      <label><?= te('Name') ?><input name="name" required maxlength="190" value="<?= e((string) ($rg['name'] ?? '')) ?>"></label>
      <label><?= te('Details') ?><input name="description" maxlength="1000" value="<?= e((string) ($rg['description'] ?? '')) ?>"></label>
      <div class="grid g2">
        <label><?= te('Kind') ?><select name="kind"><?php foreach ($rcKinds as $k => $l): ?><option value="<?= e($k) ?>"<?= ($rg['kind'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label><?= te('Points it costs') ?><input type="number" name="points_cost" min="1" max="1000000" required value="<?= e((string) ($rg['points_cost'] ?? '')) ?>"></label>
        <label><?= te('For') ?><select name="eligible"><?php foreach ($rcEligible as $k => $l): ?><option value="<?= e($k) ?>"<?= ($rg['eligible'] ?? 'everyone') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label><?= te('How many are left · empty: no limit') ?><input type="number" name="stock" min="0" value="<?= e((string) ($rg['stock'] ?? '')) ?>"></label>
        <label><?= te('Cash amount (cash rewards)') ?><input name="cash_amount" inputmode="decimal" value="<?= e((string) ($rg['cash_amount'] ?? '')) ?>"></label>
        <label><?= te('Leave (time-off rewards)') ?><select name="leave_type"><option value=""></option><?php foreach ($manage['leave_types'] as $lt): ?><option value="<?= e($lt['slug']) ?>"<?= ($rg['leave_type'] ?? '') === $lt['slug'] ? ' selected' : '' ?>><?= e(t((string) $lt['label'])) ?></option><?php endforeach; ?></select></label>
        <label><?= te('Days (time-off rewards)') ?><input type="number" name="leave_days" min="1" max="10" value="<?= e((string) ($rg['leave_days'] ?? '')) ?>"></label>
      </div>
      <p class="small muted"><?= te('Cash is paid by payroll in an open pay period; time off is added to the worker\'s leave allowance for the year once it is given.') ?></p>
      <?php if ($rg): ?><label><input type="checkbox" name="is_active" value="1"<?= (int) $rg['is_active'] ? ' checked' : '' ?>> <?= te('In use') ?></label><?php endif; ?>
      <button class="btn sm"><?= $rg ? te('Save') : te('Create') ?></button></form>
  </div>
  <div class="card scroll" id="reward-queue" data-count="<?= count($manage['queue']) ?>"><h3><?= te('Rewards asked for') ?></h3>
    <?php if (! $manage['queue']): ?><p class="muted"><?= te('None waiting.') ?></p><?php else: ?><table>
    <?php foreach ($manage['queue'] as $r): ?><tr data-queue="<?= (int) $r['id'] ?>" data-status="<?= e($r['status']) ?>"><td><strong><?= e($r['name']) ?></strong><div class="small"><?= e($r['reward']) ?> · <?= (int) $r['points'] ?><?= $r['note'] ? ' · ' . e($r['note']) : '' ?></div></td>
      <td><span class="tag <?= $rcTone[$r['status']] ?>"><?= e($rcRed[$r['status']]) ?></span></td><td>
      <?php if ($r['status'] === 'requested'): ?>
        <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="decide"><input type="hidden" name="redemption_id" value="<?= (int) $r['id'] ?>"><input name="note" maxlength="500" placeholder="<?= te('Note; required to decline') ?>" aria-label="<?= te('Note; required to decline') ?>">
          <button class="btn sm" name="decision" value="approve"><?= te('Approve') ?></button><button class="btn sm ghost" name="decision" value="decline"><?= te('Decline') ?></button></form>
      <?php elseif ($r['kind'] === 'cash'): ?><span class="small muted"><?= te('Payroll pays it in an open pay period.') ?></span>
      <?php else: ?>
        <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="fulfil"><input type="hidden" name="redemption_id" value="<?= (int) $r['id'] ?>"><input name="note" maxlength="500" placeholder="<?= te('How it was given (optional)') ?>" aria-label="<?= te('How it was given (optional)') ?>"><button class="btn sm"><?= te('Given') ?></button></form>
      <?php endif; ?></td></tr><?php endforeach; ?></table><?php endif; ?>
  </div>
</div>
<?php endif; ?>
