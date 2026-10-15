<?php
/**
 * Candidate reviews (P5-M44). One screen for the manager: what is waiting for
 * a decision, the interviews to hold, and, opened, everything the recruiter
 * saw about the applicant with the decision underneath.
 */
$kinds = candidate_document_kinds();
$stat = review_statuses();
$modes = interview_modes();
$verdicts = interview_verdicts();
$when = static fn(string $dt): string => e(d(substr($dt, 0, 10)) . ' · ' . date('g:i a', strtotime($dt)));
?>
<div class="page-heading">
  <div>
    <h1><?= te('Candidate reviews') ?></h1>
    <p class="sub"><?= te('Applicants the recruiting desk has screened and passed to you, and the interviews you hold. Decide here; the recruiter is told and takes it from there with the candidate.') ?></p>
  </div>
</div>

<?php if ($dossier): $a = $dossier; ?>
<p class="back-link"><a href="/reviews">← <?= te('All reviews') ?></a></p>
<div class="card" id="dossier">
  <div class="row">
    <div class="f2">
      <h2 class="m0"><?= e($a['full_name']) ?></h2>
      <p class="small muted m0"><?= e($a['title']) ?> · <?= e($a['project']) ?><?= $a['city'] ? ' · ' . e(trim($a['city'] . ', ' . $a['state'], ', ')) : '' ?></p>
      <p class="small m0"><?= $a['degree'] ? e($a['degree']) : '' ?><?= $a['years_exp'] !== null ? ' · ' . ten((int) $a['years_exp'], ':n year of experience', ':n years of experience') : '' ?></p>
    </div>
    <div class="right">
      <div class="small muted"><?= te('Screening score') ?></div>
      <div class="kpi" id="dossier-score"><?= $a['screening_score'] !== null ? (int) round((float) $a['screening_score']) . '%' : '—' ?></div>
    </div>
  </div>
</div>

<div class="grid g2">
  <div class="card" id="dossier-recruiter"><h3><?= te('What the recruiter says') ?></h3>
    <?php foreach ($a['reviews'] as $r): ?>
      <div class="qa"><div class="q"><?= e($r['requester']) ?> → <?= e($r['reviewer']) ?> · <span class="tag grey"><?= e($stat[$r['status']]) ?></span> · <strong><?= te('Fit :n/5', ['n' => (int) $r['rating']]) ?></strong></div>
        <div class="a"><?= nl2br(e($r['recommendation'])) ?></div>
        <?php if ($r['decision_note']): ?><div class="small muted mt-s"><?= te('Decision:') ?> <?= nl2br(e($r['decision_note'])) ?></div><?php endif; ?></div>
    <?php endforeach; ?>
    <?php if (! $a['reviews']): ?><p class="small muted"><?= te('Not passed to a manager.') ?></p><?php endif; ?>
  </div>
  <div class="card" id="dossier-answers"><h3><?= te('Screening answers') ?></h3>
    <?php foreach ($a['questions'] as $qq): ?>
      <div class="qa"><div class="q"><?= e($qq['question']) ?></div>
        <div class="a"><?= e((string) $qq['answer']) ?><?php if (screening_is_knockout($qq, (string) $qq['answer'])): ?> <span class="tag red"><?= te('Disqualifying answer') ?></span><?php elseif ((int) $qq['weight'] > 0): ?> <span class="small muted"><?= te(':earned of :weight points', ['earned' => round((int) $qq['weight'] * screening_answer_share($qq, (string) $qq['answer']), 1), 'weight' => (int) $qq['weight']]) ?></span><?php endif; ?></div></div>
    <?php endforeach; ?>
    <?php if (! $a['questions']): ?><p class="small muted"><?= te('No screening answer on file.') ?></p><?php endif; ?>
  </div>
</div>

<div class="grid g2">
  <div class="card" id="dossier-documents"><h3><?= te('Documents') ?></h3>
    <?php foreach ($a['documents'] as $doc): ?><p class="small"><?php if (strtolower(pathinfo((string) $doc['original_name'], PATHINFO_EXTENSION)) === 'pdf'): ?><a href="/resumes?download=<?= (int) $doc['id'] ?>&amp;view=1" target="_blank" rel="noopener"><?= e($doc['original_name']) ?></a> · <a href="/resumes?download=<?= (int) $doc['id'] ?>" title="<?= te('Download') ?>">⤓</a><?php else: ?><a href="/resumes?download=<?= (int) $doc['id'] ?>">⤓ <?= e($doc['original_name']) ?></a><?php endif; ?> <span class="tag grey"><?= e($kinds[$doc['kind'] ?? 'cv'] ?? t('CV')) ?></span></p><?php endforeach; ?>
    <?php if (! $a['documents']): ?><p class="small muted"><?= te('No document on file.') ?></p><?php endif; ?>
    <h3 class="mt-l"><?= te('Interviews') ?></h3>
    <?php foreach ($a['interviews'] as $iv): ?>
      <div class="qa"><div class="q"><?= $when((string) $iv['scheduled_at']) ?> · <?= e($modes[$iv['mode']]) ?> · <?= e($iv['interviewer']) ?></div>
        <div class="a small"><?php if ($iv['status'] === 'done'): ?><strong><?= (int) $iv['rating'] ?>/5 · <?= e($verdicts[$iv['verdict']] ?? '') ?></strong> — <?= nl2br(e((string) $iv['feedback'])) ?><?php else: ?><span class="muted"><?= te(ucfirst(str_replace('_', ' ', (string) $iv['status']))) ?></span><?php endif; ?></div></div>
    <?php endforeach; ?>
    <?php if (! $a['interviews']): ?><p class="small muted"><?= te('No interview yet.') ?></p><?php endif; ?>
  </div>
  <div class="card" id="dossier-history"><h3><?= te('From the application') ?></h3>
    <?php foreach (array_slice($a['notes'], 0, 8) as $n): ?><div class="qa"><div class="small muted"><?= e(d(substr((string) $n['created_at'], 0, 10))) ?><?= $n['name'] ? ' · ' . e($n['name']) : '' ?></div><div class="a small"><?= nl2br(e(mb_strimwidth((string) $n['note'], 0, 900, '…'))) ?></div></div><?php endforeach; ?>
  </div>
</div>

<?php if ($review && $review['status'] === 'pending'): ?>
<form method="post" action="/reviews" class="card" id="decide"><?= csrf_field() ?>
  <input type="hidden" name="do" value="decide"><input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>">
  <h2><?= te('Your decision') ?></h2>
  <div class="row">
    <label class="own-room"><input type="radio" name="decision" value="validated" required> <strong><?= te('Validate') ?></strong> <span class="small muted"><?= te('the recruiter goes on toward an offer') ?></span></label>
    <label class="own-room"><input type="radio" name="decision" value="interview"> <strong><?= te('Another interview') ?></strong> <span class="small muted"><?= te('schedule it below, or leave it to the recruiter') ?></span></label>
    <label class="own-room"><input type="radio" name="decision" value="declined"> <strong><?= te('Decline') ?></strong> <span class="small muted"><?= te('the recruiter closes it with the candidate') ?></span></label>
  </div>
  <div class="field mt-l"><label for="rv-note"><?= te('Your note') ?></label><textarea id="rv-note" name="note" rows="3" maxlength="4000" placeholder="<?= te('Needed for another interview or a decline: what to ask, or why not.') ?>"></textarea></div>
  <details class="mt-s" id="decide-interview"><summary class="small"><?= te('Schedule the interview now') ?></summary>
    <div class="row mt-s">
      <div><label for="rv-at"><?= te('Date and time') ?></label><input id="rv-at" type="datetime-local" name="interview_at"></div>
      <div><label for="rv-dur"><?= te('Minutes') ?></label><input id="rv-dur" type="number" name="duration" min="10" max="480" value="30"></div>
      <div><label for="rv-mode"><?= te('How') ?></label><select id="rv-mode" name="mode"><?php foreach ($modes as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label for="rv-who"><?= te('Interviewer') ?></label><select id="rv-who" name="interviewer_id"><?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>"<?= (int) $p['id'] === $me ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="field mt-s"><label for="rv-place"><?= te('Where, or the call link') ?></label><input id="rv-place" name="place" maxlength="255"></div>
  </details>
  <div class="actions mt-l"><button class="btn"><?= te('Record the decision') ?></button></div>
</form>
<?php elseif ($interview && $interview['status'] === 'scheduled'): ?>
<form method="post" action="/reviews" class="card" id="scorecard"><?= csrf_field() ?>
  <input type="hidden" name="do" value="interview_record"><input type="hidden" name="interview_id" value="<?= (int) $interview['id'] ?>">
  <h2><?= te('Interview scorecard') ?></h2>
  <p class="hint m0 mb-l"><?= $when((string) $interview['scheduled_at']) ?> · <?= e($modes[$interview['mode']]) ?> · <?= e($interview['interviewer']) ?><?= $interview['place'] ? ' · ' . e($interview['place']) : '' ?></p>
  <div class="row">
    <div><label for="sc-status"><?= te('How it went') ?></label><select id="sc-status" name="status"><option value="done"><?= te('Held') ?></option><option value="no_show"><?= te('They did not come') ?></option><option value="cancelled"><?= te('Cancelled') ?></option></select></div>
    <div><label for="sc-rating"><?= te('Rating, 1 to 5') ?></label><select id="sc-rating" name="rating"><option value=""></option><?php for ($n = 5; $n >= 1; $n--): ?><option value="<?= $n ?>"><?= $n ?></option><?php endfor; ?></select></div>
    <div><label for="sc-verdict"><?= te('Verdict') ?></label><select id="sc-verdict" name="verdict"><option value=""></option><?php foreach ($verdicts as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="field mt-l"><label for="sc-feedback"><?= te('What you saw') ?></label><textarea id="sc-feedback" name="feedback" rows="4" maxlength="4000" placeholder="<?= te('Skills, attitude, availability, anything the next person should know.') ?>"></textarea></div>
  <div class="actions mt-l"><button class="btn"><?= te('Record the interview') ?></button></div>
</form>
<?php elseif ($review): ?>
<div class="card"><p class="m0"><?= te('Decided: :status', ['status' => $stat[$review['status']]]) ?><?= $review['decision_note'] ? ' — ' . e($review['decision_note']) : '' ?></p></div>
<?php endif; ?>

<?php else: ?>

<div class="card tight" id="waiting">
  <div class="card-bar"><h2 class="m0"><?= te('Waiting for your decision') ?></h2><span class="small muted"><?= ten(count($waiting), ':n applicant', ':n applicants') ?></span></div>
  <?php if (! $waiting): ?><div class="empty"><?= te('Nothing waiting. The recruiting desk passes applicants here once they are screened.') ?></div><?php else: ?>
  <div class="scroll"><table><thead><tr><th><?= te('Applicant') ?></th><th><?= te('Requisition') ?></th><th class="right"><?= te('Score') ?></th><th><?= te('Recruiter') ?></th><th><?= te('Passed') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($waiting as $r): ?><tr>
    <td><strong><?= e($r['full_name']) ?></strong></td><td class="small"><?= e($r['title']) ?></td>
    <td class="right mono"><?= $r['screening_score'] !== null ? (int) round((float) $r['screening_score']) : '—' ?></td>
    <td class="small"><?= e($r['requester']) ?> · <?= te('Fit :n/5', ['n' => (int) $r['rating']]) ?></td>
    <td class="small nowrap"><?= e(d(substr((string) $r['created_at'], 0, 10))) ?></td>
    <td class="right"><a class="btn sm" href="/reviews?id=<?= (int) $r['id'] ?>"><?= te('Review') ?></a></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>

<div class="card tight" id="my-interviews">
  <div class="card-bar"><h2 class="m0"><?= te('Interviews to hold') ?></h2></div>
  <?php if (! $interviews): ?><div class="empty"><?= te('No interview scheduled with you.') ?></div><?php else: ?>
  <div class="scroll"><table><thead><tr><th><?= te('When') ?></th><th><?= te('Applicant') ?></th><th><?= te('How') ?></th><th><?= te('Interviewer') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($interviews as $iv): ?><tr>
    <td class="small nowrap"><?= $when((string) $iv['scheduled_at']) ?></td><td><strong><?= e($iv['full_name']) ?></strong><div class="small muted"><?= e($iv['title']) ?></div></td>
    <td class="small"><?= e($modes[$iv['mode']]) ?><?= $iv['place'] ? '<div class="muted">' . e($iv['place']) . '</div>' : '' ?></td><td class="small"><?= e($iv['interviewer']) ?></td>
    <td class="right"><a class="btn sm ghost" href="/reviews?interview=<?= (int) $iv['id'] ?>"><?= te('Open the scorecard') ?></a></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>

<?php if ($decided): ?>
<div class="card tight" id="decided">
  <div class="card-bar"><h2 class="m0"><?= te('Decided lately') ?></h2></div>
  <div class="scroll"><table><tbody>
  <?php foreach ($decided as $r): ?><tr><td><a href="/reviews?id=<?= (int) $r['id'] ?>"><?= e($r['full_name']) ?></a> <span class="small muted"><?= e($r['title']) ?></span></td><td><span class="tag <?= $r['status'] === 'validated' ? 'green' : ($r['status'] === 'declined' ? 'red' : 'amber') ?>"><?= e($stat[$r['status']]) ?></span></td><td class="small nowrap"><?= e(d(substr((string) $r['decided_at'], 0, 10))) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>
<?php endif; ?>
