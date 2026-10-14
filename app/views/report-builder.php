<?php
$periods = rb_relative_periods();
$ops = rb_operators();
$weekdays = [0 => t('Sunday'), 1 => t('Monday'), 2 => t('Tuesday'), 3 => t('Wednesday'), 4 => t('Thursday'), 5 => t('Friday'), 6 => t('Saturday')];
$roleNames = ['recruiter' => t('Recruiting'), 'payroll' => t('Payroll'), 'hotels' => t('Hotel desk')];
?>
<style>table.report th, table.report td { white-space: nowrap; }</style>
<p class="small"><a href="/reports"><?= te('Reports') ?></a> · <a href="/report-builder"><?= te('Report builder') ?></a></p>

<?php if ($form !== null): ?>
<div class="page-head"><div><h1><?= $form['id'] ? te('Change :name', ['name' => $form['name']]) : te('New saved report') ?></h1></div></div>
<form method="get" class="row"><input type="hidden" name="<?= $form['id'] ? 'edit' : 'new' ?>" value="<?= $form['id'] ? (int) $form['id'] : 1 ?>">
  <?php if (! $form['id']): ?><label><?= te('Built on') ?></label><select name="source" onchange="this.form.submit()"><?php foreach ($readable as $k => $r): ?><option value="<?= e($k) ?>"<?= $k === $form['source'] ? ' selected' : '' ?>><?= te($r['title']) ?></option><?php endforeach; ?></select><noscript><button class="btn ghost"><?= te('Choose') ?></button></noscript><?php endif; ?>
</form>
<form method="post" class="card" id="builder-form"><?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="definition_id" value="<?= (int) $form['id'] ?>"><input type="hidden" name="source" value="<?= e($form['source']) ?>">
  <label><?= te('Name') ?></label><input name="name" value="<?= e($form['name']) ?>" required minlength="3" maxlength="120">
  <h3><?= te('Columns') ?></h3>
  <div class="row"><?php foreach ($form['all_columns'] as $c => [$label, $type]): ?><label><input type="checkbox" name="columns[]" value="<?= e($c) ?>"<?= in_array($c, $form['columns'], true) ? ' checked' : '' ?>> <?= te($label) ?></label><?php endforeach; ?></div>
  <h3><?= te('Period') ?></h3>
  <?php $f = $form['filters']; ?>
  <div class="row"><select name="period"><?php foreach ($periods as $k => $label): ?><option value="<?= e($k) ?>"<?= ($f['period'] ?? 'fixed') === $k ? ' selected' : '' ?>><?= te($label) ?></option><?php endforeach; ?></select>
    <label><?= te('From') ?></label><input type="date" name="from" value="<?= e((string) ($f['from'] ?? '')) ?>"><label><?= te('To') ?></label><input type="date" name="to" value="<?= e((string) ($f['to'] ?? '')) ?>">
    <label><?= te('At') ?></label><input type="date" name="asof" value="<?= e((string) ($f['asof'] ?? '')) ?>"><label><?= te('Year') ?></label><input type="number" name="year" min="2000" max="2100" value="<?= e((string) ($f['year'] ?? '')) ?>">
    <select name="job_id"><option value="0"><?= te('Every project') ?></option><?php foreach ($jobs as $j): ?><option value="<?= (int) $j['id'] ?>"<?= (int) ($f['job_id'] ?? 0) === (int) $j['id'] ? ' selected' : '' ?>><?= e($j['title']) ?></option><?php endforeach; ?></select></div>
  <p class="small muted"><?= te('A relative period is worked out on the day the report runs: last month on 3 June is May. Fixed dates use From and To, At and Year as given.') ?></p>
  <h3><?= te('Conditions') ?></h3>
  <?php for ($i = 0; $i < 5; $i++): $c = $form['conditions'][$i] ?? ['', 'eq', '']; ?>
    <div class="row"><select name="cond_column[<?= $i ?>]"><option value=""></option><?php foreach ($form['all_columns'] as $k => [$label, $type]): ?><option value="<?= e($k) ?>"<?= $c[0] === $k ? ' selected' : '' ?>><?= te($label) ?></option><?php endforeach; ?></select>
      <select name="cond_op[<?= $i ?>]"><?php foreach ($ops as $k => $label): ?><option value="<?= e($k) ?>"<?= $c[1] === $k ? ' selected' : '' ?>><?= te($label) ?></option><?php endforeach; ?></select>
      <input name="cond_value[<?= $i ?>]" value="<?= e((string) $c[2]) ?>" maxlength="190"></div>
  <?php endfor; ?>
  <h3><?= te('Order and size') ?></h3>
  <div class="row"><select name="sort_column"><option value=""><?= te('As the report gives it') ?></option><?php foreach ($form['all_columns'] as $k => [$label, $type]): ?><option value="<?= e($k) ?>"<?= $form['sort_column'] === $k ? ' selected' : '' ?>><?= te($label) ?></option><?php endforeach; ?></select>
    <select name="sort_dir"><option value="asc"><?= te('Ascending') ?></option><option value="desc"<?= $form['sort_dir'] === 'desc' ? ' selected' : '' ?>><?= te('Descending') ?></option></select>
    <label><?= te('At most') ?></label><input type="number" name="row_limit" min="1" max="5000" value="<?= (int) $form['row_limit'] ?>"><label><?= te('rows') ?></label></div>
  <label><input type="checkbox" name="shared" value="1"<?= (int) $form['shared'] ? ' checked' : '' ?>> <?= te('Share with everyone who may read this report') ?></label>
  <button class="btn"><?= te('Save the report') ?></button>
</form>

<?php elseif ($definition !== null): ?>
<?php $f = $result['filters']; $src = $catalog[$definition['source']]; ?>
<div class="page-head"><div><h1><?= e($definition['name']) ?></h1>
<p class="sub"><?= te('Built on :report', ['report' => t($src['title'])]) ?> · <span id="resolved" data-from="<?= e($f['from']) ?>" data-to="<?= e($f['to']) ?>" data-asof="<?= e($f['asof']) ?>" data-year="<?= (int) $f['year'] ?>"><?= te(($periods[$definition['filters']['period'] ?? 'fixed'] ?? 'Fixed dates')) ?>: <?= e(d($f['from'])) ?> – <?= e(d($f['to'])) ?></span>
  <?= $definition['conditions'] ? ' · ' . te(':n condition(s)', ['n' => count($definition['conditions'])]) : '' ?></p></div></div>
<div class="row">
  <?php if (rb_may_change($definition)): ?><a class="btn ghost" href="/report-builder?edit=<?= (int) $definition['id'] ?>"><?= te('Change') ?></a><?php endif; ?>
  <?php if (rb_can_export((string) $definition['source'])): ?>
  <form method="post" id="export-form"><?= csrf_field() ?><input type="hidden" name="do" value="export"><input type="hidden" name="definition_id" value="<?= (int) $definition['id'] ?>"><button class="btn"><?= te('Export as CSV') ?></button></form>
  <?php else: ?><span class="tag grey" id="no-export"><?= te('Your role may read this report but not export it') ?></span><?php endif; ?>
</div>
<div class="card scroll"><?php require __DIR__ . '/_report-table.php'; ?></div>

<?php if (rb_may_change($definition)): ?>
<div class="grid g2" id="schedules">
  <div class="card scroll"><h2><?= te('Schedules') ?></h2>
    <?php if (! $schedules): ?><p class="muted"><?= te('Not scheduled.') ?></p><?php endif; ?>
    <?php foreach ($schedules as $s): $who = json_decode((string) $s['recipients_json'], true) ?: []; ?>
      <div data-schedule="<?= (int) $s['id'] ?>" data-active="<?= (int) $s['active'] ?>">
        <p><b><?= te(ucfirst((string) $s['frequency'])) ?></b><?= $s['frequency'] === 'weekly' ? ' · ' . e($weekdays[(int) $s['weekday']]) : '' ?><?= $s['frequency'] === 'monthly' ? ' · ' . te('day :n', ['n' => (int) $s['monthday']]) : '' ?> · <?= sprintf('%02d:00', (int) $s['run_hour']) ?>
          · <?= te(':n recipient(s)', ['n' => count($who)]) ?> · <?= (int) $s['active'] ? te('next :at', ['at' => $s['next_run_at']]) : te('stopped') ?> · <?= te(':n run(s)', ['n' => (int) $s['runs']]) ?></p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="definition_id" value="<?= (int) $definition['id'] ?>"><input type="hidden" name="schedule_id" value="<?= (int) $s['id'] ?>">
          <input type="hidden" name="do" value="<?= (int) $s['active'] ? 'schedule_stop' : 'schedule_start' ?>"><button class="btn sm ghost"><?= (int) $s['active'] ? te('Stop') : te('Restart') ?></button></form>
      </div>
    <?php endforeach; ?>
  </div>
  <form class="card" method="post" id="schedule-form"><?= csrf_field() ?><input type="hidden" name="do" value="schedule"><input type="hidden" name="definition_id" value="<?= (int) $definition['id'] ?>">
    <h2><?= te('Schedule it') ?></h2>
    <div class="row"><select name="frequency"><option value="daily"><?= te('Daily') ?></option><option value="weekly"><?= te('Weekly') ?></option><option value="monthly"><?= te('Monthly') ?></option></select>
      <select name="weekday"><?php foreach ($weekdays as $k => $n): ?><option value="<?= $k ?>"<?= $k === 1 ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
      <label><?= te('Day of the month') ?></label><input type="number" name="monthday" min="1" max="28" value="1">
      <label><?= te('Hour') ?></label><input type="number" name="run_hour" min="0" max="23" value="6"></div>
    <label><?= te('Recipients') ?></label>
    <div class="row"><?php foreach ($staff as $u): ?><label><input type="checkbox" name="recipients[]" value="<?= (int) $u['id'] ?>"> <?= e($u['name']) ?></label><?php endforeach; ?></div>
    <p class="small muted"><?= te('Only people who may read and export this report are offered. Each is told in Crewvia and downloads the file signed in, within :n days. Nothing is emailed.', ['n' => RB_DELIVERY_DAYS]) ?></p>
    <button class="btn"><?= te('Schedule') ?></button>
  </form>
</div>
<form method="post" onsubmit="return confirm(this.dataset.confirm)" data-confirm="<?= te('Archive this report and stop its schedules?') ?>"><?= csrf_field() ?><input type="hidden" name="do" value="archive"><input type="hidden" name="definition_id" value="<?= (int) $definition['id'] ?>"><button class="btn sm ghost"><?= te('Archive') ?></button></form>
<?php endif; ?>

<?php else: ?>
<div class="page-head"><div><h1><?= te('Report builder') ?></h1>
<p class="sub"><?= te('Save a report with the columns, period, conditions and order you need, export it, or have it run by itself for the people who need it. Every export is logged.') ?></p></div></div>
<p><a class="btn" href="/report-builder?new=1"><?= te('New saved report') ?></a></p>

<div class="card scroll" id="saved"><h2><?= te('Saved reports') ?></h2>
  <?php if (! $mine): ?><p class="muted"><?= te('No saved report yet.') ?></p><?php else: ?>
  <table><tr><th><?= te('Name') ?></th><th><?= te('Built on') ?></th><th><?= te('Owner') ?></th><th></th></tr>
  <?php foreach ($mine as $d): ?><tr data-definition="<?= (int) $d['id'] ?>"><td><a href="/report-builder?id=<?= (int) $d['id'] ?>"><?= e($d['name']) ?></a></td><td><?= te($catalog[$d['source']]['title']) ?></td>
    <td><?= e($d['owner_name']) ?></td><td><?= (int) $d['shared'] ? '<span class="tag blue">' . te('Shared') . '</span>' : '' ?></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>

<div class="card scroll" id="deliveries"><h2><?= te('Delivered to you') ?></h2>
  <?php if (! $deliveries): ?><p class="muted"><?= te('Nothing delivered to you.') ?></p><?php else: ?>
  <table><?php foreach ($deliveries as $r): ?><tr data-delivery="<?= (int) $r['id'] ?>" data-status="<?= e($r['status']) ?>"><td><?= e($r['name']) ?></td><td><?= e($r['period_label']) ?></td><td class="num"><?= te(':n row(s)', ['n' => (int) $r['row_count']]) ?></td>
    <td><?= $r['status'] === 'ready' ? '<a class="btn sm" href="/report-builder?delivery=' . (int) $r['id'] . '">' . te('Download') . '</a> <span class="small muted">' . te('until :d', ['d' => d(substr((string) $r['expires_at'], 0, 10))]) . '</span>' : '<span class="tag grey">' . te($r['status'] === 'expired' ? 'Expired' : 'Not run') . '</span>' ?>
      <?= $r['downloaded_at'] ? '<span class="small muted">' . te('downloaded') . '</span>' : '' ?></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>

<?php if (can('admin')): ?>
<div class="card scroll" id="policy"><h2><?= te('Who may export') ?></h2>
  <p class="muted"><?= te('By default whoever may read a report may export it. Narrow it here; administrators may always export.') ?></p>
  <table><?php foreach ($policy as $k => $p): ?><tr data-policy="<?= e($k) ?>" data-exporters="<?= e(implode(',', $p['exporters'])) ?>"><td><?= te($catalog[$k]['title']) ?></td>
    <td><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="policy"><input type="hidden" name="source" value="<?= e($k) ?>">
      <label><input type="checkbox" name="readers" value="1"<?= $p['set'] ? '' : ' checked' ?>> <?= te('Every reader') ?></label>
      <?php foreach ($p['readers'] as $r): ?><label><input type="checkbox" name="roles[]" value="<?= e($r) ?>"<?= in_array($r, $p['exporters'], true) ? ' checked' : '' ?>> <?= e($roleNames[$r] ?? $r) ?></label><?php endforeach; ?>
      <button class="btn sm ghost"><?= te('Save') ?></button></form></td></tr><?php endforeach; ?></table>
</div>
<div class="card scroll" id="all-schedules"><h2><?= te('Every schedule') ?></h2>
  <form method="post" id="run-due"><?= csrf_field() ?><input type="hidden" name="do" value="run_due"><button class="btn"><?= te('Run what is due now') ?></button></form>
  <p class="small muted"><?= te('On the server, install/run-report-schedules.php runs what is due; a cron entry every hour keeps it on time.') ?></p>
  <?php if ($allSchedules): ?><table><?php foreach ($allSchedules as $s): ?><tr data-schedule="<?= (int) $s['id'] ?>"><td><?= e($s['name']) ?></td><td><?= te(ucfirst((string) $s['frequency'])) ?></td><td><?= (int) $s['active'] ? e($s['next_run_at']) : te('stopped') ?></td><td><?= e((string) ($s['last_run_at'] ?? '')) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
