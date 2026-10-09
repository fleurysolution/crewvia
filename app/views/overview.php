<?php
require_once __DIR__ . '/../lifecycle.php';

$stages = lifecycle_stages();

$stageTag = static fn (string $s): string => match ($s) {
    'on_site'      => 'green',
    'mobilising'   => 'amber',
    'demobilising' => 'blue',
    'closed'       => 'grey',
    default        => 'grey',
};

$margin = $totals['weekly_bill'] - $totals['weekly_pay'];
?>

<div class="page-heading">
  <div>
    <span class="eyebrow"><?= te('EVERY PROJECT') ?></span>
    <h1>
      <?php if (! $projects): ?>
        <?= te('No projects yet') ?>
      <?php else: ?>
        <?= $totals['running'] === 1
            ? te(':n people out on one job', ['n' => $totals['placed']])
            : te(':n people out across :jobs jobs',
                 ['n' => $totals['placed'], 'jobs' => $totals['running']]) ?>
      <?php endif; ?>
    </h1>
    <p class="sub">
      <?= te('What is running, what it earns, and what needs somebody today.') ?>
    </p>
  </div>
  <a class="btn ghost" href="/projects"><?= te('Projects') ?></a>
</div>

<?php if (! $projects): ?>
  <div class="card">
    <div class="empty">
      <?= te('Nothing has been set up yet. A project holds the client, the site, the rates and the guarantee — everything else hangs off it.') ?>
      <p style="margin-top:12px"><a class="btn" href="/projects"><?= te('Create the first project') ?></a></p>
    </div>
  </div>
<?php else: ?>

<!-- ── the whole book of business ──────────────────────────────────── -->
<div class="grid g4" style="margin-bottom:18px">
  <div class="stat">
    <div class="n"><?= $totals['onsite'] ?></div>
    <div class="l"><?= te('On site right now') ?></div>
    <div class="h"><?= te(':n confirmed or travelling', ['n' => $totals['placed']]) ?></div>
  </div>
  <div class="stat">
    <div class="n" style="color:<?= $unplaced ? 'var(--amber)' : 'var(--green)' ?>"><?= $unplaced ?></div>
    <div class="l"><?= te('Still to place') ?></div>
    <div class="h"><?= te('against :n asked for', ['n' => $totals['target']]) ?></div>
  </div>
  <div class="stat">
    <div class="n"><?= e(money($totals['weekly_pay'])) ?></div>
    <div class="l"><?= te('Crew cost a week') ?></div>
    <div class="h">
      <?= $totals['weekly_bill'] > 0
          ? te('billing :amount, margin :margin',
               ['amount' => money($totals['weekly_bill']), 'margin' => money($margin)])
          : te('no client rates agreed') ?>
    </div>
  </div>
  <div class="stat">
    <div class="n" style="color:<?= $totals['exceptions'] ? 'var(--red)' : 'var(--green)' ?>">
      <?= $totals['exceptions'] ?>
    </div>
    <div class="l"><?= te('Things needing somebody') ?></div>
    <div class="h">
      <?= $incidents
          ? te(':n open safety incidents', ['n' => $incidents])
          : te('no open safety incidents') ?>
    </div>
  </div>
</div>

<!-- ── project by project ─────────────────────────────────────────── -->
<div class="card tight">
  <div class="scroll">
    <table class="portfolio">
      <thead><tr>
        <th><?= te('Project') ?></th>
        <th><?= te('Stage') ?></th>
        <th><?= te('Crew') ?></th>
        <th><?= te('A week is worth') ?></th>
        <th><?= te('Needs somebody') ?></th>
        <th class="right"><?= te('Open') ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($projects as $p): ?>
        <?php
        $job    = $p['job'];
        $f      = $p['figures'];
        $filled = $f['target'] > 0 ? (int) round($f['placed'] / $f['target'] * 100) : 0;
        ?>
        <tr<?= $p['stage'] === 'closed' ? ' class="muted-row"' : '' ?>>
          <td>
            <strong><?= e($job['title']) ?></strong>
            <div class="muted small">
              <?= e($job['client_name'] ?? '') ?>
              <?php $where = trim(($job['site_city'] ?? '') . ' ' . ($job['site_state'] ?? '')); ?>
              <?php if ($where !== ''): ?>&middot; <?= e($where) ?><?php endif; ?>
            </div>
            <?php if ($job['strike_live']): ?>
              <div><span class="tag red"><?= te('Strike is live') ?></span></div>
            <?php endif; ?>
          </td>

          <td>
            <span class="tag <?= $stageTag($p['stage']) ?>">
              <?= te($stages[$p['stage']]['label']) ?>
            </span>
            <?php if ($p['next']): ?>
              <div class="muted small">
                <?= te(':met of :total to :stage', [
                    'met' => $p['met'], 'total' => $p['signals'],
                    'stage' => t($stages[$p['next']]['label'])]) ?>
              </div>
            <?php endif; ?>
          </td>

          <td>
            <strong><?= $f['placed'] ?></strong><span class="muted"> / <?= $f['target'] ?></span>
            <div class="headcount-bar" style="margin-top:5px;max-width:120px">
              <span style="width:<?= min(100, $filled) ?>%"></span>
            </div>
            <div class="muted small"><?= te(':n on site', ['n' => $f['onsite']]) ?></div>

            <?php if ($p['trades']): ?>
              <ul class="trade-split">
                <?php foreach ($p['trades'] as $line): ?>
                  <?php $short = (int) $line['placed'] < (int) $line['quantity']; ?>
                  <li>
                    <span class="trade-name"><?= e($line['role_title']) ?></span>
                    <span class="mono <?= $short ? 'short' : '' ?>">
                      <?= (int) $line['placed'] ?>/<?= (int) $line['quantity'] ?>
                    </span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <div class="small" style="color:var(--amber);margin-top:6px">
                <?= te('No scope written') ?>
              </div>
            <?php endif; ?>
          </td>

          <td>
            <span class="mono"><?= e(money($f['weekly_pay'])) ?></span>
            <div class="muted small">
              <?= $f['weekly_bill'] > 0
                  ? te('margin :amount', ['amount' => money($f['weekly_bill'] - $f['weekly_pay'])])
                  : te('no bill rate agreed') ?>
            </div>
          </td>

          <td class="small">
            <?php if (! $p['exceptions']): ?>
              <span class="tag green"><?= te('Clear') ?></span>
            <?php else: ?>
              <ul class="blocker-list">
                <?php foreach ($p['exceptions'] as $x): ?>
                  <li>
                    <a href="<?= e($x['where']) ?>">
                      <?= $x['count'] ?> <?= te($x['label']) ?>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </td>

          <td class="right">
            <form method="post" action="/select-project">
              <?= csrf_field() ?>
              <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
              <input type="hidden" name="then" value="/job">
              <button class="btn ghost sm" type="submit"><?= te('Open') ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
