<?php
/**
 * Talent search: looking inside what people wrote, for work that does not
 * exist yet.
 *
 * It read as a second, worse copy of the Search screen, with no empty
 * state and a results box that was blank before anything was typed. The
 * difference is worth stating, because it is the reason this screen
 * exists: Search finds a record you already know of, across projects,
 * hotels and orders. This reads the text of résumés and recruiter notes
 * across every candidate on file, whatever project they came in on, which
 * is what somebody does when a client asks whether the agency can field
 * forty welders next month.
 */

$stages = candidate_stages();
?>

<div class="page-heading">
  <div>
    <span class="eyebrow"><?= te('RECRUITING') ?></span>
    <h1><?= te('Talent search') ?></h1>
    <p class="sub">
      <?= te('Reads the text of résumés, degrees and recruiter notes across every candidate on file, on any project. Use it to answer whether the agency could field a trade before committing to it.') ?>
    </p>
  </div>
  <a class="btn ghost" href="/search"><?= te('Search records instead') ?></a>
</div>

<form class="card" method="get">
  <div class="row">
    <div style="flex:3">
      <label for="tq"><?= te('Look for') ?></label>
      <input id="tq" name="q" value="<?= e($query) ?>"
             placeholder="<?= te('Trade, skill, certificate, degree or name') ?>">
      <span class="hint"><?= te('Matches anywhere in a name, a degree, a résumé or a note.') ?></span>
    </div>
    <div class="row tight">
      <button class="btn" type="submit"><?= te('Search') ?></button>
      <?php if ($query !== ''): ?>
        <a class="btn ghost" href="/mining"><?= te('Clear') ?></a>
      <?php endif; ?>
    </div>
  </div>
</form>

<?php if ($query === ''): ?>
  <div class="card"><div class="empty">
    <?= te('Type something to search the pool. Nothing is listed until you do, because this looks across every candidate on file rather than the project you are working on.') ?>
  </div></div>
<?php elseif (! $matches): ?>
  <div class="card"><div class="empty">
    <?= te('Nobody on file matches :term.', ['term' => e($query)]) ?>
    <div class="hint" style="margin-top:8px">
      <?= te('Résumé text is only searchable for people who attached one to a public application.') ?>
    </div>
  </div></div>
<?php else: ?>
  <div class="card tight">
    <div style="padding:14px 18px;border-bottom:1px solid var(--line)">
      <h2 style="margin:0">
        <?= te(':n match :term', ['n' => count($matches), 'term' => e($query)]) ?>
      </h2>
      <?php if (count($matches) >= 100): ?>
        <p class="small muted" style="margin:4px 0 0">
          <?= te('The first 100 are shown. Narrow the term to see the rest.') ?>
        </p>
      <?php endif; ?>
    </div>
    <div class="scroll">
      <table>
        <thead><tr>
          <th><?= te('Person') ?></th><th><?= te('Trade') ?></th>
          <th><?= te('Degree') ?></th><th><?= te('Where') ?></th><th><?= te('Stage') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($matches as $m): ?>
          <tr>
            <td><a href="/candidates/<?= (int) $m['id'] ?>"><?= e($m['full_name']) ?></a></td>
            <td class="small">
              <?= e(disciplines(true)[$m['discipline']] ?? ucfirst((string) $m['discipline'])) ?>
            </td>
            <td class="small muted"><?= e($m['degree'] ?: '—') ?></td>
            <td class="small muted">
              <?= e(trim(($m['city'] ?? '') . ' ' . ($m['state'] ?? ''))) ?: '—' ?>
            </td>
            <td>
              <span class="tag <?= e(stage_colour((string) $m['stage'])) ?>">
                <?= te($stages[$m['stage']] ?? $m['stage']) ?>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
