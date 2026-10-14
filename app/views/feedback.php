<?php
$fbKinds = fb_kinds();
$fbTone = ['praise' => 'green', 'suggestion' => 'blue', 'concern' => 'amber'];
$fbList = static function (array $list, string $id, bool $showAbout) use ($fbKinds, $fbTone): void {
    echo '<div id="' . e($id) . '" data-count="' . count($list) . '">';
    if (! $list) {
        echo '<p class="muted">' . te('None yet.') . '</p>';
    }
    foreach ($list as $f) {
        echo '<div class="small m0 mt-s mb-s" data-feedback="' . (int) $f['id'] . '" data-shared="' . (int) $f['is_shared'] . '"><span class="tag ' . $fbTone[$f['kind']] . '">' . e($fbKinds[$f['kind']]) . '</span> ';
        echo '<strong>' . e($f['from_name']) . '</strong>' . ($showAbout ? ' → <strong>' . e($f['about_name']) . '</strong>' : '');
        echo (int) $f['is_shared'] ? '' : ' <span class="tag grey">' . te('Not shared with the person') . '</span>';
        echo ' <span class="muted">' . e(d(substr((string) $f['created_at'], 0, 10))) . '</span><div class="pre-wrap wrap-any">' . e($f['body']) . '</div></div>';
    }
    echo '</div>';
};
?>
<?php if ($answer): ?>
<p class="back-link"><a href="/feedback">← <?= te('Feedback') ?></a></p>
<div class="page-head"><div><h1><?= te('Feedback about :name', ['name' => $answer['about_name']]) ?></h1>
<p class="sub"><?= te('Asked by :name', ['name' => $answer['asked_name']]) ?><?= $answer['due_on'] ? ' · ' . te('Due') . ': ' . e(d((string) $answer['due_on'])) : '' ?></p></div></div>
<div class="card"><p class="pre-wrap"><?= e($answer['question']) ?></p></div>
<?php if ($answer['answered_at'] !== null): ?><p class="muted" id="already-answered"><?= te('You have already answered that request.') ?></p>
<?php else: ?>
<form method="post" class="card" id="answer-form"><?= csrf_field() ?><input type="hidden" name="do" value="give"><input type="hidden" name="request_id" value="<?= (int) $answer['id'] ?>"><input type="hidden" name="about_user_id" value="<?= (int) $answer['about_user_id'] ?>">
  <label><?= te('Kind') ?><select name="kind"><?php foreach ($fbKinds as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
  <label><?= te('Your feedback') ?><textarea name="body" rows="5" required maxlength="2000"></textarea></label>
  <label><input type="checkbox" name="is_shared" value="1" checked> <?= te('Let :name read it', ['name' => $answer['about_name']]) ?></label>
  <button class="btn"><?= te('Give feedback') ?></button></form>
<?php endif; ?>

<?php elseif ($request): ?>
<p class="back-link"><a href="/feedback">← <?= te('Feedback') ?></a></p>
<div class="page-head"><div><h1><?= te('Feedback about :name', ['name' => $request['about_name']]) ?></h1></div></div>
<div class="card"><p class="pre-wrap"><?= e($request['question']) ?></p>
  <p class="small" id="request-people"><?php foreach ($request['people'] as $p): ?><span class="tag <?= $p['answered_at'] ? 'green' : 'grey' ?>" data-asked="<?= (int) $p['user_id'] ?>"><?= e($p['name']) ?></span> <?php endforeach; ?></p></div>
<div class="card"><h2><?= te('Answers') ?></h2><?php $fbList($entries, 'request-answers', false); ?></div>

<?php elseif ($about): ?>
<p class="back-link"><a href="/feedback">← <?= te('Feedback') ?></a></p>
<div class="page-head"><div><h1><?= te('Feedback about :name', ['name' => $about['name']]) ?></h1>
<p class="sub"><?= te('Everything given about this person, shared or not. Opening it is logged.') ?></p></div></div>
<div class="card"><?php $fbList($entries, 'about-entries', false); ?></div>

<?php else: ?>
<div class="page-head"><div><h1><?= te('Feedback') ?></h1>
<p class="sub"><?= te('Give feedback to the people you work with, and ask for it. It is not a performance review, and a concern here does not open an HR case: report conduct to HR.') ?></p></div></div>
<div class="grid g2">
  <form method="post" class="card" id="give-form"><?= csrf_field() ?><input type="hidden" name="do" value="give">
    <h2><?= te('Give feedback') ?></h2>
    <?php if (! $colleagues): ?><p class="muted"><?= te('Nobody to give feedback to yet: you see the people on your live projects.') ?></p><?php else: ?>
    <label><?= te('About') ?><select name="about_user_id"><?php foreach ($colleagues as $cid => $cname): ?><option value="<?= (int) $cid ?>"><?= e($cname) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Kind') ?><select name="kind"><?php foreach ($fbKinds as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Your feedback') ?><textarea name="body" rows="4" required maxlength="2000"></textarea></label>
    <label><input type="checkbox" name="is_shared" value="1" checked> <?= te('Let the person read it') ?></label>
    <p class="small muted"><?= te('Not shared, it is read only by the HR desk and, for a worker, their supervisor.') ?></p>
    <button class="btn"><?= te('Give feedback') ?></button><?php endif; ?></form>
  <form method="post" class="card" id="ask-form"><?= csrf_field() ?><input type="hidden" name="do" value="ask">
    <h2><?= te('Ask for feedback') ?></h2>
    <label><?= te('About') ?><select name="about_user_id"><option value="0"><?= te('Myself') ?></option><?php foreach ($subjects as $sid => $sname): ?><option value="<?= (int) $sid ?>"><?= e($sname) ?></option><?php endforeach; ?></select></label>
    <label><?= te('From') ?><select name="user_ids[]" multiple size="6" required><?php foreach ($askable as $aid => $aname): ?><option value="<?= (int) $aid ?>"><?= e($aname) ?></option><?php endforeach; ?></select></label>
    <label><?= te('Question') ?><input name="question" required maxlength="500"></label>
    <label><?= te('Due (optional)') ?><input type="date" name="due_on" min="<?= e(date('Y-m-d')) ?>"></label>
    <button class="btn"><?= te('Ask') ?></button></form>
</div>
<?php if ($toAnswer): ?><div class="card" id="to-answer"><h2><?= te('Asked of you') ?></h2><table>
  <?php foreach ($toAnswer as $r): ?><tr data-to-answer="<?= (int) $r['id'] ?>"><td><a href="/feedback?answer=<?= (int) $r['id'] ?>"><?= e($r['about_name']) ?></a><div class="small"><?= e($r['question']) ?></div></td><td class="small"><?= e($r['asked_name']) ?></td><td class="small"><?= $r['due_on'] ? e(d((string) $r['due_on'])) : '' ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
<div class="grid g2">
  <div class="card scroll"><h2><?= te('Received') ?></h2><?php $fbList($received, 'received', false); ?></div>
  <div class="card scroll"><h2><?= te('Given') ?></h2><?php $fbList($given, 'given', true); ?></div>
</div>
<?php if ($myRequests): ?><div class="card" id="my-requests"><h2><?= te('What I asked for') ?></h2><table>
  <?php foreach ($myRequests as $r): ?><tr data-my-request="<?= (int) $r['id'] ?>"><td><a href="/feedback?request=<?= (int) $r['id'] ?>"><?= e($r['about_name']) ?></a><div class="small"><?= e($r['question']) ?></div></td><td class="small"><?= te(':a of :n answered', ['a' => (int) $r['answered'], 'n' => (int) $r['asked']]) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
<?php if ($subjects): ?><div class="card" id="read-about"><h2><?= te('Read feedback about someone') ?></h2>
  <form method="get" class="row"><select name="about" aria-label="<?= te('About') ?>"><?php foreach ($subjects as $sid => $sname): ?><option value="<?= (int) $sid ?>"><?= e($sname) ?></option><?php endforeach; ?></select><button class="btn sm"><?= te('Open') ?></button></form></div><?php endif; ?>
<?php endif; ?>
