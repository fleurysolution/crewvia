<?php
$subjects = approval_subjects();

$subjectWord = [
    'requisition' => 'Client order',
    'offer'       => 'Offer',
    'placement'   => 'Deployment',
    'timesheet'   => 'Week of hours',
    'expense'     => 'Reimbursement',
];
?>

<div class="page-heading">
  <div>
    <span class="eyebrow"><?= te('APPROVALS') ?></span>
    <h1>
      <?= $mine
          ? te(':n waiting on your decision', ['n' => count($mine)])
          : te('Nothing is waiting on your decision.') ?>
    </h1>
    <p class="sub">
      <?= te('What is waiting on you, and what you decide about it. A step opens only once the steps before it are settled, and one rejection closes the rest.') ?>
    </p>
  </div>
</div>

<section class="card tight">
  <div style="padding:14px 18px;border-bottom:1px solid var(--line)">
    <h2 style="margin:0"><?= te('Your decisions') ?></h2>
  </div>

  <?php if (! $mine): ?>
    <div class="empty">
      <?= te('Nothing is waiting on your decision. Anything submitted to a chain you sit on appears here.') ?>
    </div>
  <?php endif; ?>

  <?php foreach ($mine as $r): ?>
    <div class="decision">
      <div class="decision-what">
        <span class="tag blue"><?= te($subjectWord[$r['subject_type']] ?? $r['subject_type']) ?></span>
        <strong><?= e($r['subject_label']) ?></strong>
        <div class="muted small">
          <?= te('Step :n', ['n' => (int) $r['step_order']]) ?> &middot; <?= te($r['label']) ?>
          &middot; <?= te($r['gate_type'] === 'parallel' ? 'Can be decided at any time' : 'In order') ?>
          <?php if (! (int) $r['is_required']): ?>
            &middot; <?= te('optional') ?>
          <?php endif; ?>
        </div>
      </div>

      <form method="post" class="decision-form">
        <?= csrf_field() ?>
        <input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
        <label class="sr-only" for="c-<?= (int) $r['id'] ?>"><?= te('Comment') ?></label>
        <input id="c-<?= (int) $r['id'] ?>" name="comments" maxlength="500"
               placeholder="<?= te('Why (optional)') ?>">
        <button class="btn" type="submit" name="decision" value="approved"><?= te('Approve') ?></button>
        <button class="btn ghost" type="submit" name="decision" value="rejected"><?= te('Reject') ?></button>
      </form>
    </div>
  <?php endforeach; ?>
</section>

<section class="card tight">
  <div style="padding:14px 18px;border-bottom:1px solid var(--line)">
    <h2 style="margin:0"><?= te('Everything in the chain') ?></h2>
  </div>

  <?php if (! $inFlight): ?>
    <div class="empty">
      <?= te('Nothing has been submitted for approval yet.') ?>
      <?php if (can('admin')): ?>
        <div class="hint" style="margin-top:8px">
          <?= te('Who is asked, and in what order, is set up under Administration.') ?>
          <a href="/approval-chains"><?= te('Approval chains') ?></a>
        </div>
      <?php endif; ?>
    </div>
  <?php else: ?>
  <div class="scroll">
    <table>
      <thead><tr>
        <th><?= te('What') ?></th><th><?= te('Kind') ?></th>
        <th><?= te('Progress') ?></th><th><?= te('State') ?></th><th><?= te('Submitted') ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($inFlight as $f): ?>
        <?php
        $steps    = max(1, (int) $f['steps']);
        $done     = (int) $f['approved'];
        $rejected = (int) $f['rejected'] > 0;
        $pending  = (int) $f['pending'] > 0;
        ?>
        <tr>
          <td><strong><?= e($f['subject_label']) ?></strong></td>
          <td class="small muted"><?= te($subjectWord[$f['subject_type']] ?? $f['subject_type']) ?></td>
          <td class="small">
            <?= te(':done of :steps approved', ['done' => $done, 'steps' => $steps]) ?>
            <div class="headcount-bar" style="margin-top:5px;max-width:160px">
              <span style="width:<?= (int) round($done / $steps * 100) ?>%"></span>
            </div>
          </td>
          <td>
            <?php if ($rejected): ?>
              <span class="tag red"><?= te('Rejected') ?></span>
            <?php elseif ($pending): ?>
              <span class="tag amber"><?= te('Waiting') ?></span>
            <?php else: ?>
              <span class="tag green"><?= te('Approved') ?></span>
            <?php endif; ?>
          </td>
          <td class="small muted"><?= e(date('j M, H:i', strtotime($f['started']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<?php if ($decided): ?>
<section class="card">
  <span class="eyebrow"><?= te('RECORD') ?></span>
  <h2><?= te('Recent decisions') ?></h2>
  <table>
    <thead><tr>
      <th><?= te('When') ?></th><th><?= te('Who') ?></th>
      <th><?= te('Step') ?></th><th><?= te('What') ?></th><th><?= te('Decision') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($decided as $d): ?>
      <tr>
        <td class="small mono"><?= e(date('j M, H:i', strtotime($d['decided_at']))) ?></td>
        <td class="small"><?= e($d['decided_name'] ?? t('Removed account')) ?></td>
        <td class="small"><?= te($d['label']) ?></td>
        <td class="small"><?= e($d['subject_label']) ?></td>
        <td>
          <span class="tag <?= $d['status'] === 'approved' ? 'green' : 'red' ?>">
            <?= te($d['status'] === 'approved' ? 'Approved' : 'Rejected') ?>
          </span>
          <?php if ($d['comments']): ?>
            <div class="muted small"><?= e($d['comments']) ?></div>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>
