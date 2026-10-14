<div class="page-head"><div><h1><?= te('Payroll review and export') ?></h1><p class="sub"><?= te('Approved sheets only. Exporting does not mark a salary paid. ADP RUN output is a draft adapter that must be checked against the customer\'s enabled import template and earning codes.') ?></p></div></div><form class="card row" method="get"><input type="date" name="week" value="<?= e($week) ?>"><button class="btn"><?= te('Open pay week') ?></button></form><p><a class="btn" href="/payroll-export?week=<?= e($week) ?>&export=review"><?= te('Review CSV') ?></a> <a class="btn ghost" href="/payroll-export?week=<?= e($week) ?>&export=adp_run"><?= te('ADP RUN draft CSV') ?></a></p><div class="card"><a href="https://support.adp.com/adp_payroll/content/hybrid/%40runcomplete/doc/pdf/FSDDenroll.pdf" target="_blank" rel="noopener"><?= te('ADP direct-deposit authorization form') ?></a><p class="muted"><?= te('Submit banking authorization through the approved payroll process. The platform does not initiate transfers or calculate withholding.') ?></p></div><?php foreach($sheets as $s): ?><div class="card"><h2><?= e($s['full_name']) ?> · <?= e($s['status']) ?></h2><p><?= e($s['adp_employee_id'].' · '.$s['employment_type'].' · '.$s['payment_method']) ?></p><?php if($s['status']==='approved'): ?><form class="row" method="post"><?= csrf_field() ?><input type="hidden" name="timesheet_id" value="<?= (int)$s['id'] ?>"><select name="method"><option value="direct_deposit"><?= te('direct_deposit') ?></option><option value="check"><?= te('check') ?></option><option value="cash"><?= te('cash') ?></option></select><input name="reference" required placeholder="<?= te('ADP / transfer / cheque / cash receipt reference') ?>"><button class="btn"><?= te('Record confirmed payment') ?></button></form><?php endif; ?></div><?php endforeach; ?>
<div class="card" id="adp-connector" data-mode="<?= e($mode) ?>"><h2><?= te('ADP connector') ?> <span class="tag <?= $mode === 'live' ? 'green' : ($mode === 'sandbox' ? 'amber' : 'grey') ?>"><?= te(ucfirst($mode)) ?></span></h2>
  <p class="muted"><?= $mode === 'live' ? te('ADP files are for the real payroll. Record what ADP says when each is imported.') : ($mode === 'sandbox' ? te('Sandbox: the ADP file is marked TEST. Import it into ADP\'s preview or a test company, then record that ADP accepted it; an administrator can then switch ADP live under Integrations.') : te('Switched off: no ADP file is produced. The review file is still available.')) ?></p>
</div>
<div class="card scroll" id="adp-files"><h2><?= te('Payroll files for this week') ?></h2>
  <?php if (! $exports): ?><p class="muted"><?= te('No file produced for this week yet.') ?></p><?php else: ?>
  <table><tr><th>#</th><th><?= te('Kind') ?></th><th class="num"><?= te('Employees') ?></th><th class="num"><?= te('Lines') ?></th><th><?= te('By') ?></th><th><?= te('ADP import') ?></th><th></th></tr>
  <?php foreach ($exports as $x): ?><tr data-export="<?= (int) $x['id'] ?>" data-kind="<?= e($x['kind']) ?>" data-import="<?= e((string) $x['import_result']) ?>"><td class="mono"><?= (int) $x['id'] ?></td>
    <td><?= $x['kind'] === 'review' ? te('Review') : ($x['kind'] === 'adp_test' ? '<span class="tag amber">' . te('ADP test') . '</span>' : '<span class="tag green">' . te('ADP live') . '</span>') ?></td>
    <td class="num"><?= (int) $x['employee_count'] ?></td><td class="num"><?= (int) $x['row_count'] ?></td><td class="small"><?= e(($x['by_name'] ?? '') . ' · ' . $x['created_at']) ?></td>
    <td class="small"><?php if ($x['kind'] === 'review'): ?>–<?php elseif ($x['import_result']): ?><span class="tag <?= $x['import_result'] === 'accepted' ? 'green' : 'red' ?>"><?= te(ucfirst($x['import_result'])) ?></span> <?= e((string) $x['adp_batch_ref']) ?> <?= e((string) $x['import_note']) ?> <span class="muted"><?= e((string) ($x['confirmed_name'] ?? '')) ?></span>
      <?php else: ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="confirm_import"><input type="hidden" name="export_id" value="<?= (int) $x['id'] ?>">
        <input name="batch_ref" required minlength="3" maxlength="120" placeholder="<?= te('ADP batch reference') ?>"><select name="result"><option value="accepted"><?= te('Accepted') ?></option><option value="rejected"><?= te('Rejected') ?></option></select>
        <input name="note" maxlength="500" placeholder="<?= te('What ADP reported') ?>"><button class="btn sm"><?= te('Record') ?></button></form><?php endif; ?></td>
    <td><a class="btn sm ghost" href="/payroll-export?week=<?= e($week) ?>&amp;download=<?= (int) $x['id'] ?>"><?= te('Download') ?></a> <span class="small muted mono" title="SHA-256"><?= e(substr((string) $x['sha256'], 0, 12)) ?></span></td></tr>
  <?php endforeach; ?></table><?php endif; ?>
</div>
<div class="card scroll" id="reconcile"><h2><?= te('Compare with ADP\'s payroll register') ?></h2>
  <p class="muted"><?= te('Export the payroll register for this week from ADP as CSV and give the names of its employee ID and gross pay columns. Each employee is compared with the gross pay Crewvia froze, every project together.') ?></p>
  <form method="post" enctype="multipart/form-data" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="reconcile">
    <input type="file" name="register" accept=".csv,text/csv" required><input name="id_column" value="<?= e($registerColumns[0]) ?>" required maxlength="60"><input name="gross_column" value="<?= e($registerColumns[1]) ?>" required maxlength="60">
    <button class="btn"><?= te('Compare') ?></button></form>
  <?php if ($recon): $rr = json_decode((string) $recon['result_json'], true) ?: []; ?>
  <p id="recon-summary" data-matched="<?= (int) $recon['matched'] ?>" data-differences="<?= (int) $recon['differences'] ?>"><?= te(':m matched, :d to look into', ['m' => (int) $recon['matched'], 'd' => (int) $recon['differences']]) ?> · <span class="small muted"><?= e((string) $recon['created_at']) ?></span></p>
  <table><tr><th><?= te('ADP employee ID') ?></th><th><?= te('Name') ?></th><th class="num">Crewvia</th><th class="num">ADP</th><th class="num"><?= te('Difference') ?></th><th><?= te('State') ?></th></tr>
  <?php foreach ($rr as $r): ?><tr data-recon="<?= e($r['id']) ?>" data-state="<?= e($r['state']) ?>"><td class="mono"><?= e($r['id']) ?></td><td><?= e($r['name']) ?></td>
    <td class="num mono"><?= $r['crewvia'] === null ? '–' : e(money($r['crewvia'])) ?></td><td class="num mono"><?= $r['adp'] === null ? '–' : e(money($r['adp'])) ?></td>
    <td class="num mono"><?= $r['difference'] === null ? '' : e(money($r['difference'])) ?></td>
    <td><span class="tag <?= $r['state'] === 'matched' ? 'green' : 'red' ?>"><?= te(['matched' => 'Matched', 'different' => 'Different', 'only_in_adp' => 'In ADP only', 'only_in_crewvia' => 'In Crewvia only'][$r['state']]) ?></span></td></tr>
  <?php endforeach; ?></table>
  <?php endif; ?>
</div>

<div class="card" id="adp-registration" data-code="<?= $adpReg['code'] !== '' ? 'set' : 'none' ?>">
  <h2><?= te('Workers registering with ADP') ?></h2>
  <p class="small muted m0 pull-up mb"><?= te('Each worker creates their own ADP account with the registration code ADP gave you, and proves who they are on ADP\'s site. Crewvia shows them the steps and never asks for a Social Security number or ITIN.') ?></p>
  <form method="post" action="/payroll-export?week=<?= e($week) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="adp_registration">
    <div class="row">
      <div><label for="adp-code"><?= te('Registration code from ADP') ?></label><input id="adp-code" name="code" maxlength="40" value="<?= e($adpReg['code']) ?>" placeholder="<?= te('e.g. RSSINC-2026') ?>"></div>
      <div class="f2"><label for="adp-url"><?= te('ADP\'s site') ?></label><input id="adp-url" name="url" maxlength="190" value="<?= e($adpReg['url']) ?>"></div>
    </div>
    <label for="adp-note"><?= te('A note for workers (optional)') ?></label><input id="adp-note" name="note" maxlength="500" value="<?= e($adpReg['note']) ?>" placeholder="<?= te('e.g. Register before your first Friday: pay goes to the account you set up there.') ?>">
    <button class="btn ghost mt-s"><?= te('Save') ?></button>
  </form>
  <?php if ($adpPeople): $done = count(array_filter($adpPeople, static fn($p) => $p['adp_registered_at'] !== null)); ?>
  <p class="mt" data-adp-registered="<?= $done ?>" data-adp-people="<?= count($adpPeople) ?>"><strong><?= te(':done of :n registered with ADP', ['done' => $done, 'n' => count($adpPeople)]) ?></strong></p>
  <div class="scroll"><table><tr><th><?= te('Person') ?></th><th><?= te('ADP employee ID') ?></th><th><?= te('Registered') ?></th><th><?= te('Per diem') ?></th></tr>
  <?php foreach ($adpPeople as $p): ?><tr data-adp-person="<?= (int) $p['id'] ?>" data-adp-state="<?= $p['adp_registered_at'] ? 'registered' : ($p['adp_employee_id'] ? 'id' : 'missing') ?>">
    <td><?= e($p['full_name']) ?><?php if (! (int) $p['has_portal']): ?> <span class="tag grey"><?= te('no portal') ?></span><?php endif; ?></td>
    <td><form method="post" action="/payroll-export?week=<?= e($week) ?>" class="row tight gap-s"><?= csrf_field() ?><input type="hidden" name="do" value="adp_employee_id"><input type="hidden" name="candidate_id" value="<?= (int) $p['id'] ?>"><input name="adp_employee_id" maxlength="40" class="fw-s pad-s t-13" value="<?= e((string) $p['adp_employee_id']) ?>" aria-label="<?= te('ADP employee ID') ?>"><button class="btn sm ghost"><?= te('Save') ?></button></form></td>
    <td><?= $p['adp_registered_at'] ? '<span class="tag green">' . e(d(substr((string) $p['adp_registered_at'], 0, 10))) . '</span>' : '<span class="tag amber">' . te('not yet') . '</span>' ?></td>
    <td data-per-diem-tax="<?= (int) $p['per_diem_taxable'] ? 'taxable' : 'free' ?>"><form method="post" action="/payroll-export?week=<?= e($week) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="per_diem_tax"><input type="hidden" name="placement_id" value="<?= (int) $p['placement_id'] ?>"><input type="hidden" name="taxable" value="<?= (int) $p['per_diem_taxable'] ? '0' : '1' ?>"><span class="small"><?= (int) $p['per_diem_taxable'] ? te('taxable (local)') : te('tax-free (travelling)') ?></span> <button class="link small"><?= (int) $p['per_diem_taxable'] ? te('Make tax-free') : te('Make taxable') ?></button></form></td></tr><?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
