<?php
/**
 * Time-off requests.
 *
 * The form asked for a free-text "Type", so "Vacation", "vacation" and
 * "PTO" were three different kinds of leave and nobody could answer how
 * many days somebody had left. It picks from the catalogue now, with
 * the balance beside each choice.
 *
 * The list printed raw dates, the raw type and the raw status, the way
 * several screens here used to.
 */

require_once __DIR__ . '/../hr.php';

$kinds = leave_types(true);

$statusWord = ['pending' => 'Waiting on a decision', 'approved' => 'Approved',
               'rejected' => 'Refused', 'cancelled' => 'Cancelled'];

$statusTone = static fn (string $s): string => match ($s) {
    'approved'  => 'green',
    'rejected'  => 'red',
    'cancelled' => 'grey',
    default     => 'amber',
};

/** Inclusive of both ends: Monday to Friday is five days, not four. */
$days = static fn (array $r): int =>
    (int) ((strtotime((string) $r['ends_on']) - strtotime((string) $r['starts_on'])) / 86400) + 1;
?>

<div class="page-heading">
  <div>
    <span class="eyebrow"><?= te('RUNNING THE JOB') ?></span>
    <h1><?= te('Time off') ?></h1>
    <p class="sub">
      <?= $isWorker
          ? te('Ask before you go. Your supervisor sees it and decides.')
          : te('What the crew has asked for, and what is still waiting on a decision.') ?>
    </p>
  </div>
</div>

<?php if ($isWorker): ?>
  <?php if ($balances): ?>
    <div class="grid g4" style="margin-bottom:18px">
      <?php foreach ($balances as $slug => $b): ?>
        <div class="stat">
          <div class="n"><?= $b['left'] === null ? '&infin;' : (int) $b['left'] ?></div>
          <div class="l"><?= te((string) $b['label']) ?></div>
          <div class="h">
            <?php if ($b['allowed'] === null): ?>
              <?= te('no yearly limit') ?>
            <?php else: ?>
              <?= te(':taken of :allowed days used', [
                  'taken' => (int) $b['taken'], 'allowed' => (int) $b['allowed']]) ?>
            <?php endif; ?>
            <?= (int) $b['is_paid'] ? '' : ' · ' . te('unpaid') ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (! $assignments): ?>
    <div class="card"><div class="empty">
      <?= te('You are not on an assignment, so there is nothing to ask time off from.') ?>
    </div></div>
  <?php else: ?>
    <form class="card" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="request">
      <h2><?= te('Ask for time off') ?></h2>

      <div class="row">
        <div style="flex:2">
          <label for="t-job"><?= te('Assignment') ?></label>
          <select id="t-job" name="placement_id" required>
            <?php foreach ($assignments as $a): ?>
              <option value="<?= (int) $a['id'] ?>"><?= e($a['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="t-from"><?= te('From') ?></label>
          <input id="t-from" type="date" name="starts_on" required>
        </div>
        <div>
          <label for="t-to"><?= te('Through') ?></label>
          <input id="t-to" type="date" name="ends_on" required>
        </div>
      </div>

      <div class="row" style="margin-top:12px">
        <div style="flex:2">
          <label for="t-kind"><?= te('What kind') ?></label>
          <select id="t-kind" name="leave_type" required>
            <option value=""><?= te('Choose…') ?></option>
            <?php foreach ($balances as $slug => $b): ?>
              <option value="<?= e($slug) ?>">
                <?= te((string) $b['label']) ?>
                <?php if ($b['left'] !== null): ?>
                  &nbsp;— <?= te(':n days left', ['n' => (int) $b['left']]) ?>
                <?php endif; ?>
              </option>
            <?php endforeach; ?>
          </select>
          <span class="hint">
            <?= te('Asking for more days than the allowance has left is refused here, not after you have made plans.') ?>
          </span>
        </div>
      </div>

      <div class="field" style="margin-top:12px">
        <label for="t-why"><?= te('Reason (optional)') ?></label>
        <textarea id="t-why" name="reason" rows="3" maxlength="2000"></textarea>
      </div>

      <button class="btn" type="submit" style="margin-top:12px"><?= te('Request approval') ?></button>
    </form>
  <?php endif; ?>
<?php endif; ?>

<?php if (! $requests): ?>
  <div class="card"><div class="empty">
    <?= $isWorker
        ? te('You have not asked for any time off.')
        : te('Nobody on this project has asked for time off.') ?>
  </div></div>
<?php else: ?>
  <?php $waiting = array_filter($requests, static fn ($r) => $r['status'] === 'pending'); ?>
  <div class="card tight">
    <div style="padding:14px 18px;border-bottom:1px solid var(--line)">
      <h2 style="margin:0">
        <?= $waiting
            ? te(':n waiting on a decision', ['n' => count($waiting)])
            : te('Nothing is waiting on a decision') ?>
      </h2>
    </div>
    <div class="scroll">
      <table class="wide">
        <thead><tr>
          <?php if (! $isWorker): ?><th><?= te('Person') ?></th><?php endif; ?>
          <th><?= te('Project') ?></th><th><?= te('When') ?></th>
          <th class="num"><?= te('Days') ?></th><th><?= te('Kind') ?></th>
          <th><?= te('State') ?></th>
          <?php if (! $isWorker): ?><th class="right nowrap"><?= te('Decide') ?></th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
          <tr>
            <?php if (! $isWorker): ?>
              <td><strong><?= e($r['full_name'] ?? '') ?></strong></td>
            <?php endif; ?>
            <td class="small"><?= e($r['project']) ?></td>
            <td class="small"><?= e(d($r['starts_on'])) ?> &rarr; <?= e(d($r['ends_on'])) ?></td>
            <td class="num mono"><?= $days($r) ?></td>
            <td class="small">
              <?php $slug = (string) ($r['leave_type'] ?? ''); ?>
              <?= e($slug !== '' && isset($kinds[$slug])
                    ? t((string) $kinds[$slug]['label'])
                    : ($r['request_type'] ?: '—')) ?>
              <?php if ($slug === ''): ?>
                <div class="muted small"><?= te('typed before the list existed') ?></div>
              <?php endif; ?>
            </td>
            <td>
              <span class="tag <?= $statusTone((string) $r['status']) ?>">
                <?= te($statusWord[$r['status']] ?? $r['status']) ?>
              </span>
              <?php if (! empty($r['review_note'])): ?>
                <div class="muted small"><?= e($r['review_note']) ?></div>
              <?php endif; ?>
            </td>
            <?php if (! $isWorker): ?>
              <td class="right nowrap">
                <?php if ($r['status'] === 'pending'): ?>
                  <form class="row" method="post" style="gap:8px;align-items:flex-end;margin:0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="review">
                    <input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
                    <select name="status" class="move-select">
                      <option value="approved"><?= te('Approve') ?></option>
                      <option value="rejected"><?= te('Refuse') ?></option>
                    </select>
                    <input name="review_note" maxlength="500"
                           placeholder="<?= te('Why (optional)') ?>">
                    <button class="btn sm" type="submit"><?= te('Decide') ?></button>
                  </form>
                <?php else: ?>
                  <span class="muted small"><?= te('settled') ?></span>
                <?php endif; ?>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
