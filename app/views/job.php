<?php
require_once __DIR__ . '/../lifecycle.php';
require_once __DIR__ . '/../scope.php';
?>
<div class="page-heading">
  <div>
    <span class="eyebrow">
      <?= $job ? e(strtoupper((string) ($job['client_name'] ?? t('Project')))) : te('PROJECT') ?>
    </span>
    <h1><?= e($job['title'] ?? t('Project setup')) ?></h1>
    <p class="sub">
      <?php if ($job): ?>
        <?= e(trim(($job['site_name'] ?? '') . ' ' . ($job['site_city'] ?? '') . ' ' . ($job['site_state'] ?? ''))) ?: te('Site not recorded') ?>
        <?php if ($job['order_reference']): ?>
          &middot; <?= te('Order :ref', ['ref' => e($job['order_reference'])]) ?>
        <?php endif; ?>
        <?php if ($job['strike_live']): ?>
          &middot; <span class="tag red"><?= te('Strike is live') ?></span>
        <?php endif; ?>
      <?php else: ?>
        <?= te('The agreement, and the scope of work everything else is calculated from.') ?>
      <?php endif; ?>
    </p>
  </div>
  <?php if ($job): ?>
    <div style="display:flex;gap:8px">
      <a class="btn ghost" href="#scope"><?= te('Scope of work') ?></a>
      <a class="btn ghost" href="/roster"><?= te('Open the roster') ?></a>
    </div>
  <?php endif; ?>
</div>

<?php if (! $job): ?>
  <div class="card"><div class="empty">
    <?= te('No project selected yet. Create one, or pick an existing project from the switcher above.') ?>
    <p style="margin-top:10px"><a class="btn" href="/projects"><?= te('Go to Projects') ?></a></p>
  </div></div>
<?php else: ?>

<?php
$stages   = lifecycle_stages();
$met      = lifecycle_met($signals);
$ready    = $signals && $met === count($signals);
$left     = max(0, (int) $totals['people'] - (int) $totals['placed']);
$unpriced = (int) $totals['lines'] - (int) $totals['priced'];
$terms    = scope_terms();
$strike   = (bool) $job['strike_live'];
?>

<!-- ── where this project is in its life ───────────────────────────── -->
<section class="card lifecycle">
  <ol class="lifecycle-bar">
    <?php $passed = true; foreach ($stages as $key => $meta): ?>
      <?php $here = $key === $stage; ?>
      <li class="<?= $here ? 'now' : ($passed ? 'done' : '') ?>">
        <span class="dot" aria-hidden="true"></span>
        <strong><?= te($meta['label']) ?></strong>
        <span class="muted small"><?= te($meta['note']) ?></span>
      </li>
      <?php if ($here) { $passed = false; } ?>
    <?php endforeach; ?>
  </ol>

  <div class="headcount-bar" style="margin-top:4px">
    <span style="width:<?= lifecycle_progress($stage) ?>%"></span>
  </div>
  <p class="small muted" style="margin:8px 0 0">
    <?= te(':n% through the job', ['n' => lifecycle_progress($stage)]) ?>
  </p>
</section>

<!-- ── the gate ────────────────────────────────────────────────────── -->
<?php if ($next): ?>
<section class="card gate <?= $ready ? 'ready' : '' ?>">
  <div class="page-heading" style="margin:0 0 14px">
    <div>
      <span class="eyebrow"><?= te('NEXT: :stage', ['stage' => strtoupper(t($stages[$next]['label']))]) ?></span>
      <h2 style="margin:4px 0 0">
        <?= te(':met of :total signals met', ['met' => $met, 'total' => count($signals)]) ?>
      </h2>
    </div>
    <form method="post" action="/job">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="advance">
      <button class="btn<?= $ready ? '' : ' ghost' ?>" type="submit">
        <?= te('Advance to :stage', ['stage' => t($stages[$next]['label'])]) ?>
      </button>
    </form>
  </div>

  <ul class="signals">
    <?php foreach ($signals as $signal): ?>
      <li class="<?= $signal['met'] ? 'met' : 'unmet' ?>">
        <span class="signal-mark" aria-hidden="true"><?= $signal['met'] ? '&#10003;' : '&times;' ?></span>
        <span class="signal-body">
          <strong><?= te($signal['label']) ?></strong>
          <span class="muted small"><?= e($signal['detail']) ?></span>
        </span>
        <?php if (! $signal['met']): ?>
          <a class="btn ghost sm" href="<?= e($signal['where']) ?>"><?= te('Fix') ?></a>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>

  <?php if (! $signals): ?>
    <div class="empty"><?= te('Nothing stands in the way of the next stage.') ?></div>
  <?php endif; ?>
</section>
<?php endif; ?>

<!-- ── what the agreement is worth, and who is on it ────────────────── -->
<div class="grid g2">
  <section class="card">
    <span class="eyebrow"><?= te('THE AGREEMENT') ?></span>
    <h2><?= te('A full week of the scope') ?></h2>
    <p class="small muted" style="margin:-6px 0 14px">
      <?= te('Every line of the order at the quantity agreed, on the guarantee in force :now.', [
          'now' => $strike ? t('while the strike is live') : t('outside a strike')]) ?>
    </p>

    <div class="grid g2" style="margin-bottom:14px">
      <div class="stat">
        <div class="n"><?= e(money($totals['weekly_pay'])) ?></div>
        <div class="l"><?= te('Committed to the crew') ?></div>
        <div class="h">
          <?= te(':people people across :lines lines', [
              'people' => (int) $totals['people'], 'lines' => (int) $totals['lines']]) ?>
        </div>
      </div>
      <div class="stat">
        <div class="n"><?= $totals['weekly_bill'] > 0 ? e(money($totals['weekly_bill'])) : '&mdash;' ?></div>
        <div class="l"><?= te('Billed to the client') ?></div>
        <div class="h">
          <?= $totals['weekly_bill'] > 0
              ? te('Margin :amount', ['amount' => money((float) $totals['weekly_margin'])])
              : te('No bill rate on any line yet') ?>
        </div>
      </div>
    </div>

    <?php if ($unpriced > 0): ?>
      <p class="small" style="color:var(--amber);margin:0 0 14px">
        <?= te(':n of :total lines have no pay rate yet, so they count as nothing in the figures above.', [
            'n' => $unpriced, 'total' => (int) $totals['lines']]) ?>
        <a href="#scope"><?= te('Price them') ?></a>
      </p>
    <?php endif; ?>

    <table>
      <tbody>
        <tr><td><?= te('Client') ?></td>
            <td class="right"><strong><?= e($job['client_name'] ?? '') ?></strong></td></tr>
        <tr><td><?= te('Site') ?></td>
            <td class="right"><?= e(trim(($job['site_name'] ?? '') . ' ' . ($job['site_city'] ?? '') . ' ' . ($job['site_state'] ?? ''))) ?: '&mdash;' ?></td></tr>
        <tr><td><?= te('Runs') ?></td>
            <td class="right">
              <?= $job['starts_on'] ? e(d($job['starts_on'])) : '&mdash;' ?>
              &rarr; <?= $job['ends_on'] ? e(d($job['ends_on'])) : '&mdash;' ?>
            </td></tr>
        <tr><td><?= te('The agreement also covers') ?></td>
            <td class="right small">
              <?php
              $covered = array_values(array_filter(scope_provisions($job), fn ($p) => $p['on']));
              ?>
              <?php if (! $covered): ?>
                <span class="muted"><?= te('Nothing beyond the rates') ?></span>
              <?php else: ?>
                <?= e(implode(', ', array_map(fn ($p) => t($p['label']), $covered))) ?>
              <?php endif; ?>
            </td></tr>
      </tbody>
    </table>
  </section>

  <section class="card">
    <span class="eyebrow"><?= te('THE CREW') ?></span>
    <h2><?= te(':placed of :target on the job', [
        'placed' => (int) $totals['placed'], 'target' => (int) $totals['people']]) ?></h2>

    <div class="headcount-bar">
      <span style="width:<?= (int) $totals['people'] > 0 ? (int) round($totals['placed'] / $totals['people'] * 100) : 0 ?>%"></span>
    </div>
    <p class="small muted" style="margin:8px 0 16px">
      <?= te(':n still to place', ['n' => $left]) ?>
      &middot; <?= te(':n on site', ['n' => (int) $figures['onsite']]) ?>
      &middot; <?= te(':n offered', ['n' => (int) $figures['offered']]) ?>
    </p>

    <div class="grid g2" style="margin-bottom:16px">
      <div class="stat">
        <div class="n"><?= e(money((float) $figures['weekly_pay'])) ?></div>
        <div class="l"><?= te('This week, as placed') ?></div>
        <div class="h">
          <?= (int) $figures['priced'] < (int) $totals['placed']
              ? te(':n of :total placed have no agreed rate', [
                    'n' => (int) $totals['placed'] - (int) $figures['priced'],
                    'total' => (int) $totals['placed']])
              : te('On the rates each person was signed at') ?>
        </div>
      </div>
      <div class="stat">
        <div class="n mono"><?= e(number_format((float) $figures['hours'], 2)) ?></div>
        <div class="l"><?= te('Hours recorded so far') ?></div>
        <div class="h"><?= te(':n timesheets awaiting approval', ['n' => (int) $figures['unapproved']]) ?></div>
      </div>
    </div>

    <h3><?= te('Needs somebody') ?></h3>
    <ul class="exceptions">
      <li class="<?= $figures['blocked'] ? 'bad' : 'good' ?>">
        <a href="/roster"><?= te(':n blocked from deploying', ['n' => (int) $figures['blocked']]) ?></a></li>
      <li class="<?= $figures['beds'] ? 'bad' : 'good' ?>">
        <a href="/hotels"><?= te(':n with no bed', ['n' => (int) $figures['beds']]) ?></a></li>
      <li class="<?= $figures['travel'] ? 'bad' : 'good' ?>">
        <a href="/travel"><?= te(':n with travel unbooked', ['n' => (int) $figures['travel']]) ?></a></li>
    </ul>

    <h3 style="margin-top:18px"><?= te('Who is running it') ?></h3>
    <?php if (! $supervisors): ?>
      <p class="hint"><?= te('Nobody has been given a supervisor yet. Assign them in Manning so a crew is never nobody\'s responsibility.') ?></p>
      <p><a class="btn ghost sm" href="/manning"><?= te('Open manning') ?></a></p>
    <?php else: ?>
      <ul class="supervisors">
        <?php foreach ($supervisors as $sv): ?>
          <li>
            <strong><?= e($sv['name']) ?></strong>
            <span class="tag <?= (int) $sv['crew'] > 12 ? 'amber' : 'blue' ?>">
              <?= te(':n crew', ['n' => (int) $sv['crew']]) ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<!-- ── the scope of work ────────────────────────────────────────────── -->
<section class="card tight" id="scope">
  <div style="padding:16px 18px;border-bottom:1px solid var(--line)">
    <span class="eyebrow"><?= te('THE SCOPE OF WORK') ?></span>
    <h2 style="margin:4px 0 6px"><?= te('What the client ordered, and what each trade is paid') ?></h2>
    <p class="small muted" style="margin:0">
      <?= te('One line per trade. Each line carries its own rates, because an engineer and a labourer on the same site are neither paid nor billed the same. Requisitions are raised against a line and inherit its terms.') ?>
    </p>
  </div>

  <?php if (! $scope): ?>
    <div class="empty">
      <?= te('No scope written yet. Nothing can be recruited until the agreement says how many of each trade it covers.') ?>
    </div>
  <?php else: ?>
  <div class="scroll">
    <table>
      <thead><tr>
        <th><?= te('Trade') ?></th>
        <th class="right"><?= te('Asked') ?></th>
        <th class="right"><?= te('Placed') ?></th>
        <th class="right"><?= te('Pay') ?></th>
        <th class="right"><?= te('Bill') ?></th>
        <th class="right"><?= te('Per diem') ?></th>
        <th class="right"><?= te('Guarantee') ?></th>
        <th class="right"><?= te('Week') ?></th>
        <th class="right"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($scope as $line): ?>
        <?php
        $guarantee = scope_guarantee($line, $strike);
        $lineWeek  = $line['pay_rate'] !== null
            ? (int) $line['quantity'] * $guarantee * (float) $line['pay_rate']
              + (int) $line['quantity'] * 7 * (float) ($line['per_diem_rate'] ?? 0)
            : null;
        ?>
        <tr<?= $editLine && (int) $editLine['id'] === (int) $line['id'] ? ' style="background:var(--blue-soft)"' : '' ?>>
          <td>
            <strong><?= e($line['role_title']) ?></strong>
            <div class="muted small">
              <?= te(disciplines()[$line['discipline']] ?? ucfirst((string) $line['discipline'])) ?>
              <?php if ($line['shift']): ?> &middot; <?= e($line['shift']) ?><?php endif; ?>
            </div>
            <?php if ($line['notes']): ?>
              <div class="muted small"><?= e($line['notes']) ?></div>
            <?php endif; ?>
          </td>
          <td class="right mono"><?= (int) $line['quantity'] ?></td>
          <td class="right">
            <span class="tag <?= (int) $line['filled'] >= (int) $line['quantity'] ? 'green' : ((int) $line['filled'] ? 'blue' : 'grey') ?>">
              <?= (int) $line['filled'] ?>
            </span>
          </td>
          <td class="right mono"><?= $line['pay_rate'] !== null ? e(money((float) $line['pay_rate'])) : '<span class="tag amber">' . te('Not priced') . '</span>' ?></td>
          <td class="right mono"><?= $line['bill_rate'] !== null ? e(money((float) $line['bill_rate'])) : '&mdash;' ?></td>
          <td class="right mono"><?= $line['per_diem_rate'] !== null ? e(money((float) $line['per_diem_rate'])) : '&mdash;' ?></td>
          <td class="right mono">
            <?= $guarantee > 0 ? e($guarantee . ' h') : '&mdash;' ?>
            <?php if ($strike && $line['strike_guarantee_hours'] !== null): ?>
              <div class="muted small"><?= te('strike rate') ?></div>
            <?php endif; ?>
          </td>
          <td class="right mono"><?= $lineWeek !== null ? e(money($lineWeek)) : '&mdash;' ?></td>
          <td class="right" style="white-space:nowrap">
            <a class="btn ghost sm" href="/job?line=<?= (int) $line['id'] ?>#scope"><?= te('Edit') ?></a>
            <?php if (! (int) $line['raised']): ?>
              <form method="post" action="/job" style="display:inline"
                    onsubmit="return confirm('<?= te('Remove this line from the scope?') ?>')">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="remove_line">
                <input type="hidden" name="line_id" value="<?= (int) $line['id'] ?>">
                <button class="btn ghost sm" type="submit"><?= te('Remove') ?></button>
              </form>
            <?php else: ?>
              <span class="muted small"><?= te(':n orders', ['n' => (int) $line['raised']]) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr>
        <th><?= te('Total ordered') ?></th>
        <th class="right mono"><?= (int) $totals['people'] ?></th>
        <th class="right mono"><?= (int) $totals['placed'] ?></th>
        <th colspan="4"></th>
        <th class="right mono"><?= e(money((float) $totals['weekly_pay'])) ?></th>
        <th></th>
      </tr></tfoot>
    </table>
  </div>
  <?php endif; ?>

  <!-- writing or correcting a line -->
  <form method="post" action="/job" style="padding:18px;border-top:1px solid var(--line)">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="<?= $editLine ? 'save_line' : 'add_line' ?>">
    <?php if ($editLine): ?>
      <input type="hidden" name="line_id" value="<?= (int) $editLine['id'] ?>">
    <?php endif; ?>

    <h3 style="margin:0 0 4px">
      <?= $editLine
          ? te('Change :role', ['role' => e($editLine['role_title'])])
          : te('Add a trade to the order') ?>
    </h3>
    <p class="small muted" style="margin:0 0 14px">
      <?= te('Leave a rate blank if it has not been agreed yet. The line is still on the order; the figures simply say it is not priced.') ?>
    </p>

    <div class="row">
      <div style="flex:2">
        <label for="l-role"><?= te('Trade') ?></label>
        <input id="l-role" name="role_title" required maxlength="190"
               value="<?= e($editLine['role_title'] ?? '') ?>"
               placeholder="<?= te('e.g. Mechanical engineer') ?>">
      </div>
      <div>
        <label for="l-disc"><?= te('Discipline') ?></label>
        <select id="l-disc" name="discipline">
          <?php foreach (disciplines() as $key => $label): ?>
            <option value="<?= e($key) ?>"
              <?= ($editLine['discipline'] ?? '') === $key ? 'selected' : '' ?>><?= te($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="max-width:120px">
        <label for="l-qty"><?= te('How many') ?></label>
        <input id="l-qty" type="number" name="quantity" min="1" max="65535" required
               value="<?= (int) ($editLine['quantity'] ?? 1) ?>">
      </div>
      <div>
        <label for="l-shift"><?= te('Shift') ?></label>
        <input id="l-shift" name="shift" maxlength="60"
               value="<?= e($editLine['shift'] ?? '') ?>"
               placeholder="<?= te('e.g. Nights, 7 on 7 off') ?>">
      </div>
    </div>

    <div class="row" style="margin-top:12px">
      <?php foreach ($terms as $field => $meta): ?>
        <div>
          <label for="l-<?= e($field) ?>"><?= te($meta['label']) ?></label>
          <input id="l-<?= e($field) ?>" type="number" name="<?= e($field) ?>" min="0"
                 step="<?= $meta['kind'] === 'hours' ? '1' : '0.01' ?>"
                 value="<?= $editLine && $editLine[$field] !== null
                            ? e($meta['kind'] === 'hours' ? (int) $editLine[$field] : (float) $editLine[$field])
                            : '' ?>">
          <span class="hint"><?= te($meta['hint']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="field" style="margin-top:12px">
      <label for="l-notes"><?= te('Anything else agreed on this line') ?></label>
      <input id="l-notes" name="notes" maxlength="500"
             value="<?= e($editLine['notes'] ?? '') ?>"
             placeholder="<?= te('e.g. Client provides tools; two-week minimum; CDL required') ?>">
    </div>

    <button class="btn" type="submit" style="margin-top:16px">
      <?= $editLine ? te('Save the line') : te('Add to the order') ?>
    </button>
    <?php if ($editLine): ?>
      <a class="btn ghost" href="/job#scope" style="margin-left:8px"><?= te('Cancel') ?></a>
    <?php endif; ?>
  </form>
</section>

<!-- ── the agreement ────────────────────────────────────────────────── -->
<form method="post" action="/job">
  <?= csrf_field() ?>

  <div class="card" style="<?= $strike ? 'border-color:#F0C4C1;background:#FDEEED' : '' ?>">
    <h2><?= te('The strike switch') ?></h2>
    <p class="small muted" style="margin:-6px 0 12px">
      <?= te('While the strike is live, every line of the scope guarantees its strike hours instead of its normal week. Review the contractual terms before changing this.') ?>
    </p>
    <label style="display:flex;align-items:center;gap:9px;font-weight:600;color:var(--ink);font-size:15px">
      <input type="checkbox" name="strike_live" value="1" style="width:auto;transform:scale(1.3)"
             <?= $strike ? 'checked' : '' ?>>
      <?= te('The strike is live') ?>
    </label>
  </div>

  <div class="card">
    <h2><?= te('The agreement') ?></h2>

    <div class="row">
      <div style="flex:2">
        <label for="j-title"><?= te('Project name') ?></label>
        <input id="j-title" name="title" value="<?= e($job['title']) ?>" required maxlength="190">
      </div>
      <div>
        <label for="j-ref"><?= te('Client order reference') ?></label>
        <input id="j-ref" name="order_reference" maxlength="120"
               value="<?= e($job['order_reference'] ?? '') ?>"
               placeholder="<?= te('Their PO or contract number') ?>">
      </div>
      <div>
        <label for="j-status"><?= te('Status') ?></label>
        <select id="j-status" name="status">
          <?php foreach (['planning' => 'Planning', 'active' => 'Active', 'closed' => 'Closed'] as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= $job['status'] === $k ? 'selected' : '' ?>><?= te($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="field" style="margin-top:14px">
      <label for="j-desc"><?= te('Description of the work') ?></label>
      <textarea id="j-desc" name="description" rows="4" maxlength="8000"
                placeholder="<?= te('e.g. Provide skilled labour support to the client plant for the duration of the work stoppage, in accordance with the scope of work below.') ?>"><?= e($job['description'] ?? '') ?></textarea>
      <span class="hint"><?= te('What this agreement commits the agency to, in the words the client would recognise. It is what applicants read and what the contract quotes.') ?></span>
    </div>

    <div class="row" style="margin-top:14px">
      <div style="flex:2">
        <label for="j-site"><?= te('Site') ?></label>
        <input id="j-site" name="site_name" maxlength="190" value="<?= e($job['site_name'] ?? '') ?>">
      </div>
      <div>
        <label for="j-city"><?= te('City') ?></label>
        <input id="j-city" name="site_city" maxlength="120" value="<?= e($job['site_city'] ?? '') ?>">
      </div>
      <div style="max-width:90px">
        <label for="j-state"><?= te('State') ?></label>
        <input id="j-state" name="site_state" maxlength="2" value="<?= e($job['site_state'] ?? '') ?>">
      </div>
      <div>
        <label for="j-start"><?= te('Starts') ?></label>
        <input id="j-start" type="date" name="starts_on" value="<?= e($job['starts_on'] ?? '') ?>">
      </div>
      <div>
        <label for="j-end"><?= te('Ends') ?></label>
        <input id="j-end" type="date" name="ends_on" value="<?= e($job['ends_on'] ?? '') ?>">
      </div>
    </div>

    <h3 style="margin-top:20px"><?= te('What the agreement covers') ?></h3>
    <p class="hint" style="margin:-4px 0 12px">
      <?= te('Beyond the rates on the lines. These are what the crew is told and what the client is invoiced for, so they are recorded here rather than remembered.') ?>
    </p>

    <div class="row">
      <label class="own-room">
        <input type="checkbox" name="lodging_provided" value="1"
               <?= ! empty($job['lodging_provided']) ? 'checked' : '' ?>>
        <?= te('Lodging paid') ?>
      </label>
      <label class="own-room">
        <input type="checkbox" name="travel_provided" value="1"
               <?= ! empty($job['travel_provided']) ? 'checked' : '' ?>>
        <?= te('Travel to and from site paid') ?>
      </label>
      <label class="own-room">
        <input type="checkbox" name="transport_provided" value="1"
               <?= ! empty($job['transport_provided']) ? 'checked' : '' ?>>
        <?= te('Transport on site provided') ?>
      </label>
    </div>

    <p class="small muted" style="margin:16px 0 0">
      <?= te('How many people the agreement asks for is the total of the scope of work above: :n.', [
          'n' => (int) $totals['people']]) ?>
    </p>
  </div>

  <div class="card">
    <h2><?= te('Internal notes') ?></h2>
    <textarea name="notes" rows="5"><?= e($job['notes'] ?? '') ?></textarea>
    <p class="small muted" style="margin:8px 0 0">
      <?= te('For the team only - never shown to applicants or on a contract. Kept here so the terms live in the system rather than in somebody\'s inbox.') ?>
    </p>
  </div>

  <button class="btn" type="submit"><?= te('Save the agreement') ?></button>
</form>

<?php endif; ?>
