<?php
/**
 * Candidate documents (P5-M42).
 *
 * The office's list of every CV, cover letter, diploma or other document on
 * a candidate's file, found by name, kind or requisition, each one leading
 * back to the person's application. Adding somebody who applied by email is
 * done in Hiring (Add an applicant), with their details and documents
 * together. Importing a job board's files in bulk is a secondary tool at the
 * foot of the page, with its steps, not the first thing the office sees.
 * A worker sees only their own documents.
 */
require_once __DIR__ . '/../intake.php';
$kinds = candidate_document_kinds();
?>

<div class="page-heading">
  <div>
    <h1><?= $own ? te('My documents') : te('Candidate documents') ?></h1>
    <p class="sub">
      <?= $own
          ? te('The CV and other documents on your file. Only you and the recruiting desk can open them.')
          : te('Every CV, cover letter, diploma and other document on a candidate\'s file. Stored encrypted: only the recruiting desk and the person themselves can open one.') ?>
    </p>
  </div>
  <?php if (! $own): ?>
  <div class="actions">
    <a class="btn" href="/hiring" title="<?= te('Open the requisition, then Add an applicant') ?>"><?= te('Add an applicant') ?></a>
    <a class="btn ghost" href="/candidates"><?= te('All candidates') ?></a>
  </div>
  <?php endif; ?>
</div>

<?php if (! $own): ?>
<form method="get" action="/resumes" class="filterbar" id="documents-filter">
  <input type="search" name="q" value="<?= e($docQ) ?>" placeholder="<?= te('Name or file name') ?>" aria-label="<?= te('Search documents') ?>">
  <select name="kind" aria-label="<?= te('Kind of document') ?>"><option value=""><?= te('Every kind') ?></option><?php foreach ($kinds as $k => $l): ?><option value="<?= e($k) ?>"<?= $docKind === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <select name="v" aria-label="<?= te('Requisition') ?>"><option value="0"><?= te('Every requisition') ?></option><?php foreach ($bulkVacancies as $bv): ?><option value="<?= (int) $bv['id'] ?>"<?= $docVacancy === (int) $bv['id'] ? ' selected' : '' ?>><?= e($bv['project'] . ' · ' . $bv['title']) ?></option><?php endforeach; ?></select>
  <button class="btn ghost"><?= te('Show') ?></button>
</form>
<?php endif; ?>

<?php if (! $resumes): ?>
  <div class="card"><div class="empty">
    <?php if (! $own && ($docQ !== '' || $docKind !== '' || $docVacancy)): ?>
      <?= te('No document matches. Clear the filters to see them all.') ?>
      <p class="mt"><a class="btn ghost" href="/resumes"><?= te('Clear the filters') ?></a></p>
    <?php else: ?>
      <?= $own ? te('No document on your file yet.') : te('No document on file yet. One arrives with an application, or when the office adds an applicant with what they sent.') ?>
      <?php if (! $own): ?><p class="mt"><a class="btn ghost" href="/hiring"><?= te('Open Hiring') ?></a></p><?php endif; ?>
    <?php endif; ?>
  </div></div>
<?php else: ?>
  <div class="card tight">
    <div class="card-bar">
      <h2 class="m0"><?= ten((int) $docTotal, ':n document', ':n documents') ?></h2>
      <?php if ($docTotal > count($resumes)): ?><span class="small muted"><?= te('The latest :n shown; search to find the others.', ['n' => count($resumes)]) ?></span><?php endif; ?>
    </div>
    <div class="scroll">
      <table id="documents">
        <thead><tr>
          <?php if (! $own): ?><th><?= te('Person') ?></th><?php endif; ?>
          <th><?= te('Kind') ?></th><th><?= te('File') ?></th>
          <th><?= te('Received') ?></th><th class="right"><?= te('Open') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($resumes as $resume): ?>
          <tr>
            <?php if (! $own): ?>
            <td>
              <?php if (! empty($resume['app_id'])): ?>
                <a href="/hiring?v=<?= (int) $resume['app_vacancy'] ?>&app=<?= (int) $resume['app_id'] ?>"><?= e($resume['full_name']) ?></a>
              <?php else: ?>
                <a href="/candidates/<?= (int) $resume['candidate_id'] ?>"><?= e($resume['full_name']) ?></a>
              <?php endif; ?>
            </td>
            <?php endif; ?>
            <td><span class="tag grey"><?= e($kinds[$resume['kind'] ?? 'cv'] ?? t('CV')) ?></span></td>
            <td class="small muted"><?= e($resume['original_name']) ?></td>
            <td class="small nowrap"><?= e(d($resume['created_at'])) ?></td>
            <td class="right">
              <a class="btn ghost sm" href="/resumes?download=<?= (int) $resume['id'] ?>"><?= te('Download') ?></a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php if (! $own && $bulkVacancies): ?>
<details class="card mt-l" id="import-board-documents">
  <summary><strong><?= te('Import documents from a job board') ?></strong> <span class="small muted"><?= te('For applicants already imported from a job board export') ?></span></summary>
  <ol class="small mt">
    <li><?= te('Import the job board\'s applicant export first (Administration, Import employee spreadsheets, as applicants to the requisition).') ?></li>
    <li><?= te('Download their files from the job board, keeping its file names: ResumeFirstLast.pdf for a CV, CoverLetterFirstLast.txt for a typed cover letter.') ?></li>
    <li><?= te('Choose the requisition and up to 15 files. Each file goes to the applicant whose name it carries; one that matches nobody, or two people, is listed and attached to nobody.') ?></li>
  </ol>
  <form method="post" action="/resumes" enctype="multipart/form-data" id="attach-bulk"><?= csrf_field() ?><input type="hidden" name="do" value="attach_bulk">
    <div class="row">
      <div class="f2"><label for="bulk-v"><?= te('Requisition') ?></label><select id="bulk-v" name="vacancy_id" required><?php foreach ($bulkVacancies as $bv): ?><option value="<?= (int) $bv['id'] ?>"<?= $bulkVacancy === (int) $bv['id'] ? ' selected' : '' ?>><?= e($bv['project'] . ' · ' . $bv['title']) ?> (<?= ten((int) $bv['applicants'], ':n applicant', ':n applicants') ?>)</option><?php endforeach; ?></select></div>
      <div class="f2"><label for="bulk-files"><?= te('Files from the job board') ?></label><input id="bulk-files" type="file" name="resumes[]" accept=".pdf,.docx,.txt" multiple required></div>
    </div>
    <div class="actions mt"><button class="btn ghost"><?= te('Import the files') ?></button></div>
  </form>
</details>
<?php endif; ?>
