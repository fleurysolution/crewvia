<?php
/**
 * Who is on the job, and what is missing for each of them.
 *
 * Ten columns meant the screen scrolled sideways on a laptop and the one
 * column a recruiter opens this page for - what is still blocking somebody -
 * sat off the right edge. The facts are the same; they are grouped the way
 * somebody reads them: who, where they stand, what is booked, what is missing.
 */

$statuses = ['offered' => 'Offered', 'confirmed' => 'Confirmed', 'travelling' => 'Travelling',
             'on_site' => 'On site', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

$tagFor = static fn (string $s): string => match ($s) {
    'on_site'    => 'green',
    'travelling' => 'amber',
    'confirmed'  => 'blue',
    'offered'    => 'grey',
    'cancelled'  => 'red',
    default      => 'grey',
};

$deployed  = array_filter($crew, fn ($p) => in_array($p['status'], ['confirmed','travelling','on_site'], true));
$noBed     = array_filter($deployed, fn ($p) => ! $p['hotel']);
$sharing   = array_filter($deployed, fn ($p) => $p['hotel'] && (int) $p['private_room'] === 0);
$noFlight  = array_filter($crew, fn ($p) => ! $p['arrive_time']
                                           && in_array($p['status'], ['confirmed','travelling'], true));
$notClear  = array_filter($blockers ?? [], fn ($b) => $b !== []);
?>

<div class="page-heading">
  <div>
    <span class="eyebrow"><?= te('DEPLOYMENT') ?></span>
    <h1><?= te('Roster') ?></h1>
    <p class="sub">
      <?= te(':n on :project', ['n' => count($crew), 'project' => $job['title'] ?? t('the job')]) ?>
    </p>
  </div>
  <a class="btn ghost" href="/hotels"><?= te('Hotels & rooms') ?></a>
</div>

<?php if ($crew): ?>
<div class="grid g4" style="margin-bottom:18px">
  <div class="stat">
    <div class="n"><?= count($deployed) ?></div>
    <div class="l"><?= te('Deployed') ?></div>
  </div>
  <div class="stat">
    <div class="n" style="color:<?= $notClear ? 'var(--amber)' : 'var(--green)' ?>"><?= count($notClear) ?></div>
    <div class="l"><?= te('Blocked from deploying') ?></div>
  </div>
  <div class="stat">
    <div class="n" style="color:<?= $noBed ? 'var(--red)' : 'var(--green)' ?>"><?= count($noBed) ?></div>
    <div class="l"><?= te('Without a bed') ?></div>
    <?php if ($sharing): ?>
      <div class="h"><?= te(':n sharing a room', ['n' => count($sharing)]) ?></div>
    <?php endif; ?>
  </div>
  <div class="stat">
    <div class="n" style="color:<?= $noFlight ? 'var(--amber)' : 'var(--green)' ?>"><?= count($noFlight) ?></div>
    <div class="l"><?= te('Travel not booked') ?></div>
  </div>
</div>
<?php endif; ?>

<div class="card tight">
  <?php if (! $crew): ?>
    <div class="empty">
      <?= te('Nobody placed yet. A candidate who accepts their offer lands here.') ?>
      <p><a class="btn ghost" href="/candidates"><?= te('Open the candidate board') ?></a></p>
    </div>
  <?php else: ?>
  <div class="scroll">
  <table class="roster-table wide">
    <thead><tr>
      <th><?= te('Person') ?></th>
      <th><?= te('Status') ?></th>
      <th><?= te('Ready to deploy') ?></th>
      <th><?= te('Bed and travel') ?></th>
      <?php if (can('recruiter')): ?><th class="right nowrap"><?= te('Move') ?></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($crew as $p): ?>
      <tr>
        <td>
          <a href="/placements/<?= (int) $p['id'] ?>"><strong><?= e($p['full_name']) ?></strong></a>
          <div class="muted small">
            <?php
            // What they were ordered as, which is not always their
            // discipline: a mechanical engineer can be on the order as a
            // millwright, and the roster should say what the client asked
            // for rather than what the person is filed under.
            $trade = trim((string) ($p['trade'] ?? ''));
            ?>
            <?= e($trade !== '' ? $trade : (disciplines(true)[$p['discipline']] ?? ucfirst((string) $p['discipline']))) ?>
          </div>
          <?php if ($p['phone']): ?>
            <div class="small mono">
              <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $p['phone'])) ?>"><?= e($p['phone']) ?></a>
            </div>
          <?php endif; ?>
        </td>

        <td><span class="tag <?= $tagFor($p['status']) ?>"><?= te($statuses[$p['status']] ?? $p['status']) ?></span></td>

        <td class="small">
          <?php $stops = $blockers[(int) $p['id']] ?? null; ?>
          <?php if ($stops === null): ?>
            <span class="muted">&mdash;</span>
          <?php elseif (! $stops): ?>
            <span class="tag green"><?= te('Clear') ?></span>
          <?php else: ?>
            <ul class="blocker-list">
            <?php foreach ($stops as $stop): ?>
              <li><a href="<?= e($stop['where']) ?>"><?= e(t($stop['label'], $stop['vars'])) ?></a></li>
            <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </td>

        <td class="small">
          <?php if ($p['hotel']): ?>
            <div>
              <?= e($p['hotel']) ?>
              <?php if ($p['room_number']): ?>
                <span class="muted mono"><?= e($p['room_number']) ?></span>
              <?php endif; ?>
              <?php if ((int) $p['private_room'] === 0): ?>
                <span class="tag red"><?= te('sharing') ?></span>
              <?php endif; ?>
            </div>
          <?php elseif (in_array($p['status'], ['confirmed','travelling','on_site'], true)): ?>
            <div><a class="tag red" href="/hotels"><?= te('no bed') ?></a></div>
          <?php endif; ?>

          <?php if ($p['arrive_time']): ?>
            <div class="muted">
              <?= te('Arrives') ?> <?= e(date('D j M, g:ia', strtotime($p['arrive_time']))) ?>
              <?= e(trim(($p['in_carrier'] ?? '') . ' ' . ($p['in_ref'] ?? ''))) ?>
            </div>
          <?php elseif (in_array($p['status'], ['confirmed','travelling'], true)): ?>
            <div><a class="tag amber" href="/travel"><?= te('travel not booked') ?></a></div>
          <?php endif; ?>

          <?php if ($p['depart_time']): ?>
            <div class="muted"><?= te('Leaves') ?> <?= e(date('j M', strtotime($p['depart_time']))) ?></div>
          <?php endif; ?>

          <?php if (! $p['hotel'] && ! $p['arrive_time'] && ! $p['depart_time']
                    && ! in_array($p['status'], ['confirmed','travelling','on_site'], true)): ?>
            <span class="muted">&mdash;</span>
          <?php endif; ?>
        </td>

        <?php if (can('recruiter')): ?>
        <td class="right">
          <form method="post" action="/roster" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="status">
            <input type="hidden" name="placement_id" value="<?= (int) $p['id'] ?>">
            <label class="sr-only" for="move-<?= (int) $p['id'] ?>">
              <?= te('Move :name to another status', ['name' => $p['full_name']]) ?>
            </label>
            <select id="move-<?= (int) $p['id'] ?>" name="status" class="move-select"
                    onchange="this.form.submit()">
              <option value="">&mdash;</option>
              <?php foreach ($statuses as $k => $v): ?>
                <?php if ($k !== $p['status']): ?>
                  <option value="<?= e($k) ?>"><?= te($v) ?></option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </form>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
