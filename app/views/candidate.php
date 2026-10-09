<?php
/**
 * One person. Where they stand, what is missing, and the action beside the
 * fact that calls for it.
 */

$stages = candidate_stages();

$appStages = ['new' => 'New', 'screening' => 'Screening', 'interview' => 'Interview',
              'offered' => 'Offered', 'accepted' => 'Accepted',
              'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn'];

$callOutcomes = contact_outcomes();

$live     = $placements[0] ?? null;
$contract = $contracts[0] ?? null;
$openApp  = null;

foreach ($applications as $a) {
    if (! in_array($a['stage'], ['rejected', 'withdrawn'], true)) {
        $openApp = $a;
        break;
    }
}

/**
 * The journey, measured. Each step is true or it is not, and the first one
 * that is not is what somebody has to do next.
 */
$journey = [
    ['label' => 'Applied', 'done' => (bool) $applications,
     'detail' => $applications
         ? t(':n applications', ['n' => count($applications)])
         : t('no application on file'),
     'where' => '/recruitment'],

    ['label' => 'Contacted', 'done' => (bool) $c['last_contact_at'],
     'detail' => $c['last_contact_at']
         ? t('last contacted :when', ['when' => date('j M', strtotime($c['last_contact_at']))])
         : t('nobody has contacted them'),
     'where' => '#contact'],

    ['label' => 'Screened',
     'done' => $openApp && in_array($openApp['stage'], ['screening','interview','offered','accepted'], true),
     'detail' => $openApp ? t($appStages[$openApp['stage']] ?? $openApp['stage']) : t('no open application'),
     'where' => '#applications'],

    ['label' => 'Offered',
     'done' => $openApp && in_array($openApp['stage'], ['offered','accepted'], true),
     'detail' => $openApp && $openApp['stage'] === 'offered' ? t('waiting on them') : '',
     'where' => '#applications'],

    ['label' => 'Contract signed', 'done' => $contract && $contract['status'] === 'signed',
     'detail' => $contract
         ? t(ucfirst(str_replace('_', ' ', (string) $contract['status'])))
         : t('none prepared'),
     'where' => '/contracts'],

    ['label' => 'Can sign in', 'done' => (bool) $account,
     'detail' => $account ? $account['email'] : ($invitation ? t('invited') : t('not invited')),
     'where' => '#account'],

    ['label' => 'On the roster', 'done' => (bool) $live,
     'detail' => $live ? t(ucfirst(str_replace('_', ' ', (string) $live['status']))) : t('not placed'),
     'where' => '#assignment'],
];

$nextStep = null;

foreach ($journey as $step) {
    if (! $step['done']) {
        $nextStep = $step;
        break;
    }
}
?>

<?php
$standingNow = (string) ($standing['rehire_status'] ?? 'review');
$isBlocked   = in_array($standingNow, rehire_blocked(), true);
?>

<?php if ($isBlocked): ?>
  <div class="card do-not-use">
    <h2 style="margin:0 0 6px">
      <?= $standingNow === 'do_not_use'
          ? te('DO NOT USE — do not contact this person')
          : te('No rehire — do not put this person back on a job') ?>
    </h2>
    <p style="margin:0">
      <?= e($standing['exclusion_reason'] ?? '') ?>
    </p>
    <p class="small" style="margin:8px 0 0;opacity:.85">
      <?= te('Decided by :who on :when', [
          'who'  => $standing['decided_by'] ?? t('somebody'),
          'when' => $standing['excluded_at'] ? d($standing['excluded_at']) : t('an unrecorded date')]) ?>
      &middot; <a href="#rehire"><?= te('Change this') ?></a>
    </p>
  </div>
<?php endif; ?>

<div class="page-heading">
  <div>
    <span class="eyebrow"><?= te(ucfirst((string) ($c['discipline'] ?: 'other'))) ?></span>
    <h1><?= e($c['full_name']) ?></h1>
    <p class="sub">
      <span class="tag <?= e(stage_colour((string) $c['stage'])) ?>">
        <?= te($stages[$c['stage']] ?? $c['stage']) ?>
      </span>
      <?php if ($c['phone']): ?>
        &middot; <a class="mono" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $c['phone'])) ?>"><?= e($c['phone']) ?></a>
      <?php endif; ?>
      <?php if ($c['email']): ?>
        &middot; <a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a>
      <?php endif; ?>
      <?php $where = trim(($c['city'] ?? '') . ' ' . ($c['state'] ?? '')); ?>
      <?php if ($where !== ''): ?>&middot; <?= e($where) ?><?php endif; ?>
      <?php if ($c['owner_name']): ?>
        &middot; <?= te('owned by :who', ['who' => $c['owner_name']]) ?>
      <?php endif; ?>
    </p>
  </div>
  <a class="btn ghost" href="/candidates"><?= te('All candidates') ?></a>
</div>

<!-- ── where they are, and what is next ───────────────────────────── -->
<section class="card">
  <ol class="journey">
    <?php foreach ($journey as $step): ?>
      <li class="<?= $step['done'] ? 'done' : 'todo' ?>">
        <span class="journey-mark" aria-hidden="true"><?= $step['done'] ? '&#10003;' : '' ?></span>
        <span class="journey-body">
          <strong><?= te($step['label']) ?></strong>
          <?php if ((string) $step['detail'] !== ''): ?>
            <span class="muted small"><?= e($step['detail']) ?></span>
          <?php endif; ?>
        </span>
      </li>
    <?php endforeach; ?>
  </ol>

  <?php if ($nextStep): ?>
    <p class="next-step">
      <strong><?= te('Next:') ?></strong> <?= te($nextStep['label']) ?>
      <?php if (str_starts_with((string) $nextStep['where'], '/')): ?>
        &middot; <a href="<?= e($nextStep['where']) ?>"><?= te('go there') ?></a>
      <?php endif; ?>
    </p>
  <?php else: ?>
    <p class="next-step clear"><strong><?= te('Nothing outstanding on this person.') ?></strong></p>
  <?php endif; ?>
</section>

<!-- ── reaching them ───────────────────────────────────────────────── -->
<section class="card contact-card" id="contact">
  <div class="contact-head">
    <div>
      <span class="eyebrow"><?= te('CONTACT') ?></span>
      <h2 style="margin:4px 0 0"><?= te('Reach :name', ['name' => e($c['full_name'])]) ?></h2>
      <p class="small muted" style="margin:4px 0 0">
        <?php if ($c['last_contact_at']): ?>
          <?= te('Last contacted :when by :who', [
              'when' => d($c['last_contact_at']),
              'who'  => $calls[0]['who'] ?? t('somebody')]) ?>
          <?php if (! empty($calls[0]['outcome'])): ?>
            &middot; <?= te($callOutcomes[$calls[0]['outcome']] ?? $calls[0]['outcome']) ?>
          <?php endif; ?>
        <?php else: ?>
          <?= te('Nobody has contacted this person yet.') ?>
        <?php endif; ?>
      </p>
    </div>
    <div class="contact-actions">
      <?php if ($c['phone']): ?>
        <a class="btn" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $c['phone'])) ?>">
          <?= te('Call :number', ['number' => e($c['phone'])]) ?>
        </a>
      <?php else: ?>
        <span class="tag amber"><?= te('No telephone number on file') ?></span>
      <?php endif; ?>
      <?php if ($c['email']): ?>
        <a class="btn ghost" href="mailto:<?= e($c['email']) ?>"><?= te('Email them') ?></a>
      <?php else: ?>
        <span class="tag amber"><?= te('No email address on file') ?></span>
      <?php endif; ?>
    </div>
  </div>

  <form method="post" class="row contact-log">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="call">
    <div>
      <label for="outcome"><?= te('How it went') ?></label>
      <select id="outcome" name="outcome">
        <?php foreach ($callOutcomes as $k => $v): ?>
          <option value="<?= e($k) ?>"><?= te($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="flex:2">
      <label for="callnote"><?= te('What was said or written') ?></label>
      <input id="callnote" name="note" maxlength="500"
             placeholder="<?= te('Optional, but it is what the next recruiter reads') ?>">
    </div>
    <button class="btn" type="submit"><?= te('Record the contact') ?></button>
  </form>

  <p class="hint" style="margin:10px 0 0">
    <?= $c['stage'] === 'new'
        ? te('Recording a contact moves them from Never contacted to Contacted.')
        : te('Recording a contact keeps the history below in order.') ?>
  </p>
</section>

<div class="grid g2">
  <!-- ── their applications and screening ─────────────────────────── -->
  <section class="card" id="applications">
    <span class="eyebrow"><?= te('APPLICATIONS') ?></span>
    <h2><?= te('What they applied for') ?></h2>

    <?php if (! $applications): ?>
      <div class="empty">
        <?= te('No application on file. This person was imported or added by hand rather than applying through a QR code.') ?>
      </div>
    <?php endif; ?>

    <?php foreach ($applications as $a): ?>
      <article class="application">
        <div class="application-head">
          <div>
            <strong><?= e($a['role']) ?></strong>
            <div class="muted small"><?= e($a['project']) ?></div>
          </div>
          <span class="tag <?= e(stage_colour((string) $a['stage'])) ?>">
            <?= te($appStages[$a['stage']] ?? $a['stage']) ?>
          </span>
        </div>

        <?php if ($a['checks']): ?>
          <h4><?= te('Screening checks') ?></h4>
          <ul class="checks">
            <?php foreach ($a['checks'] as $check): ?>
              <li>
                <span class="tag <?= $check['status'] === 'passed' ? 'green'
                                     : ($check['status'] === 'failed' ? 'red' : 'grey') ?>">
                  <?= te(ucfirst((string) $check['status'])) ?>
                </span>
                <span class="check-title"><?= e($check['title']) ?></span>
                <form method="post" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="check">
                  <input type="hidden" name="check_id" value="<?= (int) $check['id'] ?>">
                  <label class="sr-only" for="ck-<?= (int) $check['id'] ?>"><?= te('Outcome') ?></label>
                  <select id="ck-<?= (int) $check['id'] ?>" name="status" onchange="this.form.submit()">
                    <?php foreach (['pending' => 'Pending', 'passed' => 'Passed', 'failed' => 'Failed'] as $k => $v): ?>
                      <option value="<?= e($k) ?>" <?= $check['status'] === $k ? 'selected' : '' ?>>
                        <?= te($v) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php $answered = array_filter($a['answers'], static fn ($x) => trim((string) $x['answer']) !== ''); ?>
        <?php if ($a['answers']): ?>
          <h4>
            <?= te('Questionnaire') ?>
            <span class="muted small">
              <?= te(':done of :total answered', ['done' => count($answered), 'total' => count($a['answers'])]) ?>
            </span>
          </h4>
          <ul class="answers">
            <?php foreach ($a['answers'] as $qa): ?>
              <li>
                <span class="muted small"><?= e($qa['question']) ?></span>
                <?php if (trim((string) $qa['answer']) !== ''): ?>
                  <span><?= e($qa['answer']) ?></span>
                <?php else: ?>
                  <span class="muted"><em><?= te('not answered') ?></em></span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if (! in_array($a['stage'], ['accepted','rejected','withdrawn'], true)): ?>
          <form method="post" class="row" style="margin-top:12px">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="stage">
            <input type="hidden" name="application_id" value="<?= (int) $a['id'] ?>">
            <div>
              <label for="st-<?= (int) $a['id'] ?>"><?= te('Move to') ?></label>
              <select id="st-<?= (int) $a['id'] ?>" name="stage">
                <?php foreach (['screening','interview','offered','rejected','withdrawn'] as $k): ?>
                  <?php if ($k !== $a['stage']): ?>
                    <option value="<?= e($k) ?>"><?= te($appStages[$k]) ?></option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
            </div>
            <div style="flex:2">
              <label for="nt-<?= (int) $a['id'] ?>"><?= te('Note, or the offer terms') ?></label>
              <input id="nt-<?= (int) $a['id'] ?>" name="note" maxlength="500"
                     placeholder="<?= te('An offer needs its terms in writing') ?>">
            </div>
            <button class="btn" type="submit"><?= te('Move') ?></button>
          </form>

          <form method="post" class="row" style="margin-top:10px">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="add_check">
            <input type="hidden" name="application_id" value="<?= (int) $a['id'] ?>">
            <div style="flex:2">
              <label for="ac-<?= (int) $a['id'] ?>"><?= te('Add a screening check') ?></label>
              <input id="ac-<?= (int) $a['id'] ?>" name="title" maxlength="190"
                     placeholder="<?= te('e.g. Background check, Drug screen, Reference') ?>">
            </div>
            <button class="btn ghost" type="submit"><?= te('Add') ?></button>
          </form>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </section>

  <!-- ── the assignment, and what it pays ─────────────────────────── -->
  <section class="card" id="assignment">
    <span class="eyebrow"><?= te('ASSIGNMENT') ?></span>
    <h2><?= te('On the job') ?></h2>

    <?php if (! $placements): ?>
      <div class="empty"><?= te('Not on any roster yet.') ?></div>

      <?php if ($job): ?>
        <form method="post" class="row">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="place">
          <div>
            <label for="startd"><?= te('Starts') ?></label>
            <input id="startd" type="date" name="start_date" value="<?= e((string) ($job['starts_on'] ?? '')) ?>">
          </div>
          <button class="btn" type="submit">
            <?= te('Add to :project', ['project' => $job['title']]) ?>
          </button>
        </form>
        <p class="hint"><?= te('They inherit the rates agreed for their role, which you can change here once they are on.') ?></p>
      <?php endif; ?>
    <?php endif; ?>

    <?php foreach ($placements as $p): ?>
      <?php
      // What this person was signed at, or the agreed rate on the line of
      // the scope they were recruited against.
      $pay     = $p['pay_rate'] ?? $p['line_pay'];
      $diem    = $p['per_diem_rate'] ?? $p['line_diem'];
      $guar    = $p['guarantee_hours']
                 ?? ($p['strike_live'] && $p['line_strike_guarantee'] !== null
                        ? $p['line_strike_guarantee']
                        : $p['line_guarantee']);
      $ownRate = $p['pay_rate'] !== null;
      ?>
      <article class="assignment">
        <div class="application-head">
          <div>
            <strong><?= e($p['project']) ?></strong>
            <div class="muted small">
              <?= e($p['trade'] ?: t('Trade not set')) ?>
              <?php if ($p['shift_label']): ?>&middot; <?= e($p['shift_label']) ?><?php endif; ?>
              <?php if ($p['supervisor']): ?>
                &middot; <?= te('under :who', ['who' => $p['supervisor']]) ?>
              <?php endif; ?>
            </div>
          </div>
          <span class="tag <?= $p['status'] === 'on_site' ? 'green'
                               : ($p['status'] === 'offered' ? 'grey' : 'blue') ?>">
            <?= te(ucfirst(str_replace('_', ' ', (string) $p['status']))) ?>
          </span>
        </div>

        <table>
          <tbody>
            <tr>
              <td><?= te('Bed') ?></td>
              <td class="right">
                <?php if ($p['hotel']): ?>
                  <?= e($p['hotel']) ?>
                  <?php if ($p['room_number']): ?><span class="mono"><?= e($p['room_number']) ?></span><?php endif; ?>
                  <?php if ((int) $p['private_room'] === 0): ?>
                    <span class="tag red"><?= te('sharing') ?></span>
                  <?php endif; ?>
                <?php else: ?>
                  <a class="tag red" href="/hotels"><?= te('no bed') ?></a>
                <?php endif; ?>
              </td>
            </tr>
            <tr>
              <td><?= te('Travel') ?></td>
              <td class="right">
                <?php if ($p['arrive_time']): ?>
                  <?= e(trim(($p['carrier'] ?? '') . ' ' . ($p['reference'] ?? ''))) ?>
                  <span class="muted"><?= e(date('D j M, g:ia', strtotime($p['arrive_time']))) ?></span>
                <?php else: ?>
                  <a class="tag amber" href="/travel"><?= te('not booked') ?></a>
                <?php endif; ?>
              </td>
            </tr>
          </tbody>
        </table>

        <h4>
          <?= te('What this person is paid') ?>
          <?php if ($ownRate): ?>
            <span class="tag blue"><?= te('Own rate') ?></span>
          <?php else: ?>
            <span class="muted small"><?= te('inherited') ?></span>
          <?php endif; ?>
        </h4>

        <?php if (can('admin')): ?>
          <form method="post" class="row">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="rates">
            <input type="hidden" name="placement_id" value="<?= (int) $p['id'] ?>">
            <div>
              <label for="pr-<?= (int) $p['id'] ?>"><?= te('Pay / hour') ?></label>
              <input id="pr-<?= (int) $p['id'] ?>" name="pay_rate" type="number" step="0.01" min="0"
                     value="<?= e((string) ($p['pay_rate'] ?? '')) ?>"
                     placeholder="<?= $p['line_pay'] !== null
                         ? e(number_format((float) $p['line_pay'], 2, '.', ''))
                         : te('from the scope') ?>">
            </div>
            <div>
              <label for="br-<?= (int) $p['id'] ?>"><?= te('Bill / hour') ?></label>
              <input id="br-<?= (int) $p['id'] ?>" name="bill_rate" type="number" step="0.01" min="0"
                     value="<?= e((string) ($p['bill_rate'] ?? '')) ?>">
            </div>
            <div>
              <label for="dr-<?= (int) $p['id'] ?>"><?= te('Per diem') ?></label>
              <input id="dr-<?= (int) $p['id'] ?>" name="per_diem_rate" type="number" step="0.01" min="0"
                     value="<?= e((string) ($p['per_diem_rate'] ?? '')) ?>"
                     placeholder="<?= $p['line_diem'] !== null
                         ? e(number_format((float) $p['line_diem'], 2, '.', ''))
                         : te('from the scope') ?>">
            </div>
            <div>
              <label for="gh-<?= (int) $p['id'] ?>"><?= te('Guarantee') ?></label>
              <input id="gh-<?= (int) $p['id'] ?>" name="guarantee_hours" type="number" min="0" max="168"
                     value="<?= e((string) ($p['guarantee_hours'] ?? '')) ?>"
                     placeholder="<?= $p['line_guarantee'] !== null
                         ? (int) $p['line_guarantee'] : te('from the scope') ?>">
            </div>
            <button class="btn" type="submit"><?= te('Save') ?></button>
          </form>
          <p class="hint">
            <?= te('Blank means the rate agreed for their role applies. A week already approved keeps what it was calculated on.') ?>
          </p>
        <?php else: ?>
          <p class="small">
            <strong><?= $pay !== null ? e(money((float) $pay)) : '&mdash;' ?></strong> <?= te('an hour') ?>
            <?php if ($diem !== null): ?>
              &middot; <?= te(':amount a day', ['amount' => money((float) $diem)]) ?>
            <?php endif; ?>
            <?php if ($guar): ?>&middot; <?= te(':n h guaranteed', ['n' => (int) $guar]) ?><?php endif; ?>
          </p>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </section>
</div>

<div class="grid g2">
  <!-- ── paperwork ─────────────────────────────────────────────────── -->
  <section class="card">
    <span class="eyebrow"><?= te('PAPERWORK') ?></span>
    <h2><?= te('What is on file') ?></h2>

    <h4><?= te('Contracts') ?></h4>
    <?php if (! $contracts): ?>
      <p class="hint"><?= te('No contract prepared.') ?> <a href="/contracts"><?= te('Prepare one') ?></a></p>
    <?php else: ?>
      <ul class="plain">
        <?php foreach ($contracts as $ct): ?>
          <li>
            <a href="/contracts?id=<?= (int) $ct['id'] ?>"><?= e($ct['title']) ?></a>
            <span class="tag <?= $ct['status'] === 'signed' ? 'green' : 'amber' ?>">
              <?= te(ucfirst(str_replace('_', ' ', (string) $ct['status']))) ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <h4><?= te('I-9 and W-4') ?></h4>
    <?php if (! $clearance): ?>
      <p class="hint"><?= te('Not started.') ?> <a href="/employment"><?= te('Open employment') ?></a></p>
    <?php else: ?>
      <p class="small">
        <?= te('I-9') ?>:
        <span class="tag <?= $clearance['i9_status'] === 'employer_completed' ? 'green' : 'amber' ?>">
          <?= te(ucfirst(str_replace('_', ' ', (string) $clearance['i9_status']))) ?>
        </span>
        &middot; <?= te('W-4') ?>:
        <span class="tag <?= $clearance['w4_status'] === 'reviewed' ? 'green' : 'amber' ?>">
          <?= te(ucfirst(str_replace('_', ' ', (string) $clearance['w4_status']))) ?>
        </span>
      </p>
    <?php endif; ?>

    <h4><?= te('Documents') ?></h4>
    <?php if (! $documents): ?>
      <p class="hint"><?= te('Nothing uploaded.') ?> <a href="/proofs"><?= te('Document proofs') ?></a></p>
    <?php else: ?>
      <ul class="plain">
        <?php foreach ($documents as $doc): ?>
          <li>
            <?= e($doc['document_type']) ?>
            <span class="tag <?= $doc['status'] === 'approved' ? 'green'
                                 : ($doc['status'] === 'rejected' ? 'red' : 'amber') ?>">
              <?= te(ucfirst((string) $doc['status'])) ?>
            </span>
            <span class="muted small"><?= e(date('j M', strtotime($doc['created_at']))) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($qualifications): ?>
      <h4><?= te('Qualifications') ?></h4>
      <ul class="plain">
        <?php foreach ($qualifications as $qual): ?>
          <li>
            <?= e($qual['name'] ?? t('Qualification')) ?>
            <span class="tag <?= $qual['status'] === 'verified' ? 'green'
                                 : ($qual['status'] === 'rejected' ? 'red' : 'amber') ?>">
              <?= te(ucfirst((string) $qual['status'])) ?>
            </span>
            <?php if (! empty($qual['expires_on'])): ?>
              <span class="muted small"><?= te('expires :date', ['date' => d($qual['expires_on'])]) ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <h4 id="account"><?= te('Their account') ?></h4>
    <?php if ($account): ?>
      <p class="small">
        <?= e($account['email']) ?>
        <span class="tag <?= $account['is_active'] ? 'green' : 'grey' ?>">
          <?= $account['is_active'] ? te('Active') : te('Disabled') ?>
        </span>
      </p>
    <?php elseif ($invitation): ?>
      <p class="hint">
        <?= $invitation['used_at']
            ? te('Invitation already used, but no account is linked — tell an administrator.')
            : te('Invited, not yet activated. The link expires :when.',
                 ['when' => d($invitation['expires_at'])]) ?>
      </p>
    <?php else: ?>
      <p class="hint"><?= te('No account and no invitation. Moving their application to screening sends one.') ?></p>
    <?php endif; ?>
  </section>

  <!-- ── their record with the agency ──────────────────────────────── -->
  <section class="card" id="record">
    <span class="eyebrow"><?= te('THEIR RECORD') ?></span>
    <h2>
      <?php if ($workHistory['assignments'] === 0): ?>
        <?= te('Never been out with us') ?>
      <?php else: ?>
        <?= te(':n jobs, :done finished', [
            'n' => $workHistory['assignments'], 'done' => $workHistory['completed']]) ?>
      <?php endif; ?>
    </h2>

    <?php if ($workHistory['assignments'] > 0): ?>
      <p class="small muted" style="margin:-6px 0 12px">
        <?php if ($workHistory['grade']): ?>
          <span class="tag <?= review_grade_tone((string) $workHistory['grade']) ?>">
            <?= te('Average :grade', ['grade' => $workHistory['grade']]) ?>
          </span>
          <?= te('across :n graded assignments', ['n' => $workHistory['reviewed']]) ?>
        <?php else: ?>
          <?= te('No assignment has been graded yet, so there is nothing to go on.') ?>
        <?php endif; ?>
        <?php if ($workHistory['refused'] > 0): ?>
          &middot; <span class="tag red">
            <?= te(':n supervisors said they would not have them back',
                   ['n' => $workHistory['refused']]) ?>
          </span>
        <?php endif; ?>
      </p>

      <div class="scroll">
        <table>
          <thead><tr>
            <th><?= te('Project') ?></th><th><?= te('Trade') ?></th>
            <th><?= te('When') ?></th><th><?= te('Status') ?></th>
            <th><?= te('How it went') ?></th>
          </tr></thead>
          <tbody>
          <?php foreach ($workHistory['history'] as $h): ?>
            <tr>
              <td>
                <strong><?= e($h['project']) ?></strong>
                <?php if ($h['client']): ?>
                  <div class="muted small"><?= e($h['client']) ?></div>
                <?php endif; ?>
              </td>
              <td class="small"><?= e($h['trade'] ?: '—') ?></td>
              <td class="small muted">
                <?= $h['start_date'] ? e(d($h['start_date'])) : '—' ?>
                <?php if ($h['end_date']): ?>
                  <br><?= e(d($h['end_date'])) ?>
                <?php endif; ?>
              </td>
              <td>
                <span class="tag <?= placement_tone((string) $h['status']) ?>">
                  <?= te(placement_word((string) $h['status'])) ?>
                </span>
              </td>
              <td class="small">
                <?php if ($h['grade']): ?>
                  <span class="tag <?= review_grade_tone((string) $h['grade']) ?>">
                    <?= e($h['grade']) ?>
                  </span>
                  <?php if (! (int) $h['would_rehire']): ?>
                    <span class="tag red"><?= te('Would not rehire') ?></span>
                  <?php endif; ?>
                  <?php if ($h['note']): ?>
                    <div class="muted"><?= e($h['note']) ?></div>
                  <?php endif; ?>
                  <?php if ($h['supervisor']): ?>
                    <div class="muted"><?= te('under :who', ['who' => $h['supervisor']]) ?></div>
                  <?php endif; ?>
                <?php elseif ($h['status'] === 'completed'): ?>
                  <span class="tag amber"><?= te('Not graded') ?></span>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($owedToUs > 0): ?>
      <p class="small" style="color:var(--amber);margin:14px 0 0">
        <?= te('Owes :amount on an advance against wages.', ['amount' => money($owedToUs)]) ?>
        <a href="/advances"><?= te('See the advance') ?></a>
      </p>
    <?php endif; ?>
  </section>

  <!-- ── what they can do ──────────────────────────────────────────── -->
  <section class="card" id="skills">
    <span class="eyebrow"><?= te('SKILLS') ?></span>
    <h2><?= te('What they can actually do') ?></h2>
    <p class="small muted" style="margin:-6px 0 12px">
      <?= te('Their discipline says which department they belong to. This says whether they can weld. A recruiter searching for a trade searches these.') ?>
    </p>

    <?php if (! $theirSkills): ?>
      <div class="empty"><?= te('No skills recorded. Nothing will find this person by trade until one is.') ?></div>
    <?php else: ?>
      <ul class="skill-list">
        <?php foreach ($theirSkills as $skill): ?>
          <li>
            <span class="tag <?= (int) $skill['confirmed'] ? 'green' : 'grey' ?>">
              <?= e(skills_list(true)[$skill['skill_slug']] ?? $skill['skill_slug']) ?>
            </span>
            <?php if ($skill['years'] !== null): ?>
              <span class="muted small"><?= te(':n years', ['n' => (int) $skill['years']]) ?></span>
            <?php endif; ?>
            <span class="muted small">
              <?= (int) $skill['confirmed'] ? te('confirmed here') : te('claimed on an application') ?>
            </span>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="remove_skill">
              <input type="hidden" name="skill" value="<?= e($skill['skill_slug']) ?>">
              <button class="btn ghost sm" type="submit"><?= te('Remove') ?></button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <form method="post" class="row" style="margin-top:14px">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="add_skill">
      <div style="flex:2">
        <label for="sk"><?= te('Add a skill') ?></label>
        <select id="sk" name="skill" required>
          <option value=""><?= te('Choose a skill…') ?></option>
          <?php foreach (skills_list() as $slug => $label): ?>
            <option value="<?= e($slug) ?>"><?= te($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="max-width:130px">
        <label for="sky"><?= te('Years') ?></label>
        <input id="sky" name="years" type="number" min="0" max="60">
      </div>
      <div class="row tight"><button class="btn" type="submit"><?= te('Add') ?></button></div>
    </form>
  </section>

  <!-- ── the register ──────────────────────────────────────────────── -->
  <section class="card" id="rehire">
    <span class="eyebrow"><?= te('THE REGISTER') ?></span>
    <h2><?= te('Can we call this person again?') ?></h2>
    <p class="small muted" style="margin:-6px 0 12px">
      <?= te('Kept on the person, not on the project they were fired from, so it travels with them to every job. Every recruiter sees it beside their name.') ?>
    </p>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="rehire">

      <label for="rh"><?= te('Decision') ?></label>
      <select id="rh" name="rehire_status">
        <?php foreach (rehire_states() as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $standingNow === $key ? 'selected' : '' ?>>
            <?= te($label) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label for="rhw"><?= te('Why') ?></label>
      <textarea id="rhw" name="exclusion_reason" rows="3" maxlength="1000"
                placeholder="<?= te('e.g. Walked off the Gary job on day two without notice. Supervisor: Jack.') ?>"><?= e($standing['exclusion_reason'] ?? '') ?></textarea>
      <span class="hint">
        <?= te('Required for No rehire and DO NOT USE. A block with no reason cannot be checked by anybody later, including the person it is about.') ?>
      </span>

      <button class="btn" type="submit" style="margin-top:12px"><?= te('Record the decision') ?></button>
    </form>
  </section>

  <!-- ── calls ─────────────────────────────────────────────────────── -->
  <section class="card" id="calls">
    <span class="eyebrow"><?= te('HISTORY') ?></span>
    <h2><?= te('Every contact') ?></h2>
    <p class="small muted" style="margin:-6px 0 12px">
      <?= te('Recorded at the top of this page. Newest first.') ?>
    </p>

    <?php if (! $calls): ?>
      <div class="empty"><?= te('Nobody has contacted this person yet.') ?></div>
    <?php else: ?>
      <ul class="plain calls">
        <?php foreach ($calls as $call): ?>
          <li>
            <span class="tag <?= in_array($call['outcome'], ['reached','callback'], true) ? 'green' : 'grey' ?>">
              <?= te($callOutcomes[$call['outcome']] ?? $call['outcome']) ?>
            </span>
            <span class="muted small mono"><?= e(date('j M, H:i', strtotime($call['called_at']))) ?></span>
            <span class="muted small"><?= e($call['who'] ?? '') ?></span>
            <?php if ($call['note']): ?>
              <div class="small"><?= e($call['note']) ?></div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<!-- ── the details themselves ──────────────────────────────────────── -->
<form class="card" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="save">
  <span class="eyebrow"><?= te('DETAILS') ?></span>
  <h2><?= te('What we know about them') ?></h2>

  <div class="row">
    <div>
      <label for="d-phone"><?= te('Telephone') ?></label>
      <input id="d-phone" name="phone" value="<?= e((string) $c['phone']) ?>" maxlength="40">
    </div>
    <div style="flex:2">
      <label for="d-email"><?= te('Email') ?></label>
      <input id="d-email" name="email" type="email" value="<?= e((string) $c['email']) ?>" maxlength="190">
    </div>
    <div>
      <label for="d-city"><?= te('City') ?></label>
      <input id="d-city" name="city" value="<?= e((string) $c['city']) ?>" maxlength="120">
    </div>
    <div style="max-width:100px">
      <label for="d-state"><?= te('State') ?></label>
      <input id="d-state" name="state" value="<?= e((string) $c['state']) ?>" maxlength="2">
    </div>
  </div>

  <div class="row" style="margin-top:14px">
    <div>
      <label for="d-disc"><?= te('Discipline') ?></label>
      <select id="d-disc" name="discipline">
        <?php foreach (disciplines() as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $c['discipline'] === $key ? 'selected' : '' ?>>
            <?= te($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="flex:2">
      <label for="d-degree"><?= te('Degree') ?></label>
      <input id="d-degree" name="degree" value="<?= e((string) $c['degree']) ?>" maxlength="190">
    </div>
    <div>
      <label for="d-years"><?= te('Years of experience') ?></label>
      <input id="d-years" name="years_exp" type="number" min="0" max="60"
             value="<?= e((string) $c['years_exp']) ?>">
    </div>
  </div>

  <div class="field" style="margin-top:14px">
    <label for="d-notes"><?= te('Notes') ?></label>
    <textarea id="d-notes" name="notes" rows="3" maxlength="4000"><?= e((string) $c['notes']) ?></textarea>
  </div>

  <button class="btn" type="submit"><?= te('Save') ?></button>
  <a class="btn ghost" href="/employee-folder?id=<?= (int) $c['id'] ?>"><?= te('Open employee folder') ?></a>
</form>
