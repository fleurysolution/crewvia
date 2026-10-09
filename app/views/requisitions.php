<div class="page-heading">
  <div>
    <span class="eyebrow"><?= te('RECRUITMENT / REQUISITIONS') ?></span>
    <h1><?= te('Open the next opportunity.') ?></h1>
    <p class="sub"><?= te('One requisition. One scannable application QR. Every candidate arrives in your workspace.') ?></p>
  </div>
  <a class="btn ghost" href="/recruitment"><?= te('Review applications') ?></a>
</div>

<div class="grid g2">
  <form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create">
    <h2><?= te('Create a requisition') ?></h2>
    <p class="hint" style="margin:-2px 0 18px">
      <?php if (! empty($job['title'])): ?>
        <?= te('For') ?> <strong><?= e($job['title']) ?></strong>
      <?php else: ?>
        <span style="color:var(--amber)"><?= te('Choose a working project first — use the selector above.') ?></span>
      <?php endif; ?>
    </p>

    <?php if (! empty($job['id'])): ?>
      <?php if (! $scope): ?>
        <p class="small" style="color:var(--amber);margin:-6px 0 18px">
          <?= te('This project has no scope of work yet, so there is nothing to raise a requisition against and no agreed rate to inherit.') ?>
          <a href="/job#scope"><?= te('Write the scope of work') ?></a>
        </p>
      <?php elseif (! array_filter($scope, static fn ($l) => (int) $l['remaining'] > 0)): ?>
        <p class="small" style="color:var(--amber);margin:-6px 0 18px">
          <?= te('Every trade on this agreement is already fully covered by requisitions, so there is nothing left to raise.') ?>
          <a href="/job#scope"><?= te('Raise a quantity on the scope') ?></a>
          <?= te('or close a requisition below that is no longer being recruited.') ?>
        </p>
      <?php else: ?>
        <div class="field" style="margin-bottom:18px">
          <label for="r-line"><?= te('Line of the scope of work') ?></label>
          <select id="r-line" name="order_line_id" required>
            <option value=""><?= te('Choose what the agreement covers…') ?></option>
            <?php foreach ($scope as $line): ?>
              <option value="<?= (int) $line['id'] ?>" <?= (int) $line['remaining'] === 0 ? 'disabled' : '' ?>>
                <?= e($line['role_title']) ?>
                &mdash; <?= te(':left of :asked still to order', [
                    'left' => (int) $line['remaining'], 'asked' => (int) $line['quantity']]) ?>
                <?php if ($line['pay_rate'] !== null): ?>
                  &middot; <?= e(money((float) $line['pay_rate'])) ?><?= te('/h') ?>
                <?php else: ?>
                  &middot; <?= te('not priced') ?>
                <?php endif; ?>
              </option>
            <?php endforeach; ?>
          </select>
          <span class="hint">
            <?= te('The requisition inherits this line\'s agreed pay, bill, per diem and guarantee. The agency cannot order more of a trade than the agreement covers.') ?>
          </span>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <div class="row">
      <div style="flex:2">
        <label for="r-title"><?= te('Role / trade') ?></label>
        <input id="r-title" name="title" required maxlength="190" placeholder="<?= te('e.g. Mechanical engineer') ?>">
      </div>
      <div>
        <label for="r-disc"><?= te('Discipline') ?></label>
        <select id="r-disc" name="discipline">
          <?php foreach (disciplines() as $k => $v): ?>
            <option value="<?= e($k) ?>"><?= te($v) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint"><?= te('Used to match the candidate pool.') ?></span>
      </div>
    </div>

    <div class="row" style="margin-top:16px">
      <div>
        <label for="r-open"><?= te('How many needed') ?></label>
        <input id="r-open" name="openings" type="number" min="1" max="9999" value="1" required>
      </div>
      <div>
        <label for="r-start"><?= te('Starts') ?></label>
        <input id="r-start" type="date" name="starts_on"
               value="<?= e($job['starts_on'] ?? '') ?>">
      </div>
      <div style="flex:2">
        <label for="r-shift"><?= te('Shift') ?></label>
        <input id="r-shift" name="shift" maxlength="60" placeholder="<?= te('e.g. Days, 12 hours, 6 on 1 off') ?>">
      </div>
    </div>

    <div class="row" style="margin-top:16px">
      <div style="flex:2">
        <label for="r-degree"><?= te('Degree required') ?></label>
        <input id="r-degree" name="degree" maxlength="190" placeholder="<?= te('e.g. BSc Mechanical or Chemical Engineering') ?>">
      </div>
      <div>
        <label for="r-years"><?= te('Years of experience') ?></label>
        <input id="r-years" name="years_experience" type="number" min="0" max="60" placeholder="<?= te('Minimum') ?>">
      </div>
    </div>

    <div class="field" style="margin-top:16px">
      <label for="r-req"><?= te('Must-haves') ?></label>
      <textarea id="r-req" name="requirements" maxlength="4000" rows="3"
                placeholder="<?= te('Certifications, clearances, licences — one per line') ?>"></textarea>
      <span class="hint"><?= te('What disqualifies somebody if it is missing. Screened before an offer.') ?></span>
    </div>

    <h3 style="margin:22px 0 4px"><?= te('Override the agreed rates') ?></h3>
    <p class="hint" style="margin:0 0 14px">
      <?= te('Leave these blank — and almost always do. The rates were settled with the client on the line of the scope above, and the requisition inherits them; a number retyped here is how the order and the agreement start disagreeing. Fill one in only when this particular requisition really was agreed differently.') ?>
    </p>

    <div class="row">
      <div>
        <label for="r-pay"><?= te('Pay rate (per hour)') ?></label>
        <input id="r-pay" name="pay_rate" type="number" min="0" step="0.01"
               placeholder="<?= te('from the line') ?>">
        <span class="hint"><?= te('What the worker earns') ?></span>
      </div>
      <div>
        <label for="r-bill"><?= te('Bill rate (per hour)') ?></label>
        <input id="r-bill" name="bill_rate" type="number" min="0" step="0.01"
               placeholder="<?= te('from the line') ?>">
        <span class="hint"><?= te('What the client is charged for this role') ?></span>
      </div>
      <div>
        <label for="r-diem"><?= te('Per diem (per day)') ?></label>
        <input id="r-diem" name="per_diem_rate" type="number" min="0" step="0.01"
               placeholder="<?= te('from the line') ?>">
      </div>
      <div>
        <label for="r-guar"><?= te('Guarantee (hours)') ?></label>
        <input id="r-guar" name="guarantee_hours" type="number" min="0" max="168"
               placeholder="<?= te('from the line') ?>">
      </div>
    </div>

    <div class="field">
      <label for="r-desc"><?= te('Job description shown to candidates') ?></label>
      <textarea id="r-desc" name="description" required maxlength="10000" rows="6"
                placeholder="<?= te('The work, the schedule, what is paid and what is provided — hotel, travel, per diem.') ?>"></textarea>
      <span class="hint"><?= te('This is what somebody reads after scanning the QR. Write it for them, not for the file.') ?></span>
    </div>

    <?php if (! empty($job['id']) && $scope
              && ! array_filter($scope, static fn ($l) => (int) $l['remaining'] > 0)): ?>
      <button class="btn" type="submit" disabled><?= te('Create application QR') ?></button>
      <span class="hint" style="display:inline-block;margin-left:10px">
        <?= te('Nothing left to order on this agreement.') ?>
      </span>
    <?php else: ?>
      <button class="btn" type="submit"><?= te('Create application QR') ?></button>
    <?php endif; ?>
  </form>

  <section class="card">
    <span class="eyebrow"><?= te('CANDIDATE JOURNEY') ?></span>
    <h2><?= te('From scan to assignment.') ?></h2>
    <ol class="journey-list">
      <li><?= te('Scan the QR and apply with a personal email.') ?></li>
      <li><?= te('The recruiting team reviews and screens the application.') ?></li>
      <li><?= te('Progression automatically queues a private activation invitation.') ?></li>
      <li><?= te('The candidate verifies the link and chooses their password.') ?></li>
      <li><?= te('Complete onboarding, deployment and assignment closeout.') ?></li>
    </ol>
  </section>
</div>

<div class="grid g3 requisition-grid">
<?php foreach($requisitions as $requisition): ?>
  <article class="card">
    <span class="tag <?= $requisition['is_open'] ? 'green' : 'grey' ?>">
      <?= $requisition['is_open'] ? te('Open') : te('Closed') ?>
    </span>
    <h2 style="margin-top:10px"><?= e($requisition['title']) ?></h2>

    <p class="muted small" style="margin:0 0 12px">
      <?= te(ucfirst((string) ($requisition['discipline'] ?? 'other'))) ?>
      &middot; <?= (int) ($requisition['openings'] ?? 1) ?> <?= te('needed') ?>
      <?php $where = trim((string)$requisition['site_city'] . ' ' . (string)$requisition['site_state']); ?>
      <?php if ($where !== ''): ?>&middot; <?= e($where) ?><?php endif; ?>
      <?php if (! empty($requisition['shift'])): ?><br><?= e($requisition['shift']) ?><?php endif; ?>
      <?php if (! empty($requisition['starts_on'])): ?>
        &middot; <?= te('Starts') ?> <?= e(d($requisition['starts_on'])) ?>
      <?php endif; ?>
    </p>

    <?php
    // The rate shown is the one that will actually be offered: the
    // requisition's own if it was given one, otherwise the agreed rate on
    // its line of the scope. Nothing falls back to a project-wide number,
    // because there is no longer any such thing.
    $rolePay  = $requisition['pay_rate']  ?? $requisition['line_pay'];
    $roleBill = $requisition['bill_rate'] ?? $requisition['line_bill'];
    $ownRate  = $requisition['pay_rate'] !== null;
    ?>
    <p class="small" style="margin:0 0 4px">
      <strong><?= $rolePay !== null ? e(money((float) $rolePay)) : '&mdash;' ?></strong>
      <?= te('an hour') ?>
      <?php if ($roleBill !== null): ?>
        <span class="muted">&middot; <?= te('billed :amount', ['amount' => money((float) $roleBill)]) ?></span>
      <?php endif; ?>
      <?php if ($ownRate): ?>
        <span class="tag blue"><?= te('Own rate') ?></span>
      <?php elseif ($rolePay !== null): ?>
        <span class="muted small"><?= te('agreed on the scope') ?></span>
      <?php endif; ?>
    </p>

    <p class="small muted" style="margin:0 0 10px">
      <?php if (! empty($requisition['line_role'])): ?>
        <?= te('Against :role on the scope (:n covered)', [
            'role' => e($requisition['line_role']),
            'n'    => (int) $requisition['line_quantity']]) ?>
        &middot; <a href="/job#scope"><?= te('See the scope') ?></a>
      <?php else: ?>
        <span class="tag amber"><?= te('Not on the scope of work') ?></span>
        <?= te('No line of the agreement backs this requisition, so it has no agreed rate behind it.') ?>
      <?php endif; ?>
    </p>

    <?php if (! empty($requisition['degree'])): ?>
      <p class="small" style="margin:0 0 10px"><strong><?= te('Degree required') ?>:</strong> <?= e($requisition['degree']) ?></p>
    <?php endif; ?>

    <div class="application-qr" data-vacancy="<?= (int)$requisition['id'] ?>"
         style="background:#fff;padding:12px;width:216px;max-width:100%"></div>

    <?php
    $asked  = max(1, (int) ($requisition['openings'] ?? 1));
    $placed = (int) ($requisition['placed'] ?? 0);
    $left   = max(0, $asked - $placed);
    ?>

    <p class="small" style="margin-top:10px">
      <strong><?= $left ?></strong>
      <?= te('of :asked still to place', ['asked' => $asked]) ?>
      <span class="muted">
        &middot; <?= te(':count applications received', ['count' => (int) $requisition['applications']]) ?>
      </span>
    </p>

    <div class="headcount-bar" style="margin-top:6px">
      <span style="width:<?= (int) round($placed / $asked * 100) ?>%"></span>
    </div>
    <p class="small">
      <a href="/apply?id=<?= (int)$requisition['id'] ?>" target="_blank" rel="noopener">
        <?= te('Open the public application page') ?>
      </a>
    </p>

    <?php if (can('admin')): ?>
    <div class="requisition-actions">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="<?= $requisition['is_open'] ? 'close' : 'reopen' ?>">
        <input type="hidden" name="vacancy_id" value="<?= (int) $requisition['id'] ?>">
        <button class="btn ghost sm" type="submit">
          <?= $requisition['is_open'] ? te('Close this requisition') : te('Reopen it') ?>
        </button>
      </form>

      <?php if ((int) $requisition['applications'] === 0): ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="remove">
        <input type="hidden" name="vacancy_id" value="<?= (int) $requisition['id'] ?>">
        <button class="btn ghost sm" type="submit"><?= te('Remove') ?></button>
      </form>
      <?php endif; ?>
    </div>
    <?php else: ?>
      <p class="hint"><?= te('An administrator opens and closes requisitions.') ?></p>
    <?php endif; ?>
  </article>
<?php endforeach; ?>
<?php if (! $requisitions): ?>
  <div class="card"><div class="empty">
    <?= te('No requisitions on this project yet. Create one above and its QR code appears here.') ?>
  </div></div>
<?php endif; ?>
</div>

<script src="/assets/qrcode.min.js"></script>
<script>document.querySelectorAll('.application-qr').forEach(el=>new QRCode(el,{text:location.origin+'/apply?id='+el.dataset.vacancy,width:192,height:192,correctLevel:QRCode.CorrectLevel.M}));</script>
