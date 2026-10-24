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
      <?php $requestedReq = ($requisition['posting_status'] ?? 'published') === 'requested'; ?>
      <tr data-requisition="<?= (int) $requisition['id'] ?>" id="req-<?= (int) $requisition['id'] ?>"<?= (int) ($_GET['req'] ?? 0) === (int) $requisition['id'] ? ' class="row-focus"' : '' ?>>
        <td><a href="/candidates?vacancy=<?= (int) $requisition['id'] ?>" data-applicants-link><strong><?= e($requisition['title']) ?></strong></a>
          <div class="small muted"><?= te(ucfirst((string) ($requisition['discipline'] ?? 'other'))) ?><?= $where !== '' ? ' &middot; ' . e($where) : '' ?><?= ! empty($requisition['shift']) ? ' &middot; ' . e($requisition['shift']) : '' ?><?= ! empty($requisition['starts_on']) ? ' &middot; ' . te('Starts') . ' ' . e(d($requisition['starts_on'])) : '' ?>
          <?php if (empty($requisition['line_role'])): ?> &middot; <span class="tag amber"><?= te('Not on the scope of work') ?></span><?php endif; ?></div>
          <?php // P5-M103: the trail - requested by whom, when the emails left, published by whom. ?>
          <?php if (! empty($requisition['requested_name']) || ! empty($requisition['published_name']) || (int) $requisition['mailed'] > 0): ?>
          <div class="small muted" data-trail="<?= (int) $requisition['id'] ?>">
            <?php if (! empty($requisition['requested_name'])): ?><?= te('Requested by :name, :when', ['name' => $requisition['requested_name'], 'when' => d(substr((string) $requisition['requested_at'], 0, 10)) . ' ' . substr((string) $requisition['requested_at'], 11, 5)]) ?><?php endif; ?>
            <?php if ((int) $requisition['mailed'] > 0): ?> &middot; <span data-mailed="<?= (int) $requisition['mailed_sent'] ?>/<?= (int) $requisition['mailed'] ?>"><?= (int) $requisition['mailed_sent'] === (int) $requisition['mailed'] && $requisition['mailed_at']
                ? tn((int) $requisition['mailed'], 'emailed to :n recruiter, sent :when', 'emailed to :n recruiters, sent :when', ['when' => substr((string) $requisition['mailed_at'], 11, 5)])
                : ((int) $requisition['mailed_sent'] === 0
                    ? tn((int) $requisition['mailed'], 'email to :n recruiter waiting to send', 'emails to :n recruiters waiting to send')
                    : te('emailed to :sent of :n recruiters, the rest waiting to send', ['sent' => (int) $requisition['mailed_sent'], 'n' => (int) $requisition['mailed']])) ?></span><?php endif; ?>
            <?php if (! empty($requisition['published_name']) && ! empty($requisition['requested_name'])): ?> &middot; <?= te('published by :name, :when', ['name' => $requisition['published_name'], 'when' => d(substr((string) $requisition['published_at'], 0, 10)) . ' ' . substr((string) $requisition['published_at'], 11, 5)]) ?><?php endif; ?>
          </div>
          <?php endif; ?></td>
        <td class="nowrap"><strong><?= $rolePay !== null ? e(money((float) $rolePay)) : '&mdash;' ?></strong> <span class="small muted"><?= te('/h') ?></span>
          <?php if ($roleBill !== null): ?><div class="small muted"><?= te('billed :amount', ['amount' => money((float) $roleBill)]) ?></div><?php endif; ?></td>
        <td class="req-fill"><div class="headcount-bar"><span style="width:<?= (int) round(min($placed, $asked) / $asked * 100) ?>%"></span></div>
          <span class="small"><?= te(':placed of :asked placed', ['placed' => $placed, 'asked' => $asked]) ?></span></td>
        <td><?php // P5-M91: the applicants of this requisition, a click away, on Candidates. ?><a class="btn ghost sm" href="/candidates?vacancy=<?= (int) $requisition['id'] ?>" title="<?= te('See the applicants') ?>"><?= (int) $requisition['applications'] ?></a></td>
        <td><?php if ($requestedReq && $requisition['is_open']): ?><span class="tag amber" data-requested><?= te('Requested · not published') ?></span><?php else: ?><span class="tag <?= $requisition['is_open'] ? 'green' : 'grey' ?>"><?= $requisition['is_open'] ? te('Open') : te('Closed') ?></span><?php endif; ?></td>
        <td class="right nowrap">
          <?php if ($requestedReq): ?>
          <?php // P5-M103: the recruiter's gesture - publishing creates the public offer and tells whoever requested it. ?>
          <form method="post" class="inline-block" data-publish-req="<?= (int) $requisition['id'] ?>"><?= csrf_field() ?><input type="hidden" name="do" value="publish"><input type="hidden" name="vacancy_id" value="<?= (int) $requisition['id'] ?>"><button class="btn sm" type="submit"><?= te('Publish') ?></button></form>
          <?php else: ?>
          <button type="button" class="btn ghost sm" data-copy-link="<?= e($link) ?>" data-copied="<?= te('Link copied') ?>"><?= te('Copy link') ?></button>
          <?php endif; ?>
          <?php // P5-M102: closing a requisition is one click on its row, not inside "QR code & more". It leaves the careers page and the website feed at once. ?>
          <?php if (can('admin')): ?><form method="post" class="inline-block" data-close-req="<?= (int) $requisition['id'] ?>"><?= csrf_field() ?><input type="hidden" name="do" value="<?= $requisition['is_open'] ? 'close' : 'reopen' ?>"><input type="hidden" name="vacancy_id" value="<?= (int) $requisition['id'] ?>"><button class="btn ghost sm" type="submit"><?= $requisition['is_open'] ? te('Close') : te('Reopen it') ?></button></form><?php endif; ?>
          <?php // P5-M105: the recruiter completes the requisition - the detail candidates read. A dialog sits above the page, ?>
          <?php // whatever the table's scrolling, and opens by itself when the recruiter arrives from the email. ?>
          <button type="button" class="btn ghost sm" data-edit-open="<?= (int) $requisition['id'] ?>" onclick="document.getElementById('req-edit-<?= (int) $requisition['id'] ?>').showModal()"><?= te('Edit') ?></button>
          <dialog class="req-edit" id="req-edit-<?= (int) $requisition['id'] ?>" data-edit-req="<?= (int) $requisition['id'] ?>"<?= $requestedReq && (int) ($_GET['req'] ?? 0) === (int) $requisition['id'] ? ' data-open-now' : '' ?> aria-label="<?= te('Edit the requisition') ?>">
            <h2 class="m0 mb-s"><?= te('Edit the requisition') ?></h2>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="edit"><input type="hidden" name="vacancy_id" value="<?= (int) $requisition['id'] ?>">
                <label for="re-t-<?= (int) $requisition['id'] ?>"><?= te('Role / trade') ?></label><input id="re-t-<?= (int) $requisition['id'] ?>" name="title" required maxlength="190" value="<?= e($requisition['title']) ?>">
                <div class="row mt-s">
                  <div><label for="re-d-<?= (int) $requisition['id'] ?>"><?= te('Discipline') ?></label><select id="re-d-<?= (int) $requisition['id'] ?>" name="discipline"><?php foreach (disciplines() as $k => $v): ?><option value="<?= e($k) ?>"<?= ($requisition['discipline'] ?? '') === $k ? ' selected' : '' ?>><?= te($v) ?></option><?php endforeach; ?></select></div>
                  <div><label for="re-o-<?= (int) $requisition['id'] ?>"><?= te('How many needed') ?></label><input id="re-o-<?= (int) $requisition['id'] ?>" name="openings" type="number" min="1" max="9999" required value="<?= (int) $requisition['openings'] ?>"></div>
                  <div><label for="re-s-<?= (int) $requisition['id'] ?>"><?= te('Starts') ?></label><input id="re-s-<?= (int) $requisition['id'] ?>" type="date" name="starts_on" value="<?= e((string) ($requisition['starts_on'] ?? '')) ?>"></div>
                </div>
                <div class="row mt-s">
                  <div class="f2"><label for="re-sh-<?= (int) $requisition['id'] ?>"><?= te('Shift') ?></label><input id="re-sh-<?= (int) $requisition['id'] ?>" name="shift" maxlength="60" value="<?= e((string) ($requisition['shift'] ?? '')) ?>"></div>
                  <div><label for="re-y-<?= (int) $requisition['id'] ?>"><?= te('Years of experience') ?></label><input id="re-y-<?= (int) $requisition['id'] ?>" name="years_experience" type="number" min="0" max="60" value="<?= e((string) ($requisition['years_experience'] ?? '')) ?>"></div>
                </div>
                <label class="mt-s" for="re-dg-<?= (int) $requisition['id'] ?>"><?= te('Degree required') ?></label><input id="re-dg-<?= (int) $requisition['id'] ?>" name="degree" maxlength="190" value="<?= e((string) ($requisition['degree'] ?? '')) ?>">
                <label class="mt-s" for="re-de-<?= (int) $requisition['id'] ?>"><?= te('Description') ?></label><textarea id="re-de-<?= (int) $requisition['id'] ?>" name="description" rows="6" maxlength="10000" required><?= e((string) $requisition['description']) ?></textarea>
                <label class="mt-s" for="re-r-<?= (int) $requisition['id'] ?>"><?= te('Must-haves') ?></label><textarea id="re-r-<?= (int) $requisition['id'] ?>" name="requirements" rows="3" maxlength="4000"><?= e((string) ($requisition['requirements'] ?? '')) ?></textarea>
                <div class="actions mt-s"><button class="btn sm" data-edit-save><?= te('Save') ?></button> <button type="button" class="btn ghost sm" onclick="this.closest('dialog').close()"><?= te('Cancel') ?></button></div>
              </form>
          </dialog>
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
      <?php // P5-M103: sent to recruiting, a recruiter publishes it; or published at once. ?>
      <button class="btn" type="submit" name="send" value="request" data-send-request><?= te('Send to recruiting') ?></button>
      <button class="btn ghost" type="submit" name="send" value="publish" data-send-publish><?= te('Create and publish') ?></button>
      <span class="hint d-block mt-xs"><?= te('Send to recruiting: the recruiters are emailed and a recruiter publishes it. Create and publish: it is on the careers page at once.') ?></span>
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
<script src="/assets/fold.js?v=105"></script>
<script>document.querySelectorAll('dialog.req-edit[data-open-now]').forEach(function (d) { d.showModal(); });</script>
