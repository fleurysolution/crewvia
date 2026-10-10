<?php
$waiting = [];
if ($home['self_review']) { $waiting[] = ['/appraisals', t('A review is waiting for your own view'), 'self_review']; }
if ($home['to_ack']) { $waiting[] = ['/hr-records', t('An HR decision is waiting for you to read it'), 'to_ack']; }
if ($home['answered']) { $waiting[] = ['/hr-requests', t('HR answered :n of your requests', ['n' => $home['answered']]), 'answered']; }
$card = static function (string $href, string $title, string $line, string $key): void { ?>
  <a class="card" href="<?= e($href) ?>" data-card="<?= e($key) ?>" style="display:block;text-decoration:none;color:inherit"><h3 style="margin:0 0 6px"><?= e($title) ?></h3><div class="small muted"><?= e($line) ?></div></a>
<?php };
?>
<h1><?= te('My self-service') ?></h1>
<p class="sub"><?= te('Hello :name. Everything about your work with us, in one place.', ['name' => $home['name']]) ?></p>

<div class="card" id="waiting"><h2><?= te('Waiting on you') ?></h2>
  <?php if (! $waiting): ?><p class="muted" data-waiting="0"><?= te('Nothing is waiting on you.') ?></p><?php endif; ?>
  <?php foreach ($waiting as [$href, $label, $key]): ?><div data-waiting="<?= e($key) ?>"><a href="<?= e($href) ?>"><?= e($label) ?></a></div><?php endforeach; ?>
</div>

<div class="grid g2">
  <?php $card('/employee-folder', t('My details'), $home['details'] ? t(':n change(s) waiting for HR to check', ['n' => $home['details']]) : t('Your name, contact details and bank details; changes are checked by HR.'), 'details'); ?>
  <?php $card('/timeoff', t('Time off'), ($home['leave'] ? implode(' · ', array_map(fn($l) => $l['label'] . ': ' . leave_days_text((float) $l['left']), $home['leave'])) : t('Ask for time off.')) . ($home['timeoff'] ? ' · ' . t(':n request(s) waiting', ['n' => $home['timeoff']]) : ''), 'timeoff'); ?>
  <?php $card('/my-payslips', t('My pay statements'), $home['payslips'] ? t(':n pay statement(s)', ['n' => $home['payslips']]) : t('No pay statement yet.'), 'payslips'); ?>
  <?php $card('/benefits', t('My benefits'), $home['benefits'] ? implode(', ', array_column($home['benefits'], 'name')) : t('No coverage yet.'), 'benefits'); ?>
  <?php $card('/appraisals', t('Performance reviews'), $home['self_review'] ? t('One is waiting for your own view.') : t('Your reviews, once approved.'), 'reviews'); ?>
  <?php $card('/performance', t('Goals and development'), $home['goals'] ? t(':n goal(s) in progress', ['n' => $home['goals']]) : t('No goal in progress.'), 'goals'); ?>
  <?php $card('/hr-records', t('My HR record'), t('Recognition, and any HR decision about you.'), 'hr_record'); ?>
  <?php $card('/hr-requests', t('My HR requests'), t('Ask for a letter, a document, or an answer about your pay or schedule.'), 'requests'); ?>
  <?php $card('/expenses', t('My reimbursements'), t('Claim what you spent for the job.'), 'expenses'); ?>
  <?php $card('/notifications', t('Notifications'), $home['unread'] ? t(':n unread', ['n' => $home['unread']]) : t('Nothing unread.'), 'notifications'); ?>
</div>
