<?php
require_once __DIR__ . '/../intake.php'; // P5-M42: the kinds of document on a candidate's file
$hwStageTone = ['new' => 'blue', 'screening' => 'amber', 'interview' => 'amber', 'offered' => 'green', 'accepted' => 'green', 'rejected' => 'red', 'withdrawn' => 'grey'];
$hwDisc = ['mechanical' => t('Mechanical'), 'chemical' => t('Chemical'), 'electrical' => t('Electrical'), 'instrumentation' => t('Instrumentation'), 'operator' => t('Operator'), 'other' => t('Other')];
$hwUrl = static fn(array $over) => '/hiring?' . http_build_query(array_filter($over + ['v' => (int) ($hwVacancy['id'] ?? 0)], static fn($x) => $x !== null && $x !== ''));
$hwInterestButtons = static function (array $a, string $back): void {
    echo '<div class="interest" role="group" aria-label="' . te('Interest') . '">';
    foreach (['yes' => ['✓', t('Interested')], 'maybe' => ['?', t('Maybe')], 'no' => ['✗', t('Not interested')]] as $v => [$sym, $lab]) {
        echo '<form method="post" action="/hiring">' . csrf_field() . '<input type="hidden" name="do" value="interest"><input type="hidden" name="application_id" value="' . (int) $a['id'] . '">'
           . '<input type="hidden" name="interest" value="' . $v . '"><input type="hidden" name="back" value="' . e($back) . '">'
           . '<button class="' . $v . ($a['interest'] === $v ? ' on' : '') . '" title="' . e($lab) . '" aria-label="' . e($lab) . '" aria-pressed="' . ($a['interest'] === $v ? 'true' : 'false') . '">' . $sym . '</button></form>';
    }
    echo '</div>';
};
$hwScore = static function (?string $s): string {
    if ($s === null) { return '<span class="score" title="' . te('Not scored') . '">—</span>'; }
    $n = (int) round((float) $s);
    return '<span class="score ' . ($n >= 75 ? 'hi' : ($n < 40 ? 'lo' : '')) . '" title="' . te('Screening score') . '">' . $n . '</span>';
};
?>
<?php if (! isset($hwVacancy)): ?>
<div class="page-head">
  <div><h1><?= te('Hiring') ?></h1><p class="sub m0"><?= te('Every requisition and where its applicants stand. Open one to review its applicants, move them along, or add people from the talent pool.') ?></p></div>
  <div class="actions"><a class="btn" href="/requisitions"><?= te('Create a requisition') ?></a></div>
</div>
<form method="get" class="filterbar" id="jobs-filter">
  <select name="status" aria-label="<?= te('Status') ?>"><?php foreach (['open' => t('Open'), 'closed' => t('Closed'), 'all' => t('All')] as $k => $l): ?><option value="<?= $k ?>"<?= $hwStatus === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <select name="project" aria-label="<?= te('Project') ?>"><option value="0"><?= te('All projects') ?></option><?php foreach ($hwProjects as $p): ?><option value="<?= (int) $p['id'] ?>"<?= (int) ($_GET['project'] ?? 0) === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['title']) ?></option><?php endforeach; ?></select>
  <button class="btn ghost"><?= te('Show') ?></button> <span class="small muted"><?= ten(count($hwJobs), ':n requisition', ':n requisitions') ?></span>
</form>
<?php if (! $hwJobs): ?>
<div class="card empty-state" id="no-jobs"><h3><?= te('No requisition here yet') ?></h3><p><?= te('Create a requisition for a project: applicants then arrive here, by its public link and QR code, or from the talent pool.') ?></p><a class="btn" href="/requisitions"><?= te('Create a requisition') ?></a></div>
<?php else: ?>
<div class="card tight" id="jobs"><table>
  <tr><th><?= te('Requisition') ?></th><th><?= te('Applicants') ?></th><th><?= te('Openings') ?></th><th><?= te('Last application') ?></th><th><?= te('Status') ?></th></tr>
  <?php foreach ($hwJobs as $j): ?><tr data-vacancy="<?= (int) $j['id'] ?>">
    <td><a href="/hiring?v=<?= (int) $j['id'] ?>"><strong><?= e($j['title']) ?></strong></a><div class="small muted"><?= e($j['project']) ?><?= isset($hwDisc[$j['discipline'] ?? '']) ? ' · ' . e($hwDisc[$j['discipline']]) : '' ?><?= $j['starts_on'] ? ' · ' . e(starts_words((string) $j['starts_on'])) : '' ?></div></td>
    <td><div class="counts">
      <a href="/hiring?v=<?= (int) $j['id'] ?>&amp;stage=all"><b><?= (int) $j['total'] ?></b><?= te('All') ?></a>
      <a href="/hiring?v=<?= (int) $j['id'] ?>&amp;stage=new" data-new="<?= (int) $j['new_count'] ?>"><b><?= (int) $j['new_count'] ?></b><?= te('New') ?></a>
      <a href="/hiring?v=<?= (int) $j['id'] ?>&amp;stage=screening"><b><?= (int) $j['reviewing'] ?></b><?= te('Reviewing') ?></a>
      <a href="/hiring?v=<?= (int) $j['id'] ?>&amp;stage=offered"><b><?= (int) $j['offered'] ?></b><?= te('Offered') ?></a>
      <a href="/hiring?v=<?= (int) $j['id'] ?>&amp;stage=accepted"><b><?= (int) $j['hired'] ?></b><?= te('Hired') ?></a></div></td>
    <td class="num"><?= (int) $j['openings'] ?></td>
    <td class="small"><?= $j['last_applied'] ? e(hw_ago((string) $j['last_applied'])) : '<span class="muted">—</span>' ?></td>
    <td><span class="tag <?= (int) $j['is_open'] ? 'green' : 'grey' ?>"><?= (int) $j['is_open'] ? te('Open') : te('Closed') ?></span></td></tr><?php endforeach; ?>
</table></div>
<?php endif; ?>

<?php else: $v = $hwVacancy; ?>
<p class="back-link"><a href="/hiring">← <?= te('All requisitions') ?></a></p>
<div class="page-head">
  <div><h1 id="vacancy-title"><?= e($v['title']) ?></h1>
    <div class="meta"><?= e($v['project']) ?><?= isset($hwDisc[$v['discipline'] ?? '']) ? ' · ' . e($hwDisc[$v['discipline']]) : '' ?> · <?= ten((int) $v['openings'], ':n opening', ':n openings') ?><?= $v['starts_on'] ? ' · ' . e(starts_words((string) $v['starts_on'])) : '' ?>
      · <span class="tag <?= (int) $v['is_open'] ? 'green' : 'grey' ?>"><?= (int) $v['is_open'] ? te('Open') : te('Closed') ?></span></div></div>
  <div class="actions"><?php if ((int) $v['is_open']): ?><a class="btn" href="<?= e($hwUrl(['tab' => 'add'])) ?>" id="add-applicant-link"><?= te('Add an applicant') ?></a> <?php endif; ?><a class="btn ghost" href="/requisitions"><?= te('Edit requisition') ?></a></div>
</div>
<nav class="tabs" aria-label="<?= te('Requisition') ?>">
  <a href="<?= e($hwUrl([])) ?>" class="<?= $hwView === 'candidates' ? 'on' : '' ?>"><?= te('Applicants') ?></a>
  <a href="<?= e($hwUrl(['tab' => 'matches'])) ?>" class="<?= $hwView === 'matches' ? 'on' : '' ?>" id="tab-matches"><?= te('Talent pool matches') ?></a>
  <a href="<?= e($hwUrl(['tab' => 'job'])) ?>" class="<?= $hwView === 'job' ? 'on' : '' ?>"><?= te('Requisition') ?></a>
</nav>

<?php if ($hwView === 'candidates'): ?>
<div class="chips" id="stage-tabs">
  <?php foreach (hw_tabs() as $k => $l): ?><a href="<?= e($hwUrl(['stage' => $k === 'active' ? null : $k, 'q' => $hwFilter['q'], 'interest' => $hwFilter['interest'], 'sort' => $hwFilter['sort'], 'miles' => $hwFilter['miles'] ?: null])) ?>" class="<?= $hwTab === $k ? 'on' : '' ?>" data-stage-tab="<?= e($k) ?>"><?= e($l) ?><b><?= (int) $hwCounts[$k] ?></b></a><?php endforeach; ?>
</div>
<form method="get" class="filterbar" id="applicant-filter"><input type="hidden" name="v" value="<?= (int) $v['id'] ?>"><?php if ($hwTab !== 'active'): ?><input type="hidden" name="stage" value="<?= e($hwTab) ?>"><?php endif; ?>
  <select name="sort" aria-label="<?= te('Sort') ?>"><?php foreach (['' => t('Newest first'), 'oldest' => t('Oldest first'), 'score' => t('Best screening score'), 'name' => t('Name')] + ($hwSite ? ['near' => t('Nearest the site')] : []) as $k => $l): ?><option value="<?= e($k) ?>"<?= $hwFilter['sort'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <select name="interest" aria-label="<?= te('Interest') ?>"><?php foreach (['' => t('Any interest'), 'yes' => t('Interested'), 'maybe' => t('Maybe'), 'no' => t('Not interested'), 'unset' => t('Not marked yet')] as $k => $l): ?><option value="<?= e($k) ?>"<?= $hwFilter['interest'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <?php if ($hwSite): ?><select name="miles" aria-label="<?= te('Distance from the site') ?>" id="miles-filter"><option value=""><?= te('Any distance') ?></option><?php foreach (geo_radii() as $r): ?><option value="<?= $r ?>"<?= $hwFilter['miles'] === $r ? ' selected' : '' ?>><?= te('Within :n miles of the site', ['n' => $r]) ?></option><?php endforeach; ?></select><?php else: ?><span class="small muted" id="no-site-location"><?= te('No distance: the project\'s site has no city and state or ZIP code.') ?></span><?php endif; ?>
  <input type="search" name="q" value="<?= e($hwFilter['q']) ?>" placeholder="<?= te('Search applicants by name, place or degree') ?>" aria-label="<?= te('Search applicants') ?>">
  <button class="btn ghost"><?= te('Apply') ?></button>
</form>

<?php if ($hwApp): $a = $hwApp; ?>
<div class="split" id="review">
  <nav class="split-list" aria-label="<?= te('Applicants') ?>">
    <?php foreach ($hwList as $r): ?><a href="<?= e($hwSelf . '&app=' . (int) $r['id']) ?>" class="<?= (int) $r['id'] === (int) $a['id'] ? 'on' : '' ?>" data-list-app="<?= (int) $r['id'] ?>">
      <div class="who"><?= $hwScore($r['screening_score']) ?><div><strong><?= e($r['full_name']) ?></strong><div class="sm"><?= e(trim(($r['city'] ?? '') . ', ' . ($r['state'] ?? ''), ', ')) ?><?= $r['miles_away'] !== null ? ' · <span data-miles="' . (int) $r['id'] . '">' . te('≈ :n mi', ['n' => (int) round((float) $r['miles_away'])]) . '</span>' : '' ?></div><div class="sm"><?= e(hw_stages()[$r['stage']]) ?> · <?= e(hw_ago((string) $r['created_at'])) ?></div></div></div></a><?php endforeach; ?>
    <?php if (! $hwList): ?><p class="muted small pad"><?= te('Nobody in this tab.') ?></p><?php endif; ?>
  </nav>
  <section>
    <div class="card" id="applicant" data-application="<?= (int) $a['id'] ?>" data-stage="<?= e($a['stage']) ?>">
      <div class="detail-head">
        <div class="who"><span class="avatar lg" aria-hidden="true"><?= e(hw_initials((string) $a['full_name'])) ?></span>
          <div><h2><?= e($a['full_name']) ?></h2>
            <div class="sm"><?= e(trim(($a['city'] ?? '') . ', ' . ($a['state'] ?? ''), ', ')) ?><?= $a['phone'] ? ' · ' . e($a['phone']) : '' ?><?= $a['email'] ? ' · ' . e($a['email']) : '' ?></div>
            <div class="sm"><span class="tag <?= $hwStageTone[$a['stage']] ?? 'grey' ?>"><?= e(hw_stages()[$a['stage']]) ?></span> · <?= te('applied :when', ['when' => hw_ago((string) $a['created_at'])]) ?> · <?= e((string) $a['source']) ?></div></div></div>
        <div class="head-tools">
          <?php $hwInterestButtons($a, $hwSelf . '&app=' . (int) $a['id']); ?>
          <?php if ($a['prev']): ?><a class="btn ghost sm" href="<?= e($hwSelf . '&app=' . (int) $a['prev']) ?>" aria-label="<?= te('Previous applicant') ?>" id="prev-app">←</a><?php endif; ?>
          <?php if ($a['next']): ?><a class="btn ghost sm" href="<?= e($hwSelf . '&app=' . (int) $a['next']) ?>" aria-label="<?= te('Next applicant') ?>" id="next-app">→</a><?php endif; ?>
          <?php if ($a['position']): ?><span class="small muted"><?= te(':i of :n', ['i' => (int) $a['position'], 'n' => count($hwList)]) ?></span><?php endif; ?>
        </div>
      </div>
      <?php if (($a['validation'] ?? 'none') === 'pending'): ?><p class="flash" data-validation="pending"><?= te('Sent for validation. The candidate gets the offer once it is approved.') ?> <a href="/approvals"><?= te('Approvals') ?></a></p><?php elseif (($a['validation'] ?? 'none') === 'rejected' && $a['stage'] !== 'offered'): ?><p class="flash err" data-validation="rejected"><?= te('The offer was not validated. Change it and send it again, or move the applicant on.') ?></p><?php endif; ?>
      <?php foreach ($a['matches'] ?? [] as $match): ?>
      <p class="flash err" data-match="<?= (int) $match['id'] ?>"><?= te('Possible existing candidate:') ?> <a href="/candidates/<?= (int) $match['id'] ?>"><?= e($match['full_name'] . ' · ' . (candidate_stages()[$match['stage']] ?? $match['stage'])) ?></a><?= te('. Verify identity and prior decisions before continuing. A matching contact or name does not prove identity.') ?></p>
      <?php endforeach; ?>
      <?php if (! in_array($a['stage'], ['accepted', 'rejected', 'withdrawn'], true)): ?>
      <form method="post" action="/hiring" class="detail-actions" id="move-form"><?= csrf_field() ?><input type="hidden" name="do" value="move"><input type="hidden" name="application_id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="back" value="<?= e($hwSelf . '&app=' . (int) $a['id']) ?>">
        <select name="stage" aria-label="<?= te('Move to') ?>"><?php foreach (['screening', 'interview', 'offered', 'new', 'withdrawn'] as $s): if ($s === $a['stage']) { continue; } ?><option value="<?= $s ?>"><?= te('Move to :stage', ['stage' => hw_stages()[$s]]) ?></option><?php endforeach; ?></select>
        <input name="note" maxlength="2000" placeholder="<?= te('Note: with an offer, the candidate reads it beside the agreed terms') ?>" aria-label="<?= te('Note') ?>" class="f1 min-w-s">
        <button class="btn"><?= te('Move') ?></button>
      </form>
      <form method="post" action="/hiring" class="detail-actions" id="reject-form"><?= csrf_field() ?><input type="hidden" name="do" value="reject"><input type="hidden" name="application_id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="back" value="<?= e($hwSelf . '&app=' . (int) $a['id']) ?>">
        <input name="note" maxlength="2000" placeholder="<?= te('Why (kept on the application)') ?>" aria-label="<?= te('Why') ?>" class="f1 min-w-s"><button class="btn ghost"><?= te('Reject') ?></button>
        <a class="btn ghost" href="/candidates/<?= (int) $a['candidate_id'] ?>"><?= te('Full profile') ?></a>
      </form>
      <?php else: ?><p class="small muted"><?= te('That application is settled. Raise a new one for the same person if they come back.') ?> <a href="/candidates/<?= (int) $a['candidate_id'] ?>"><?= te('Full profile') ?></a></p><?php endif; ?>
    </div>
    <nav class="tabs" aria-label="<?= te('Applicant') ?>">
      <a href="<?= e($hwSelf . '&app=' . (int) $a['id']) ?>" class="<?= $hwDetail === 'application' ? 'on' : '' ?>"><?= te('Application') ?></a>
      <a href="<?= e($hwSelf . '&app=' . (int) $a['id'] . '&detail=activity') ?>" class="<?= $hwDetail === 'activity' ? 'on' : '' ?>" id="tab-activity"><?= te('Activity & notes') ?></a>
    </nav>
    <?php if ($hwDetail === 'application'): ?>
    <div class="grid g2">
      <div class="card" id="screener"><h3><?= te('Screener questions') ?></h3>
        <?php if (! $a['questions']): ?><p class="muted small"><?= te('This project asks no screening question.') ?></p><?php endif; ?>
        <?php foreach ($a['questions'] as $qq): $ok = trim((string) $qq['answer']) !== ''; ?><div class="qa" data-question="<?= (int) $qq['id'] ?>"><div class="q"><?= e($qq['question']) ?><?= (int) $qq['required'] ? ' <span class="small muted">' . te('required') . '</span>' : '' ?></div>
          <div class="a"><?= $ok ? '<span class="checks"><span class="' . (screening_is_knockout($qq, (string) $qq['answer']) ? 'ko">✗' : 'ok">✓') . '</span></span> ' . e((string) $qq['answer']) . ((int) $qq['weight'] > 0 ? ' <span class="small muted" data-earned="' . (int) $qq['id'] . '">' . te(':earned of :weight points', ['earned' => round((int) $qq['weight'] * screening_answer_share($qq, (string) $qq['answer']), 1), 'weight' => (int) $qq['weight']]) . '</span>' : '') : '<span class="checks"><span class="' . ((int) $qq['required'] ? 'ko' : 'wait') . '">' . ((int) $qq['required'] ? '✗' : '–') . '</span></span> <span class="muted">' . te('Not answered') . '</span>' ?></div></div><?php endforeach; ?>
        <?php if ((int) $a['screened_out']): ?><p class="small t-red"><?= te('Screened out automatically: :why', ['why' => (string) $a['screened_out_why']]) ?></p><?php endif; ?>
      </div>
      <div>
        <div class="card" id="checks"><h3><?= te('Screening checks') ?></h3>
          <?php if (! $a['checks']): ?><p class="muted small"><?= te('No check on this application.') ?></p><?php endif; ?>
          <?php foreach ($a['checks'] as $ch): ?><div class="qa"><span class="tag <?= $ch['status'] === 'passed' ? 'green' : ($ch['status'] === 'failed' ? 'red' : 'grey') ?>"><?= te(ucfirst($ch['status'])) ?></span> <?= e($ch['title']) ?><?= $ch['note'] ? '<div class="small muted">' . e($ch['note']) . '</div>' : '' ?></div><?php endforeach; ?>
          <p class="small"><a href="/recruitment"><?= te('Manage checks on Application screening') ?></a></p>
        </div>
        <?php if (! empty($a['notes'])): ?>
        <div class="card" id="application-notes"><h3><?= te('From the application') ?></h3>
          <?php foreach ($a['notes'] as $n): ?><div class="mb-s" data-note-stage="<?= e((string) $n['stage']) ?>"><p class="small muted m0"><?= e(d(substr((string) $n['created_at'], 0, 10))) ?><?= $n['name'] ? ' · ' . e($n['name']) : '' ?></p><p class="small m0 pre-wrap"><?= e((string) $n['note']) ?></p></div><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="card" id="background"><h3><?= te('Background') ?></h3>
          <p class="small"><?= $a['degree'] ? e($a['degree']) : '<span class="muted">' . te('No degree on file') . '</span>' ?><?= $a['years_exp'] !== null ? ' · ' . ten((int) $a['years_exp'], ':n year of experience', ':n years of experience') : '' ?></p>
          <?php if ($a['skills']): ?><p class="small"><?php foreach ($a['skills'] as $sk): ?><span class="tag <?= (int) $sk['confirmed'] ? 'green' : 'grey' ?>"><?= e((string) $sk['skill_slug']) ?><?= $sk['years'] ? ' · ' . (int) $sk['years'] . 'y' : '' ?></span> <?php endforeach; ?></p><?php endif; ?>
          <?php foreach ($a['resumes'] as $r): ?><p class="small"><a href="/resumes?download=<?= (int) $r['id'] ?>">⤓ <?= e($r['original_name']) ?></a> <span class="tag grey"><?= e(candidate_document_kinds()[$r['kind'] ?? 'cv'] ?? t('CV')) ?></span> <span class="muted"><?= e(d(substr((string) $r['created_at'], 0, 10))) ?></span></p><?php endforeach; ?>
          <?php if (! $a['resumes']): ?><p class="small muted"><?= te('No résumé on file.') ?></p><?php endif; ?>
          <form method="post" action="/resumes" enctype="multipart/form-data" class="row tight gap-s mt-s" id="attach-document"><?= csrf_field() ?><input type="hidden" name="do" value="attach"><input type="hidden" name="candidate_id" value="<?= (int) $a['candidate_id'] ?>"><input type="hidden" name="back" value="/hiring?v=<?= (int) $a['vacancy_id'] ?>&app=<?= (int) $a['id'] ?>">
            <select name="kind" aria-label="<?= te('What it is') ?>" class="w-auto"><?php foreach (candidate_document_kinds() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
            <input type="file" name="resume" accept=".pdf,.docx" required aria-label="<?= te('File') ?>">
            <button class="btn sm ghost"><?= te('Attach') ?></button>
          </form>
        </div>
      </div>
    </div>
    <?php if ($textPanel): require __DIR__ . '/_text-panel.php'; endif; ?>
    <?php else: ?>
    <div class="card" id="activity">
      <form method="post" action="/hiring" class="filterbar"><?= csrf_field() ?><input type="hidden" name="do" value="note"><input type="hidden" name="application_id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="back" value="<?= e($hwSelf . '&app=' . (int) $a['id'] . '&detail=activity') ?>">
        <input name="note" required maxlength="2000" placeholder="<?= te('Add a note to this application') ?>" aria-label="<?= te('Note') ?>" class="f1"><button class="btn"><?= te('Add note') ?></button></form>
      <?php if (! $a['timeline']): ?><p class="muted small"><?= te('Nothing yet.') ?></p><?php else: ?>
      <ul class="timeline"><?php foreach ($a['timeline'] as $ev): ?><li data-kind="<?= e($ev['kind']) ?>"><strong><?= e((string) $ev['what']) ?></strong><?= $ev['note'] ? ' — ' . e((string) $ev['note']) : '' ?><div class="when"><?= e(hw_ago((string) $ev['when'])) ?><?= $ev['who'] ? ' · ' . e((string) $ev['who']) : '' ?></div></li><?php endforeach; ?></ul><?php endif; ?>
    </div>
    <?php endif; ?>
  </section>
</div>

<?php else: ?>
<?php if (! $hwList): ?>
<div class="card empty-state" id="no-applicants"><h3><?= te('Nobody here yet') ?></h3><p><?= te('Applicants arrive by the requisition\'s public link and QR code. You can also add people from the talent pool.') ?></p><a class="btn ghost" href="<?= e($hwUrl(['tab' => 'matches'])) ?>"><?= te('See talent pool matches') ?></a></div>
<?php else: ?>
<div class="rows" id="applicants">
  <div class="rows-head"><span><?= te('Applicant and score') ?></span><span><?= te('Background and screening') ?></span><span><?= te('Activity') ?></span><span><?= te('Interest') ?></span></div>
  <?php foreach ($hwList as $r): $chk = $r['checks']; ?><div class="row-item" data-app="<?= (int) $r['id'] ?>" data-interest="<?= e((string) $r['interest']) ?>">
    <div class="who"><?= $hwScore($r['screening_score']) ?><div class="min-w-0"><a class="name" href="<?= e($hwSelf . '&app=' . (int) $r['id']) ?>"><?= e($r['full_name']) ?></a>
      <div class="sm"><?= e(trim(($r['city'] ?? '') . ', ' . ($r['state'] ?? ''), ', ') ?: t('Location not given')) ?><?= $r['miles_away'] !== null ? ' · <span data-miles="' . (int) $r['id'] . '">' . te('≈ :n mi', ['n' => (int) round((float) $r['miles_away'])]) . '</span>' : '' ?></div>
      <div class="sm"><span class="tag <?= $hwStageTone[$r['stage']] ?? 'grey' ?>"><?= e(hw_stages()[$r['stage']]) ?></span></div></div></div>
    <div class="sm"><div class="t-ink"><?= $r['degree'] ? e($r['degree']) : te('No degree on file') ?><?= $r['years_exp'] !== null ? ' · ' . te(':n yr', ['n' => (int) $r['years_exp']]) : '' ?></div>
      <?php if ($r['skills']): ?><div><?= e(implode(' · ', $r['skills'])) ?></div><?php endif; ?>
      <div class="checks"><?php if ($r['required']): ?><span class="<?= $r['answered'] >= $r['required'] ? 'ok' : 'wait' ?>"><?= te(':a/:n answers', ['a' => (int) $r['answered'], 'n' => (int) $r['required']]) ?></span><?php endif; ?>
        <?php if (! empty($chk['passed'])): ?><span class="ok">✓ <?= (int) $chk['passed'] ?></span><?php endif; ?><?php if (! empty($chk['failed'])): ?><span class="ko">✗ <?= (int) $chk['failed'] ?></span><?php endif; ?><?php if (! empty($chk['pending'])): ?><span class="wait">… <?= (int) $chk['pending'] ?></span><?php endif; ?>
        <?php if ((int) $r['screened_out']): ?><span class="ko"><?= te('screened out') ?></span><?php endif; ?></div></div>
    <div class="sm"><?= te('Applied :when', ['when' => hw_ago((string) $r['created_at'])]) ?><div><?= $r['last_contact_at'] ? te('Last contact :when', ['when' => hw_ago((string) $r['last_contact_at'])]) : te('Not contacted yet') ?></div><div><?= e((string) $r['source']) ?></div></div>
    <div><?php $hwInterestButtons($r, $hwSelf); ?></div>
  </div><?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php elseif ($hwView === 'add'): ?>
<?php $af = $hwAddForm; $afv = static fn(string $k, string $d = ''): string => e((string) ($af[$k] ?? $d)); ?>
<form method="post" action="/hiring" enctype="multipart/form-data" class="card" id="add-applicant"><?= csrf_field() ?>
  <input type="hidden" name="do" value="add_applicant"><input type="hidden" name="vacancy_id" value="<?= (int) $v['id'] ?>">
  <h2><?= te('Add an applicant') ?></h2>
  <p class="hint m0 pull-up mb-l"><?= te('For somebody who applied outside Crewvia: by email, by phone, in person or through a referral. They join this requisition as a new applicant, with what they sent, and are reviewed like everybody else.') ?></p>

  <h3><?= te('Who they are') ?></h3>
  <div class="row">
    <div><label for="ap-first"><?= te('First name') ?></label><input id="ap-first" name="first_name" required maxlength="90" autocomplete="off" value="<?= $afv('first_name') ?>"></div>
    <div><label for="ap-last"><?= te('Last name') ?></label><input id="ap-last" name="last_name" required maxlength="90" autocomplete="off" value="<?= $afv('last_name') ?>"></div>
  </div>
  <div class="row mt-l">
    <div class="f2"><label for="ap-email"><?= te('Email') ?></label><input id="ap-email" name="email" type="email" maxlength="190" autocomplete="off" value="<?= $afv('email') ?>"></div>
    <div><label for="ap-phone"><?= te('Phone') ?></label><input id="ap-phone" name="phone" type="tel" maxlength="40" autocomplete="off" value="<?= $afv('phone') ?>"></div>
  </div>
  <p class="hint"><?= te('An email or a phone number is needed to reach them. Their portal invitation goes to the email.') ?></p>
  <div class="row mt-l">
    <div class="f2"><label for="ap-city"><?= te('City') ?></label><input id="ap-city" name="city" maxlength="120" value="<?= $afv('city') ?>"></div>
    <div><label for="ap-state"><?= te('State') ?></label><input id="ap-state" name="state" maxlength="40" value="<?= $afv('state') ?>"></div>
    <div><label for="ap-zip"><?= te('ZIP code') ?></label><input id="ap-zip" name="zip" maxlength="10" inputmode="numeric" value="<?= $afv('zip') ?>"></div>
  </div>

  <h3 class="mt-l"><?= te('Their application') ?></h3>
  <div class="row">
    <div><label for="ap-channel"><?= te('How they applied') ?></label><select id="ap-channel" name="channel"><?php foreach (hw_channels() as $k => $l): ?><option value="<?= e($k) ?>"<?= ($af['channel'] ?? 'email') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label for="ap-received"><?= te('Received on') ?></label><input id="ap-received" name="received_on" type="date" max="<?= e(date('Y-m-d')) ?>" value="<?= $afv('received_on', date('Y-m-d')) ?>"></div>
  </div>
  <div class="row mt-l">
    <div class="f2"><label for="ap-degree"><?= te('Degree or trade qualification') ?></label><input id="ap-degree" name="degree" maxlength="190" placeholder="<?= te('e.g. B.S. Chemical Engineering') ?>" value="<?= $afv('degree') ?>"></div>
    <div><label for="ap-years"><?= te('Years of experience') ?></label><input id="ap-years" name="years_exp" type="number" min="0" max="60" value="<?= $afv('years_exp') ?>"></div>
  </div>
  <div class="field mt-l"><label for="ap-message"><?= te('Their message or cover letter') ?></label><textarea id="ap-message" name="message" rows="5" maxlength="8000" placeholder="<?= te('Paste the email they wrote, if any.') ?>"><?= $afv('message') ?></textarea></div>

  <h3 class="mt-l"><?= te('What they sent') ?></h3>
  <p class="hint m0 mb-l"><?= te('Up to 6 documents, PDF or Word, 5 MB each. Stored encrypted on their file; they find them in their portal.') ?></p>
  <?php foreach (['cv', 'cover_letter', 'certificate'] as $i => $default): ?>
  <div class="row<?= $i ? ' mt-s' : '' ?>" data-document-row>
    <div><label for="ap-kind-<?= $i ?>"><?= te('Document') ?> <?= $i + 1 ?></label><select id="ap-kind-<?= $i ?>" name="document_kind[<?= $i ?>]"><?php foreach (candidate_document_kinds() as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === $default ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="f2"><label for="ap-file-<?= $i ?>"><?= te('File') ?></label><input id="ap-file-<?= $i ?>" type="file" name="documents[<?= $i ?>]" accept=".pdf,.docx"></div>
  </div>
  <?php endforeach; ?>
  <details class="mt-s"><summary class="small"><?= te('More documents') ?></summary>
  <?php foreach ([3, 4, 5] as $i): ?>
  <div class="row mt-s" data-document-row>
    <div><label for="ap-kind-<?= $i ?>"><?= te('Document') ?> <?= $i + 1 ?></label><select id="ap-kind-<?= $i ?>" name="document_kind[<?= $i ?>]"><?php foreach (candidate_document_kinds() as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === 'other' ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="f2"><label for="ap-file-<?= $i ?>"><?= te('File') ?></label><input id="ap-file-<?= $i ?>" type="file" name="documents[<?= $i ?>]" accept=".pdf,.docx"></div>
  </div>
  <?php endforeach; ?>
  </details>

  <div class="actions mt-l"><button class="btn"><?= te('Add the applicant') ?></button> <a class="btn ghost" href="<?= e($hwUrl([])) ?>"><?= te('Cancel') ?></a></div>
</form>
<?php elseif ($hwView === 'matches'): ?>
<p class="sub"><?= te('People in the talent pool who fit this requisition\'s discipline, not yet on it and not on the do-not-rehire register; those who have worked for the agency before come first.') ?></p>
<form method="get" class="filterbar"><input type="hidden" name="v" value="<?= (int) $v['id'] ?>"><input type="hidden" name="tab" value="matches"><?php if ($hwSite): ?><select name="miles" aria-label="<?= te('Distance from the site') ?>" id="miles-filter"><option value=""><?= te('Any distance') ?></option><?php foreach (geo_radii() as $r): ?><option value="<?= $r ?>"<?= $hwFilter['miles'] === $r ? ' selected' : '' ?>><?= te('Within :n miles of the site', ['n' => $r]) ?></option><?php endforeach; ?></select><?php else: ?><span class="small muted" id="no-site-location"><?= te('No distance: the project\'s site has no city and state or ZIP code.') ?></span><?php endif; ?><input type="search" name="q" value="<?= e($hwFilter['q']) ?>" placeholder="<?= te('Search by name, place or degree') ?>" aria-label="<?= te('Search') ?>"><button class="btn ghost"><?= te('Search') ?></button></form>
<?php if (! $hwMatches): ?><div class="card empty-state"><h3><?= te('No match in the pool') ?></h3><p><?= te('Nobody in the talent pool fits this requisition yet.') ?></p></div><?php else: ?>
<div class="rows" id="matches">
  <div class="rows-head"><span><?= te('Person') ?></span><span><?= te('Background') ?></span><span><?= te('With the agency') ?></span><span></span></div>
  <?php foreach ($hwMatches as $m): ?><div class="row-item" data-match="<?= (int) $m['id'] ?>">
    <div class="who"><span class="avatar" aria-hidden="true"><?= e(hw_initials((string) $m['full_name'])) ?></span><div><a class="name" href="/candidates/<?= (int) $m['id'] ?>"><?= e($m['full_name']) ?></a><div class="sm"><?= e(trim(($m['city'] ?? '') . ', ' . ($m['state'] ?? ''), ', ')) ?><?= $m['miles_away'] !== null ? ' · <span data-miles="' . (int) $m['id'] . '">' . te('≈ :n mi', ['n' => (int) round((float) $m['miles_away'])]) . '</span>' : '' ?></div></div></div>
    <div class="sm"><?= $m['degree'] ? e($m['degree']) : te('No degree on file') ?><?= $m['years_exp'] !== null ? ' · ' . te(':n yr', ['n' => (int) $m['years_exp']]) : '' ?></div>
    <div class="sm"><?= (int) $m['past_jobs'] ? ten((int) $m['past_jobs'], ':n past assignment', ':n past assignments') : te('No assignment yet') ?><div><?= $m['last_contact_at'] ? te('Last contact :when', ['when' => hw_ago((string) $m['last_contact_at'])]) : te('Not contacted yet') ?></div></div>
    <div><?php if ((int) $v['is_open']): ?><form method="post" action="/hiring"><?= csrf_field() ?><input type="hidden" name="do" value="add"><input type="hidden" name="vacancy_id" value="<?= (int) $v['id'] ?>"><input type="hidden" name="candidate_id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="back" value="<?= e($hwUrl(['tab' => 'matches', 'q' => $hwFilter['q']])) ?>"><button class="btn sm"><?= te('Add to this job') ?></button></form><?php endif; ?></div>
  </div><?php endforeach; ?>
</div><?php endif; ?>

<?php else: ?>
<div class="grid g2" id="requisition">
  <div class="card"><h3><?= te('The job') ?></h3>
    <p class="small"><?= nl2br(e((string) $v['description'])) ?></p>
    <?php if (! empty($v['requirements'])): ?><h3><?= te('Must-haves') ?></h3><p class="small"><?= nl2br(e((string) $v['requirements'])) ?></p><?php endif; ?></div>
  <div class="card"><h3><?= te('Terms') ?></h3>
    <table><tr><td class="muted"><?= te('Openings') ?></td><td><?= (int) $v['openings'] ?></td></tr>
      <?php if ($v['starts_on']): ?><tr><td class="muted"><?= te('Starts') ?></td><td><?= e(d((string) $v['starts_on'])) ?></td></tr><?php endif; ?>
      <?php if (! empty($v['shift'])): ?><tr><td class="muted"><?= te('Shift') ?></td><td><?= e((string) $v['shift']) ?></td></tr><?php endif; ?>
      <?php if (! empty($v['degree'])): ?><tr><td class="muted"><?= te('Degree required') ?></td><td><?= e((string) $v['degree']) ?></td></tr><?php endif; ?>
      <?php if ($v['years_experience'] !== null): ?><tr><td class="muted"><?= te('Years of experience') ?></td><td><?= (int) $v['years_experience'] ?></td></tr><?php endif; ?>
      <?php if ($v['pay_rate'] !== null): ?><tr><td class="muted"><?= te('Pay rate') ?></td><td><?= e(money($v['pay_rate'])) ?></td></tr><?php endif; ?>
    </table></div>
</div>
<?php endif; ?>
<?php endif; ?>
