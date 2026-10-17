<?php
/** The call list. Colour carries the state; the search bar carries the rest. */
// Exactly the outcomes the handler accepts - a sixth one here would be
// offered on screen and refused on submission.
$callOutcomes = contact_outcomes();

$stages = candidate_stages();
?>

<div class="page-head"><div><h1><?= te('Candidates') ?></h1>
<p class="sub"><?= $total ?> on file &middot; <?= (int) ($counts['new'] ?? 0) ?> nobody has called yet</p></div></div>

<form class="card" method="get" action="/candidates">
  <div class="row">
    <div class="f2">
      <label for="q"><?= te('Search') ?></label>
      <input id="q" name="q" value="<?= e($search) ?>" placeholder="<?= te('Name, phone, email or city') ?>">
    </div>
    <?php // P5-M82: the applicants of one requisition, in the order they applied. ?>
    <div class="f2">
      <label for="vacancy"><?= te('Applied to') ?></label>
      <select id="vacancy" name="vacancy">
        <option value=""><?= te('Any requisition, or none') ?></option>
        <?php foreach ($vacancyChoices as $vc): ?><option value="<?= (int) $vc['id'] ?>"<?= (int) $vc['id'] === $vacancyFilter ? ' selected' : '' ?>><?= e($vc['title'] . ' · ' . $vc['job']) ?> (<?= (int) $vc['applied'] ?>)</option><?php endforeach; ?>
      </select>
      <?php if ($vacancyFilter): ?><span class="hint d-block" data-pool-tabs><?php foreach (['applied' => t('Applicants'), 'match' => t('Matching in the database')] as $pk => $pl): ?><?= $vacancyPool === $pk ? '<strong>' . e($pl) . '</strong>' : '<a href="?' . e(http_build_query(['pool' => $pk === 'match' ? 'match' : '', 'page' => ''] + $_GET)) . '">' . e($pl) . '</a>' ?> &middot; <?php endforeach; ?></span><?php endif; ?>
      <?php if ($vacancyFilter && $vacancyPool === 'applied'): ?><span class="hint"><?= ($_GET['order'] ?? '') === 'newest' ? te('Newest application first.') : te('First applied, first listed.') ?> <a href="?<?= e(http_build_query(['order' => ($_GET['order'] ?? '') === 'newest' ? '' : 'newest'] + $_GET)) ?>"><?= ($_GET['order'] ?? '') === 'newest' ? te('Oldest first') : te('Newest first') ?></a></span><?php endif; ?>
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
    <div class="row tight gap-l pb-s">
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
  </div>
  <div class="row mt-s" id="location-filter">
    <div class="in-l">
      <label for="in_state"><?= te('State') ?></label>
      <select id="in_state" name="in_state">
        <option value=""><?= te('Any state') ?></option>
        <?php foreach (geo_states() as $code => $stateName): ?>
          <option value="<?= e($code) ?>" <?= $geoState === $code ? 'selected' : '' ?>><?= e($code . ' - ' . $stateName) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="in-l">
      <label for="city"><?= te('City') ?></label>
      <input id="city" name="city" value="<?= e($geoCity) ?>" maxlength="120">
    </div>
    <div class="in-m">
      <label for="miles"><?= te('Distance') ?></label>
      <select id="miles" name="miles">
        <option value=""><?= te('Any distance') ?></option>
        <?php foreach (geo_radii() as $r): ?><option value="<?= $r ?>" <?= $geoMiles === $r ? 'selected' : '' ?>><?= te('Within :n miles', ['n' => $r]) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="f2">
      <label for="near"><?= te('of the site of') ?></label>
      <select id="near" name="near">
        <option value=""><?= te('Choose a project') ?></option>
        <?php foreach ($geoProjects as $gp): ?>
          <option value="<?= (int) $gp['id'] ?>" <?= $geoNear === (int) $gp['id'] ? 'selected' : '' ?>><?= e($gp['title'] . ' - ' . trim(($gp['site_city'] ?? '') . ', ' . ($gp['site_state'] ?? ''), ', ')) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="in-m">
      <label for="near_zip"><?= te('or of ZIP code') ?></label>
      <input id="near_zip" name="near_zip" value="<?= e($geoZip) ?>" maxlength="10" inputmode="numeric">
    </div>
  </div>
  <?php if ($geoMissing): ?><p class="small t-red m0 mt-s" id="location-missing"><?= te('That place could not be found: give the project a city and state or a ZIP code, or try another ZIP code.') ?></p><?php endif; ?>
  <div class="row mt-s">
    <div class="row tight">
      <button class="btn" type="submit"><?= te('Filter') ?></button>
      <a class="btn ghost" href="/candidates"><?= te('Clear') ?></a>
    </div>
  </div>
</form>

<section class="card" id="talent-pool">
  <span class="eyebrow"><?= te('TALENT POOL') ?></span>
  <h2 class="m0 mt-xs mb-xs"><?= te('Find people by their history with the agency') ?></h2>
  <p class="small muted m0 mb">
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
        <p class="mt">
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
      <th><input type="checkbox" aria-label="<?= te('Select all') ?>" data-pick-all></th>
      <th><?= te('Name') ?></th><th><?= te('Field') ?></th><th><?= te('Telephone') ?></th><th><?= te('Where') ?></th>
      <th><?= te('Stage') ?></th><th><?= te('Last call') ?></th><th><?= te('Recruiter') ?></th><th class="right"><?= te('Log a call') ?></th><th class="right"><?= te('Move to') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($list as $c): ?>
      <tr>
        <td><input type="checkbox" value="<?= (int) $c['id'] ?>" data-pick aria-label="<?= e($c['full_name']) ?>"></td>
        <td>
          <a href="/candidates/<?= (int) $c['id'] ?>"
             class="<?= in_array($c['rehire_status'] ?? '', rehire_blocked(), true) ? 'barred' : '' ?>">
            <?= e($c['full_name']) ?>
          </a>
          <?= rehire_tag($c['rehire_status'] ?? null) ?>
          <?php if (! empty($c['applied_at'])): ?><div class="small muted" data-applied><?= te('Applied :date', ['date' => d(substr((string) $c['applied_at'], 0, 10))]) ?><?= $c['applied_via'] ? ' &middot; ' . e((string) $c['applied_via']) : '' ?></div><?php endif; ?>
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
        <td class="small muted"><?= e(trim(($c['city'] ?? '') . ', ' . ($c['state'] ?? ''), ' ,')) ?: '—' ?><?php if ($c['miles_away'] !== null): ?><div data-miles="<?= (int) $c['id'] ?>"><?= te('≈ :n mi', ['n' => (int) round((float) $c['miles_away'])]) ?></div><?php endif; ?></td>
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
<?php // P5-M82: the people ticked get one message - email or text - through a campaign (unsubscribe, approval above 50). ?>
<form method="get" action="/campaigns" class="pick-bar card" id="message-selected" hidden>
  <input type="hidden" name="ids" value=""><input type="hidden" name="vacancy_id" value="<?= (int) ($vacancyFilter ?? 0) ?>"><input type="hidden" name="preview" value="1">
  <strong data-pick-count></strong> <button class="btn sm" name="channel" value="email"><?= te('Email them') ?></button> <button class="btn ghost sm" name="channel" value="text"><?= te('Text them') ?></button>
</form>
<script>(function(){var bar=document.getElementById('message-selected');if(!bar)return;var boxes=function(){return [].slice.call(document.querySelectorAll('[data-pick]'));};var sync=function(){var ids=boxes().filter(function(b){return b.checked;}).map(function(b){return b.value;});bar.hidden=!ids.length;bar.querySelector('[name=ids]').value=ids.join(',');bar.querySelector('[data-pick-count]').textContent=ids.length+' <?= te('selected') ?>';};document.addEventListener('change',function(e){if(e.target.matches('[data-pick-all]')){boxes().forEach(function(b){b.checked=e.target.checked;});}if(e.target.matches('[data-pick],[data-pick-all]'))sync();});})();</script>
