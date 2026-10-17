<div class="page-heading">
  <div>
    <h1><?= te('Requisitions') ?></h1>
    <p class="sub"><?= te('Openings for the current project.') ?></p>
  </div>
  <div class="flex gap-s"><a class="btn" href="#new-requisition" data-new-requisition><?= te('+ Add a requisition') ?></a><a class="btn ghost" href="/recruitment"><?= te('Review applications') ?></a></div>
</div>

<?php // P5-M74: one line per requisition - the trade, the rate, how far it is filled; the QR code and the link on demand. ?>
<div class="card tight" id="requisitions">
  <div class="card-bar"><h2 class="m0"><?= tn(count($requisitions), ':n requisition', ':n requisitions') ?></h2></div>
  <?php if (! $requisitions): ?>
    <div class="empty"><?= te('No requisitions on this project yet. Add one: its link and QR code are ready at once.') ?></div>
  <?php else: ?>
  <div class="table-scroll"><table class="req-lines">
    <thead><tr><th><?= te('Requisition') ?></th><th><?= te('Pay') ?></th><th><?= te('Filled') ?></th><th><?= te('Applications') ?></th><th><?= te('Status') ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($requisitions as $requisition):
        $rolePay  = $requisition['pay_rate']  ?? $requisition['line_pay'];
        $roleBill = $requisition['bill_rate'] ?? $requisition['line_bill'];
        $asked  = max(1, (int) ($requisition['openings'] ?? 1));
        $placed = (int) ($requisition['placed'] ?? 0);
        $link = rtrim((string) ($config['app_url'] ?? ''), '/') . '/apply?id=' . (int) $requisition['id'];
        $where = trim((string) $requisition['site_city'] . ' ' . (string) $requisition['site_state']); ?>
      <tr data-requisition="<?= (int) $requisition['id'] ?>">
        <td><a href="/candidates?vacancy=<?= (int) $requisition['id'] ?>" data-applicants-link><strong><?= e($requisition['title']) ?></strong></a>
          <div class="small muted"><?= te(ucfirst((string) ($requisition['discipline'] ?? 'other'))) ?><?= $where !== '' ? ' &middot; ' . e($where) : '' ?><?= ! empty($requisition['shift']) ? ' &middot; ' . e($requisition['shift']) : '' ?><?= ! empty($requisition['starts_on']) ? ' &middot; ' . te('Starts') . ' ' . e(d($requisition['starts_on'])) : '' ?>
          <?php if (empty($requisition['line_role'])): ?> &middot; <span class="tag amber"><?= te('Not on the scope of work') ?></span><?php endif; ?></div></td>
        <td class="nowrap"><strong><?= $rolePay !== null ? e(money((float) $rolePay)) : '&mdash;' ?></strong> <span class="small muted"><?= te('/h') ?></span>
          <?php if ($roleBill !== null): ?><div class="small muted"><?= te('billed :amount', ['amount' => money((float) $roleBill)]) ?></div><?php endif; ?></td>
        <td class="req-fill"><div class="headcount-bar"><span style="width:<?= (int) round(min($placed, $asked) / $asked * 100) ?>%"></span></div>
          <span class="small"><?= te(':placed of :asked placed', ['placed' => $placed, 'asked' => $asked]) ?></span></td>
        <td><?php // P5-M91: the applicants of this requisition, a click away, on Candidates. ?><a class="btn ghost sm" href="/candidates?vacancy=<?= (int) $requisition['id'] ?>" title="<?= te('See the applicants') ?>"><?= (int) $requisition['applications'] ?></a></td>
        <td><span class="tag <?= $requisition['is_open'] ? 'green' : 'grey' ?>"><?= $requisition['is_open'] ? te('Open') : te('Closed') ?></span></td>
        <td class="right nowrap">
          <button type="button" class="btn ghost sm" data-copy-link="<?= e($link) ?>" data-copied="<?= te('Link copied') ?>"><?= te('Copy link') ?></button>
          <details class="req-more inline-block"><summary class="btn ghost sm"><?= te('QR code & more') ?></summary>
            <div class="req-pop">
              <div class="application-qr qr-box" data-vacancy="<?= (int) $requisition['id'] ?>"></div>
              <p class="small"><a href="/apply?id=<?= (int) $requisition['id'] ?>" target="_blank" rel="noopener"><?= te('Open the public application page') ?></a><br>
                <a href="/poster?id=<?= (int) $requisition['id'] ?>" target="_blank" rel="noopener" data-poster="<?= (int) $requisition['id'] ?>"><?= te('Print the job poster with its QR code') ?></a>
                <?php if (! empty($requisition['line_role'])): ?><br><a href="/job#scope"><?= te('See the scope') ?></a><?php endif; ?></p>
              <form method="post" class="mt-s" data-managers-form><?= csrf_field() ?><input type="hidden" name="do" value="managers"><input type="hidden" name="vacancy_id" value="<?= (int) $requisition['id'] ?>">
                <p class="small m0"><strong><?= te('Hiring managers') ?></strong></p>
                <div class="pick-grid"><?php foreach ($managerChoices as $mc): ?><label class="small"><input type="checkbox" name="managers[]" value="<?= (int) $mc['id'] ?>"<?= in_array((int) $mc['id'], $vacancyManagers[(int) $requisition['id']] ?? [], true) ? ' checked' : '' ?>> <?= e($mc['name']) ?></label><?php endforeach; ?></div>
                <p class="small m0 mt-xs"><strong><?= te('Recruiting checklist') ?></strong></p>
                <?php foreach ($checklistChoices as $cc): ?><label class="d-block small"><input type="checkbox" name="checklists[]" value="<?= (int) $cc['id'] ?>"<?= in_array((int) $cc['id'], $vacancyChecklists[(int) $requisition['id']] ?? [], true) ? ' checked' : '' ?>> <?= e($cc['name']) ?></label><?php endforeach; ?>
                <button class="btn ghost sm"><?= te('Save') ?></button>
              </form>
              <?php if (can('admin')): ?>
              <div class="requisition-actions">
                <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="<?= $requisition['is_open'] ? 'close' : 'reopen' ?>"><input type="hidden" name="vacancy_id" value="<?= (int) $requisition['id'] ?>">
                  <button class="btn ghost sm" type="submit"><?= $requisition['is_open'] ? te('Close this requisition') : te('Reopen it') ?></button></form>
                <?php if ((int) $requisition['applications'] === 0): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="remove"><input type="hidden" name="vacancy_id" value="<?= (int) $requisition['id'] ?>">
                  <button class="btn ghost sm" type="submit"><?= te('Remove') ?></button></form>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </div>
          </details>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<details class="card fold" id="new-requisition" data-fold-forget<?= ! $requisitions || ! empty($_GET['new']) ? ' open' : '' ?>>
  <summary><h2><?= te('Create a requisition') ?></h2></summary>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create">
    <p class="hint m0 pull-up mb-l">
      <?php if (! empty($job['title'])): ?>
        <?= te('For') ?> <strong><?= e($job['title']) ?></strong>
      <?php else: ?>
        <span class="t-amber"><?= te('Choose a working project first — use the selector above.') ?></span>
      <?php endif; ?>
    </p>

    <?php if (! empty($job['id'])): ?>
      <?php if (! $scope): ?>
        <p class="small t-amber m0 pull-up mb-l">
          <?= te('This project has no scope of work yet, so there is nothing to raise a requisition against and no agreed rate to inherit.') ?>
          <a href="/job#scope"><?= te('Write the scope of work') ?></a>
        </p>
      <?php elseif (! array_filter($scope, static fn ($l) => (int) $l['remaining'] > 0)): ?>
        <p class="small t-amber m0 pull-up mb-l">
          <?= te('Every trade on this agreement is already fully covered by requisitions, so there is nothing left to raise.') ?>
          <a href="/job#scope"><?= te('Raise a quantity on the scope') ?></a>
          <?= te('or close a requisition below that is no longer being recruited.') ?>
        </p>
      <?php else: ?>
        <div class="field mb-l">
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
      <div class="f2">
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

    <div class="row mt-l">
      <div>
        <label for="r-open"><?= te('How many needed') ?></label>
        <input id="r-open" name="openings" type="number" min="1" max="9999" value="1" required>
      </div>
      <div>
        <label for="r-start"><?= te('Starts') ?></label>
        <input id="r-start" type="date" name="starts_on"
               value="<?= e(max((string) ($job['starts_on'] ?? ''), date('Y-m-d'))) ?>">
      </div>
      <div class="f2">
        <label for="r-shift"><?= te('Shift') ?></label>
        <input id="r-shift" name="shift" maxlength="60" placeholder="<?= te('e.g. Days, 12 hours, 6 on 1 off') ?>">
      </div>
    </div>

    <div class="row mt-l">
      <div class="f2">
        <label for="r-degree"><?= te('Degree required') ?></label>
        <input id="r-degree" name="degree" maxlength="190" placeholder="<?= te('e.g. BSc Mechanical or Chemical Engineering') ?>">
      </div>
      <div>
        <label for="r-years"><?= te('Years of experience') ?></label>
        <input id="r-years" name="years_experience" type="number" min="0" max="60" placeholder="<?= te('Minimum') ?>">
      </div>
    </div>

    <div class="field mt-l">
      <label for="r-req"><?= te('Must-haves') ?></label>
      <textarea id="r-req" name="requirements" maxlength="4000" rows="3"
                placeholder="<?= te('Certifications, clearances, licences — one per line') ?>"></textarea>
      <span class="hint"><?= te('What disqualifies somebody if it is missing. Screened before an offer.') ?></span>
    </div>

    <?php if (can('admin')): ?>
    <?php // P5-M75: the override is a button (RSS: "the override should be a button"); the line's rates apply unless it is opened and filled. ?>
    <p class="hint mt-l m0" id="rates-from-line-admin"><?= te('Pay, bill rate, per diem and guarantee come from the line of the scope.') ?></p>
    <details class="mt-s" id="rate-override"><summary class="btn ghost sm" data-rate-override><?= te('Override the agreed rates') ?></summary>
    <p class="hint m0 mb mt-s">
      <?= te('Fill in only what was agreed differently for this requisition.') ?>
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
    <button type="button" class="btn ghost sm" data-fold-close><?= te('Keep the rates of the line') ?></button>
    </details>
    <?php else: ?>
    <p class="hint mt-l" id="rates-from-line"><?= te('Pay, bill rate, per diem and guarantee come from the line of the scope. Only an administrator can override them.') ?></p>
    <?php endif; ?>

    <div class="field">
      <label for="r-desc"><?= te('Job description shown to candidates') ?></label>
      <textarea id="r-desc" name="description" required maxlength="10000" rows="6"
                placeholder="<?= te('The work, the schedule, what is paid and what is provided — hotel, travel, per diem.') ?>"></textarea>
      <span class="hint"><?= te('This is what somebody reads after scanning the QR. Write it for them, not for the file.') ?></span>
    </div>

    <?php // P5-M85: the hiring managers - the review goes to them, and only they are told. ?>
    <fieldset class="mt"><legend><?= te('Hiring managers') ?></legend>
      <div class="pick-grid"><?php foreach ($managerChoices as $mc): ?><label class="small"><input type="checkbox" name="managers[]" value="<?= (int) $mc['id'] ?>"<?= (int) $mc['id'] === uid() ? ' checked' : '' ?>> <?= e($mc['name']) ?></label><?php endforeach; ?></div>
      <span class="hint"><?= te('They review the applicants sent for review; any of them decides.') ?> <a href="/people"><?= te('Mark hiring managers on Team') ?></a></span>
    </fieldset>
    <fieldset class="mt"><legend><?= te('Recruiting checklist') ?></legend>
      <?php if (! $checklistChoices): ?><span class="hint"><a href="/checklists#new-template"><?= te('Write a checklist template first') ?></a></span><?php endif; ?>
      <?php foreach ($checklistChoices as $cc): ?><label class="d-block small"><input type="checkbox" name="checklists[]" value="<?= (int) $cc['id'] ?>"> <?= e($cc['name']) ?> <span class="muted">· <?= tn((int) $cc['items'], ':n question', ':n questions') ?></span></label><?php endforeach; ?>
      <span class="hint"><?= te('The questions the recruiter asks before sending an applicant for review.') ?> <a href="/checklists"><?= te('Templates') ?></a></span>
    </fieldset>
    <?php if (! empty($job['id']) && $scope
              && ! array_filter($scope, static fn ($l) => (int) $l['remaining'] > 0)): ?>
      <button class="btn" type="submit" disabled><?= te('Create the requisition') ?></button>
      <span class="hint inline-block ml">
        <?= te('Every trade is already on requisitions. When the client asks for more people, edit the line on the scope of work: the change is kept in its history.') ?> <a href="/job#scope"><?= te('Scope of work') ?></a>
      </span>
    <?php else: ?>
      <button class="btn" type="submit"><?= te('Create the requisition') ?></button>
    <?php endif; ?>
    <button type="button" class="btn ghost" data-fold-close><?= te('Cancel') ?></button>
  </form>
</details>

<details class="card fold" id="candidate-journey">
  <summary><span><span class="eyebrow"><?= te('CANDIDATE JOURNEY') ?></span><h2><?= te('From scan to assignment.') ?></h2></span></summary>
    <ol class="journey-list">
      <li><?= te('Scan the QR and apply with a personal email.') ?></li>
      <li><?= te('The recruiting team reviews and screens the application.') ?></li>
      <li><?= te('Progression automatically queues a private activation invitation.') ?></li>
      <li><?= te('The candidate verifies the link and chooses their password.') ?></li>
      <li><?= te('Complete onboarding, deployment and assignment closeout.') ?></li>
    </ol>
</details>

<script src="/assets/qrcode.min.js"></script>
<?php // P5-M46: the site's configured address, not the one the office browses from, so a QR code always opens the real page. ?>
<script>document.querySelectorAll('.application-qr').forEach(el=>new QRCode(el,{text:<?= json_encode(rtrim((string) ($config['app_url'] ?? ''), '/'), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>+'/apply?id='+el.dataset.vacancy,width:192,height:192,correctLevel:QRCode.CorrectLevel.M}));</script>
<script src="/assets/fold.js"></script>
