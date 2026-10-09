<h1><?= te('Team') ?></h1>
<p class="sub"><?= count($team) ?> accounts &middot; a desk decides what somebody sees, not how senior they are</p>

<div class="card tight">
  <div class="scroll">
  <table>
    <thead><tr><th><?= te('Name') ?></th><th><?= te('Email') ?></th><th><?= te('Desk') ?></th><th><?= te('Title') ?></th><th><?= te('Last signed in') ?></th><th class="right"><?= te('Actions') ?></th></tr></thead>
    <tbody>
    <?php foreach ($team as $t): ?>
      <tr style="<?= (int) $t['is_active'] === 0 ? 'opacity:.5' : '' ?>">
        <td><strong><?= e($t['name']) ?></strong>
          <?php if ((int) $t['is_active'] === 0): ?><span class="tag red"><?= te('off') ?></span><?php endif; ?>
          <?php if ((int) $t['must_change_pw'] === 1): ?><span class="tag amber"><?= te('temp password') ?></span><?php endif; ?>
        </td>
        <td class="small muted"><?= e($t['email']) ?></td>
        <td>
          <form method="post" action="/people" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="role">
            <input type="hidden" name="user_id" value="<?= (int) $t['id'] ?>">
            <select name="role" style="width:auto;padding:4px 8px;font-size:13px" onchange="this.form.submit()">
              <?php foreach (roles() as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= $t['role'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </td>
        <td class="small muted"><?= e($t['job_title'] ?? '—') ?></td>
        <td class="small muted"><?= $t['last_login_at'] ? e(date('j M, g:ia', strtotime($t['last_login_at']))) : 'never' ?></td>
        <td class="right">
          <form method="post" action="/people" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="do" value="reset">
            <input type="hidden" name="user_id" value="<?= (int) $t['id'] ?>">
            <button class="btn ghost sm" type="submit"><?= te('Reset password') ?></button>
          </form>
          <?php if ((int) $t['id'] !== uid()): ?>
          <form method="post" action="/people" style="display:inline">
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
      <div style="flex:2"><label><?= te('Email') ?></label><input name="email" type="email" required></div>
      <div><label><?= te('Telephone') ?></label><input name="phone"></div>
      <div><label><?= te('Desk') ?></label>
        <select name="role">
          <?php foreach (roles() as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
        </select></div>
      <div><label><?= te('Title') ?></label><input name="job_title"></div>
      <div class="row tight"><button class="btn" type="submit"><?= te('Add') ?></button></div>
    </div>
    <p class="small muted" style="margin:10px 0 0">
      <?= te('They get a temporary password, shown once on this screen. Pass it on yourself.') ?>
    </p>
  </form>
</div>

<div class="card tight">
  <div style="padding:14px 18px;border-bottom:1px solid var(--line)"><h2 style="margin:0"><?= te('Recent activity') ?></h2></div>
  <?php if (! $recent): ?>
    <div class="empty"><?= te('Nothing yet.') ?></div>
  <?php else: ?>
    <?php foreach ($recent as $a): ?>
      <div style="padding:9px 18px;border-bottom:1px solid var(--line)" class="small">
        <strong><?= e($a['who'] ?? 'someone') ?></strong> <?= e($a['action']) ?>
        <?php if ($a['detail']): ?><span class="muted">&mdash; <?= e($a['detail']) ?></span><?php endif; ?>
        <span class="muted">&middot; <?= e(date('j M, g:ia', strtotime($a['created_at']))) ?></span>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
