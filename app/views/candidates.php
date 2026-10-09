<?php
/** The call list. Colour carries the state; the search bar carries the rest. */
// Exactly the outcomes the handler accepts - a sixth one here would be
// offered on screen and refused on submission.
$callOutcomes = contact_outcomes();

$stages = candidate_stages();
?>

<h1><?= te('Candidates') ?></h1>
<p class="sub"><?= $total ?> on file &middot; <?= (int) ($counts['new'] ?? 0) ?> nobody has called yet</p>

<form class="card" method="get" action="/candidates">
  <div class="row">
    <div style="flex:2">
      <label for="q"><?= te('Search') ?></label>
      <input id="q" name="q" value="<?= e($search) ?>" placeholder="<?= te('Name, phone, email or city') ?>">
    </div>
    <div>
      <label for="stage"><?= te('Stage') ?></label>
      <select id="stage" name="stage">
        <option value=""><?= te('Any stage') ?></option>
        <?php foreach ($stages as $k => $v): ?>
          <option value="<?= e($k) ?>" <?= $stage === $k ? 'selected' : '' ?>>
            <?= e($v) ?> (<?= (int) ($counts[$k] ?? 0) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="skill"><?= te('Skill') ?></label>
      <select id="skill" name="skill">
        <option value=""><?= te('Any skill') ?></option>
        <?php foreach (skills_list() as $slug => $label): ?>
          <option value="<?= e($slug) ?>" <?= $skill === $slug ? 'selected' : '' ?>>
            <?= te($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <span class="hint"><?= te('What they can do, not their department.') ?></span>
    </div>
    <div>
      <label for="field"><?= te('Discipline') ?></label>
      <select id="field" name="field">
        <option value=""><?= te('Either') ?></option>
        <?php foreach (disciplines() as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $discipline === $key ? 'selected' : '' ?>>
            <?= te($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="row tight" style="gap:14px;padding-bottom:7px">
      <label class="tick">
        <input type="checkbox" name="phone" value="1" <?= $hasPhone ? 'checked' : '' ?>>
        <?= te('Has a number') ?>
      </label>
      <label class="tick" title="<?= te('A candidate is assigned to whoever contacts them first.') ?>">
        <input type="checkbox" name="mine" value="1" <?= $mine ? 'checked' : '' ?>>
        <?= te('Assigned to me') ?>
      </label>
      <label class="tick" title="<?= te('Everybody marked No rehire or DO NOT USE.') ?>">
        <input type="checkbox" name="blocked" value="1" <?= $blocked ? 'checked' : '' ?>>
        <?= te('On the register') ?>
      </label>
    </div>
    <div class="row tight">
      <button class="btn" type="submit"><?= te('Filter') ?></button>
      <a class="btn ghost" href="/candidates"><?= te('Clear') ?></a>
    </div>
  </div>
</form>

<section class="card" id="talent-pool">
  <span class="eyebrow"><?= te('TALENT POOL') ?></span>
  <h2 style="margin:4px 0 2px"><?= te('Find people by their history with the agency') ?></h2>
  <p class="small muted" style="margin:0 0 14px">
    <?= te('Separate from the search above: this looks at what happened last time rather than at who somebody is.') ?>
  </p>

  <form class="pool-filters" method="get">
    <label class="own-room">
      <input type="checkbox" name="worked_before" value="1" <?= isset($_GET['worked_before']) ? 'checked' : '' ?>>
      <?= te('Previously worked here') ?>
    </label>
    <label class="own-room">
      <input type="checkbox" name="rejected_before" value="1" <?= isset($_GET['rejected_before']) ? 'checked' : '' ?>>
      <?= te('Previous rejection') ?>
    </label>
    <label class="own-room">
      <input type="checkbox" name="available" value="1" <?= isset($_GET['available']) ? 'checked' : '' ?>>
      <?= te('Available / rehire review') ?>
    </label>
    <label class="own-room" title="<?= te('A screening expires: check it is still valid before relying on it.') ?>">
      <input type="checkbox" name="screened_before" value="1" <?= isset($_GET['screened_before']) ? 'checked' : '' ?>>
      <?= te('Previously screened') ?>
    </label>
    <button class="btn" type="submit"><?= te('Search the pool') ?></button>
  </form>
</section>


<div class="card tight">
  <?php if (! $list): ?>
    <?php /* An empty database and a search that found nothing look the same
             on screen and are not the same problem. Telling somebody to clear
             filters they never set sends them hunting for a cause that is not
             there. */ ?>
    <?php if ((int) $total === 0): ?>
      <div class="empty">
        <?= te('No candidates yet. They arrive through a requisition QR code, the public talent pool, or a spreadsheet import.') ?>
        <p style="margin-top:10px">
          <a class="btn" href="/requisitions"><?= te('Create a requisition') ?></a>
          <a class="btn ghost" href="/imports"><?= te('Import spreadsheets') ?></a>
        </p>
      </div>
    <?php else: ?>
      <div class="empty"><?= te('Nobody matches that.') ?> <a href="/candidates"><?= te('Clear the filters') ?></a>.</div>
    <?php endif; ?>
  <?php else: ?>
  <div class="scroll">
  <table class="wide">
    <thead><tr>
      <th><?= te('Name') ?></th><th><?= te('Field') ?></th><th><?= te('Telephone') ?></th><th><?= te('Where') ?></th>
      <th><?= te('Stage') ?></th><th><?= te('Last call') ?></th><th><?= te('Recruiter') ?></th><th class="right"><?= te('Log a call') ?></th><th class="right"><?= te('Move to') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($list as $c): ?>
      <tr>
        <td>
          <a href="/candidates/<?= (int) $c['id'] ?>"
             class="<?= in_array($c['rehire_status'] ?? '', rehire_blocked(), true) ? 'barred' : '' ?>">
            <?= e($c['full_name']) ?>
          </a>
          <?= rehire_tag($c['rehire_status'] ?? null) ?>
          <?php if ((int) $c['calls'] > 0): ?>
            <span class="small muted">&middot; <?= (int) $c['calls'] ?> call<?= (int) $c['calls'] === 1 ? '' : 's' ?></span>
          <?php endif; ?>
        </td>
        <td class="small">
          <?= e(disciplines(true)[$c['discipline']] ?? ucfirst((string) $c['discipline'])) ?>
          <?php if (! empty($c['skill_slugs'])): ?>
            <?php
            $theirs = array_slice(explode(',', (string) $c['skill_slugs']), 0, 3);
            $named  = array_map(static fn ($s) => t(skills_list(true)[$s] ?? $s), $theirs);
            ?>
            <div class="muted small"><?= e(implode(', ', $named)) ?></div>
          <?php endif; ?>
        </td>
        <td class="small mono">
          <?php if (! empty($c['phone'])): ?>
            <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $c['phone'])) ?>"><?= e($c['phone']) ?></a>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td class="small muted"><?= e(trim(($c['city'] ?? '') . ', ' . ($c['state'] ?? ''), ' ,')) ?: '—' ?></td>
        <td><span class="tag <?= e(stage_colour($c['stage'])) ?>"><?= e($stages[$c['stage']] ?? $c['stage']) ?></span></td>
        <td class="small">
          <?php if ($c['last_contact_at']): ?>
            <?= e(date('j M, g:ia', strtotime($c['last_contact_at']))) ?>
            <?php if (! empty($c['last_outcome'])): ?>
              <div class="muted"><?= te($callOutcomes[$c['last_outcome']] ?? $c['last_outcome']) ?></div>
            <?php endif; ?>
          <?php else: ?>
            <span class="muted"><?= te('never') ?></span>
          <?php endif; ?>
        </td>
        <td class="small muted"><?= e($c['owner_name'] ?? '—') ?></td>
        <td class="right nowrap">
          <form method="post" action="/candidates" class="call-log">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="log_call">
            <input type="hidden" name="candidate_id" value="<?= (int) $c['id'] ?>">
            <input type="hidden" name="back" value="<?= e($qs) ?>">
            <label class="sr-only" for="note-<?= (int) $c['id'] ?>">
              <?= te('What was said to :name', ['name' => $c['full_name']]) ?>
            </label>
            <input id="note-<?= (int) $c['id'] ?>" name="note" maxlength="500"
                   placeholder="<?= te('Note (optional)') ?>">
            <label class="sr-only" for="call-<?= (int) $c['id'] ?>">
              <?= te('How the call to :name went', ['name' => $c['full_name']]) ?>
            </label>
            <select id="call-<?= (int) $c['id'] ?>" name="outcome" class="move-select"
                    onchange="this.form.submit()">
              <option value=""><?= te('Record a call') ?></option>
              <?php foreach ($callOutcomes as $key => $label): ?>
                <option value="<?= e($key) ?>"><?= te($label) ?></option>
              <?php endforeach; ?>
            </select>
            <noscript><button class="btn sm"><?= te('Log') ?></button></noscript>
          </form>
        </td>
        <td class="right nowrap">
          <form method="post" action="/candidates" class="stage-move">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="stage">
            <input type="hidden" name="candidate_id" value="<?= (int) $c['id'] ?>">
            <input type="hidden" name="back" value="<?= e($qs) ?>">
            <select name="stage" class="move-select"
                    onchange="this.form.submit()">
              <option value="">—</option>
              <?php foreach ($stages as $k => $v): ?>
                <?php if ($k !== $c['stage']): ?>
                  <option value="<?= e($k) ?>"><?= e($v) ?></option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="small muted table-footer">
    <span>
      <?= te('Showing :n of :total matching, page :page', [
          'n' => count($list), 'total' => $listTotal, 'page' => $page]) ?>
    </span>
    <span class="legend">
      <span class="tag blue"><?= te('Never contacted') ?></span>
      <span class="tag amber"><?= te('In progress') ?></span>
      <span class="tag green"><?= te('Accepted or placed') ?></span>
      <span class="tag red"><?= te('Not proceeding') ?></span>
    </span>
  </p>
  <?php endif; ?>
</div>
<div class="card row pager"><span></span><?php if($page>1): ?><a href="/candidates?<?= e(http_build_query(array_merge($_GET,['page'=>$page-1]))) ?>"><?= te('Previous') ?></a><?php endif; ?><?php if($page*100<$listTotal): ?><a href="/candidates?<?= e(http_build_query(array_merge($_GET,['page'=>$page+1]))) ?>"><?= te('Next') ?></a><?php endif; ?></div>