<div class="page-head"><div><h1><?= te('Team') ?></h1>
<p class="sub"><?= ten(array_sum($peopleCounts), ':n account', ':n accounts') ?> &middot; <?= te('a desk decides what somebody sees, not how senior they are') ?></p></div>
<div class="actions"><a class="btn" href="/people?tab=staff#add-person" onclick="const f=document.getElementById('add-person');if(f){f.open=true;f.scrollIntoView();f.querySelector('input[name=name]').focus();return false;}"><?= te('Add somebody') ?></a></div></div>

<details class="card" id="add-person" <?= $peopleTab === 'staff' ? 'open' : '' ?>>
  <summary><h2 class="m0 inline-title"><?= te('Add somebody to the staff') ?></h2></summary>
  <form method="post" action="/people" class="mt-s">
    <?= csrf_field() ?><input type="hidden" name="do" value="add">
    <div class="grid g3 form-grid">
      <label><?= te('Name') ?><input name="name" required maxlength="190"></label>
      <label><?= te('Email') ?><input name="email" type="email" required maxlength="190"></label>
      <label><?= te('Telephone') ?><input name="phone" maxlength="40"></label>
      <label><?= te('Desk') ?>
        <select name="role" required>
          <?php foreach ($staffDesks as $k => $v): ?><option value="<?= e($k) ?>" <?= $k === 'recruiter' ? 'selected' : '' ?>><?= te($v) ?></option><?php endforeach; ?>
        </select></label>
      <label><?= te('Title') ?><input name="job_title" maxlength="190" placeholder="<?= te('e.g. Project manager, Site supervisor') ?>"></label>
      <div class="row gap-s items-end"><button class="btn" type="submit"><?= te('Add') ?></button></div>
    </div>
    <p class="small muted m0 mt-s"><?= te('They get a temporary password, shown once on this screen. Pass it on yourself; they choose their own when they first sign in. Employees and clients get their portal through their invitation, not here.') ?></p>
  </form>
</details>

<nav class="tabs" aria-label="<?= te('Accounts') ?>">
  <?php foreach (['staff' => t('Staff'), 'clients' => t('Client portals'), 'workers' => t('Worker portals')] as $k => $label): ?>
  <a href="/people?tab=<?= e($k) ?>" class="<?= $peopleTab === $k ? 'on' : '' ?>" data-tab="<?= e($k) ?>"><?= e($label) ?> · <?= (int) $peopleCounts[$k] ?></a>
  <?php endforeach; ?>
</nav>
<form method="get" action="/people" class="row gap-s mt-s mb-s" id="people-search"><input type="hidden" name="tab" value="<?= e($peopleTab) ?>">
  <input name="q" value="<?= e($peopleQ) ?>" placeholder="<?= te('Search a name or an email') ?>" aria-label="<?= te('Search a name or an email') ?>" class="f1">
  <button class="btn ghost"><?= te('Search') ?></button><?php if ($peopleQ !== ''): ?> <a class="btn ghost" href="/people?tab=<?= e($peopleTab) ?>"><?= te('Clear') ?></a><?php endif; ?>
</form>
<div class="card tight">
  <div class="scroll">
  <table>
    <thead><tr><th><?= te('Name') ?></th><th><?= te('Email') ?></th><th><?= te('Desk') ?></th><th><?= te('Title') ?></th><th><?= te('Last signed in') ?></th><th class="right"><?= te('Actions') ?></th></tr></thead>
    <tbody>
    <?php foreach ($team as $t): ?>
      <tr class="<?= (int) $t['is_active'] === 0 ? 'faded' : '' ?>">
        <td><strong><?= e($t['name']) ?></strong>
          <?php if ((int) $t['is_active'] === 0): ?><span class="tag red"><?= te('off') ?></span><?php endif; ?>
          <?php if ((int) $t['must_change_pw'] === 1): ?><span class="tag amber"><?= te('temp password') ?></span><?php endif; ?>
          <?php if (isset($lockedUntil[(int) $t['id']])): ?><span class="tag red" data-locked="<?= (int) $t['id'] ?>"><?= te('sign-in locked until :time', ['time' => date('g:ia', $lockedUntil[(int) $t['id']])]) ?></span><?php endif; ?>
        </td>
        <td class="small muted"><?= e($t['email']) ?></td>
        <td>
          <?php if (in_array($t['role'], ['worker', 'client'], true)): ?><span class="small"><?= te(roles()[$t['role']]) ?></span><?php else: ?>
          <form class="inline" method="post" action="/people">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="role">
            <input type="hidden" name="user_id" value="<?= (int) $t['id'] ?>">
            <select name="role" class="w-auto pad-s t-13" onchange="this.form.submit()">
              <?php foreach ($staffDesks as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= $t['role'] === $k ? 'selected' : '' ?>><?= te($v) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
          <?php // P5-M88: who may hire. ?>
          <form class="inline" method="post" action="/people"><?= csrf_field() ?><input type="hidden" name="do" value="hiring_manager"><input type="hidden" name="user_id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="on" value="<?= ! empty($t['hiring_manager']) ? 0 : 1 ?>">
            <button class="btn sm <?= ! empty($t['hiring_manager']) ? '' : 'ghost' ?>" data-hiring-manager="<?= (int) $t['id'] ?>" title="<?= te('Hiring managers are offered on requisitions and review the applicants.') ?>"><?= ! empty($t['hiring_manager']) ? te('Hiring manager ✓') : te('Hiring manager') ?></button></form><?php endif; ?>
        </td>
        <td class="small muted"><?= e($t['job_title'] ?? '—') ?></td>
        <td class="small muted"><?= $t['last_login_at'] ? e(date('j M, g:ia', strtotime($t['last_login_at']))) : te('never') ?></td>
        <td class="right"><div class="row no-wrap gap-s nowrap justify-end">
          <form class="inline" method="post" action="/people">
            <?= csrf_field() ?><input type="hidden" name="do" value="reset">
            <input type="hidden" name="user_id" value="<?= (int) $t['id'] ?>">
            <button class="btn ghost sm" type="submit"><?= te('Reset password') ?></button>
          </form>
          <?php if (isset($lockedUntil[(int) $t['id']])): ?>
          <form class="inline" method="post" action="/people">
            <?= csrf_field() ?><input type="hidden" name="do" value="unlock">
            <input type="hidden" name="user_id" value="<?= (int) $t['id'] ?>">
            <button class="btn sm" type="submit"><?= te('Unlock sign-in') ?></button>
          </form>
          <?php endif; ?>
          <?php if ((int) $t['id'] !== uid()): ?>
          <form class="inline" method="post" action="/people">
            <?= csrf_field() ?><input type="hidden" name="do" value="toggle">
            <input type="hidden" name="user_id" value="<?= (int) $t['id'] ?>">
            <button class="btn ghost sm" type="submit"><?= (int) $t['is_active'] === 1 ? te('Switch off') : te('Switch on') ?></button>
          </form>
          <?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    <?php if (! $team): ?><tr><td colspan="6" class="muted"><?= te('Nobody here.') ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php if ($peoplePages > 1): ?>
<nav class="row gap-s mt-s" id="people-pages" aria-label="<?= te('Pages') ?>"><span class="small muted"><?= te(':n found · page :p of :pages', ['n' => $peopleFound, 'p' => $peoplePage, 'pages' => $peoplePages]) ?></span>
  <?php if ($peoplePage > 1): ?><a class="btn ghost sm" href="/people?<?= e(http_build_query(['tab' => $peopleTab, 'q' => $peopleQ, 'page' => $peoplePage - 1])) ?>"><?= te('Previous') ?></a><?php endif; ?>
  <?php if ($peoplePage < $peoplePages): ?><a class="btn ghost sm" href="/people?<?= e(http_build_query(['tab' => $peopleTab, 'q' => $peopleQ, 'page' => $peoplePage + 1])) ?>"><?= te('Next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>

<div class="card tight">
  <div class="card-bar"><h2 class="m0"><?= te('Recent activity') ?></h2></div>
  <?php if (! $recent): ?>
    <div class="empty"><?= te('Nothing yet.') ?></div>
  <?php else: ?>
    <?php foreach ($recent as $a): ?>
      <div class="small card-row">
        <strong><?= e($a['who'] ?? 'someone') ?></strong> <?= e($a['action']) ?>
        <?php if ($a['detail']): ?><span class="muted">&mdash; <?= e($a['detail']) ?></span><?php endif; ?>
        <span class="muted">&middot; <?= e(date('j M, g:ia', strtotime($a['created_at']))) ?></span>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
