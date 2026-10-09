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
    <span class="eyebrow"><?= te('RECRUITING') ?></span>
    <h1><?= te('Candidate resumes') ?></h1>
    <p class="sub">
      <?= te('Attached by applicants when they apply through a requisition link. Stored encrypted: only the recruiting desk and the person themselves can open one.') ?>
    </p>
  </div>
  <a class="btn ghost" href="/candidates"><?= te('All candidates') ?></a>
</div>

<?php if (! $resumes): ?>
  <div class="card"><div class="empty">
    <?= te('No résumés on file yet. One arrives when somebody attaches it to a public application.') ?>
    <p style="margin-top:10px">
      <a class="btn ghost" href="/requisitions"><?= te('Open requisitions') ?></a>
    </p>
  </div></div>
<?php else: ?>
  <div class="card tight">
    <div style="padding:14px 18px;border-bottom:1px solid var(--line)">
      <h2 style="margin:0"><?= te(':n on file', ['n' => count($resumes)]) ?></h2>
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
