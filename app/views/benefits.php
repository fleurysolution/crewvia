<?php
$kinds = benefit_kinds();
$tiers = benefit_tiers();
$cost = static fn(array $p): string => $p['method'] === 'percent'
    ? t('person elects up to :max %, agency adds :er %', ['max' => rtrim(rtrim((string) $p['max_employee_percent'], '0'), '.'), 'er' => rtrim(rtrim((string) $p['employer_percent'], '0'), '.')])
    : implode(' · ', array_map(fn($t) => benefit_tiers()[$t['tier']] . ' ' . money($t['employee_amount']) . ' + ' . money($t['employer_amount']), $p['tiers']));
$stone = ['enrolled' => 'green', 'waived' => 'grey', 'ended' => 'grey'];
?>
<h1><?= $role === 'worker' ? te('My benefits') : te('Benefits') ?></h1>
<p class="sub"><?= te('What a plan costs the person comes off their pay each week, before tax unless the plan says otherwise; what the agency adds is its contribution. Coverage is never edited: a change ends one and starts the next.') ?></p>

<?php if ($person): ?>
<div class="card scroll" id="person-benefits"><h2><?= e($person['name']) ?></h2>
  <p class="small muted"><?= e(employment_types()[$person['type']] ?? $person['type']) ?> · <?= $person['hired'] ? te('first assignment began :date', ['date' => d($person['hired'])]) : te('no assignment yet') ?></p>
  <?php if (! $person['history']): ?><p class="muted"><?= te('No coverage and no waiver yet.') ?></p><?php else: ?>
  <table><tr><th><?= te('Plan') ?></th><th><?= te('Coverage') ?></th><th class="num"><?= te('Person pays') ?></th><th class="num"><?= te('Agency adds') ?></th><th><?= te('From') ?></th><th><?= te('Until') ?></th><th><?= te('State') ?></th><?php if ($role !== 'worker'): ?><th></th><?php endif; ?></tr>
  <?php foreach ($person['history'] as $h): $pct = $h['method'] === 'percent'; ?>
    <tr data-enrollment="<?= (int) $h['id'] ?>" data-enrollment-status="<?= e($h['status']) ?>">
      <td><?= e($h['plan']) ?><div class="small muted"><?= e($kinds[$h['kind']]) ?></div></td>
      <td><?= $h['status'] === 'waived' ? te('Declined') . ' · ' . e((string) $h['reason']) : ($pct ? te(':p % of gross', ['p' => rtrim(rtrim((string) $h['employee_percent'], '0'), '.')]) : e($tiers[$h['tier']] ?? '')) ?></td>
      <td class="num mono"><?= $h['status'] === 'waived' ? '' : ($pct ? e(rtrim(rtrim((string) $h['employee_amount'], '0'), '.')) . ' %' : e(money($h['employee_amount']))) ?></td>
      <td class="num mono"><?= $h['status'] === 'waived' ? '' : ($pct ? e(rtrim(rtrim((string) $h['employer_amount'], '0'), '.')) . ' %' : e(money($h['employer_amount']))) ?></td>
      <td><?= e(d((string) $h['starts_on'])) ?></td><td><?= $h['ends_on'] ? e(d((string) $h['ends_on'])) : '—' ?><?= $h['end_reason'] ? '<div class="small muted">' . e($h['end_reason']) . '</div>' : '' ?></td>
      <td><span class="tag <?= $stone[$h['status']] ?>"><?= e(['enrolled' => t('Enrolled'), 'waived' => t('Waived'), 'ended' => t('Ended')][$h['status']]) ?></span></td>
      <?php if ($role !== 'worker'): ?><td><?php if ($h['ends_on'] === null && $h['status'] !== 'ended'): ?>
        <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="end"><input type="hidden" name="enrollment_id" value="<?= (int) $h['id'] ?>"><input type="hidden" name="candidate_id" value="<?= (int) $person['id'] ?>"><input type="date" name="ends_on" required><input name="reason" required minlength="3" maxlength="500" placeholder="<?= te('Why it ends') ?>" style="max-width:9em"><button class="btn ghost sm"><?= te('End') ?></button></form>
        <?php if ($h['status'] === 'enrolled'): ?><details class="small"><summary><?= te('Change coverage') ?></summary><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="change"><input type="hidden" name="enrollment_id" value="<?= (int) $h['id'] ?>"><input type="hidden" name="candidate_id" value="<?= (int) $person['id'] ?>">
          <?php if ($pct): ?><input name="employee_percent" type="number" min="0.01" step="0.01" placeholder="%" required style="max-width:6em"><?php else: ?><select name="tier"><?php foreach ($tiers as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
          <input type="date" name="starts_on" required><input name="reason" required minlength="3" maxlength="500" placeholder="<?= te('What changed') ?>" style="max-width:9em"><button class="btn sm"><?= te('Change') ?></button></form></details><?php endif; ?>
      <?php endif; ?></td><?php endif; ?></tr>
  <?php endforeach; ?></table><?php endif; ?>
  <?php if ($role !== 'worker' && $plans): ?>
  <form method="post" id="enroll-form"><?= csrf_field() ?><input type="hidden" name="candidate_id" value="<?= (int) $person['id'] ?>">
    <h3><?= te('Enroll, or record a waiver') ?></h3>
    <div class="grid g2">
      <div><label><?= te('Plan') ?></label><select name="plan_id"><?php foreach ($plans as $p): if ((int) $p['is_active'] === 1): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endif; endforeach; ?></select></div>
      <div><label><?= te('Coverage starts') ?></label><input type="date" name="starts_on" required></div>
      <div><label><?= te('Coverage level · fixed plans') ?></label><select name="tier"><?php foreach ($tiers as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label><?= te('Elected % · percent plans') ?></label><input name="employee_percent" type="number" min="0.01" step="0.01"></div>
    </div>
    <label><?= te('Note · required for a waiver') ?></label><input name="reason" maxlength="500">
    <div class="row"><button class="btn" name="do" value="enroll"><?= te('Enroll') ?></button><button class="btn ghost" name="do" value="waive"><?= te('Record the waiver') ?></button></div>
  </form><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($role !== 'worker'): ?>
<div class="card scroll" id="to-offer"><h2><?= te('To offer') ?></h2>
  <p class="muted"><?= te('People on assignment who are eligible for a plan and have neither joined it nor declined it.') ?></p>
  <?php if (! $toOffer): ?><p class="muted"><?= te('Nobody is waiting for an offer.') ?></p><?php else: ?>
  <table><?php foreach ($toOffer as $o): ?><tr data-offer="<?= (int) $o['candidate_id'] ?>-<?= (int) $o['plan']['id'] ?>"><td><a href="/benefits?candidate=<?= (int) $o['candidate_id'] ?>"><?= e($o['name']) ?></a></td><td><?= e($o['plan']['name']) ?></td>
    <td<?= $o['due'] ? ' class="warn"' : '' ?>><?= $o['due'] ? te('eligible since :date', ['date' => d($o['from'])]) : te('eligible from :date', ['date' => d($o['from'])]) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>

<div class="card scroll" id="plans"><h2><?= te('Plans') ?></h2>
  <?php if (! $plans): ?><p class="muted"><?= te('No plan yet.') ?></p><?php else: ?>
  <table><tr><th><?= te('Plan') ?></th><th><?= te('Cost a week: person + agency') ?></th><th><?= te('Covers') ?></th><th class="num"><?= te('Enrolled') ?></th><th></th></tr>
  <?php foreach ($plans as $p): ?>
    <tr data-plan="<?= e($p['code']) ?>"<?= (int) $p['is_active'] === 1 ? '' : ' class="muted"' ?>><td><?= e($p['name']) ?><div class="small muted"><?= e($kinds[$p['kind']]) ?><?= $p['provider'] ? ' · ' . e($p['provider']) : '' ?><?= (int) $p['pre_tax'] === 1 ? ' · ' . te('before tax') : '' ?></div></td>
      <td class="small"><?= e($cost($p)) ?></td>
      <td class="small"><?= e(implode(', ', array_map(fn($t) => employment_types()[$t] ?? $t, explode(',', (string) $p['eligible_types'])))) ?><?= (int) $p['waiting_days'] > 0 ? ' · ' . te(':n days after the first assignment begins', ['n' => (int) $p['waiting_days']]) : '' ?></td>
      <td class="num mono"><?= (int) $p['enrolled'] ?></td>
      <td><form method="post"><?= csrf_field() ?><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="do" value="<?= (int) $p['is_active'] === 1 ? 'retire' : 'restore' ?>"><button class="btn ghost sm"><?= (int) $p['is_active'] === 1 ? te('Retire') : te('Put back in use') ?></button></form></td></tr>
  <?php endforeach; ?></table><?php endif; ?>
  <form method="post" id="plan-form"><?= csrf_field() ?><input type="hidden" name="do" value="plan">
    <h3><?= te('New plan') ?></h3>
    <div class="grid g2">
      <div><label><?= te('Name') ?></label><input name="name" required minlength="3" maxlength="120"></div>
      <div><label><?= te('Kind') ?></label><select name="kind"><?php foreach ($kinds as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label><?= te('Provider') ?></label><input name="provider" maxlength="190"></div>
      <div><label><?= te('Paid for') ?></label><select name="method"><option value="fixed"><?= te('A weekly cost per coverage level') ?></option><option value="percent"><?= te('A share of gross wages') ?></option></select></div>
      <div><label><?= te('Waiting period (days)') ?></label><input name="waiting_days" type="number" min="0" max="365" value="0"></div>
      <div><label><input type="checkbox" name="pre_tax" value="1" checked> <?= te('Taken before tax') ?></label></div>
    </div>
    <label><?= te('Covers') ?></label><div class="row"><?php foreach (employment_types() as $k => $l): ?><label class="small"><input type="checkbox" name="eligible_types[]" value="<?= e($k) ?>"<?= in_array($k, ['hourly', 'salaried'], true) ? ' checked' : '' ?>> <?= e($l) ?></label><?php endforeach; ?></div>
    <table><tr><th><?= te('Coverage level') ?></th><th><?= te('Person, a week') ?></th><th><?= te('Agency, a week') ?></th></tr>
    <?php foreach ($tiers as $k => $l): ?><tr><td><?= e($l) ?></td><td><input name="employee_amount[<?= e($k) ?>]" type="number" min="0" step="0.01" style="max-width:8em"></td><td><input name="employer_amount[<?= e($k) ?>]" type="number" min="0" step="0.01" style="max-width:8em"></td></tr><?php endforeach; ?></table>
    <div class="grid g2"><div><label><?= te('Percent plans: the person elects up to (%)') ?></label><input name="max_employee_percent" type="number" min="0.01" max="100" step="0.01"></div>
      <div><label><?= te('Percent plans: the agency adds (%)') ?></label><input name="employer_percent" type="number" min="0" max="25" step="0.01"></div></div>
    <button class="btn"><?= te('Create the plan') ?></button>
  </form>
</div>
<?php endif; ?>
