<?php
/**
 * Choosing whose folder to open.
 *
 * The folder itself needs an id in the query string. Nothing in the
 * interface shows an id, so opening the menu entry simply failed. This is
 * the missing front door.
 */

$stages = candidate_stages();
?>

<div class="page-heading">
  <div>
    <span class="eyebrow"><?= te('HIRING') ?></span>
    <h1><?= te('Employee folders') ?></h1>
    <p class="sub">
      <?= te('One folder per person, kept across every project they work: their identity, applications, assignments, credentials and the history of what was done to the record.') ?>
    </p>
  </div>
  <a class="btn ghost" href="/candidates"><?= te('Candidate board') ?></a>
</div>

<form class="card" method="get">
  <div class="row">
    <div style="flex:3">
      <label for="fq"><?= te('Find somebody') ?></label>
      <input id="fq" name="q" value="<?= e($search) ?>"
             placeholder="<?= te('Name, email or employee number') ?>">
    </div>
    <div class="row tight">
      <button class="btn" type="submit"><?= te('Search') ?></button>
      <?php if ($search !== ''): ?>
        <a class="btn ghost" href="/employee-folder"><?= te('Clear') ?></a>
      <?php endif; ?>
    </div>
  </div>
</form>

<div class="card tight">
  <div style="padding:14px 18px;border-bottom:1px solid var(--line)">
    <h2 style="margin:0">
      <?= $search !== ''
          ? te(':n match :term', ['n' => count($people), 'term' => e($search)])
          : te('Most recently added') ?>
    </h2>
  </div>

  <?php if (! $people): ?>
    <div class="empty">
      <?= $search !== ''
          ? te('Nobody on file matches :term.', ['term' => e($search)])
          : te('Nobody is on file yet.') ?>
    </div>
  <?php else: ?>
    <div class="scroll">
      <table>
        <thead><tr>
          <th><?= te('Person') ?></th><th><?= te('Employee number') ?></th>
          <th><?= te('Trade') ?></th><th><?= te('Where') ?></th>
          <th><?= te('Stage') ?></th><th class="right"><?= te('Open') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($people as $person): ?>
          <tr>
            <td><strong><?= e($person['full_name']) ?></strong></td>
            <td class="small mono muted"><?= e($person['employee_number'] ?: '—') ?></td>
            <td class="small">
              <?= e(disciplines(true)[$person['discipline']] ?? ucfirst((string) $person['discipline'])) ?>
            </td>
            <td class="small muted">
              <?= e(trim(($person['city'] ?? '') . ' ' . ($person['state'] ?? ''))) ?: '—' ?>
            </td>
            <td>
              <span class="tag <?= e(stage_colour((string) $person['stage'])) ?>">
                <?= te($stages[$person['stage']] ?? $person['stage']) ?>
              </span>
            </td>
            <td class="right">
              <a class="btn ghost sm" href="/employee-folder?id=<?= (int) $person['id'] ?>">
                <?= te('Open folder') ?>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
