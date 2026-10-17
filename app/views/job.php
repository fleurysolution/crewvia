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
        <?= te('Project details and scope of work.') ?>
      <?php endif; ?>
    </p>
  </div>
  <?php if ($job): ?>
    <div class="flex gap-s">
      <?php // P5-M73: the strike switch, at the top (RSS: "it'd be good for it to be at the top"). ?>
      <form method="post" action="/job" class="inline" id="strike-switch" onsubmit="return confirm(this.dataset.ask)"
            data-ask="<?= $job['strike_live'] ? te('Stand the strike down? Every line goes back to its normal guaranteed week.') : te('Declare the strike live? Every line then guarantees its strike hours. Check the contract first.') ?>">
        <?= csrf_field() ?><input type="hidden" name="do" value="strike"><input type="hidden" name="strike_live" value="<?= $job['strike_live'] ? 0 : 1 ?>">
        <button class="btn <?= $job['strike_live'] ? '' : 'ghost' ?>" title="<?= te('While the strike is live, every line of the scope guarantees its strike hours instead of its normal week.') ?>"><?= $job['strike_live'] ? te('Strike is live - stand it down') : te('Declare the strike live') ?></button>
      </form>
      <a class="btn ghost" href="#scope"><?= te('Scope of work') ?></a>
      <a class="btn ghost" href="/roster"><?= te('Open the roster') ?></a>
    </div>
  <?php endif; ?>
</div>

<?php if (! $job): ?>
  <div class="card"><div class="empty">
    <?= te('No project selected yet. Create one, or pick an existing project from the switcher above.') ?>
    <p class="mt"><a class="btn" href="/projects"><?= te('Go to Projects') ?></a></p>
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

  <div class="headcount-bar mt-xs">
    <span style="width:<?= lifecycle_progress($stage) ?>%"></span>
  </div>
  <p class="small muted m0 mt-s">
    <?= te(':n% through the job', ['n' => lifecycle_progress($stage)]) ?>
  </p>
</section>

<!-- ── the gate ────────────────────────────────────────────────────── -->
<?php if ($next): ?>
<section class="card gate <?= $ready ? 'ready' : '' ?>">
  <div class="page-heading m0 mb">
    <div>
      <span class="eyebrow"><?= te('NEXT: :stage', ['stage' => strtoupper(t($stages[$next]['label']))]) ?></span>
      <h2 class="m0 mt-xs">
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

  <?php // P5-M78: what is left to do is shown; what is done folds under "Done" (RSS: "once added, it should not show again"). ?>
  <?php $doneSignals = array_values(array_filter($signals, static fn ($x) => $x['met'])); $todoSignals = array_values(array_filter($signals, static fn ($x) => ! $x['met'])); ?>
  <?php if (! $todoSignals && $signals): ?><p class="small m0 mb" data-gate-ready><?= te('Everything is in place. Advance when you are ready.') ?></p><?php endif; ?>
  <ul class="signals">
    <?php foreach ($todoSignals as $signal): ?>
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
  <?php if ($doneSignals): ?>
  <details class="mt-s" id="gate-done"><summary class="small muted"><?= te('Done (:n)', ['n' => count($doneSignals)]) ?></summary>
    <ul class="signals">
      <?php foreach ($doneSignals as $signal): ?>
        <li class="met"><span class="signal-mark" aria-hidden="true">&#10003;</span><span class="signal-body"><strong><?= te($signal['label']) ?></strong> <span class="muted small"><?= e($signal['detail']) ?></span></span></li>
      <?php endforeach; ?>
    </ul>
  </details>
  <?php endif; ?>

  <?php if (! $signals): ?>
    <div class="empty"><?= te('Nothing stands in the way of the next stage.') ?></div>
  <?php endif; ?>
</section>
<?php endif; ?>

<!-- ── what the agreement is worth, and who is on it ────────────────── -->
<div class="grid g2">
  <details class="card fold">
  <summary><span><span class="eyebrow"><?= te('THE PROJECT') ?></span><h2><?= te('A full week of the scope') ?></h2></span><span class="fold-note"><?= e(money($totals['weekly_pay'])) ?></span></summary>
    <p class="small muted m0 pull-up mb">
      <?= te('Every line of the order at the quantity agreed, on the guarantee in force :now.', [
          'now' => $strike ? t('while the strike is live') : t('outside a strike')]) ?>
    </p>

    <div class="grid g2 mb">
      <div class="stat">
        <div class="n"><?= e(money($totals['weekly_pay'])) ?></div>
        <div class="l"><?= te('Committed to the crew') ?></div>
        <div class="h">
          <?= te(':people across :lines', [
              'people' => tn((int) $totals['people'], ':n person', ':n people'), 'lines' => tn((int) $totals['lines'], ':n line', ':n lines')]) ?>
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
      <p class="small t-amber m0 mb">
        <?= te(':n of :total lines have no pay rate yet, so they count as nothing in the figures above.', [
            'n' => $unpriced, 'total' => (int) $totals['lines']]) ?>
        <a href="#scope"><?= te('Price them') ?></a>
      </p>
    <?php endif; ?>

    <table>
      <tbody>
        <tr><td><?= te('Client') ?></td>
            <td class="right"><strong><?= e($job['client_name'] ?? '') ?></strong></td></tr>
        <?php $chief = array_values(array_filter($projectContacts, static fn($x) => (int) $x['in_charge'] === 1))[0] ?? null; ?>
        <tr data-in-charge><td><?= te('In charge at the client') ?></td>
            <td class="right"><?= $chief ? e($chief['name']) . ($chief['phone'] ? ' · ' . e($chief['phone']) : '') : '<a href="#project-contacts">' . te('Choose') . '</a>' ?></td></tr>
        <tr><td><?= te('Site') ?></td>
            <td class="right"><?= e(trim(($job['site_name'] ?? '') . ' ' . ($job['site_city'] ?? '') . ' ' . ($job['site_state'] ?? ''))) ?: '&mdash;' ?></td></tr>
        <tr><td><?= te('Runs') ?></td>
            <td class="right">
              <?= $job['starts_on'] ? e(d($job['starts_on'])) : '&mdash;' ?>
              &rarr; <?= $job['ends_on'] ? e(d($job['ends_on'])) : te('ongoing, no end date') ?>
            </td></tr>
        <tr><td><?= te('The contract also covers') ?></td>
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
  </details>

  <details class="card fold">
  <summary><span><span class="eyebrow"><?= te('THE CREW') ?></span><h2><?= te(':placed of :target on the job', ['placed' => (int) $totals['placed'], 'target' => (int) $totals['people']]) ?></h2></span></summary>

    <div class="headcount-bar">
      <span style="width:<?= (int) $totals['people'] > 0 ? (int) round($totals['placed'] / $totals['people'] * 100) : 0 ?>%"></span>
    </div>
    <p class="small muted m0 mt-s mb-l">
      <?= te(':n still to place', ['n' => $left]) ?>
      &middot; <?= te(':n on site', ['n' => (int) $figures['onsite']]) ?>
      &middot; <?= te(':n offered', ['n' => (int) $figures['offered']]) ?>
    </p>

    <div class="grid g2 mb-l">
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

    <h3 class="mt-l"><?= te('Who is running it') ?></h3>
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
  </details>
</div>

<!-- ── the scope of work ────────────────────────────────────────────── -->
<details class="card fold tight" id="scope">
  <summary><span><span class="eyebrow"><?= te('THE SCOPE OF WORK') ?></span><h2><?= te('What the client ordered, and what each trade is paid') ?></h2></span><span class="fold-note"><?= tn((int) $totals['lines'], ':n trade', ':n trades') ?> · <?= tn((int) $totals['people'], ':n person', ':n people') ?></span>
  <div class="card-bar"></summary>
    <p class="small muted m0">
      <?= te('One line per trade, with its own rates.') ?>
    </p>
  </div>

  <?php if (! $scope): ?>
    <div class="empty">
      <?= te('No scope written yet. Nothing can be recruited until the project says how many of each trade the contract covers.') ?>
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
        <tr<?= $editLine && (int) $editLine['id'] === (int) $line['id'] ? ' class="bg-blue-soft"' : '' ?>>
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
          <td class="right mono"><?= $line['bill_rate'] !== null ? e(money((float) $line['bill_rate'])) : '&mdash;' ?><?php if ($line['bill_rate'] !== null && $line['pay_rate'] !== null && (float) $line['bill_rate'] > 0): ?><div class="small muted" data-margin="<?= (int) $line['id'] ?>"><?= te(':pct% margin', ['pct' => number_format(((float) $line['bill_rate'] - (float) $line['pay_rate']) / (float) $line['bill_rate'] * 100, 1)]) ?></div><?php endif; ?></td>
          <td class="right mono"><?= $line['per_diem_rate'] !== null ? e(money((float) $line['per_diem_rate'])) : '&mdash;' ?></td>
          <td class="right mono">
            <?= $guarantee > 0 ? e($guarantee . ' h') : '&mdash;' ?>
            <?php if ($strike && $line['strike_guarantee_hours'] !== null): ?>
              <div class="muted small"><?= te('strike rate') ?></div>
            <?php endif; ?>
          </td>
          <td class="right mono"><?= $lineWeek !== null ? e(money($lineWeek)) : '&mdash;' ?></td>
          <td class="right nowrap">
            <a class="btn ghost sm" href="/job?line=<?= (int) $line['id'] ?>#scope"><?= te('Edit') ?></a>
            <?php if (! (int) $line['raised']): ?>
              <form class="inline" method="post" action="/job"
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
  <?php // P5-M72: folded behind "Add a trade" once the scope has a line (RSS: "too much information in my face"); open to write the first one or to change one. ?>
  <?php // P5-M90: every edit of a line, kept: what changed, why, who and when. ?>
  <?php if ($changeOrders): ?>
  <details class="card-foot" id="change-orders"><summary class="small"><?= tn(count($changeOrders), ':n change to the scope', ':n changes to the scope') ?></summary>
    <table class="mt-s"><tbody><?php foreach ($changeOrders as $co): ?><tr data-change="<?= (int) $co['id'] ?>"><td class="small"><?= e(d(substr((string) $co['created_at'], 0, 10))) ?></td><td><strong><?= e($co['role_title']) ?></strong><div class="small"><?= e((string) ($co['changes'] ?? '')) ?></div></td><td class="small"><?= te('Why:') ?> <?= e((string) ($co['note'] ?? '')) ?></td><td class="small muted"><?= e((string) ($co['by_name'] ?? '')) ?></td></tr><?php endforeach; ?></tbody></table>
  </details>
  <?php endif; ?>
  <details class="card-foot" id="add-trade"<?= $editLine || ! $scope || isset($_GET['discipline']) ? ' open' : '' ?>>
  <summary class="btn<?= $scope ? ' ghost' : '' ?>" data-add-trade><?= $editLine ? te('Change :role', ['role' => $editLine['role_title']]) : te('+ Add a trade') ?></summary>
  <form method="post" action="/job" class="mt">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="<?= $editLine ? 'save_line' : 'add_line' ?>">
    <?php if ($editLine): ?>
      <input type="hidden" name="line_id" value="<?= (int) $editLine['id'] ?>">
    <?php endif; ?>

    <h3 class="m0 mb-xs">
      <?= $editLine
          ? te('Change :role', ['role' => e($editLine['role_title'])])
          : te('Add a trade to the order') ?>
    </h3>
    <p class="small muted m0 mb">
      <?= te('Leave a rate blank if it is not agreed yet.') ?>
    </p>

    <div class="row">
      <div class="f2">
        <label for="l-role"><?= te('Trade') ?></label>
        <input id="l-role" name="role_title" required maxlength="190"
               value="<?= e($editLine['role_title'] ?? '') ?>"
               placeholder="<?= te('e.g. Mechanical engineer') ?>">
      </div>
      <div>
        <label for="l-disc"><?= te('Discipline') ?></label>
        <select id="l-disc" name="discipline">
          <?php $pickDisc = (string) ($editLine['discipline'] ?? ($_GET['discipline'] ?? '')); ?>
          <?php foreach (disciplines() as $key => $label): ?>
            <option value="<?= e($key) ?>"
              <?= $pickDisc === $key ? 'selected' : '' ?>><?= te($label) ?></option>
          <?php endforeach; ?>
        </select>
        <?php // P5-M71: a discipline not on the list is added here, and chosen at once. ?>
        <details class="mt-xs small" id="add-discipline-box"><summary><?= te('+ Add a discipline') ?></summary>
          <input name="discipline_label" form="add-discipline" maxlength="90" placeholder="<?= te('e.g. Millwright') ?>" aria-label="<?= te('New discipline') ?>">
          <button class="btn ghost sm" form="add-discipline" data-add-discipline><?= te('Add') ?></button>
          <span class="small" data-add-discipline-status></span>
        </details>
      </div>
      <div class="in-s">
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

    <?php
    // P5-M72: the line in two parts - what the person is paid, then what the client is billed for this trade
    // (RSS, 17 Oct 2026: "pay rate, then bill rate, then markup on pay - it's confusing"). Overtime starts at
    // 40 hours and time and a half, and is changed when the contract says otherwise (sometimes 38).
    $termInput = static function (string $field, array $meta, $default = '') use ($editLine): void {
        $value = $editLine ? ($editLine[$field] !== null ? ($meta['kind'] === 'hours' ? (int) $editLine[$field] : (float) $editLine[$field]) : '') : $default; ?>
        <div>
          <label for="l-<?= e($field) ?>"><?= te($meta['label']) ?></label>
          <input id="l-<?= e($field) ?>" type="number" name="<?= e($field) ?>" min="0"
                 step="<?= $meta['kind'] === 'hours' ? '1' : '0.01' ?>" value="<?= e((string) $value) ?>">
          <span class="hint"><?= te($meta['hint']) ?></span>
        </div>
    <?php };
    $lineMarkup = $editLine && (float) ($editLine['pay_rate'] ?? 0) > 0 && $editLine['bill_rate'] !== null
        ? round(((float) $editLine['bill_rate'] / (float) $editLine['pay_rate'] - 1) * 100, 1) : null; ?>
    <fieldset class="mt" data-line-pay>
      <legend><?= te('1. What the person is paid') ?></legend>
      <div class="row">
        <?php $termInput('pay_rate', $terms['pay_rate']); ?>
        <?php $termInput('per_diem_rate', $terms['per_diem_rate']); ?>
        <?php $termInput('guarantee_hours', $terms['guarantee_hours']); ?>
        <?php $termInput('strike_guarantee_hours', $terms['strike_guarantee_hours']); ?>
      </div>
      <div class="row">
        <?php $termInput('overtime_after', $terms['overtime_after'], 40); ?>
        <?php $termInput('overtime_multiplier', $terms['overtime_multiplier'], 1.5); ?>
      </div>
      <p class="small muted m0"><?= te('Default: overtime after 40 hours at 1.5x.') ?></p>
    </fieldset>
    <fieldset class="mt" data-line-bill>
      <legend><?= te('2. What the client is billed for this trade, per hour') ?></legend>
      <div class="row">
        <div>
          <label class="d-block"><input type="radio" name="bill_by" value="rate" checked> <?= te('A bill rate agreed with the client') ?></label>
          <label class="d-block"><input type="radio" name="bill_by" value="markup"> <?= te('A markup on the pay rate') ?></label>
        </div>
        <?php $termInput('bill_rate', $terms['bill_rate']); ?>
        <div>
          <label for="l-markup"><?= te('Markup on pay (%)') ?></label>
          <input id="l-markup" type="number" name="markup" min="0" max="1000" step="0.1" placeholder="35" value="<?= $lineMarkup !== null ? e((string) $lineMarkup) : '' ?>">
          <span class="hint"><?= te('Bill rate = pay rate x (1 + markup).') ?></span>
        </div>
      </div>
      <p class="small m0" data-line-figures data-text="<?= te('Billed $:bill/h for $:pay/h paid: $:margin/h margin (:pct% markup).') ?>"></p>
      <p class="small muted m0"><?= te('Hotel, travel, per diem and transport are billed under') ?> <a href="#billing-terms"><?= te('What the client is billed') ?></a></p>
    </fieldset>

    <div class="field mt">
      <label for="l-notes"><?= te('Anything else agreed on this line') ?></label>
      <input id="l-notes" name="notes" maxlength="500"
             value="<?= e($editLine['notes'] ?? '') ?>"
             placeholder="<?= te('e.g. Client provides tools; two-week minimum; CDL required') ?>">
    </div>

    <?php if ($editLine): ?><div class="field mt"><label for="l-why"><?= te('Why it changes') ?></label><input id="l-why" name="change_reason" maxlength="500" placeholder="<?= te('e.g. Client asked for 20 more, email of 17 Oct') ?>"><span class="hint"><?= te('Kept in the history with who and when.') ?></span></div><?php endif; ?>
    <button class="btn mt-l" type="submit">
      <?= $editLine ? te('Save the line') : te('Add to the order') ?>
    </button>
    <?php if ($editLine): ?>
      <a class="btn ghost ml-s" href="/job#scope"><?= te('Cancel') ?></a>
    <?php else: ?>
      <button type="button" class="btn ghost ml-s" data-fold-close><?= te('Cancel') ?></button>
    <?php endif; ?>
  </form>
  </details>
</details>

<!-- ── P5-M27: the plant's shifts ── -->
<details class="card fold" id="shifts">
  <summary><span><span class="eyebrow"><?= te('THE PLANT') ?></span><h2><?= te('Shifts and buses') ?></h2></span><span class="fold-note"><?= $shifts ? e(implode(' · ', array_column($shifts, 'name'))) : te('none yet') ?></span></summary>
  <p class="small muted m0 mb"><?= te('Shift times and bus departures.') ?></p>
  <?php if (! $shifts): ?>
    <form method="post" action="/job" class="mb"><?= csrf_field() ?><input type="hidden" name="do" value="shift_defaults"><button class="btn ghost sm"><?= te('Add the usual day and night shifts') ?></button></form>
  <?php else: ?>
  <div class="scroll"><table>
    <tr><th><?= te('Shift') ?></th><th><?= te('Starts') ?></th><th><?= te('Ends') ?></th><th><?= te('Bus leaves') ?></th><th></th></tr>
    <?php foreach ($shifts as $sh): ?>
    <tr data-shift="<?= (int) $sh['id'] ?>"><td colspan="5">
      <form method="post" action="/job" class="row items-end m0"><?= csrf_field() ?><input type="hidden" name="do" value="shift_save"><input type="hidden" name="shift_id" value="<?= (int) $sh['id'] ?>">
        <input class="in-s" name="name" maxlength="60" value="<?= e($sh['name']) ?>" aria-label="<?= te('Shift') ?>">
        <input class="in-xs" name="starts_at" type="time" value="<?= e(substr((string) $sh['starts_at'], 0, 5)) ?>" aria-label="<?= te('Starts') ?>">
        <input class="in-xs" name="ends_at" type="time" value="<?= e(substr((string) $sh['ends_at'], 0, 5)) ?>" aria-label="<?= te('Ends') ?>">
        <input class="in-xs" name="bus_departs" type="time" value="<?= e(substr((string) ($sh['bus_departs'] ?? ''), 0, 5)) ?>" aria-label="<?= te('Bus leaves') ?>">
        <span class="small muted"><?= e(shift_hours($sh)) ?></span>
        <button class="btn sm"><?= te('Save') ?></button>
      </form>
      <form method="post" action="/job" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="shift_remove"><input type="hidden" name="shift_id" value="<?= (int) $sh['id'] ?>"><button class="btn ghost sm"><?= te('Remove') ?></button></form>
    </td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
  <form method="post" action="/job" class="row items-end mt"><?= csrf_field() ?><input type="hidden" name="do" value="shift_save">
    <div><label for="ns-name"><?= te('New shift') ?></label><input id="ns-name" class="in-s" name="name" maxlength="60" placeholder="<?= te('e.g. Swing') ?>"></div>
    <div><label for="ns-start"><?= te('Starts') ?></label><input id="ns-start" type="time" name="starts_at"></div>
    <div><label for="ns-end"><?= te('Ends') ?></label><input id="ns-end" type="time" name="ends_at"></div>
    <div><label for="ns-bus"><?= te('Bus leaves') ?></label><input id="ns-bus" type="time" name="bus_departs"></div>
    <button class="btn ghost sm"><?= te('Add the shift') ?></button>
  </form>
</details>

<!-- P5-M71: who from the client is on this project, and the one in charge -->
<details class="card fold" id="project-contacts">
  <summary><h2><?= te('Client contacts on this project') ?></h2><span class="fold-note"><?php $chiefNote = array_values(array_filter($projectContacts, static fn($x) => (int) $x['in_charge'] === 1))[0] ?? null; ?><?= $chiefNote ? te('in charge: :name', ['name' => $chiefNote['name']]) : te('nobody chosen yet') ?></span></summary>
  <p class="small muted"><?= te('Select the client contacts for this project and the person in charge.', ['client' => $job['client_name'] ?? '']) ?></p>
  <?php if (! $clientContacts): ?>
  <p class="muted"><?= te('This client has no contact yet.') ?> <a href="/clients?id=<?= (int) $job['client_id'] ?>#contacts"><?= te('Add their contacts') ?></a></p>
  <?php else: ?>
  <form method="post" action="/job#project-contacts"><?= csrf_field() ?><input type="hidden" name="do" value="project_contacts">
    <table><thead><tr><th><?= te('On this project') ?></th><th><?= te('In charge') ?></th><th><?= te('Name') ?></th><th><?= te('Title or role') ?></th><th><?= te('Email') ?></th><th><?= te('Phone') ?></th></tr></thead><tbody>
    <?php foreach ($clientContacts as $x): $on = isset($projectContacts[$x['id']]); ?>
      <tr data-project-contact="<?= (int) $x['id'] ?>"><td><input type="checkbox" name="contacts[]" value="<?= (int) $x['id'] ?>"<?= $on ? ' checked' : '' ?> aria-label="<?= te('On this project') ?>: <?= e($x['name']) ?>"></td>
        <td><input type="radio" name="in_charge" value="<?= (int) $x['id'] ?>"<?= $on && $projectContacts[$x['id']]['in_charge'] ? ' checked' : '' ?> aria-label="<?= te('In charge') ?>: <?= e($x['name']) ?>"></td>
        <td><strong><?= e($x['name']) ?></strong><?= $x['is_main'] ? ' <span class="tag green">' . te('Main contact') . '</span>' : '' ?><?= $x['receives_invoices'] ? ' <span class="tag blue">' . te('Receives the invoices') . '</span>' : '' ?></td>
        <td><?= e($x['title'] ?? '') ?: '—' ?></td><td><?= e($x['email'] ?? '') ?: '—' ?></td><td><?= e($x['phone'] ?? '') ?: '—' ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <p class="mt-s"><button class="btn"><?= te('Save the project contacts') ?></button> <a class="btn ghost" href="/clients?id=<?= (int) $job['client_id'] ?>#contacts"><?= te('Add or change contacts') ?></a></p>
  </form>
  <?php endif; ?>
</details>

<!-- P5-M70: what the client is billed, beyond the hours at the bill rate -->
<details class="card fold" id="billing-terms">
  <summary><h2><?= te('What the client is billed') ?></h2><span class="fold-note"><?php $btSet = array_filter(['per_diem_mode', 'lodging_mode', 'travel_mode', 'transport_mode'], static fn($k) => $billing[$k] !== 'none'); ?><?= $btSet || $billing['ot_mode'] !== 'straight' ? te('terms set') : te('every hour at the bill rate, nothing else') ?></span></summary>
  <p class="small muted"><?= te('How each approved week is invoiced to the client.') ?></p>
  <?php $bm = bt_modes(); $sel = static fn(string $k) => implode('', array_map(static fn($v, $label) => '<option value="' . e($v) . '"' . ((string) $billing[$k] === (string) $v ? ' selected' : '') . '>' . e($label) . '</option>', array_keys($bm[$k]), $bm[$k])); $num = static fn(string $k) => e($billing[$k] === null ? '' : rtrim(rtrim(number_format((float) $billing[$k], 2, '.', ''), '0'), '.')); ?>
  <form method="post" action="/job#billing-terms"><?= csrf_field() ?><input type="hidden" name="do" value="billing_terms">
    <table class="terms">
      <?php // P5-M72: each amount is asked for only with the way of billing that uses it (RSS: "at cost and then a 20% markup makes no sense"). ?>
      <tr><td><?= te('Overtime') ?></td><td><select name="ot_mode" data-bt-mode aria-label="<?= te('Overtime') ?>"><?= $sel('ot_mode') ?></select></td>
          <td><label class="small" data-bt-when="multiplier"><?= te('Multiplier') ?> <input name="ot_multiplier" inputmode="decimal" class="in-xs" value="<?= $num('ot_multiplier') ?>"></label>
              <label class="small" data-bt-when="rate"><?= te('Overtime rate $/h') ?> <input name="ot_rate" inputmode="decimal" class="in-xs" value="<?= $num('ot_rate') ?>"></label></td></tr>
      <tr><td><?= te('Per diem') ?></td><td><select name="per_diem_mode" data-bt-mode aria-label="<?= te('Per diem') ?>"><?= $sel('per_diem_mode') ?></select></td>
          <td><label class="small" data-bt-when="cost_plus"><?= te('Markup %') ?> <input name="per_diem_markup_pct" inputmode="decimal" class="in-xs" value="<?= $num('per_diem_markup_pct') ?>"></label></td></tr>
      <tr><td><?= te('Hotel') ?></td><td><select name="lodging_mode" data-bt-mode aria-label="<?= te('Hotel') ?>"><?= $sel('lodging_mode') ?></select></td>
          <td><label class="small" data-bt-when="cost_plus"><?= te('Markup %') ?> <input name="lodging_markup_pct" inputmode="decimal" class="in-xs" value="<?= $num('lodging_markup_pct') ?>"></label>
              <label class="small" data-bt-when="flat_night"><?= te('Billed $ per room per night, whatever the hotel') ?> <input name="lodging_night_rate" inputmode="decimal" class="in-xs" value="<?= $num('lodging_night_rate') ?>"></label>
              <span class="small muted d-block" data-bt-when="hotel_rate"><?= te('The price per night is set on each of the project\'s hotels.') ?> <a href="/hotels#project-hotels"><?= te('Hotels') ?></a></span>
              <span class="small muted d-block" data-bt-when="cost cost_plus"><?= te('At cost: each booking is billed at its own nightly rate.') ?></span></td></tr>
      <tr><td><?= te('Travel') ?></td><td><select name="travel_mode" data-bt-mode aria-label="<?= te('Travel') ?>"><?= $sel('travel_mode') ?></select></td>
          <td><label class="small" data-bt-when="cost_plus"><?= te('Markup %') ?> <input name="travel_markup_pct" inputmode="decimal" class="in-xs" value="<?= $num('travel_markup_pct') ?>"></label></td></tr>
      <tr><td><?= te('Transport on site') ?></td><td><select name="transport_mode" data-bt-mode aria-label="<?= te('Transport on site') ?>"><?= $sel('transport_mode') ?></select></td>
          <td><label class="small" data-bt-when="per_day per_week"><?= te('Rate $') ?> <input name="transport_rate" inputmode="decimal" class="in-xs" value="<?= $num('transport_rate') ?>"></label></td></tr>
      <?php foreach ($shifts as $sh): ?><tr data-shift-premium="<?= (int) $sh['id'] ?>"><td><?= te(':shift shift', ['shift' => $sh['name']]) ?></td><td><?= te('premium billed, $ per hour') ?></td><td><input name="shift_premium[<?= (int) $sh['id'] ?>]" inputmode="decimal" class="in-xs" value="<?= (float) ($sh['bill_premium'] ?? 0) > 0 ? e(rtrim(rtrim(number_format((float) $sh['bill_premium'], 2, '.', ''), '0'), '.')) : '' ?>" placeholder="0"> <span class="small muted"><?= te('empty: billed at the regular rate') ?></span></td></tr><?php endforeach; ?>
    </table>
    <button class="btn mt-s"><?= te('Save what the client is billed') ?></button>
  </form>
</details>

<!-- ── the agreement ────────────────────────────────────────────────── -->
<form method="post" action="/job">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="save_project">

  <?php // P5-M73: the strike switch is at the top of the page; saving the project keeps it as it is. ?>
  <?php if ($strike): ?><input type="hidden" name="strike_live" value="1"><?php endif; ?>

  <details class="card fold" id="project-form">
    <summary><h2><?= te('The project') ?></h2><span class="fold-note"><?= e(trim(($job['site_address'] ?? '') . ' ' . ($job['site_city'] ?? '') . ' ' . ($job['site_state'] ?? ''))) ?: te('Site not recorded') ?> · <?= $job['starts_on'] ? e(d($job['starts_on'])) : '—' ?> &rarr; <?= $job['ends_on'] ? e(d($job['ends_on'])) : te('ongoing, no end date') ?></span></summary>

    <div class="row">
      <div class="f2">
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
        <label for="j-pm"><?= te('Project manager') ?></label>
        <select id="j-pm" name="project_manager_id">
          <option value="0"><?= te('Nobody yet') ?></option>
          <?php foreach ($staff as $s): ?><option value="<?= (int) $s['id'] ?>"<?= (int) ($job['project_manager_id'] ?? 0) === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
        </select>
        <span class="hint"><?= te('Told of each order and requisition on this plant.') ?></span>
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

    <?php // P5-M84: who onboards the approved applicants of this project. ?>
    <div class="row mt-s"><div class="f2"><label for="j-coord"><?= te('HR (onboarding)') ?></label>
      <select id="j-coord" name="coordinator_id"><option value=""><?= te('Whoever runs onboarding') ?></option>
        <?php foreach ($staff as $st): if (! in_array($st['role'], ['admin', 'recruiter', 'hr', 'payroll', 'hotels', 'supervisor'], true)) { continue; } ?><option value="<?= (int) $st['id'] ?>"<?= (int) ($job['coordinator_id'] ?? 0) === (int) $st['id'] ? ' selected' : '' ?>><?= e($st['name']) ?></option><?php endforeach; ?>
      </select><span class="hint"><?= te('Receives the applicants a manager approves.') ?></span></div></div>
    <div class="field mt">
      <label for="j-desc"><?= te('Description of the work') ?></label>
      <textarea id="j-desc" name="description" rows="4" maxlength="8000"
                placeholder="<?= te('e.g. Provide skilled labour support to the client plant for the duration of the work stoppage, in accordance with the scope of work below.') ?>"><?= e($job['description'] ?? '') ?></textarea>
      <span class="hint"><?= te('Shown to applicants.') ?></span>
    </div>

  <?php // P5-M71: the site address finds the position, city, state and ZIP (app/geocode.php). ?>
  <div class="row mt" data-geo data-lat="#j-lat" data-lng="#j-lng" data-city="#j-city" data-state="#j-state" data-zip="#j-zip">
    <div class="f2"><label for="j-address"><?= te('Site address') ?></label><input id="j-address" name="site_address" maxlength="190" autocomplete="off" data-geo-address value="<?= e($job['site_address'] ?? '') ?>" placeholder="<?= te('e.g. 1 N Broadway, Gary, IN') ?>">
      <span class="hint" data-geo-status data-searching="<?= te('Finding the address...') ?>" data-found="<?= te('Using :address - position :lat, :lng. Save to keep it.') ?>" data-use="<?= te('Use this address') ?>" data-other="<?= te('Not this one') ?>" data-hint="<?= te('Type the address more fully (number, street, city, state), or type the latitude and longitude.') ?>"><?= te('City, state, ZIP and position are filled from the address.') ?></span></div>
    <div><label>&nbsp;</label><button type="button" class="btn ghost sm" data-geo-find><?= te('Find the address') ?></button></div>
  </div>
    <div class="row mt-s">
      <div class="f2">
        <label for="j-site"><?= te('Site') ?></label>
        <input id="j-site" name="site_name" maxlength="190" value="<?= e($job['site_name'] ?? '') ?>">
      </div>
      <div>
        <label for="j-city"><?= te('City') ?></label>
        <input id="j-city" name="site_city" maxlength="120" value="<?= e($job['site_city'] ?? '') ?>">
      </div>
      <div class="in-xs">
        <label for="j-state"><?= te('State') ?></label>
        <input id="j-state" name="site_state" maxlength="2" value="<?= e($job['site_state'] ?? '') ?>">
      </div>
      <div class="in-s">
        <label for="j-lat"><?= te('Site latitude') ?></label>
        <input id="j-lat" name="site_lat" inputmode="decimal" maxlength="12" value="<?= e((string) ($job['site_lat'] ?? '')) ?>">
      </div>
      <div class="in-s">
        <label for="j-lng"><?= te('Site longitude') ?></label>
        <input id="j-lng" name="site_lng" inputmode="decimal" maxlength="12" value="<?= e((string) ($job['site_lng'] ?? '')) ?>">
      </div>
      <div class="in-s">
        <label for="j-radius"><?= te('Clock-in radius (m)') ?></label>
        <input id="j-radius" name="site_radius_m" type="number" min="50" max="5000" value="<?= (int) ($job['site_radius_m'] ?? 300) ?>"><span class="hint"><?= te('In metres (300 m is about 1,000 ft). Clock-ins outside it are flagged to the supervisor.') ?></span>
      </div>
      <div class="in-s"><label>&nbsp;</label><button type="button" class="btn ghost sm" onclick="var b=this;navigator.geolocation&&navigator.geolocation.getCurrentPosition(function(p){document.getElementById('j-lat').value=p.coords.latitude.toFixed(6);document.getElementById('j-lng').value=p.coords.longitude.toFixed(6);b.textContent=b.dataset.done;},function(){b.textContent=b.dataset.fail;},{enableHighAccuracy:true,timeout:15000})" data-done="<?= te('Position taken: save') ?>" data-fail="<?= te('Position unavailable') ?>"><?= te('My position here') ?></button></div>
      <div class="in-s">
        <label for="j-zip"><?= te('ZIP code') ?></label>
        <input id="j-zip" name="site_zip" maxlength="10" inputmode="numeric" pattern="\d{5}(-\d{4})?" value="<?= e($job['site_zip'] ?? '') ?>">
      </div>
      <div>
        <label for="j-start"><?= te('Starts') ?></label>
        <input id="j-start" type="date" name="starts_on" value="<?= e($job['starts_on'] ?? '') ?>">
      </div>
      <div>
        <label for="j-end"><?= te('Ends') ?></label>
        <input id="j-end" type="date" name="ends_on" value="<?= e($job['ends_on'] ?? '') ?>"<?= $job['ends_on'] ? '' : ' disabled' ?>>
        <label class="d-block small"><input type="checkbox" name="ends_unknown" value="1"<?= $job['ends_on'] ? '' : ' checked' ?> onchange="var d=document.getElementById('j-end');d.disabled=this.checked;if(this.checked)d.value=''"> <?= te('End date unknown (ongoing)') ?></label>
      </div>
    </div>

    <h3 class="mt-l"><?= te('What the contract covers') ?></h3>
    <p class="hint m0 pull-up mb">
      <?= te('Provided to the crew and billed to the client.') ?>
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

    <p class="small muted m0 mt-l">
      <?= te('Headcount from the scope of work: :n.', [
          'n' => (int) $totals['people']]) ?>
    </p>
  </details>

  <details class="card fold" id="internal-notes">
    <summary><h2><?= te('Internal notes') ?></h2><span class="fold-note"><?= $job['notes'] ? e(mb_strimwidth((string) $job['notes'], 0, 60, '...')) : te('none') ?></span></summary>
    <textarea name="notes" rows="5"><?= e($job['notes'] ?? '') ?></textarea>
    <p class="small muted m0 mt-s">
      <?= te('Internal only. Not shown to applicants or clients.') ?>
    </p>
  </details>

  <button class="btn" type="submit"><?= te('Save the project') ?></button>
</form>

<?php endif; ?>
<script src="/assets/site-address.js"></script>
<form method="post" action="/job#scope" id="add-discipline"><?= csrf_field() ?><input type="hidden" name="do" value="add_discipline"><?php if (! empty($editLine['id'])): ?><input type="hidden" name="line_id" value="<?= (int) $editLine['id'] ?>"><?php endif; ?></form>
<script src="/assets/add-discipline.js"></script>
<script src="/assets/scope-line.js"></script>
<script src="/assets/billing-terms.js"></script>
<script src="/assets/fold.js"></script>
