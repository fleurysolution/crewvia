<?php
/**
 * The workforce at a glance. Included at the top of Activity for staff.
 * Expects $wf (see app/pages/activity.php).
 */
$t = $wf['today'];
// Each tile opens a page the reader's desk can open; the roll call is admin-only.
$rollCall = can('admin') ? '/structure' : '/roster';
$leave = can('recruiter') ? '/timeoff' : '/roster';
$tiles = [
    ['n' => $t['on_assignment'], 'label' => t('On assignment'), 'foot' => t('Confirmed, travelling or on site'), 'tone' => 'wf-navy',   'href' => '/roster', 'icon' => 'people'],
    ['n' => $t['present'],       'label' => t('Present today'), 'foot' => t('Marked present at roll call'),     'tone' => 'wf-teal',   'href' => $rollCall, 'icon' => 'check'],
    ['n' => $t['absent'],        'label' => t('Absent today'),  'foot' => t('Marked absent at roll call'),      'tone' => 'wf-violet', 'href' => $rollCall, 'icon' => 'cross'],
    ['n' => $t['on_leave'],      'label' => t('On leave today'),'foot' => t('Approved time off covering today'),'tone' => 'wf-olive',  'href' => $leave, 'icon' => 'leave'],
];
$icons = [
    'people' => '<circle cx="9" cy="8" r="3.2"/><circle cx="17" cy="9" r="2.6"/><path d="M2.5 19c.6-3.6 3.3-5.5 6.5-5.5s5.9 1.9 6.5 5.5z"/><path d="M15.2 13.9c2.8.1 5 1.8 5.6 5.1h-4.4"/>',
    'check'  => '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 19c.6-3.6 3.3-5.5 6.5-5.5s5.9 1.9 6.5 5.5z"/><path d="M15.5 12.5l2.2 2.2 4-4.4" fill="none" stroke-width="2.2"/>',
    'cross'  => '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 19c.6-3.6 3.3-5.5 6.5-5.5s5.9 1.9 6.5 5.5z"/><path d="M16 10.5l5 5m0-5l-5 5" fill="none" stroke-width="2.2"/>',
    'leave'  => '<circle cx="12" cy="8" r="3.4"/><path d="M5 20c.7-4 3.6-6.2 7-6.2s6.3 2.2 7 6.2z" fill="none" stroke-width="1.8"/>',
];
?>
<style>
.wf{margin:0 0 22px}
.wf-tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:14px}
.wf-tile{position:relative;display:block;color:#fff;border-radius:var(--radius,10px);overflow:hidden;min-height:112px;
  box-shadow:0 1px 2px rgba(15,46,76,.18);text-decoration:none!important;transition:transform .12s ease}
.wf-tile:hover{transform:translateY(-1px)}
.wf-tile .wf-body{padding:14px 16px 10px}
.wf-tile .wf-n{font-size:30px;font-weight:700;line-height:1;font-variant-numeric:tabular-nums}
.wf-tile .wf-l{font-size:14px;margin-top:8px;opacity:.95}
.wf-tile .wf-foot{background:rgba(0,0,0,.16);font-size:12px;padding:7px 16px;text-align:center;opacity:.95}
.wf-tile svg.wf-icon{position:absolute;right:12px;top:12px;width:56px;height:56px;fill:rgba(255,255,255,.22);stroke:rgba(255,255,255,.22)}
.wf-navy{background:linear-gradient(135deg,#0F2E4C,#1F4F8F)}
.wf-teal{background:linear-gradient(135deg,#3C8FA0,#6AAFBF)}
.wf-violet{background:linear-gradient(135deg,#5B3FB0,#8B6BD8)}
.wf-olive{background:linear-gradient(135deg,#5E7A3A,#8AA35E)}
.wf-panels{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(220px,.62fr);gap:14px}
.wf-panel{background:var(--card,#fff);border:1px solid var(--line,#D4DCE6);border-radius:var(--radius,10px);padding:14px 16px}
.wf-panel h3{margin:0 0 8px;font-size:14px;color:var(--navy,#0F2E4C)}
.wf-panel .wf-sub{font-size:12px;color:var(--muted,#657387);margin:-4px 0 8px}
.wf-chart{width:100%;height:auto;display:block}
.wf-grid{stroke:var(--line,#D4DCE6);stroke-width:1}
.wf-axis{fill:var(--muted,#657387);font-size:11px}
.wf-bar.present{fill:#3C8FA0}
.wf-bar.absent{fill:#8B6BD8}
.wf-recent{list-style:none;margin:0;padding:0}
.wf-recent li{padding:8px 0;border-bottom:1px solid var(--line,#D4DCE6);font-size:13px}
.wf-recent li:last-child{border-bottom:0}
.wf-recent .wf-meta{font-size:12px;color:var(--muted,#657387)}
@media (max-width:1100px){.wf-panels{grid-template-columns:1fr 1fr}.wf-panels .wf-panel:last-child{grid-column:1/-1}}
@media (max-width:760px){.wf-tiles{grid-template-columns:1fr 1fr}.wf-panels{grid-template-columns:1fr}}
@media (max-width:420px){.wf-tiles{grid-template-columns:1fr}}
</style>

<section class="wf" aria-label="<?= te('Workforce at a glance') ?>">
  <div class="wf-tiles">
    <?php foreach ($tiles as $tile): ?>
      <a class="wf-tile <?= $tile['tone'] ?>" href="<?= e($tile['href']) ?>">
        <svg class="wf-icon" viewBox="0 0 24 24" aria-hidden="true"><?= $icons[$tile['icon']] ?></svg>
        <div class="wf-body">
          <div class="wf-n"><?= (int) $tile['n'] ?></div>
          <div class="wf-l"><?= e($tile['label']) ?></div>
        </div>
        <div class="wf-foot"><?= e($tile['foot']) ?></div>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="wf-panels">
    <div class="wf-panel">
      <h3><?= te('Present, last 30 days') ?></h3>
      <p class="wf-sub"><?= te('People marked present at roll call each day on this project.') ?></p>
      <?php if (array_sum($wf['present']) === 0): ?>
        <p class="small muted"><?= te('No roll call has been taken on this project in the last 30 days. It is taken on the Deployment view.') ?></p>
      <?php endif; ?>
      <?= workforce_bar_chart($wf['present'], t('Present, last 30 days'), 'present') ?>
    </div>
    <div class="wf-panel">
      <h3><?= te('Absent, last 15 days') ?></h3>
      <p class="wf-sub"><?= te('People marked absent at roll call each day on this project.') ?></p>
      <?php if (array_sum($wf['absent']) === 0): ?>
        <p class="small muted"><?= te('Nobody has been marked absent in the last 15 days.') ?></p>
      <?php endif; ?>
      <?= workforce_bar_chart($wf['absent'], t('Absent, last 15 days'), 'absent') ?>
    </div>
    <div class="wf-panel">
      <h3><?= te('Recently placed') ?></h3>
      <?php if (! $wf['recent']): ?>
        <p class="small muted"><?= te('Nobody has been placed on this project yet.') ?></p>
      <?php else: ?>
        <ul class="wf-recent">
          <?php foreach ($wf['recent'] as $r): ?>
            <li><a href="/placements/<?= (int) $r['id'] ?>"><?= e($r['full_name']) ?></a>
              <div class="wf-meta"><?= e(trim(($r['trade'] ?: '') . ' · ' . ($r['start_date'] ? t('Starts :date', ['date' => d($r['start_date'])]) : t('No start date yet')), ' ·')) ?></div></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($wf['today']['unmarked'] > 0): ?>
        <p class="small" style="margin:10px 0 0;color:var(--amber,#B4740B)"><?= te(':n on assignment not yet marked at roll call today.', ['n' => $wf['today']['unmarked']]) ?></p>
      <?php endif; ?>
    </div>
  </div>
</section>
