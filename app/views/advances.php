<?php
/**
 * Advances against wages.
 *
 * What somebody already owes sits beside their name in the list you
 * choose from, because that is the number to read before agreeing
 * another one.
 */

require_once __DIR__ . '/../hr.php';
require_once __DIR__ . '/../loans.php';

$states = advance_states();
?>

<div class="page-heading">
  <div>
    <span class="eyebrow"><?= te('PAY AND BILLING') ?></span>
    <h1><?= te('Advances against wages') ?></h1>
    <p class="sub">
      <?= te('Money handed over before it is earned, repaid out of later weeks. Somebody flying in on Monday often needs it before the first cheque.') ?>
    </p>
  </div>
  <?php if ($outstanding > 0): ?>
    <div class="stat" style="min-width:180px">
      <div class="n"><?= e(money($outstanding)) ?></div>
      <div class="l"><?= te('Still owed to the agency') ?></div>
    </div>
  <?php endif; ?>
</div>

<!-- ── agreeing a new one ─────────────────────────────────────────────── -->
<?php if (can('payroll') || can('recruiter')): ?>
<form class="card" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="request">
  <h2><?= te('Record a new advance') ?></h2>

  <?php if (! $people): ?>
    <div class="empty">
      <?= te('Nobody is on an assignment yet, so there is nobody to advance money to.') ?>
    </div>
  <?php else: ?>
    <div class="row">
      <div style="flex:2">
        <label for="a-who"><?= te('Who') ?></label>
        <select id="a-who" name="candidate_id" required>
          <option value=""><?= te('Choose somebody…') ?></option>
          <?php foreach ($people as $person): ?>
            <option value="<?= (int) $person['id'] ?>">
              <?= e($person['full_name']) ?><?php if ($person['owed'] > 0): ?>
                &nbsp;— <?= te('already owes :amount', ['amount' => money((float) $person['owed'])]) ?>
              <?php endif; ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="hint"><?= te('What they already owe is shown beside the name. Read it before agreeing another.') ?></span>
      </div>
      <div>
        <label for="a-amount"><?= te('Amount') ?></label>
        <input id="a-amount" name="amount" type="number" step="0.01" min="0.01" required
               placeholder="500.00">
      </div>
      <div>
        <label for="a-weekly"><?= te('Taken back each week') ?></label>
        <input id="a-weekly" name="weekly_repayment" type="number" step="0.01" min="0.01" required
               placeholder="100.00">
      </div>
    </div>

    <div class="row" style="margin-top:12px">
      <div>
        <label for="a-kind"><?= te('Kind') ?></label>
        <select id="a-kind" name="kind"><?php foreach (loan_kinds() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
      </div>
      <div>
        <label for="a-first"><?= te('Repayment starts the week of') ?></label>
        <input id="a-first" name="first_week" type="date" min="<?= e(date('Y-m-d')) ?>">
        <span class="hint"><?= te('Empty: the week it is paid out.') ?></span>
      </div>
    </div>
    <p class="small muted"><?= te('Above :limit, or when it takes what the person owes above it, an administrator approves. Whoever records it never approves it.', ['limit' => money($limit)]) ?></p>

    <div class="field" style="margin-top:12px">
      <label for="a-reason"><?= te('Why') ?></label>
      <input id="a-reason" name="reason" maxlength="500"
             placeholder="<?= te('e.g. Travelled in Sunday, first cheque is Friday week.') ?>">
    </div>

    <button class="btn" type="submit" style="margin-top:14px"><?= te('Record the advance') ?></button>
  <?php endif; ?>
</form>
<?php endif; ?>

<!-- ── outstanding balances (P2-M05) ──────────────────────────────────── -->
<?php $open = array_values(array_filter($advances, fn($a) => $a['status'] === 'paid_out')); ?>
<?php if ($open): ?>
<div class="card scroll" id="outstanding"><h2><?= te('Outstanding') ?></h2>
  <table><tr><th><?= te('Who') ?></th><th><?= te('Kind') ?></th><th class="num"><?= te('Owed') ?></th><th class="num"><?= te('A week') ?></th><th class="num"><?= te('Weeks left') ?></th><th><?= te('Schedule') ?></th></tr>
  <?php foreach ($open as $a): $s = $a['schedule']; $pausedNow = advance_paused_on($a['pauses'], week_ending(date('Y-m-d'))); ?>
    <tr data-outstanding="<?= (int) $a['id'] ?>" data-behind="<?= e(number_format($s['behind'], 2, '.', '')) ?>"><td><?= e($a['full_name']) ?></td><td><?= e(loan_kinds()[$a['kind'] ?? 'advance']) ?></td>
      <td class="num mono"><?= e(money($a['balance'])) ?></td><td class="num mono"><?= e(money($a['weekly_repayment'])) ?></td><td class="num mono"><?= (int) $s['weeks_left'] ?></td>
      <td><?= $pausedNow ? '<span class="tag grey">' . te('Paused until :date', ['date' => d((string) $pausedNow['until_week'])]) . '</span>' : ($s['behind'] > 0 ? '<span class="tag red">' . te(':amount behind', ['amount' => money($s['behind'])]) . '</span>' : '<span class="tag green">' . te('On schedule') . '</span>') ?></td></tr>
  <?php endforeach; ?></table>
</div>
<?php endif; ?>

<!-- ── what is in flight ──────────────────────────────────────────────── -->
<?php if (! $advances): ?>
  <div class="card"><div class="empty">
    <?= te('No advances on record.') ?>
  </div></div>
<?php endif; ?>

<?php foreach ($advances as $a): ?>
  <article class="card">
    <div class="page-heading" style="margin:0 0 10px">
      <div>
        <h2 style="margin:0">
          <a href="/candidates/<?= (int) $a['candidate_id'] ?>"><?= e($a['full_name']) ?></a>
        </h2>
        <p class="small muted" style="margin:4px 0 0">
          <?= e(loan_kinds()[$a['kind'] ?? 'advance']) ?> · <?= e(money((float) $a['amount'])) ?>
          <?php if (advance_first_week($a)): ?> &middot; <?= te('repaid from the week ending :date', ['date' => d((string) advance_first_week($a))]) ?><?php endif; ?>
          &middot; <?= te(':amount a week', ['amount' => money((float) $a['weekly_repayment'])]) ?>
          <?php if ($a['project']): ?> &middot; <?= e($a['project']) ?><?php endif; ?>
          <?php if ($a['paid_out_on']): ?>
            &middot; <?= te('paid out :when', ['when' => d($a['paid_out_on'])]) ?>
          <?php endif; ?>
        </p>
        <?php if ($a['reason']): ?>
          <p class="small" style="margin:6px 0 0"><?= e($a['reason']) ?></p>
        <?php endif; ?>
      </div>
      <div class="heading-actions">
        <span class="tag <?= advance_tone((string) $a['status']) ?>">
          <?= te($states[$a['status']] ?? $a['status']) ?>
        </span>
        <?php if ($a['status'] === 'paid_out'): ?>
          <span class="tag <?= $a['balance'] > 0 ? 'amber' : 'green' ?>">
            <?= te(':amount still owed', ['amount' => money((float) $a['balance'])]) ?>
          </span>
        <?php endif; ?>
      </div>
    </div>

    <?php if (can('payroll')): ?>
      <div class="row" style="align-items:flex-end;gap:10px;flex-wrap:wrap">
        <?php if ($a['needs_admin'] && ! can('admin')): ?><span class="small muted"><?= te('Above the limit: an administrator approves.') ?></span><?php endif; ?>
        <?php if (in_array($a['status'], ['requested'], true)): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="approve">
            <input type="hidden" name="advance_id" value="<?= (int) $a['id'] ?>">
            <button class="btn" type="submit"><?= te('Approve') ?></button>
          </form>
        <?php endif; ?>

        <?php if (in_array($a['status'], ['requested', 'approved'], true)): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="cancel">
            <input type="hidden" name="advance_id" value="<?= (int) $a['id'] ?>">
            <button class="btn ghost" type="submit"><?= te('Cancel') ?></button>
          </form>
        <?php endif; ?>

        <?php if ($a['status'] === 'approved'): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="pay_out">
            <input type="hidden" name="advance_id" value="<?= (int) $a['id'] ?>">
            <button class="btn" type="submit"><?= te('Mark paid out') ?></button>
          </form>
        <?php endif; ?>

        <?php if ($a['status'] === 'paid_out'): ?>
          <form method="post" class="row" style="gap:10px;align-items:flex-end;margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="repay">
            <input type="hidden" name="advance_id" value="<?= (int) $a['id'] ?>">
            <div style="max-width:150px">
              <label for="r-<?= (int) $a['id'] ?>"><?= te('Taken back') ?></label>
              <input id="r-<?= (int) $a['id'] ?>" name="amount" type="number" step="0.01"
                     min="0.01" max="<?= e((float) $a['balance']) ?>" required
                     value="<?= e(min((float) $a['weekly_repayment'], (float) $a['balance'])) ?>">
            </div>
            <div style="max-width:170px">
              <label for="rd-<?= (int) $a['id'] ?>"><?= te('On') ?></label>
              <input id="rd-<?= (int) $a['id'] ?>" name="paid_on" type="date"
                     value="<?= e(date('Y-m-d')) ?>">
            </div>
            <div style="flex:2">
              <label for="rn-<?= (int) $a['id'] ?>"><?= te('Note') ?></label>
              <input id="rn-<?= (int) $a['id'] ?>" name="note" maxlength="255"
                     placeholder="<?= te('e.g. Week ending 17 Oct') ?>">
            </div>
            <button class="btn" type="submit"><?= te('Record repayment') ?></button>
          </form>
          <form method="post" class="row" style="gap:10px;align-items:flex-end;margin:0">
            <?= csrf_field() ?><input type="hidden" name="do" value="pause"><input type="hidden" name="advance_id" value="<?= (int) $a['id'] ?>">
            <div><label><?= te('Pause from the week of') ?></label><input name="from_week" type="date" required min="<?= e(date('Y-m-d')) ?>"></div>
            <div><label><?= te('until the week of') ?></label><input name="until_week" type="date" required></div>
            <div style="flex:2"><label><?= te('Why') ?></label><input name="reason" required minlength="3" maxlength="500" placeholder="<?= te('e.g. No hours while the plant is down') ?>"></div>
            <button class="btn ghost" type="submit"><?= te('Pause repayment') ?></button>
          </form>
          <?php if (can('admin')): ?>
          <form method="post" class="row" style="gap:10px;align-items:flex-end;margin:0">
            <?= csrf_field() ?><input type="hidden" name="do" value="write_off"><input type="hidden" name="advance_id" value="<?= (int) $a['id'] ?>">
            <div style="flex:2"><label><?= te('Write off what is left') ?></label><input name="reason" required minlength="3" maxlength="500" placeholder="<?= te('Why it will not be recovered') ?>"></div>
            <button class="btn ghost" type="submit"><?= te('Write off') ?></button>
          </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($a['status'] === 'written_off'): ?><p class="small"><span class="tag grey"><?= te('Written off: :amount', ['amount' => money((float) $a['written_off_amount'])]) ?></span> <?= e((string) $a['written_off_reason']) ?></p><?php endif; ?>
    <?php if ($a['schedule']['rows'] && in_array($a['status'], ['paid_out', 'cleared', 'written_off'], true)): ?>
      <details style="margin-top:12px" data-schedule="<?= (int) $a['id'] ?>"><summary><?= te('Schedule: :n installment(s) · :repaid of :amount repaid', ['n' => count(array_filter($a['schedule']['rows'], fn($r) => $r['planned'] > 0)), 'repaid' => money($a['schedule']['repaid']), 'amount' => money((float) $a['amount'])]) ?></summary>
        <div class="scroll"><table><tr><th><?= te('Week ending') ?></th><th class="num"><?= te('Planned') ?></th><th class="num"><?= te('Taken') ?></th><th class="num"><?= te('Balance') ?></th><th></th></tr>
        <?php foreach ($a['schedule']['rows'] as $r): ?><tr data-week="<?= e($r['week']) ?>" data-state="<?= e($r['state']) ?>"><td><?= e(d($r['week'])) ?></td><td class="num mono"><?= $r['planned'] > 0 ? e(money($r['planned'])) : '' ?></td><td class="num mono"><?= $r['taken'] > 0 ? e(money($r['taken'])) : '' ?></td><td class="num mono"><?= e(money($r['balance'])) ?></td>
          <td class="small"><?= e(['paused' => t('Paused'), 'coming' => t('Coming'), 'taken' => t('Taken'), 'short' => t('Short')][$r['state']]) ?></td></tr><?php endforeach; ?></table></div>
      </details>
    <?php endif; ?>
    <?php if ($a['events']): ?><div class="small muted" style="margin-top:8px"><?php foreach ($a['events'] as $ev): ?><div><?= e(d(substr((string) $ev['created_at'], 0, 10))) ?> · <?= e(['requested' => t('Recorded'), 'approved' => t('Approved'), 'cancelled' => t('Cancelled'), 'paid_out' => t('Paid out'), 'paused' => t('Paused'), 'written_off' => t('Written off'), 'repaid_by_hand' => t('Repaid by hand')][$ev['event']] ?? $ev['event']) ?><?= $ev['detail'] ? ' · ' . e($ev['detail']) : '' ?> · <?= e($ev['by_name'] ?? '') ?></div><?php endforeach; ?></div><?php endif; ?>

    <?php if ($a['payments']): ?>
      <h3 style="margin:16px 0 8px"><?= te('Repaid so far') ?></h3>
      <div class="scroll">
        <table>
          <thead><tr>
            <th><?= te('When') ?></th><th class="num"><?= te('Amount') ?></th>
            <th><?= te('Note') ?></th><th><?= te('Recorded by') ?></th>
          </tr></thead>
          <tbody>
          <?php foreach ($a['payments'] as $p): ?>
            <tr>
              <td class="small"><?= e(d($p['paid_on'])) ?></td>
              <td class="num mono"><?= e(money((float) $p['amount'])) ?></td>
              <td class="small muted"><?= e($p['note'] ?: '—') ?></td>
              <td class="small muted"><?= e($p['who'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </article>
<?php endforeach; ?>
