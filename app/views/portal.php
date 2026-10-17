<div class="page-head"><div><h1><?= te('Welcome, :name', ['name'=>$worker['full_name']]) ?></h1>
<?php if ($isCandidate): ?>
<p class="sub"><?= te('Your applications with us in one place: what needs you, your offers and your papers. Your employee portal opens when you are hired.') ?></p></div>
<div class="actions"><a class="btn ghost" href="/my-jobs"><?= te('Jobs for you') ?></a></div></div>
<div class="card" id="waiting"><h2><?= te('Waiting on you') ?></h2>
  <?php if (! $extraWaiting): ?><p class="muted" data-waiting="0"><?= te('Nothing is waiting on you.') ?></p><?php endif; ?>
  <?php foreach ($extraWaiting as [$href, $label, $key]): ?><div data-waiting="<?= e($key) ?>"><a href="<?= e($href) ?>"><?= e($label) ?></a></div><?php endforeach; ?>
</div>
<?php else: ?>
<p class="sub"><?= te('Your work with us in one place: what needs you, your assignment, your pay and your papers.') ?></p></div>
<div class="actions"><a class="btn ghost" href="/contracts"><?= te('Contracts & signatures') ?></a><a class="btn ghost" href="/my-payslips"><?= te('My pay statements') ?></a></div></div>

<?php require __DIR__ . '/_self-service-cards.php'; ?>
<?php endif; ?>

<div class="grid g2 mt">
<div class="card" id="jobs-for-you-card"><h2><?= te('Jobs for you') ?></h2><p><?= $jobsForYou ? ten($jobsForYou, ':n open job fits your file.', ':n open jobs fit your file.') : te('No open job fits your file right now.') ?></p><a class="btn<?= $jobsForYou ? '' : ' ghost' ?>" href="/my-jobs"><?= te('See the jobs') ?></a></div>
<?php if (! $isCandidate): ?><div class="card" id="assignments"><h2><?= te('Assignments') ?></h2><?php if (! $assignments): ?><p class="muted small"><?= te('No assignment yet.') ?></p><?php endif; ?><?php foreach($assignments as $a): ?><p><?= te(':project · :status · :date',['project'=>$a['title'],'status'=>t(placement_word((string) $a['status'])),'date'=>d($a['start_date'])]) ?></p><?php endforeach; ?>
  <?php foreach($details as $d): ?><p class="small muted"><?= te(':project · :trade · :shift · supervisor :name',['project'=>$d['title'],'trade'=>$d['trade'],'shift'=>$d['shift_label'],'name'=>$d['supervisor']]) ?></p><?php endforeach; ?></div><?php endif; ?>
</div>

<?php if ($lodging || $travel || $routes): ?>
<div class="grid g3" id="trip">
<div class="card"><h2><?= te('Your accommodation') ?></h2><?php foreach($lodging as $l): ?><p><?= te(':hotel · room :room · :start to :end',['hotel'=>$l['name'],'room'=>$l['room_number'],'start'=>d($l['check_in']),'end'=>d($l['check_out'])]) ?></p><?php endforeach; ?></div>
<div class="card" id="itinerary"><h2><?= te('Your travel') ?></h2><?php foreach($travel as $t): ?><div class="mb-s" data-leg="<?= e($t['direction']) ?>"><?php foreach($t['lines'] as $i => $line): ?><p class="m0<?= $i ? ' small' : '' ?>"><?= $i ? e($line) : '<strong>' . e($line) . '</strong>' ?></p><?php endforeach; ?><?php if (trim((string) $t['ticket_details']) !== ''): ?><details class="mt-xs"><summary class="small"><?= te('Your ticket') ?></summary><pre class="pre-wrap t-font small m0"><?= e($t['ticket_details']) ?></pre></details><?php endif; ?></div><?php endforeach; ?>
  <?php if ($travelAdvice): ?><h3 class="small m0 mt-s"><?= te('Before you travel') ?></h3><ul class="small m0" id="travel-advice"><?php foreach($travelAdvice as $a): ?><li><?= e($a['body']) ?></li><?php endforeach; ?></ul><?php endif; ?></div>
<div class="card"><h2><?= te('Your pickups') ?></h2><?php foreach($routes as $r): ?><p><?= te(':date :time · :stop · driver :name',['date'=>d($r['runs_on']),'time'=>$r['depart_time'],'stop'=>$r['pickup_stop'],'name'=>$r['driver']]) ?></p><?php endforeach; ?></div>
</div>
<?php endif; ?>

<?php foreach ($clock as $k): $ci = $k['today']; ?>
<div class="card" id="clock" data-clock="<?= $k['room'] ? 'room' : ($ci ? e($ci['status']) : 'ready') ?>">
  <h2><?= te('Today on :project', ['project' => $k['project']]) ?></h2>
  <?php if ($k['shift'] !== '' || $k['start']): ?><p class="small muted"><?= e(trim($k['shift'] . ($k['start'] ? ' · ' . t('starts :time', ['time' => date('g:i a', strtotime($k['start']))]) : ''), ' ·')) ?></p><?php endif; ?>
  <?php if ($k['room']): ?>
    <p<?= $k['room']['overdue'] ? ' class="t-red"' : '' ?>><?= $k['room']['overdue'] ? te('Your room number at :hotel was due by :time. Enter it now: you cannot clock in without it.', ['hotel' => $k['room']['hotel'], 'time' => date('D j M, g:i a', strtotime($k['room']['deadline']))]) : te('Enter your room number at :hotel by :time. You cannot clock in without it.', ['hotel' => $k['room']['hotel'], 'time' => date('D j M, g:i a', strtotime($k['room']['deadline']))]) ?></p>
    <form method="post" action="/portal" class="row tight gap-s"><?= csrf_field() ?><input type="hidden" name="do" value="room_number"><input type="hidden" name="placement_id" value="<?= (int) $k['placement_id'] ?>"><label class="sr-only" for="room-<?= (int) $k['placement_id'] ?>"><?= te('Room number') ?></label><input id="room-<?= (int) $k['placement_id'] ?>" name="room_number" maxlength="12" required class="fw-s" placeholder="<?= te('Room') ?>"><button class="btn"><?= te('Save my room number') ?></button></form>
  <?php elseif (! $ci): ?>
    <p><strong><?= te('Scan the code on your supervisor’s phone to clock in.') ?></strong></p>
    <form method="post" action="/portal" data-geo data-wait="<?= te('Finding where you are…') ?>"><?= csrf_field() ?><input type="hidden" name="do" value="clock_in"><input type="hidden" name="placement_id" value="<?= (int) $k['placement_id'] ?>"><input type="hidden" name="lat"><input type="hidden" name="lng"><input type="hidden" name="accuracy"><button class="btn ghost sm"><?= te('Clock in without the code (your supervisor is told)') ?></button></form><script src="/assets/clock.js"></script>
  <?php else: ?>
    <p><?= te('Clocked in at :time.', ['time' => date('g:i a', strtotime($ci['clocked_in_at']))]) ?> <?php if ($ci['status'] === 'waiting'): ?><span class="tag amber"><?= te('Waiting for your supervisor') ?></span><?php elseif ($ci['status'] === 'validated'): ?><span class="tag green"><?= te('Validated') ?></span><?php else: ?><span class="tag red"><?= te('Not seen on site: call your supervisor') ?></span><?php endif; ?></p>
    <?php if (empty($ci['clocked_out_at'])): ?><p class="small"><?= te('To clock out, scan your supervisor’s code again.') ?></p>
    <form method="post" action="/portal" data-geo data-wait="<?= te('Finding where you are…') ?>"><?= csrf_field() ?><input type="hidden" name="do" value="clock_out"><input type="hidden" name="placement_id" value="<?= (int) $k['placement_id'] ?>"><input type="hidden" name="lat"><input type="hidden" name="lng"><input type="hidden" name="accuracy"><button class="btn ghost sm"><?= te('Clock out without the code (your supervisor is told)') ?></button></form><script src="/assets/clock.js"></script>
    <?php else: ?><p data-clocked-out="1"><?= te('Clocked out at :time.', ['time' => date('g:i a', strtotime($ci['clocked_out_at']))]) ?><?= ci_is_hourly((int) $worker['id']) ? ' ' . te(':h hours.', ['h' => ci_hours($ci)]) : '' ?></p><?php endif; ?>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<?php if ($adpShow): ?>
<div class="card" id="adp" data-registered="<?= $adpStatus['adp_registered_at'] ? '1' : '0' ?>">
  <h2><?= te('Register with ADP to be paid') ?></h2>
  <?php if ($adpStatus['adp_registered_at']): ?><p><span class="tag green"><?= te('Done') ?></span> <?= te('You told payroll you registered with ADP on :date.', ['date' => d(substr((string) $adpStatus['adp_registered_at'], 0, 10))]) ?></p><?php endif; ?>
  <ol class="m0">
    <li><?= te('Go to ADP:') ?> <a href="<?= e($adpReg['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($adpReg['url']) ?></a> <?= te('and choose to create an account.') ?></li>
    <li><?= te('Enter the registration code:') ?> <strong class="mono" id="adp-code"><?= e($adpReg['code']) ?></strong></li>
    <li><?= te('ADP asks you to prove who you are.') ?> <?php if ($adpStatus['adp_employee_id']): ?><?= te('Your employee ID is:') ?> <strong class="mono" id="adp-employee-id"><?= e($adpStatus['adp_employee_id']) ?></strong>. <?php else: ?><?= te('Payroll gives you your employee ID; until then, ADP may let you use another way.') ?> <?php endif; ?><?= te('ADP may also ask for your Social Security number or ITIN and your date of birth: enter those on ADP\'s site only.') ?></li>
    <li><?= te('Choose how you are paid there (direct deposit or a pay card).') ?></li>
  </ol>
  <?php if ($adpReg['note'] !== ''): ?><p class="small"><?= e($adpReg['note']) ?></p><?php endif; ?>
  <p class="small muted"><?= te('Crewvia asks for your Social Security number only in your W-4 and I-9, in this portal. Never give it by text, phone or email, to anyone.') ?></p>
  <?php if (! $adpStatus['adp_registered_at']): ?><form method="post" action="/portal"><?= csrf_field() ?><input type="hidden" name="do" value="adp_registered"><button class="btn"><?= te('I have registered with ADP') ?></button></form><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($tripAhead || $travelProfile): $tp = $travelProfile; ?>
<form method="post" action="/portal" class="card" id="travel-details" data-entered="<?= $tp ? '1' : '0' ?>"><?= csrf_field() ?><input type="hidden" name="do" value="travel_profile">
  <h2><?= te('Your travel details') ?></h2>
  <p class="small muted"><?= te('The travel coordinator books your flight and hotel from these. Your address is kept encrypted. Never enter your Social Security number here.') ?></p>
  <div class="row">
    <div class="f2"><label for="tp-name"><?= te('Your name exactly as on your ID') ?></label><input id="tp-name" name="legal_name" maxlength="190" required value="<?= e($tp['legal_name'] ?? $worker['full_name']) ?>"></div>
    <div><label for="tp-airport"><?= te('The airport you fly from') ?></label><input id="tp-airport" name="airport" maxlength="120" required value="<?= e($tp['airport'] ?? '') ?>" placeholder="<?= te('e.g. IAH or Houston Bush') ?>"></div>
  </div>
  <label for="tp-address"><?= te('Your home address') ?></label><textarea id="tp-address" name="address" rows="2" maxlength="300" required placeholder="<?= te('Street, city, state, ZIP code') ?>"><?= e($tp['address'] ?? '') ?></textarea>
  <label for="tp-notes"><?= te('Anything the coordinator should know (optional)') ?></label><input id="tp-notes" name="travel_notes" maxlength="500" value="<?= e($tp['notes'] ?? '') ?>" placeholder="<?= te('e.g. I can only fly after 10 am') ?>">
  <button class="btn mt-s"><?= $tp ? te('Save the changes') : te('Send my travel details') ?></button>
</form>
<?php endif; ?>

<?php if ($hotelCharges): ?><div class="card" id="hotel-charges"><h2><?= te('Hotel charges to you') ?></h2><p class="small muted"><?= te('Charges the hotel billed for your room that the agency asks you to pay. Payroll recovers them with an adjustment they approve; ask payroll if one is wrong.') ?></p><table><?php foreach ($hotelCharges as $hc): ?><tr><td><?= e(d((string) $hc['week_ending'])) ?></td><td><?= e($hc['hotel']) ?></td><td><?= e($hc['description']) ?><?= $hc['accepted_note'] ? '<div class="small muted">' . e($hc['accepted_note']) . '</div>' : '' ?></td><td class="num"><?= e(money($hc['amount'])) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>

<?php if ($tasks): ?>
<div class="card" id="onboarding"><h2><?= te('Onboarding tasks') ?></h2><p class="muted small"><?= te('Your Social Security number and bank numbers go only in the forms of these tasks: masked as you type them, encrypted, seen only by payroll and the office. Never send them by text or email.') ?></p>
<?php $taskWord=['pending'=>t('To do'),'submitted'=>t('Sent, waiting to be checked'),'approved'=>t('Done'),'rejected'=>t('Sent back to you')];$formWord=['draft'=>t('Filled in, to sign at the office'),'submitted'=>t('Waiting to be checked'),'accepted'=>t('Accepted'),'returned'=>t('Sent back to you'),'replaced'=>t('Replaced by a newer one')];
foreach($tasks as $t): $kind=(string)($t['form_kind']??'');$open=in_array($t['status'],['pending','rejected'],true);$tid=(int)$t['id']; ?>
<div class="card" id="task-<?= $tid ?>" data-task="<?= $tid ?>" data-form-kind="<?= e($kind) ?>"><h3><?= e($t['project'].' · '.$t['title']) ?></h3><p><?= e($t['instructions']) ?></p><span class="tag <?= $t['status']==='approved'?'green':($t['status']==='rejected'?'red':($t['status']==='submitted'?'amber':'')) ?>"><?= e($taskWord[$t['status']]??$t['status']) ?></span>
<?php if($kind==='w4'||$kind==='i9'): $fm=$taskForms[$kind]; ?>
  <?php if($fm&&$fm['status']==='draft'): ?><p class="small" data-draft-task="<?= e($kind) ?>"><span class="tag amber"><?= e($formWord['draft']) ?></span> <?= te('At the office, the coordinator gives you a code and you sign on your phone.') ?> · <a href="/tax-forms?fill=<?= e($kind) ?>"><?= te('Change it') ?></a> · <a href="/tax-forms?sign="><?= te('I am at the office: sign') ?></a></p>
  <?php elseif($fm): ?><p class="small"><?= te('Signed :date', ['date'=>d(substr((string)$fm['signed_at'],0,10))]) ?> · <?= e($formWord[$fm['status']]??$fm['status']) ?><?= $fm['review_note']?' · '.e($fm['review_note']):'' ?> · <a href="/tax-forms?id=<?= (int)$fm['id'] ?>"><?= te('See what I signed') ?></a></p><?php endif; ?>
  <?php if($open&&(!$fm||$fm['status']==='returned')): ?><p class="mt-s"><a class="btn" href="/tax-forms?fill=<?= e($kind) ?>" data-fill="<?= e($kind) ?>"><?= $kind==='w4'?te('Fill in my W-4 here'):te('Fill in Section 1 of my I-9 here') ?></a></p><?php endif; ?>
<?php elseif($kind==='bank'): $last=$taskBank['last']; ?>
  <?php if($taskBank['in_use']): ?><p class="small"><?= te('In use: :bank, account ending :last.', ['bank'=>$taskBank['in_use']['bank_label'],'last'=>$taskBank['in_use']['last_four']]) ?></p><?php endif; ?>
  <?php if($last): ?><p class="small"><?= te('Sent :date: :bank, ending :last', ['date'=>d(substr((string)$last['submitted_at'],0,10)),'bank'=>$last['bank_label'],'last'=>$last['last_four']]) ?> · <?= e(['pending'=>t('Waiting to be checked'),'approved'=>t('Accepted'),'rejected'=>t('Refused')][$last['status']]??$last['status']) ?><?= $last['review_note']?' · '.e($last['review_note']):'' ?></p><?php endif; ?>
  <?php if($open&&(!$last||$last['status']!=='pending')): ?>
  <form class="mt-s" method="post" action="/portal" autocomplete="off" enctype="multipart/form-data" data-bank-task="<?= $tid ?>"><?= csrf_field() ?><input type="hidden" name="do" value="task_bank">
    <div class="grid g2">
      <div><label for="tb-holder-<?= $tid ?>"><?= te('Name on the account') ?></label><input id="tb-holder-<?= $tid ?>" name="account_holder" required maxlength="190" value="<?= e($worker['full_name']) ?>"></div>
      <div><label for="tb-bank-<?= $tid ?>"><?= te('Bank') ?></label><input id="tb-bank-<?= $tid ?>" name="bank_name" required maxlength="90"></div>
      <div><label for="tb-routing-<?= $tid ?>"><?= te('Routing number') ?></label><input id="tb-routing-<?= $tid ?>" name="routing_number" required inputmode="numeric" pattern="[0-9 ]{9,11}" autocomplete="off"><p class="small muted m0"><?= te('Nine digits, at the bottom left of a cheque.') ?></p></div>
      <div></div>
      <div><label for="tb-acct-<?= $tid ?>"><?= te('Account number') ?></label><div class="row no-wrap"><input id="tb-acct-<?= $tid ?>" name="account_number" type="text" class="secret" data-lpignore="true" data-1p-ignore required inputmode="numeric" pattern="[0-9 ]{4,24}" autocomplete="off"><button type="button" class="btn ghost sm" data-reveal="tb-acct-<?= $tid ?>" data-show="<?= te('Show') ?>" data-hide="<?= te('Hide') ?>" aria-pressed="false"><?= te('Show') ?></button></div></div>
      <div><label for="tb-acct2-<?= $tid ?>"><?= te('Account number, again') ?></label><input id="tb-acct2-<?= $tid ?>" name="account_number_confirm" type="text" class="secret" data-lpignore="true" data-1p-ignore required inputmode="numeric" pattern="[0-9 ]{4,24}" autocomplete="off" data-secret-pair="tb-acct-<?= $tid ?>"><p id="tb-acct2-<?= $tid ?>-match" class="small" aria-live="polite" data-ok="<?= te('The two numbers match.') ?>" data-no="<?= te('The two numbers differ.') ?>"></p></div>
      <div><label for="tb-proof-<?= $tid ?>"><?= te('Proof: a voided cheque, or the direct deposit form or letter from your bank') ?></label><input id="tb-proof-<?= $tid ?>" name="bank_proof" type="file" data-shrink required accept=".pdf,.jpg,.jpeg,.png,image/*" capture="environment"></div>
    </div>
    <p class="small muted"><?= te('A photo or a PDF where the routing and account numbers can be read. Payroll compares them with what you typed before your pay goes there, so a wrong digit never sends your pay to another account. Everything is encrypted as soon as it is sent; only the last four digits are ever shown.') ?></p>
    <button class="btn"><?= te('Send my bank details') ?></button></form>
  <?php endif; ?>
<?php elseif($open): ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="task_id" value="<?= $tid ?>"><label for="tn-<?= $tid ?>"><?= te('Completion note') ?></label><textarea id="tn-<?= $tid ?>" name="response" maxlength="2000" required></textarea><button class="btn"><?= te('Submit for review') ?></button></form>
<?php endif; ?>
</div><?php endforeach; ?><script src="/assets/masked-fields.js"></script><script src="/assets/shrink-photo.js"></script></div>
<?php endif; ?>

<?php if ($applications): ?>
<div class="card" id="applications"><h2><?= te('Your applications') ?></h2><?php foreach($applications as $a): ?><div class="card"><h3><?= e($a['title'].' · '.$a['project']) ?></h3><p><?= e($a['stage_word'] ?? $a['stage']) ?></p><?php if($a['stage']==='offered'&&!$a['has_contract']&&!$a['contract_required']): $L=$a['letter']; ?>
<article class="offer-letter" id="offer-letter-<?= (int)$a['id'] ?>">
<p class="eyebrow"><?= e($L['agency']) ?> · <?= te('Offer of employment') ?></p>
<p><?= te('Dear :name,', ['name'=>$L['candidate']]) ?></p>
<p><?= te(':agency is pleased to offer you the position of :position at :site, on the terms below.', ['agency'=>$L['agency'],'position'=>$L['position'],'site'=>$L['site']]) ?></p>
<h4><?= te('The terms') ?></h4>
<?php if(!empty($a['terms'])): ?><ul class="steps" id="offer-terms-<?= (int)$a['id'] ?>"><?php foreach($a['terms'] as $term): ?><li><?= e($term) ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php if($L['note']!==''): ?><p class="small mt-s"><strong><?= te('From recruiting:') ?></strong> <?= nl2br(e($L['note'])) ?></p><?php endif; ?>
<?php if($L['description']!==''): ?><h4><?= te('The job') ?></h4><div class="small" id="offer-job-<?= (int)$a['id'] ?>"><?= nl2br(e($L['description'])) ?></div><?php endif; ?>
<?php if($L['conditions']!==''): ?><h4><?= te('General conditions') ?></h4><div class="small" id="offer-conditions-<?= (int)$a['id'] ?>"><?= nl2br(e($L['conditions'])) ?></div><?php endif; ?>
<p class="small muted mt-s"><?= te('Your signature below accepts this offer. The office then reviews it and countersigns it; you are hired once it is countersigned, and you will be told.') ?></p>
<?php if(empty($a['signed']) && $a['others']): ?><p class="small mt-s" id="offer-others-<?= (int)$a['id'] ?>"><strong><?= ten($a['others'], 'Signing this offer withdraws your other application in progress.', 'Signing this offer withdraws your :n other applications in progress.') ?></strong></p><?php endif; ?>
<p class="no-print"><button type="button" class="btn ghost" onclick="document.querySelectorAll('.offer-letter').forEach(x=>x.classList.remove('printing'));this.closest('.offer-letter').classList.add('printing');document.body.classList.add('print-offer');window.print();"><?= te('Print or save as PDF') ?></button></p>
</article>
<?php if(!empty($a['signed'])): ?>
<p class="tag amber" id="offer-signed-<?= (int)$a['id'] ?>"><?= te('Signed by you on :date (:name). The office is reviewing and countersigning it.', ['date'=>d(substr((string)$a['signed']['signed_at'],0,10)),'name'=>$a['signed']['signer_name']]) ?></p>
<?php else: ?>
<form method="post" id="offer-accept-<?= (int)$a['id'] ?>"><?= csrf_field() ?><input type="hidden" name="do" value="accept_offer"><input type="hidden" name="application_id" value="<?= (int)$a['id'] ?>"><label><input class="w-auto" type="checkbox" name="consent" required> <?= te('I accept the offer terms displayed above and intend to sign electronically.') ?></label><label><?= te('Your full name') ?><input name="signer_name" required maxlength="190" autocomplete="name"></label><button class="btn"><?= te('Sign and accept offer') ?></button></form>
<details class="mt-s" id="offer-decline-<?= (int)$a['id'] ?>"><summary class="small"><?= te('Decline this offer') ?></summary>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="decline_offer"><input type="hidden" name="application_id" value="<?= (int)$a['id'] ?>"><label><?= te('Why (optional, the office reads it)') ?><textarea name="reason" rows="2" maxlength="2000"></textarea></label><label><input class="w-auto" type="checkbox" name="confirm" required> <?= te('I decline this offer.') ?></label><div class="row gap-s"><button class="btn ghost"><?= te('Decline the offer') ?></button> <button type="button" class="btn ghost" data-cancel-decline onclick="const d=this.closest('details');d.open=false;d.querySelector('form').reset();"><?= te('Cancel') ?></button></div></form></details>
<?php endif; ?><?php endif; ?></div><?php endforeach; ?></div>
<?php endif; ?>
