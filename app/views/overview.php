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
            ? ten($totals['placed'], ':n person out on one job', ':n people out on one job')
            : ten($totals['placed'], ':n person out across :jobs jobs', ':n people out across :jobs jobs', ['jobs' => $totals['running']]) ?>
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
      <p class="mt"><a class="btn" href="/projects"><?= te('Create the first project') ?></a></p>
    </div>
  </div>
<?php else: ?>

<!-- ── the whole book of business, in three groups (T25) ─────────── -->
<?php $fillRate = $totals['target'] > 0 ? (int) round($totals['placed'] / $totals['target'] * 100) : null;
      $marginPct = $totals['weekly_bill'] > 0 ? (int) round($margin / $totals['weekly_bill'] * 100) : null;
      // Whole dollars in the summary: three amounts with cents do not fit side by side. The table keeps the cents.
      $whole = static fn(float $v): string => ($v < 0 ? '-$' : '$') . number_format(abs($v)); ?>
<div class="overview-groups" id="overview-groups">
  <section class="card overview-group" id="group-people">
    <h2><?= te('People') ?></h2>
    <div class="overview-figures">
      <div><div class="n" data-figure="onsite"><?= $totals['onsite'] ?></div><div class="l"><?= te('On site now') ?></div></div>
      <div><div class="n" data-figure="placed"><?= $totals['placed'] ?></div><div class="l"><?= te('Confirmed, travelling or on site') ?></div></div>
      <div><div class="n <?= $unplaced ? 't-amber' : 't-green' ?>" data-figure="unplaced"><?= $unplaced ?></div><div class="l"><?= te('Still to place, of :n asked for', ['n' => $totals['target']]) ?></div></div>
    </div>
    <?php if ($fillRate !== null): ?><div class="headcount-bar" style="margin:10px 0 4px"><span style="width:<?= min(100, $fillRate) ?>%"></span></div><div class="small muted" data-figure="fill"><?= te(':n% of the headcount filled', ['n' => $fillRate]) ?></div><?php endif; ?>
    <div class="overview-sub">
      <span class="small muted"><?= te('Hiring') ?>:</span>
      <a href="/hiring" data-figure="new_week"><?= ten((int) $hiring['new_week'], ':n new applicant this week', ':n new applicants this week') ?></a>
      · <a href="/hiring" data-figure="reviewing"><?= te(':n being reviewed', ['n' => (int) $hiring['reviewing']]) ?></a>
      · <a href="/hiring" data-figure="offered"><?= ten((int) $hiring['offered'], ':n offer out', ':n offers out') ?></a>
      · <span class="small muted"><?= ten((int) $hiring['open_reqs'], ':n open requisition', ':n open requisitions') ?></span>
    </div>
  </section>

  <section class="card overview-group" id="group-money">
    <h2><?= te('This week\'s money') ?></h2>
    <div class="overview-figures money">
      <div><div class="n" data-figure="pay"><?= e($whole($totals['weekly_pay'])) ?></div><div class="l"><?= te('Crew cost') ?></div></div>
      <div><div class="n" data-figure="bill"><?= $totals['weekly_bill'] > 0 ? e($whole($totals['weekly_bill'])) : '—' ?></div><div class="l"><?= te('Billed to clients') ?></div></div>
      <div><div class="n <?= $margin < 0 ? 't-red' : '' ?>" data-figure="margin"><?= $totals['weekly_bill'] > 0 ? e($whole($margin)) : '—' ?></div><div class="l"><?= $marginPct !== null ? te('Margin, :n% of billing', ['n' => $marginPct]) : te('Margin: no client rates agreed') ?></div></div>
    </div>
    <div class="overview-sub small muted"><?= te('What one week of the people out costs and bills, at their agreed rates.') ?> <a href="/project-costs"><?= te('Project costs') ?></a></div>
  </section>

  <section class="card overview-group" id="group-attention">
    <h2><?= te('Needs somebody today') ?> <span class="tag <?= $attention ? 'red' : 'green' ?>" data-figure="attention"><?= array_sum(array_column($attention, 'count')) ?></span></h2>
    <?php if (! $attention): ?>
      <p class="small" style="color:var(--green)"><?= te('Nothing waiting. Every project is clear.') ?></p>
    <?php else: ?>
      <ul class="attention-list">
        <?php foreach ($attention as $x): ?><li data-attention="<?= e($x['where']) ?>"><span class="dot <?= e($x['tone']) ?>"></span><a href="<?= e($x['where']) ?>"><strong><?= (int) $x['count'] ?></strong> <?= te($x['label']) ?></a><?php if ($x['projects']): ?> <span class="small muted"><?= ten($x['projects'], 'on :n project', 'on :n projects') ?></span><?php endif; ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<div class="chips" id="stage-chips" style="margin:4px 0 10px">
  <a href="/overview" class="<?= $stageFilter === '' ? 'on' : '' ?>" data-stage-chip=""><?= te('All') ?><b><?= count($projects) ?></b></a>
  <?php foreach ($stages as $key => $s): if (empty($stageCounts[$key])) { continue; } ?><a href="/overview?stage=<?= e($key) ?>" class="<?= $stageFilter === $key ? 'on' : '' ?>" data-stage-chip="<?= e($key) ?>"><?= te($s['label']) ?><b><?= (int) $stageCounts[$key] ?></b></a><?php endforeach; ?>
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
      <?php foreach ($shown as $p): ?>
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
