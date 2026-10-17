<?php // P5-M68: every W-4 and I-9, who signed in front of whom, Section 2, E-Verify, and what is wrong. ?>
<div class="page-head"><div><h1><?= te('Forms register') ?></h1>
  <p class="sub"><?= te('Every W-4 and I-9 signed: who signed, when, in front of whom, who accepted it, Section 2, E-Verify, the copies kept, how often it was opened. For an audit or an inspection: export it, and open each official form from its row.') ?></p></div>
  <div class="actions"><a class="btn" href="/forms-register?<?= e(http_build_query(['form' => $form, 'status' => $status, 'from' => $from, 'to' => $to, 'q' => $q, 'csv' => 1])) ?>"><?= te('Export (CSV)') ?></a></div></div>

<form method="get" class="card row gap-s" action="/forms-register">
  <select name="form" aria-label="<?= te('Form') ?>"><option value=""><?= te('W-4 and I-9') ?></option><option value="w4" <?= $form === 'w4' ? 'selected' : '' ?>>W-4</option><option value="i9" <?= $form === 'i9' ? 'selected' : '' ?>>I-9</option></select>
  <select name="status" aria-label="<?= te('Status') ?>"><option value=""><?= te('Any status') ?></option><?php foreach ($statusWord as $k => $w): ?><option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select>
  <label class="small"><?= te('Signed from') ?> <input type="date" name="from" value="<?= e($from) ?>"></label>
  <label class="small"><?= te('to') ?> <input type="date" name="to" value="<?= e($to) ?>"></label>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('Find a person') ?>" aria-label="<?= te('Find a person') ?>">
  <button class="btn ghost"><?= te('Show') ?></button>
</form>

<div class="card">
  <p class="small muted"><?= tn(count($rows), ':n form', ':n forms') ?> · <?= te('No Social Security number is shown or exported here.') ?></p>
  <table class="register">
    <thead><tr><th><?= te('Person') ?></th><th><?= te('Form') ?></th><th><?= te('Signed') ?></th><th><?= te('Accepted') ?></th><th><?= te('Section 2') ?></th><th>E-Verify</th><th><?= te('Copies') ?></th><th><?= te('Opened') ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $x): ?>
      <tr data-register-form="<?= (int) $x['id'] ?>"<?= $x['issues'] ? ' class="has-issue"' : '' ?>>
        <td><a href="/employee-folder?id=<?= (int) $x['candidate_id'] ?>"><?= e($x['full_name']) ?></a></td>
        <td><?= $x['form'] === 'w4' ? 'W-4' : 'I-9' ?> · <?= e($statusWord[$x['status']] ?? $x['status']) ?></td>
        <td><?= e(d(substr((string) $x['signed_at'], 0, 10))) ?> <?= e(date('g:i a', strtotime((string) $x['signed_at']))) ?><br><span class="small"><?= $x['witness_id'] ? te('in person, in front of :name', ['name' => $x['witness']]) : te('without a witness') ?></span></td>
        <td><?= $x['reviewed_at'] && $x['status'] !== 'returned' ? e(d(substr((string) $x['reviewed_at'], 0, 10)) . ' · ' . $x['reviewer']) : '—' ?></td>
        <td><?= $x['form'] === 'i9' && $x['verified_at'] ? e(d(substr((string) $x['verified_at'], 0, 10)) . ' · ' . $x['verifier_name']) . '<br><span class="small">' . te('due :date', ['date' => $x['review_due_on'] ? d((string) $x['review_due_on']) : '—']) . '</span>' : '—' ?></td>
        <td><?= $x['everify_case'] ? e($x['everify_case'] . ' · ' . $x['everify_result']) : '—' ?></td>
        <td><?= $x['form'] === 'i9' ? (int) $x['copies'] : '—' ?></td>
        <td><?= (int) $x['opened'] ?></td>
        <td><a href="/tax-forms?id=<?= (int) $x['id'] ?>"><?= te('Open, and the official PDF') ?></a><?php foreach ($x['issues'] as $issue): ?><br><span class="tag red small"><?= e($issue) ?></span><?php endforeach; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
