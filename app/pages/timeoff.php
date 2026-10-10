<?php
require_login();require_once __DIR__.'/../hr.php';
$isWorker=is_worker_account();$jobId=(int)(current_job()['id'] ?? 0);
if($_SERVER['REQUEST_METHOD']==='POST') {
 $do=$_POST['do'] ?? '';
 if($do==='request') {
  $pid=(int)($_POST['placement_id'] ?? 0);$from=(string)($_POST['starts_on'] ?? '');$to=(string)($_POST['ends_on'] ?? '');
  $own=row("SELECT p.id FROM placements p JOIN worker_accounts w ON w.candidate_id=p.candidate_id WHERE w.user_id=? AND p.id=? AND p.status NOT IN ('completed','cancelled')",[uid(),$pid]);
  if(!$own || !valid_date($from) || !valid_date($to) || $to<$from) { refuse(422, t('Invalid assignment or dates.')); }
  // A kind of leave from the catalogue, not whatever somebody typed.
  // The label goes into request_type beside it so everything already
  // reading that column keeps working.
  $slug = (string) ($_POST['leave_type'] ?? '');
  $kinds = leave_types();

  if (! array_key_exists($slug, $kinds)) {
   flash(t('Choose what kind of time off this is.'), 'err');
   redirect('/timeoff');
  }

  // Refused before it is asked for rather than after it is approved:
  // an allowance that is only checked at review has already been spent
  // in somebody's plans.
  $candidate = (int) val('SELECT candidate_id FROM placements WHERE id = ?', [$pid]);

  // Some kinds of leave are not for everybody, or not yet.
  if (($why = leave_refusal_for($candidate, $slug, $from)) !== null) {
   refuse(422, $why);
  }

  $balance = leave_balance($candidate, $slug, (int) date('Y', strtotime($from)));
  $asking = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;

  if ($balance['left'] !== null && $asking > $balance['left']) {
   refuse(422, t('That is :asked days and only :left of the :kind allowance is left this year.',
           ['asked' => $asking, 'left' => leave_days_text($balance['left']),
            'kind' => t((string) $kinds[$slug]['label'])]));
  }

  q('INSERT INTO time_off_requests(placement_id,user_id,starts_on,ends_on,request_type,leave_type,reason) VALUES (?,?,?,?,?,?,?)',
    [$pid,uid(),$from,$to,mb_substr((string)$kinds[$slug]['label'],0,120),$slug,trim((string)($_POST['reason'] ?? ''))]);
  q("INSERT INTO notifications(user_id,message,target) SELECT id,'Time-off request awaiting review','/timeoff' FROM users WHERE role='admin' AND is_active=1");
 }
 if($do==='review') {
  require_role('recruiter','supervisor');$status=$_POST['status'] ?? ''; $id=(int)($_POST['request_id'] ?? 0);
  if(in_array($status,['approved','rejected'],true)) {
   $r=user()['role']==='supervisor' ? row("SELECT r.* FROM time_off_requests r JOIN assignment_details d ON d.placement_id=r.placement_id WHERE r.id=? AND d.supervisor_id=? AND r.status='pending'",[$id,uid()]) : row("SELECT r.* FROM time_off_requests r JOIN placements p ON p.id=r.placement_id WHERE r.id=? AND p.job_id=? AND r.status='pending'",[$id,$jobId]);
   if($r && $r['user_id']!==uid() && $status==='approved' && $r['leave_type']) {
    // Checked again now: the balance can have moved since it was asked
    // for - another request approved, an allowance changed.
    $who=(int)val('SELECT candidate_id FROM placements WHERE id=?',[$r['placement_id']]);
    if(($why=leave_refusal_for($who,(string)$r['leave_type'],(string)$r['starts_on']))!==null) refuse(422,$why);
    $left=leave_balance($who,(string)$r['leave_type'],(int)date('Y',strtotime((string)$r['starts_on'])),(int)$r['id'])['left'];
    $asking=(int)((strtotime((string)$r['ends_on'])-strtotime((string)$r['starts_on']))/86400)+1;
    if($left!==null && $asking>$left) refuse(422,t('Approving this would go over the allowance: :asked days asked, :left left.',['asked'=>$asking,'left'=>leave_days_text($left)]));
   }
   if($r && $r['user_id']!==uid()) {
    q("UPDATE time_off_requests SET status=?,reviewed_by=?,reviewed_at=NOW(),review_note=? WHERE id=? AND status='pending'",[$status,uid(),trim((string)($_POST['review_note'] ?? '')),$id]);
    q('INSERT INTO notifications(user_id,message,target) VALUES (?,?,?)',[$r['user_id'],'Time-off request '.$status,'/timeoff']);log_activity('reviewed time off','request',$id,$status);
   }
  }
 }
 redirect('/timeoff');
}
if($isWorker && user()['role']!=='supervisor') {
 $requests=rows('SELECT r.*,j.title project FROM time_off_requests r JOIN placements p ON p.id=r.placement_id JOIN jobs j ON j.id=p.job_id WHERE r.user_id=? ORDER BY r.id DESC',[uid()]);
 $assignments=rows("SELECT p.id,j.title FROM placements p JOIN jobs j ON j.id=p.job_id JOIN worker_accounts w ON w.candidate_id=p.candidate_id WHERE w.user_id=? AND p.status NOT IN ('completed','cancelled')",[uid()]);
}else if(user()['role']==='supervisor') { $isWorker=false;$assignments=[];$requests=rows('SELECT r.*,c.full_name,j.title project FROM time_off_requests r JOIN placements p ON p.id=r.placement_id JOIN candidates c ON c.id=p.candidate_id JOIN jobs j ON j.id=p.job_id JOIN assignment_details d ON d.placement_id=p.id WHERE d.supervisor_id=? ORDER BY r.id DESC',[uid()]); }else { require_role('recruiter','supervisor');$requests=rows('SELECT r.*,c.full_name,j.title project FROM time_off_requests r JOIN placements p ON p.id=r.placement_id JOIN candidates c ON c.id=p.candidate_id JOIN jobs j ON j.id=p.job_id WHERE p.job_id=? ORDER BY r.id DESC',[$jobId]);$assignments=[]; }
// What each kind of leave this person has left, so the form can say so
// before they ask rather than after somebody refuses them.
$balances = [];

if ($isWorker) {
 $me = (int) val('SELECT candidate_id FROM worker_accounts WHERE user_id = ?', [uid()]);

 foreach (leave_types() as $slug => $kind) {
  $balances[$slug] = leave_balance($me, $slug) + ['label' => $kind['label'],
                                                  'is_paid' => (int) $kind['is_paid']];
 }
}

render('timeoff',compact('requests','assignments','isWorker','balances'));
