<div class="page-head"><div><h1><?= te('Team') ?></h1>
<p class="sub"><?= count($team) ?> accounts &middot; a desk decides what somebody sees, not how senior they are</p></div></div>

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
          <form class="inline" method="post" action="/people">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="role">
            <input type="hidden" name="user_id" value="<?= (int) $t['id'] ?>">
            <select name="role" class="w-auto pad-s t-13" onchange="this.form.submit()">
              <?php foreach (roles() as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= $t['role'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </td>
        <td class="small muted"><?= e($t['job_title'] ?? '—') ?></td>
        <td class="small muted"><?= $t['last_login_at'] ? e(date('j M, g:ia', strtotime($t['last_login_at']))) : 'never' ?></td>
        <td class="right">
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
            <button class="btn ghost sm" type="submit"><?= (int) $t['is_active'] === 1 ? 'Switch off' : 'Switch on' ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="card">
  <h2><?= te('Add somebody') ?></h2>
  <form method="post" action="/people">
    <?= csrf_field() ?><input type="hidden" name="do" value="add">
    <div class="row">
      <div><label><?= te('Name') ?></label><input name="name" required></div>
      <div class="f2"><label><?= te('Email') ?></label><input name="email" type="email" required></div>
      <div><label><?= te('Telephone') ?></label><input name="phone"></div>
      <div><label><?= te('Desk') ?></label>
        <select name="role">
          <?php foreach (roles() as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
        </select></div>
      <div><label><?= te('Title') ?></label><input name="job_title"></div>
      <div class="row tight"><button class="btn" type="submit"><?= te('Add') ?></button></div>
    </div>
    <p class="small muted m0 mt">
      <?= te('They get a temporary password, shown once on this screen. Pass it on yourself.') ?>
    </p>
  </form>
</div>

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
