<?php
/**
 * Résumés on file.
 *
 * The screen was a bare heading and an empty box: no explanation of where
 * these documents come from, no empty state, no link to the person they
 * belong to, and no sign that a worker opening it sees only their own. An
 * empty box is indistinguishable from a broken screen.
 */
?>

<div class="page-heading">
  <div>
    <h1><?= te('Candidate resumes') ?></h1>
    <p class="sub">
      <?= te('Attached by applicants when they apply through a requisition link. Stored encrypted: only the recruiting desk and the person themselves can open one.') ?>
    </p>
  </div>
  <a class="btn ghost" href="/candidates"><?= te('All candidates') ?></a>
</div>

<?php if (! empty($bulkVacancies)): ?>
<form method="post" action="/resumes" enctype="multipart/form-data" class="card" id="attach-bulk"><?= csrf_field() ?><input type="hidden" name="do" value="attach_bulk">
  <h2><?= te('Attach CVs to a requisition\'s applicants') ?></h2>
  <p class="small muted"><?= te('Up to 15 files at a time, PDF or Word. Each one goes to the applicant whose name is in the file name, as Indeed names them (ResumeFirstLast.pdf); a file named CoverLetter… is filed as a cover letter. A file that matches nobody, or two people, is attached to nobody and listed.') ?></p>
  <div class="row">
    <div class="f2"><label for="bulk-v"><?= te('Requisition') ?></label><select id="bulk-v" name="vacancy_id" required><?php foreach ($bulkVacancies as $v): ?><option value="<?= (int) $v['id'] ?>"<?= $bulkVacancy === (int) $v['id'] ? ' selected' : '' ?>><?= e($v['project'] . ' · ' . $v['title']) ?> (<?= ten((int) $v['applicants'], ':n applicant', ':n applicants') ?>)</option><?php endforeach; ?></select></div>
    <div class="f2"><label for="bulk-files"><?= te('Files') ?></label><input id="bulk-files" type="file" name="resumes[]" accept=".pdf,.docx,.txt" multiple required></div>
  </div>
  <button class="btn"><?= te('Attach') ?></button>
</form>
<?php endif; ?>

<?php if (! $resumes): ?>
  <div class="card"><div class="empty">
    <?= te('No résumés on file yet. One arrives when somebody attaches it to a public application.') ?>
    <p class="mt">
      <a class="btn ghost" href="/requisitions"><?= te('Open requisitions') ?></a>
    </p>
  </div></div>
<?php else: ?>
  <div class="card tight">
    <div class="card-bar">
      <h2 class="m0"><?= te(':n on file', ['n' => count($resumes)]) ?></h2>
    </div>
    <div class="scroll">
      <table>
        <thead><tr>
          <th><?= te('Person') ?></th><th><?= te('File') ?></th>
          <th><?= te('Received') ?></th><th class="right"><?= te('Open') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($resumes as $resume): ?>
          <tr>
            <td>
              <a href="/candidates/<?= (int) $resume['candidate_id'] ?>">
                <?= e($resume['full_name']) ?>
              </a>
            </td>
            <td class="small muted"><?= e($resume['original_name']) ?></td>
            <td class="small"><?= e(d($resume['created_at'])) ?></td>
            <td class="right">
              <a class="btn ghost sm" href="/resumes?download=<?= (int) $resume['id'] ?>">
                <?= te('Download') ?>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
