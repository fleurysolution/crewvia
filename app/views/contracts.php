<?php
/**
 * Contracts, from a draft to a signature that holds up.
 *
 * The archive was a list of links, the status a bare word, and the screen
 * opened on a policy form - so the first thing anybody saw was a setting
 * rather than the work. What a recruiter needs on arriving is which contracts
 * are waiting on them and which are waiting on somebody else.
 */

$statusTag = static fn (string $s): string => match ($s) {
    'signed'                        => 'green',
    'issued', 'viewed', 'sending'   => 'amber',
    'uploaded_review'               => 'blue',
    'voided', 'declined', 'expired' => 'red',
    default                         => 'grey',
};

$statusWord = [
    'draft'           => 'Draft',
    'approved'        => 'Approved, not yet issued',
    'sending'         => 'Being sent',
    'issued'          => 'With the worker',
    'viewed'          => 'Opened by the worker',
    'uploaded_review' => 'Signed copy awaiting verification',
    'signed'          => 'Signed',
    'declined'        => 'Declined',
    'voided'          => 'Voided',
    'expired'         => 'Expired',
];

$waitingOnUs = array_filter($contracts, fn ($c) => in_array($c['status'], ['draft', 'approved', 'uploaded_review'], true));
$withWorker  = array_filter($contracts, fn ($c) => in_array($c['status'], ['issued', 'viewed', 'sending'], true));
$done        = array_filter($contracts, fn ($c) => $c['status'] === 'signed');
?>

<div class="page-heading">
  <div>
    <span class="eyebrow"><?= te('HIRING') ?></span>
    <h1><?= $isWorker ? te('Your contracts') : te('Contracts & signatures') ?></h1>
    <p class="sub">
      <?= $isWorker
          ? te('Everything sent to you to read and sign, with a record of what you signed.')
          : te('A contract is prepared, approved, issued to the worker, then signed. Every step is recorded with the hash of what was shown.') ?>
    </p>
  </div>
  <?php if (! $isWorker): ?><a class="btn ghost" href="/proofs"><?= te('Document proofs') ?></a><?php endif; ?>
</div>

<?php if (! $isWorker && $contracts): ?>
<div class="grid g4" style="margin-bottom:18px">
  <div class="stat">
    <div class="n" style="color:<?= $waitingOnUs ? 'var(--amber)' : 'var(--green)' ?>"><?= count($waitingOnUs) ?></div>
    <div class="l"><?= te('Waiting on you') ?></div>
  </div>
  <div class="stat">
    <div class="n"><?= count($withWorker) ?></div>
    <div class="l"><?= te('With the worker') ?></div>
  </div>
  <div class="stat">
    <div class="n" style="color:var(--green)"><?= count($done) ?></div>
    <div class="l"><?= te('Signed') ?></div>
  </div>
  <div class="stat">
    <div class="n"><?= count($contracts) ?></div>
    <div class="l"><?= te('On this project') ?></div>
  </div>
</div>
<?php endif; ?>

<!-- ── the archive, which is really the work queue ──────────────────── -->
<div class="card tight">
  <div style="padding:14px 18px;border-bottom:1px solid var(--line)">
    <h2 style="margin:0"><?= te('Contracts on this project') ?></h2>
  </div>

  <?php if (! $contracts): ?>
    <div class="empty">
      <?= $isWorker
          ? te('Nothing has been sent to you yet. A contract appears here when recruiting issues it.')
          : te('No contracts yet. Prepare one below for a candidate who has been offered a job.') ?>
    </div>
  <?php else: ?>
  <div class="scroll">
    <table>
      <thead><tr>
        <th><?= te('Worker') ?></th><th><?= te('Contract') ?></th>
        <th><?= te('Status') ?></th><th><?= te('Method') ?></th><th><?= te('Expires') ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($contracts as $c): ?>
        <tr>
          <td><strong><?= e($c['full_name']) ?></strong></td>
          <td>
            <a href="/contracts?id=<?= (int) $c['id'] ?>"><?= e($c['title']) ?></a>
            <div class="muted small"><?= te('Revision :n', ['n' => (int) $c['revision']]) ?></div>
          </td>
          <td>
            <span class="tag <?= $statusTag($c['status']) ?>">
              <?= te($statusWord[$c['status']] ?? $c['status']) ?>
            </span>
          </td>
          <td class="small muted"><?= $c['method'] === 'docusign' ? 'DocuSign' : te('Internal signature') ?></td>
          <td class="small muted">
            <?= $c['expires_at'] ? e(date('j M Y', strtotime($c['expires_at']))) : '—' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ── the one that is open ─────────────────────────────────────────── -->
<?php if ($selected): $c = $selected; ?>
<article class="card">
  <span class="eyebrow"><?= te('OPEN CONTRACT') ?></span>
  <h2><?= e($c['title']) ?> <span class="muted">· <?= te('Revision :n', ['n' => (int) $c['revision']]) ?></span></h2>

  <p>
    <span class="tag <?= $statusTag($c['status']) ?>"><?= te($statusWord[$c['status']] ?? $c['status']) ?></span>
    <?php if ($c['expires_at']): ?>
      <span class="muted small"><?= te('Expires') ?> <?= e(date('j M Y', strtotime($c['expires_at']))) ?></span>
    <?php endif; ?>
  </p>

  <div class="contract-terms"><?= e($c['terms']) ?></div>

  <p class="small muted">SHA-256: <span class="mono"><?= e($c['content_hash']) ?></span></p>

  <?php foreach ($files as $f): ?>
    <p class="small">
      <a href="/contracts?download=<?= (int) $f['id'] ?>"><?= te('Download') ?> <?= te($f['kind']) ?></a>
      <span class="muted mono">SHA-256: <?= e($f['file_hash']) ?></span>
    </p>
  <?php endforeach; ?>

  <?php if ($c['status'] === 'signed'): ?>
    <div class="signed-block">
      <p><strong><?= te('Signed by :name on :date', ['name' => $c['signer_name'], 'date' => $c['signed_at']]) ?></strong></p>
      <p><a class="btn ghost" href="/contracts?receipt=<?= (int) $c['id'] ?>"><?= te('Download signature record') ?></a></p>
      <p class="hint"><?= te('The internal signature record binds the displayed terms and attached original PDF hash. An uploaded PDF requires staff verification; it is not a provider certificate.') ?></p>
    </div>
  <?php endif; ?>

  <!-- what the worker can do -->
  <?php if ($isWorker && in_array($c['status'], ['issued', 'viewed'], true)): ?>
    <?php if ($c['method'] === 'internal'): ?>
      <form method="post" class="sign-form">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="sign">
        <input type="hidden" name="contract_id" value="<?= (int) $c['id'] ?>">
        <input type="hidden" name="content_hash" value="<?= e($c['content_hash']) ?>">
        <h3><?= te('Sign this contract') ?></h3>
        <div class="field">
          <label for="signer"><?= te('Your full name') ?></label>
          <input id="signer" name="signer_name" required maxlength="190">
        </div>
        <label>
          <input type="checkbox" name="consent" required>
          <?= te('I have reviewed the terms and every attached original document, and intend to sign this contract electronically.') ?>
        </label>
        <button class="btn" type="submit"><?= te('Sign contract') ?></button>
      </form>

      <form method="post" enctype="multipart/form-data" class="sign-form">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="upload_signed">
        <input type="hidden" name="contract_id" value="<?= (int) $c['id'] ?>">
        <div class="field">
          <label for="signed-pdf"><?= te('Or upload a signed PDF for staff verification') ?></label>
          <input id="signed-pdf" type="file" name="signed_pdf" accept="application/pdf" required>
        </div>
        <button class="btn ghost" type="submit"><?= te('Submit signed PDF') ?></button>
      </form>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="signing_url">
        <input type="hidden" name="contract_id" value="<?= (int) $c['id'] ?>">
        <button class="btn" type="submit"><?= te('Review and sign with DocuSign') ?></button>
      </form>
    <?php endif; ?>

    <form method="post" class="sign-form">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="decline">
      <input type="hidden" name="contract_id" value="<?= (int) $c['id'] ?>">
      <div class="field">
        <label for="decline-reason"><?= te('Reason for declining') ?></label>
        <textarea id="decline-reason" name="reason" maxlength="2000" rows="3"></textarea>
      </div>
      <button class="btn ghost" type="submit"><?= te('Decline contract') ?></button>
    </form>
  <?php endif; ?>

  <!-- what the recruiter can do -->
  <?php if (! $isWorker): ?>
    <?php if ($c['status'] === 'draft'): ?>
      <form method="post" enctype="multipart/form-data" class="sign-form">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="attach">
        <input type="hidden" name="contract_id" value="<?= (int) $c['id'] ?>">
        <div class="field">
          <label for="contract-pdf"><?= te('Attach original contract PDF') ?></label>
          <input id="contract-pdf" type="file" name="contract_pdf" accept="application/pdf" required>
          <span class="hint"><?= te('Up to 15 MB. Its hash is bound into the signature record.') ?></span>
        </div>
        <button class="btn ghost" type="submit"><?= te('Attach PDF') ?></button>
      </form>
    <?php endif; ?>

    <?php
    // What can be done next, from where the contract actually stands.
    $actions = $c['status'] === 'draft'
        ? ['approve' => 'Approve contract']
        : ($c['status'] === 'approved'
            ? [($c['method'] === 'internal' ? 'issue' : 'send_provider') => 'Issue contract']
            : []);

    if ($c['method'] === 'docusign'
        && in_array($c['status'], ['sending', 'issued', 'viewed', 'expired'], true)) {
        $actions['reconcile'] = 'Reconcile DocuSign';
    }

    if ($c['status'] !== 'signed') {
        $actions['void'] = 'Void contract';
    }
    ?>

    <?php if ($actions): ?>
    <div class="contract-actions">
      <?php foreach ($actions as $action => $label): ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="<?= e($action) ?>">
          <input type="hidden" name="contract_id" value="<?= (int) $c['id'] ?>">
          <button class="btn <?= $action === 'void' ? 'ghost' : '' ?>" type="submit">
            <?= te($label) ?>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($c['status'] === 'uploaded_review'): ?>
      <form method="post" class="sign-form">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="approve_upload">
        <input type="hidden" name="contract_id" value="<?= (int) $c['id'] ?>">
        <div class="field">
          <label for="review-note"><?= te('Signature verification notes') ?></label>
          <textarea id="review-note" name="review_note" required rows="3"></textarea>
          <span class="hint"><?= te('Say what you checked and against what. This is the record if the signature is ever questioned.') ?></span>
        </div>
        <button class="btn" type="submit"><?= te('Approve uploaded signature') ?></button>
      </form>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($events): ?>
  <h3 style="margin-top:24px"><?= te('History') ?></h3>
  <ul class="contract-history">
    <?php foreach ($events as $event): ?>
      <?php
      // What was recorded with the step, not only that it happened. The
      // verification note on an uploaded signature is the evidence that
      // somebody checked the identity behind it: the handler refuses
      // without it, and it was then written to the record and shown
      // nowhere, which is the same as not having it.
      $detail = [];

      try {
          $detail = (array) json_decode((string) $event['details_json'], true,
                                        8, JSON_THROW_ON_ERROR);
      } catch (Throwable $e) {
          $detail = [];
      }

      $said = [];

      foreach (['review_note'   => 'Verified',
                'method'        => 'Method',
                'signer'        => 'Signer',
                'reason'        => 'Reason',
                'provider_status' => 'Provider said'] as $key => $label) {
          if (! empty($detail[$key]) && is_scalar($detail[$key])) {
              $said[] = t($label) . ': ' . (string) $detail[$key];
          }
      }
      ?>
      <li>
        <span class="mono muted"><?= e(date('j M Y, H:i', strtotime($event['created_at']))) ?></span>
        <?= te(ucfirst(str_replace('_', ' ', (string) $event['action']))) ?>
        <?php if ($said): ?>
          <div class="small muted"><?= e(implode(' · ', $said)) ?></div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</article>
<?php endif; ?>

<!-- ── preparing a new one ──────────────────────────────────────────── -->
<?php if (! $isWorker): ?>
<form class="card" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="draft">
  <span class="eyebrow"><?= te('NEW CONTRACT') ?></span>
  <h2><?= te('Prepare a contract draft') ?></h2>

  <?php if (! $applications): ?>
    <div class="empty">
      <?= te('Nobody on this project is at a stage where a contract applies. Offer a candidate a job first.') ?>
      <p><a class="btn ghost" href="/recruitment"><?= te('Open application screening') ?></a></p>
    </div>
  <?php else: ?>
  <div class="row">
    <div style="flex:2">
      <label for="c-app"><?= te('Application') ?></label>
      <select id="c-app" name="application_id" required>
        <?php foreach ($applications as $a): ?>
          <?php
          $appStageWord = ['new' => 'New', 'screening' => 'Screening',
                           'interview' => 'Interview', 'offered' => 'Offered'];
          ?>
          <option value="<?= (int) $a['id'] ?>">
            <?= e($a['full_name'] . ' · ' . $a['title']) ?>
            &middot; <?= te($appStageWord[$a['stage']] ?? $a['stage']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="c-days"><?= te('Validity in days') ?></label>
      <input id="c-days" type="number" name="days" value="14" min="1" max="90">
      <span class="hint"><?= te('After this it expires unsigned.') ?></span>
    </div>
  </div>

  <div class="field" style="margin-top:16px">
    <label for="c-title"><?= te('Contract title') ?></label>
    <input id="c-title" name="title" required maxlength="190"
           placeholder="<?= te('e.g. Strike coverage assignment - mechanical engineer') ?>">
  </div>

  <div class="field">
    <label for="c-terms"><?= te('Contract terms') ?></label>
    <textarea id="c-terms" name="terms" required rows="8"
              placeholder="<?= te('Rate, guaranteed hours, per diem, lodging, travel, start date and what ends the assignment.') ?>"></textarea>
    <span class="hint"><?= te('This exact text is what gets hashed and signed. Write what was promised.') ?></span>
  </div>

  <details class="docusign-block">
    <summary><?= te('DocuSign signature placement') ?></summary>
    <p class="hint"><?= te('Only used when the project signs through DocuSign. Attach the complete PDF to the draft and verify the coordinates before approval.') ?></p>
    <div class="row">
      <div><label for="c-page"><?= te('Signature page') ?></label>
        <input id="c-page" type="number" name="signature_page" value="1" min="1" max="1000"></div>
      <div><label for="c-x">X</label>
        <input id="c-x" type="number" name="signature_x" value="40" min="0" max="2000"></div>
      <div><label for="c-y">Y</label>
        <input id="c-y" type="number" name="signature_y" value="60" min="0" max="2000"></div>
    </div>
  </details>

  <button class="btn" type="submit"><?= te('Prepare draft') ?></button>
  <?php endif; ?>
</form>
<?php endif; ?>

<!-- ── the policy that governs them ─────────────────────────────────── -->
<?php if (can('admin')): ?>
<form class="card" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="policy">
  <span class="eyebrow"><?= te('PROJECT POLICY') ?></span>
  <h2><?= te('How this project signs') ?></h2>

  <div class="row">
    <div>
      <label for="p-method"><?= te('Signature method') ?></label>
      <select id="p-method" name="method">
        <option value="internal" <?= ($policy['signature_method'] ?? 'internal') === 'internal' ? 'selected' : '' ?>>
          <?= te('Internal signature') ?>
        </option>
        <option value="docusign" <?= ($policy['signature_method'] ?? '') === 'docusign' ? 'selected' : '' ?>>DocuSign</option>
      </select>
    </div>
  </div>

  <label>
    <input type="checkbox" name="required" <?= ! empty($policy['required_before_deployment']) ? 'checked' : '' ?>>
    <?= te('Signed contract required before deployment') ?>
  </label>

  <div class="field" style="margin-top:16px">
    <label for="p-docs"><?= te('Required document types before contract preparation (one per line)') ?></label>
    <textarea id="p-docs" name="document_types" rows="4"><?= e(implode("\n", array_column($requirements, 'document_type'))) ?></textarea>
    <span class="hint"><?= te('Use exact document types from proof uploads. Configure lawful requirements and deadlines; do not require I-9 before an offer by default.') ?></span>
  </div>

  <button class="btn" type="submit"><?= te('Save policy') ?></button>
</form>
<?php endif; ?>
