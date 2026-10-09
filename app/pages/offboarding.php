<?php
require_login();$own=is_worker_account();if(!$own)require_role('recruiter');$jobId=(int)(current_job()['id']??0);
if($_SERVER['REQUEST_METHOD']==='POST') {
    $do=(string)($_POST['do']??'');
    if($do==='requirement') {
        require_role('recruiter');$title=trim((string)($_POST['title']??''));$instructions=trim((string)($_POST['instructions']??''));
        if(!$jobId||!$title||mb_strlen($title)>190||mb_strlen($instructions)>4000){refuse(422, t('Valid offboarding title and instructions required.'));}
        q('INSERT INTO offboarding_requirements(job_id,title,instructions) VALUES (?,?,?) ON DUPLICATE KEY UPDATE instructions=VALUES(instructions)',[$jobId,$title,$instructions]);
    }
    if($do==='submit') {
        $pid=(int)($_POST['placement_id']??0);$rid=(int)($_POST['requirement_id']??0);$response=trim((string)($_POST['response']??''));
        $allowed=$own?row('SELECT p.id FROM placements p JOIN worker_accounts w ON w.candidate_id=p.candidate_id JOIN offboarding_requirements r ON r.job_id=p.job_id WHERE p.id=? AND r.id=? AND w.user_id=?',[$pid,$rid,uid()]):row('SELECT p.id FROM placements p JOIN offboarding_requirements r ON r.job_id=p.job_id WHERE p.id=? AND r.id=? AND p.job_id=?',[$pid,$rid,$jobId]);
        if(!$allowed||!$response||mb_strlen($response)>4000){refuse(422, t('Valid assignment and offboarding response required.'));}
        q("INSERT INTO offboarding_tasks(placement_id,requirement_id,status,response,submitted_at) VALUES (?,?,'submitted',?,NOW()) ON DUPLICATE KEY UPDATE response=IF(status='approved',response,VALUES(response)),submitted_at=IF(status='approved',submitted_at,NOW()),status=IF(status='approved',status,'submitted')",[$pid,$rid,$response]);
    }
    if($do==='review') {
        require_role('recruiter');$status=(string)($_POST['status']??'');
        if(!in_array($status,['approved','rejected'],true)){refuse(422, t('Invalid review decision.'));}
        q("UPDATE offboarding_tasks t JOIN placements p ON p.id=t.placement_id SET t.status=?,t.reviewed_by=?,t.reviewed_at=NOW() WHERE t.placement_id=? AND t.requirement_id=? AND p.job_id=? AND t.status='submitted'",[$status,uid(),(int)($_POST['placement_id']??0),(int)($_POST['requirement_id']??0),$jobId]);
    }
    log_activity('offboarding update','project',$jobId,$do);redirect('/offboarding');
}
$page=max(1,min(100000,(int)($_GET['page']??1)));$offset=($page-1)*100;
$requirements=!$own?rows('SELECT * FROM offboarding_requirements WHERE job_id=? ORDER BY id',[$jobId]):[];
$tasks=$own?rows('SELECT p.id placement_id,r.id requirement_id,r.title,r.instructions,j.title project,c.full_name,t.status,t.response FROM placements p JOIN candidates c ON c.id=p.candidate_id JOIN jobs j ON j.id=p.job_id JOIN worker_accounts w ON w.candidate_id=c.id JOIN offboarding_requirements r ON r.job_id=p.job_id LEFT JOIN offboarding_tasks t ON t.placement_id=p.id AND t.requirement_id=r.id WHERE w.user_id=? ORDER BY p.id DESC,r.id',[uid()]):rows('SELECT p.id placement_id,r.id requirement_id,r.title,r.instructions,j.title project,c.full_name,t.status,t.response FROM placements p JOIN candidates c ON c.id=p.candidate_id JOIN jobs j ON j.id=p.job_id JOIN offboarding_requirements r ON r.job_id=p.job_id LEFT JOIN offboarding_tasks t ON t.placement_id=p.id AND t.requirement_id=r.id WHERE p.job_id=? ORDER BY c.full_name,r.id LIMIT 100 OFFSET '.$offset,[$jobId]);
render('offboarding',compact('own','requirements','tasks','page'));
