<?php
$stages = ['new' => 'Never contacted', 'contacted' => 'Contacted', 'screening' => 'Screening',
           'offered' => 'Offered', 'accepted' => 'Accepted', 'placed' => 'Placed',
           'declined' => 'Declined', 'rejected' => 'Rejected'];
?>

<div class="page-heading">
  <div>
    <span class="eyebrow"><?= te('SEARCH') ?></span>
    <h1>
      <?php if ($q === ''): ?>
        <?= te('Find anybody, anywhere') ?>
      <?php elseif ($total === 0): ?>
        <?= te('Nothing matched :q', ['q' => $q]) ?>
      <?php else: ?>
        <?= $total === 1
            ? te('One result for :q', ['q' => $q])
            : te(':n results for :q', ['n' => $total, 'q' => $q]) ?>
      <?php endif; ?>
    </h1>
    <p class="sub">
      <?= te('People, projects, client orders, hotels, contracts and accounts — whatever your desk can open.') ?>
    </p>
  </div>
</div>

<form class="card" method="get" action="/search">
  <div class="row">
    <div style="flex:3">
      <label for="search-q"><?= te('Search') ?></label>
      <input id="search-q" name="q" value="<?= e($q) ?>" autofocus
             placeholder="<?= te('A name, a phone number, a hotel, a project') ?>">
    </div>
    <button class="btn" type="submit"><?= te('Search') ?></button>
  </div>
</form>

<?php if ($q !== '' && mb_strlen($q) < 2): ?>
  <div class="card"><div class="empty"><?= te('Two letters or more, otherwise everything matches.') ?></div></div>
<?php endif; ?>

<?php if ($q !== '' && $total === 0 && mb_strlen($q) >= 2): ?>
  <div class="card">
    <div class="empty">
      <?= te('Nothing here matched. A person who has never applied and was never imported will not be in the system at all.') ?>
      <p><a class="btn ghost" href="/candidates"><?= te('Open the candidate board') ?></a></p>
    </div>
  </div>
<?php endif; ?>

<?php foreach ($groups as $group): ?>
<section class="card tight">
  <div style="padding:14px 18px;border-bottom:1px solid var(--line)">
    <h2 style="margin:0">
      <?= te($group['title']) ?>
      <span class="muted small"><?= count($group['rows']) ?></span>
    </h2>
  </div>

  <ul class="results">
    <?php foreach ($group['rows'] as $r): ?>
      <li>
      <?php if ($group['icon'] === 'person'): ?>
        <a href="/candidates/<?= (int) $r['id'] ?>">
          <span class="result-main">
            <strong><?= e($r['full_name']) ?></strong>
            <span class="muted small">
              <?= te(ucfirst((string) ($r['discipline'] ?: 'other'))) ?>
              <?php $where = trim(($r['city'] ?? '') . ' ' . ($r['state'] ?? '')); ?>
              <?php if ($where !== ''): ?>&middot; <?= e($where) ?><?php endif; ?>
              <?php if ($r['phone']): ?>&middot; <span class="mono"><?= e($r['phone']) ?></span><?php endif; ?>
            </span>
          </span>
          <span class="result-side">
            <span class="tag <?= e(stage_colour((string) $r['stage'])) ?>">
              <?= te($stages[$r['stage']] ?? $r['stage']) ?>
            </span>
            <?php if ((int) $r['placements']): ?>
              <span class="muted small"><?= te(':n assignments', ['n' => (int) $r['placements']]) ?></span>
            <?php endif; ?>
          </span>
        </a>

      <?php elseif ($group['icon'] === 'project'): ?>
        <a href="/projects">
          <span class="result-main">
            <strong><?= e($r['title']) ?></strong>
            <span class="muted small">
              <?= e($r['client_name'] ?? '') ?>
              <?php $where = trim(($r['site_name'] ?? '') . ' ' . ($r['site_city'] ?? '') . ' ' . ($r['site_state'] ?? '')); ?>
              <?php if ($where !== ''): ?>&middot; <?= e($where) ?><?php endif; ?>
            </span>
          </span>
          <span class="result-side">
            <span class="tag <?= $r['status'] === 'active' ? 'green' : ($r['status'] === 'closed' ? 'grey' : 'blue') ?>">
              <?= te(ucfirst((string) $r['status'])) ?>
            </span>
          </span>
        </a>

      <?php elseif ($group['icon'] === 'order'): ?>
        <a href="/requisitions">
          <span class="result-main">
            <strong><?= e($r['title']) ?></strong>
            <span class="muted small">
              <?= e($r['project']) ?>
              &middot; <?= te(ucfirst((string) ($r['discipline'] ?: 'other'))) ?>
              &middot; <?= te(':n needed', ['n' => (int) $r['openings']]) ?>
            </span>
          </span>
          <span class="result-side">
            <span class="tag <?= $r['is_open'] ? 'green' : 'grey' ?>">
              <?= $r['is_open'] ? te('Open') : te('Closed') ?>
            </span>
          </span>
        </a>

      <?php elseif ($group['icon'] === 'hotel'): ?>
        <a href="/hotels">
          <span class="result-main">
            <strong><?= e($r['name']) ?></strong>
            <span class="muted small">
              <?= e(trim(($r['city'] ?? '') . ' ' . ($r['state'] ?? ''))) ?>
              <?php if ($r['phone']): ?>&middot; <span class="mono"><?= e($r['phone']) ?></span><?php endif; ?>
              <?php if ($r['nightly_rate']): ?>&middot; <?= e(money((float) $r['nightly_rate'])) ?><?php endif; ?>
            </span>
          </span>
          <span class="result-side">
            <span class="muted small"><?= te(':n rooms held', ['n' => (int) $r['rooms']]) ?></span>
          </span>
        </a>

      <?php elseif ($group['icon'] === 'contract'): ?>
        <a href="/contracts?id=<?= (int) $r['id'] ?>">
          <span class="result-main">
            <strong><?= e($r['title']) ?></strong>
            <span class="muted small">
              <?= e($r['full_name']) ?> &middot; <?= te('Revision :n', ['n' => (int) $r['revision']]) ?>
            </span>
          </span>
          <span class="result-side">
            <span class="tag <?= $r['status'] === 'signed' ? 'green' : 'amber' ?>">
              <?= te(ucfirst(str_replace('_', ' ', (string) $r['status']))) ?>
            </span>
          </span>
        </a>

      <?php else: ?>
        <a href="/people">
          <span class="result-main">
            <strong><?= e($r['name']) ?></strong>
            <span class="muted small"><?= e($r['email']) ?></span>
          </span>
          <span class="result-side">
            <span class="tag <?= $r['is_active'] ? 'blue' : 'grey' ?>">
              <?= te(roles()[$r['role']] ?? $r['role']) ?>
            </span>
            <?php if (! $r['is_active']): ?>
              <span class="muted small"><?= te('inactive') ?></span>
            <?php endif; ?>
          </span>
        </a>
      <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>

<?php if ($q === '' && $scope !== ''): ?>
<section class="card tight">
  <div style="padding:14px 18px;border-bottom:1px solid var(--line)">
    <span class="eyebrow"><?= te('WHO IS WHERE') ?></span>
    <h2 style="margin:4px 0 2px"><?= te(':n out on a project', ['n' => count($directory)]) ?></h2>
    <p class="small muted" style="margin:0"><?= te($scope) ?></p>
  </div>

  <?php if (! $directory): ?>
    <div class="empty"><?= te('Nobody is out on a project right now.') ?></div>
  <?php else: ?>
  <div class="scroll">
    <table class="wide">
      <thead><tr>
        <th><?= te('Person') ?></th><th><?= te('Project') ?></th><th><?= te('Site') ?></th>
        <th><?= te('Trade') ?></th><th><?= te('Shift') ?></th>
        <th><?= te('Where they sleep') ?></th><th><?= te('Supervisor') ?></th>
        <th><?= te('Status') ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($directory as $d): ?>
        <tr>
          <td>
            <a href="/candidates/<?= (int) $d['candidate_id'] ?>"><?= e($d['full_name']) ?></a>
            <?php if ($d['phone']): ?>
              <div class="muted small mono"><?= e($d['phone']) ?></div>
            <?php endif; ?>
          </td>
          <td class="small"><?= e($d['project']) ?></td>
          <td class="small muted">
            <?= e(trim(($d['site_name'] ?? '') . ' ' . ($d['site_city'] ?? '') . ' ' . ($d['site_state'] ?? ''))) ?: '&mdash;' ?>
          </td>
          <td class="small"><?= e($d['trade'] ?: ucfirst((string) $d['discipline'])) ?></td>
          <td class="small muted"><?= e($d['shift_label'] ?: '&mdash;') ?></td>
          <td class="small">
            <?php if ($d['hotel']): ?>
              <?= e($d['hotel']) ?>
              <div class="muted">
                <?= $d['room_number'] ? te('room :n', ['n' => $d['room_number']]) : te('room not set') ?>
                <?= (int) $d['private_room'] ? '' : ' · ' . te('sharing') ?>
              </div>
            <?php else: ?>
              <span class="tag amber"><?= te('No bed') ?></span>
            <?php endif; ?>
          </td>
          <td class="small">
            <?= $d['supervisor'] ? e($d['supervisor']) : '<span class="tag amber">' . te('Nobody') . '</span>' ?>
          </td>
          <td>
            <span class="tag <?= $d['status'] === 'on_site' ? 'green' : 'blue' ?>">
              <?= te(ucfirst(str_replace('_', ' ', (string) $d['status']))) ?>
            </span>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
