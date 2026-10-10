<?php
$amount = static fn($v): string => abs((float) $v) < 0.005 ? '' : money($v);
$num = static fn($v): string => number_format((float) $v, 2, '.', '');
$acctName = static fn(string $key): string => isset($accounts[$key]) ? trim($accounts[$key]['number'] . ' ' . t($accounts[$key]['label'])) : $key;
$sides = ['asset' => t('Asset'), 'liability' => t('Liability'), 'equity' => t('Equity'), 'income' => t('Income'), 'expense' => t('Expense')];
$kinds = ['source' => t('From a record'), 'manual' => t('Manual'), 'reversal' => t('Reversal')];
$linesTable = static function (array $ls) use ($acctName, $amount): string {
    $h = '<table class="small">';
    foreach ($ls as $l) {
        $h .= '<tr data-line-account="' . e($l['account_key']) . '"><td>' . e($acctName($l['account_key'])) . '</td><td class="num mono">' . e($amount($l['debit'])) . '</td><td class="num mono">' . e($amount($l['credit'])) . '</td>'
            . '<td>' . e((string) $l['party']) . '</td><td>' . e((string) $l['class']) . '</td></tr>';
    }
    return $h . '</table>';
};
?>
<h1><?= te('Ledger') ?></h1>
<p class="sub"><?= te('Crewvia keeps its own books. Each invoice, payment, credit note, bill, paid claim and payroll period posts itself here once, when it is recorded; nothing is keyed twice, and the QuickBooks export is drawn from these journals. Type a journal only for what Crewvia never sees: rent, insurance, bank fees, payroll taxes paid through ADP.') ?></p>

<div class="card" id="ledger-integrity" data-ok="<?= $integrity['ok'] ? '1' : '0' ?>" data-checked="<?= (int) $integrity['checked'] ?>" data-broken="<?= (int) $integrity['broken'] ?>" data-triggers="<?= (int) $integrity['triggers'] ?>" data-synced="<?= (int) $synced ?>" data-drift="<?= count($drift) ?>">
  <h2><?= te('Integrity') ?></h2>
  <?php if ($integrity['ok']): ?>
    <p><span class="tag green"><?= te('Intact') ?></span> <?= te(':n posted journals checked: each balances, and each fingerprint matches its content and the journal before it.', ['n' => (int) $integrity['checked']]) ?></p>
  <?php else: ?>
    <div class="err"><?= $integrity['broken'] ? te('Journal :n no longer matches its fingerprint: it, or one before it, was changed outside Crewvia. Restore it from a backup.', ['n' => (int) $integrity['broken']]) : '' ?>
      <?= $integrity['unbalanced'] ? te('Journals that do not balance: :list.', ['list' => implode(', ', $integrity['unbalanced'])]) : '' ?></div>
  <?php endif; ?>
  <p class="small muted"><?= $integrity['triggers'] >= 5 ? te('The database itself refuses any change to a posted journal.') : te('The database triggers that refuse changes to posted journals are missing: grant TRIGGER and run the upgrade. Crewvia still refuses them.') ?>
    <?= $synced ? ' ' . te(':n journals were just posted from the records.', ['n' => (int) $synced]) : '' ?></p>
  <?php if ($skipped): ?><div class="err"><?= te('These records do not balance and were not posted: :list.', ['list' => implode(', ', $skipped)]) ?></div><?php endif; ?>
  <?php if ($drift): ?>
  <div id="ledger-drift"><h3><?= te('Records changed after posting') ?></h3>
    <p class="muted small"><?= te('The journal stands as posted. Correct the record with its own correction, a reversal or a credit note, which posts the difference.') ?></p>
    <table class="small"><?php foreach ($drift as $dr): ?><tr data-drift="<?= e($dr['source']) ?>"><td class="mono"><?= e($dr['source']) ?></td><td><?= e($dr['what']) ?></td></tr><?php endforeach; ?></table></div>
  <?php endif; ?>
</div>

<div class="card scroll" id="ledger-trial" data-balanced="<?= $trial['balanced'] ? '1' : '0' ?>">
  <h2><?= te('Trial balance') ?> · <?= e($period) ?></h2>
  <form method="get" class="row"><input type="month" name="period" value="<?= e($period) ?>"><button class="btn ghost"><?= te('Show') ?></button></form>
  <?php if (! $trial['rows']): ?><p class="muted"><?= te('Nothing posted up to the end of this month.') ?></p><?php else: ?>
  <table><tr><th><?= te('Account') ?></th><th class="num"><?= te('Opening') ?></th><th class="num"><?= te('Debits') ?></th><th class="num"><?= te('Credits') ?></th><th class="num"><?= te('Closing debit') ?></th><th class="num"><?= te('Closing credit') ?></th></tr>
  <?php foreach ($trial['rows'] as $r): ?>
    <tr data-account="<?= e($r['key']) ?>" data-closing="<?= e($num($r['closing'])) ?>" data-debit="<?= e($num($r['debit'])) ?>" data-credit="<?= e($num($r['credit'])) ?>">
      <td><a href="/ledger?account=<?= e(rawurlencode($r['key'])) ?>&amp;from=<?= e($period) ?>-01&amp;to=<?= e(date('Y-m-t', strtotime($period . '-01'))) ?>#account-ledger"><?= e($acctName($r['key'])) ?></a></td>
      <td class="num mono"><?= e($amount($r['opening'])) ?></td><td class="num mono"><?= e($amount($r['debit'])) ?></td><td class="num mono"><?= e($amount($r['credit'])) ?></td>
      <td class="num mono"><?= e($amount(max(0, $r['closing']))) ?></td><td class="num mono"><?= e($amount(max(0, -$r['closing']))) ?></td></tr>
  <?php endforeach; ?>
    <tr><th colspan="2"><?= te('Total') ?></th><th class="num mono"><?= e(money($trial['debit'])) ?></th><th class="num mono"><?= e(money($trial['credit'])) ?></th>
      <th class="num mono"><?= e(money($trial['closing_debit'])) ?></th><th class="num mono"><?= e(money($trial['closing_credit'])) ?></th></tr>
  </table>
  <p class="<?= $trial['balanced'] ? 'muted' : 'err' ?>"><?= $trial['balanced'] ? te('Debits equal credits.') : te('Debits and credits differ. Check the integrity above.') ?></p>
  <?php endif; ?>
</div>

<?php if ($accountLines !== null): ?>
<div class="card scroll" id="account-ledger" data-account="<?= e($account) ?>" data-opening="<?= e($num($accountLines['opening'])) ?>" data-closing="<?= e($num($accountLines['closing'])) ?>">
  <h2><?= e($acctName($account)) ?></h2>
  <form method="get" class="row"><input type="hidden" name="account" value="<?= e($account) ?>"><input type="date" name="from" value="<?= e($from) ?>"><input type="date" name="to" value="<?= e($to) ?>"><button class="btn ghost"><?= te('Show') ?></button></form>
  <table><tr><th><?= te('Posted') ?></th><th><?= te('Journal') ?></th><th><?= te('Memo') ?></th><th class="num"><?= te('Debit') ?></th><th class="num"><?= te('Credit') ?></th><th class="num"><?= te('Balance') ?></th></tr>
    <tr><td colspan="5" class="muted"><?= te('Opening balance') ?></td><td class="num mono"><?= e(money($accountLines['opening'])) ?></td></tr>
  <?php foreach ($accountLines['lines'] as $l): ?>
    <tr data-journal="<?= (int) $l['journal_id'] ?>"><td><?= e(d((string) $l['posted_on'])) ?></td><td class="mono">#<?= (int) $l['journal_id'] ?></td><td class="small"><?= e($l['memo']) ?> <?= e((string) $l['party']) ?></td>
      <td class="num mono"><?= e($amount($l['debit'])) ?></td><td class="num mono"><?= e($amount($l['credit'])) ?></td><td class="num mono"><?= e(money($l['balance'])) ?></td></tr>
  <?php endforeach; ?></table>
</div>
<?php endif; ?>

<div class="card scroll" id="ledger-vs-export"><h2><?= te('Ledger and QuickBooks') ?></h2>
  <p class="muted"><?= te('Each account in the ledger beside what was exported to QuickBooks, to date. A difference is waiting to be exported, or payroll left to ADP.') ?></p>
  <table><tr><th><?= te('Account') ?></th><th class="num"><?= te('Ledger') ?></th><th class="num"><?= te('Exported') ?></th><th class="num"><?= te('Difference') ?></th></tr>
  <?php foreach ($compare as $c): ?>
    <tr data-account="<?= e($c['key']) ?>" data-ledger="<?= e($num($c['ledger'])) ?>" data-exported="<?= e($num($c['exported'])) ?>" data-difference="<?= e($num($c['difference'])) ?>">
      <td><?= e($acctName($c['key'])) ?></td><td class="num mono"><?= e(money($c['ledger'])) ?></td><td class="num mono"><?= e(money($c['exported'])) ?></td>
      <td class="num mono"><?= abs($c['difference']) < 0.005 ? '<span class="muted">-</span>' : e(money($c['difference'])) ?></td></tr>
  <?php endforeach; ?></table>
  <p><a href="/accounting"><?= te('QuickBooks export') ?></a></p>
</div>

<div class="card scroll" id="ledger-drafts"><h2><?= te('Journals waiting to be posted') ?></h2>
  <?php if (! $drafts): ?><p class="muted"><?= te('No draft journal.') ?></p><?php endif; ?>
  <?php foreach ($drafts as $j): ?>
    <div class="card" data-journal="<?= (int) $j['id'] ?>" data-status="draft">
      <p><b>#<?= (int) $j['id'] ?></b> · <?= e(d((string) $j['doc_date'])) ?> · <?= e($j['memo']) ?> · <span class="mono"><?= e(money($j['total'])) ?></span> · <span class="muted"><?= te('by :name', ['name' => (string) ($j['by_name'] ?? '')]) ?></span></p>
      <?= $linesTable($lines[(int) $j['id']] ?? []) ?>
      <?php if ((int) $j['created_by'] !== uid()): ?>
      <form method="post" class="row" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="approve"><input type="hidden" name="journal_id" value="<?= (int) $j['id'] ?>"><button class="btn sm"><?= te('Post it') ?></button></form>
      <?php else: ?><span class="small muted"><?= te('Someone else posts it.') ?></span><?php endif; ?>
      <?php if ((int) $j['created_by'] === uid() || can('admin')): ?>
      <form method="post" class="row" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="discard"><input type="hidden" name="journal_id" value="<?= (int) $j['id'] ?>"><button class="btn sm ghost"><?= te('Discard') ?></button></form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<form class="card scroll" method="post" id="journal-form"><?= csrf_field() ?><input type="hidden" name="do" value="draft">
  <h2><?= te('New journal') ?></h2>
  <p class="muted"><?= te('Only for what Crewvia does not record. Client and vendor balances go through Receivables and payables. Someone other than you posts it.') ?></p>
  <div class="row"><label><?= te('Date') ?></label><input type="date" name="date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required>
    <label><?= te('What it records') ?></label><input name="memo" required minlength="3" maxlength="500"></div>
  <datalist id="ledger-projects"><?php foreach ($projects as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?></datalist>
  <table><tr><th><?= te('Account') ?></th><th><?= te('Debit') ?></th><th><?= te('Credit') ?></th><th><?= te('Name') ?></th><th><?= te('Class') ?></th></tr>
  <?php for ($i = 0; $i < 6; $i++): ?>
    <tr><td><select name="account[<?= $i ?>]"><option value=""></option><?php foreach ($manualAccounts as $k => $a): ?><option value="<?= e($k) ?>"><?= e($acctName($k)) ?></option><?php endforeach; ?></select></td>
      <td><input name="debit[<?= $i ?>]" inputmode="decimal" size="10"></td><td><input name="credit[<?= $i ?>]" inputmode="decimal" size="10"></td>
      <td><input name="party[<?= $i ?>]" maxlength="190"></td><td><input name="class[<?= $i ?>]" maxlength="190" list="ledger-projects"></td></tr>
  <?php endfor; ?></table>
  <button class="btn"><?= te('Draft the journal') ?></button>
</form>

<div class="card scroll" id="ledger-journals"><h2><?= te('Posted journals') ?></h2>
  <?php if (! $journals): ?><p class="muted"><?= te('Nothing posted yet.') ?></p><?php else: ?>
  <table><tr><th>#</th><th><?= te('Posted') ?></th><th><?= te('Kind') ?></th><th><?= te('Memo') ?></th><th><?= te('Lines') ?></th><th></th></tr>
  <?php foreach ($journals as $j): ?>
    <tr data-journal="<?= (int) $j['id'] ?>" data-kind="<?= e($j['kind']) ?>" data-source="<?= e((string) $j['source']) ?>" data-posted="<?= e((string) $j['posted_on']) ?>" data-doc="<?= e((string) $j['doc_date']) ?>" data-reversed="<?= (int) $j['reversed_by_id'] ?>">
      <td class="mono"><?= (int) $j['id'] ?></td>
      <td><?= e(d((string) $j['posted_on'])) ?><?= $j['doc_date'] !== $j['posted_on'] ? '<div class="small muted">' . te('dated :d, a month already closed', ['d' => d((string) $j['doc_date'])]) . '</div>' : '' ?></td>
      <td><?= e($kinds[$j['kind']]) ?><?= $j['kind'] !== 'source' ? '<div class="small muted">' . e(trim(($j['by_name'] ?? '') . ' / ' . ($j['approved_name'] ?? ''), ' /')) . '</div>' : '' ?></td>
      <td class="small"><?= e($j['memo']) ?><?= $j['reason'] ? '<div class="muted">' . e($j['reason']) . '</div>' : '' ?><?= $j['reversed_by_id'] ? '<div><span class="tag grey">' . te('Reversed by :n', ['n' => (int) $j['reversed_by_id']]) . '</span></div>' : '' ?></td>
      <td><?= $linesTable($lines[(int) $j['id']] ?? []) ?></td>
      <td><?php if ($j['kind'] === 'manual' && ! $j['reversed_by_id']): ?>
        <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="reverse"><input type="hidden" name="journal_id" value="<?= (int) $j['id'] ?>">
          <input type="date" name="date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>"><input name="reason" placeholder="<?= te('Why it is reversed') ?>" required minlength="3" maxlength="500"><button class="btn sm ghost"><?= te('Reverse') ?></button></form>
      <?php endif; ?><span class="small muted mono" title="SHA-256"><?= e(substr((string) $j['hash'], 0, 12)) ?></span></td></tr>
  <?php endforeach; ?></table><?php endif; ?>
</div>

<div class="card scroll" id="chart"><h2><?= te('Chart of accounts') ?></h2>
  <table><tr><th><?= te('Number') ?></th><th><?= te('Account') ?></th><th><?= te('Kind') ?></th><th><?= te('QuickBooks account') ?></th><th><?= te('State') ?></th></tr>
  <?php foreach ($accounts as $k => $a): ?>
    <tr data-account="<?= e($k) ?>" data-active="<?= (int) $a['is_active'] ?>"><td class="mono"><?= e((string) $a['number']) ?></td><td><a href="/ledger?account=<?= e(rawurlencode($k)) ?>#account-ledger"><?= te($a['label']) ?></a></td>
      <td><?= e($sides[$a['side']] ?? $a['side']) ?></td><td><?= e($a['qb_account']) ?><?= (int) $a['confirmed'] === 1 ? '' : ' <span class="tag amber">' . te('not checked') . '</span>' ?></td>
      <td><?php if ((int) $a['is_system'] === 1): ?><span class="small muted"><?= te('Built in: stays active') ?></span>
        <?php elseif (can('admin')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="account_active"><input type="hidden" name="account" value="<?= e($k) ?>"><input type="hidden" name="active" value="<?= (int) $a['is_active'] === 1 ? '0' : '1' ?>">
          <button class="btn sm ghost"><?= (int) $a['is_active'] === 1 ? te('Switch off') : te('Switch on') ?></button></form>
        <?php else: ?><?= (int) $a['is_active'] === 1 ? te('Active') : te('Off') ?><?php endif; ?></td></tr>
  <?php endforeach; ?></table>
  <?php if (can('admin')): ?>
  <form method="post" class="row" id="account-form"><?= csrf_field() ?><input type="hidden" name="do" value="account_add">
    <input name="number" placeholder="<?= te('Number') ?>" required pattern="\d{4,6}" size="7"><input name="label" placeholder="<?= te('Name') ?>" required minlength="3" maxlength="120">
    <select name="side"><?php foreach ($sides as $k => $s): ?><option value="<?= e($k) ?>"><?= e($s) ?></option><?php endforeach; ?></select>
    <button class="btn"><?= te('Add the account') ?></button></form>
  <p class="small muted"><?= te('A new account is mapped to QuickBooks on the QuickBooks export page before a journal using it can be exported.') ?></p>
  <?php endif; ?>
</div>
