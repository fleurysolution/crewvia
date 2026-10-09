<?php
/**
 * Advances against wages.
 *
 * What somebody already owes sits beside their name in the list you
 * choose from, because that is the number to read before agreeing
 * another one.
 */

require_once __DIR__ . '/../hr.php';

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

    <div class="field" style="margin-top:12px">
      <label for="a-reason"><?= te('Why') ?></label>
      <input id="a-reason" name="reason" maxlength="500"
             placeholder="<?= te('e.g. Travelled in Sunday, first cheque is Friday week.') ?>">
    </div>

    <button class="btn" type="submit" style="margin-top:14px"><?= te('Record the advance') ?></button>
  <?php endif; ?>
</form>
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
          <?= te('Advance of :amount', ['amount' => money((float) $a['amount'])]) ?>
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
        <?php endif; ?>
      </div>
    <?php endif; ?>

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
