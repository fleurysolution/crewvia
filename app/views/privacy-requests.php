<div class="page-head"><div><h1><?= te('Privacy') ?></h1>
<p class="sub"><?= te('The agency\'s privacy notice, the consents given to it, and the requests people make about their information. Every action here is logged.') ?></p></div></div>

<form class="card" method="post" id="publish-form"><?= csrf_field() ?><input type="hidden" name="do" value="publish">
  <h2><?= te('Privacy notice') ?></h2>
  <p class="muted"><?= te('Write it with the agency\'s adviser: who the agency is, what it keeps about applicants and workers, why, for how long, who it shares it with, and how to ask for a copy or a correction. Crewvia shows it at /privacy and links it from every public form; it does not write it.') ?></p>
  <label for="notice-body"><?= te('Text of the notice') ?></label>
  <textarea id="notice-body" name="body" rows="14" maxlength="60000" required minlength="200"><?= e((string) ($notice['body'] ?? '')) ?></textarea>
  <button class="btn"><?= te('Publish this version') ?></button> <a href="/privacy" target="_blank" rel="noopener"><?= te('See it as the public sees it') ?></a>
</form>

<div class="card scroll" id="notice-versions"><h2><?= te('Versions') ?></h2>
  <?php if (! $versions): ?><p class="muted"><?= te('No notice published yet: the public forms say so.') ?></p><?php else: ?>
  <table><tr><th>#</th><th><?= te('Published') ?></th><th><?= te('By') ?></th><th class="num"><?= te('Consents') ?></th><th>SHA-256</th></tr>
  <?php foreach ($versions as $v): ?><tr data-version="<?= (int) $v['id'] ?>"><td class="mono"><?= (int) $v['id'] ?></td><td><?= e((string) $v['published_at']) ?></td><td><?= e((string) ($v['by_name'] ?? '')) ?></td><td class="num"><?= (int) $v['consents'] ?></td><td class="mono small"><?= e(substr((string) $v['sha256'], 0, 16)) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>

<div class="grid g2">
  <form class="card" method="post" id="export-form"><?= csrf_field() ?><input type="hidden" name="do" value="export">
    <h2><?= te('A person asks what is held about them') ?></h2>
    <label for="export-who"><?= te('Their email, or their number') ?></label><input id="export-who" name="who" required maxlength="190">
    <p class="small muted"><?= te('One file of everything held about them. Bank details and identity documents are listed, not decrypted; send those through the usual pages if they are asked for.') ?></p>
    <button class="btn"><?= te('Download the file') ?></button>
  </form>
  <form class="card" method="post" id="anonymise-form"><?= csrf_field() ?><input type="hidden" name="do" value="anonymise">
    <h2><?= te('A person asks to be removed') ?></h2>
    <label for="anon-who"><?= te('Their email, or their number') ?></label><input id="anon-who" name="who" required maxlength="190">
    <label for="anon-reason"><?= te('Why, and when they asked') ?></label><input id="anon-reason" name="reason" required minlength="5" maxlength="300">
    <p class="small muted"><?= te('Only an applicant never placed is removed: name, contact details, notes and résumé go. Anyone placed keeps their record, because pay, tax and employment records must be kept.') ?></p>
    <button class="btn ghost"><?= te('Remove the applicant') ?></button>
  </form>
</div>

<div class="card scroll" id="consents"><h2><?= te('Consents recorded') ?></h2>
  <p class="small muted" id="unrecorded" data-n="<?= (int) $unrecorded ?>"><?= te(':n people in Crewvia have no consent recorded: entered by hand, imported, or received before consent was recorded.', ['n' => $unrecorded]) ?></p>
  <?php if ($consents): ?><table><tr><th><?= te('Person') ?></th><th><?= te('For') ?></th><th><?= te('Through') ?></th><th><?= te('Notice version') ?></th><th><?= te('When') ?></th></tr>
  <?php foreach ($consents as $c): ?><tr data-consent="<?= (int) $c['candidate_id'] ?>"><td><?= e($c['full_name']) ?> <span class="muted">#<?= (int) $c['candidate_id'] ?></span></td><td><?= e($c['purpose']) ?></td><td><?= e($c['source']) ?></td>
    <td><?= $c['notice_id'] ? (int) $c['notice_id'] : '<span class="tag amber">' . te('none published') . '</span>' ?></td><td class="small"><?= e((string) $c['given_at']) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>
