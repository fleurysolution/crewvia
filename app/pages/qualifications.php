<?php
require_login();require_once __DIR__.'/../qualifications.php';
$own=is_worker_account();if(!$own)require_role('recruiter');
$jobId=(int)(current_job()['id']??0);
$cid=$own?(int)val('SELECT candidate_id FROM worker_accounts WHERE user_id=?',[uid()]):(int)($_GET['candidate_id']??$_POST['candidate_id']??0);
if($cid&&!row('SELECT id FROM candidates WHERE id=?',[$cid])){refuse(404, t('Candidate unavailable.'));}
if($_SERVER['REQUEST_METHOD']==='POST') {
    $action=(string)($_POST['do']??'');
    if($action==='type') {
        require_role('admin');$name=trim((string)($_POST['name']??''));
        if($name===''||mb_strlen($name)>190){refuse(422, t('Enter a valid qualification name.'));}
        q('INSERT IGNORE INTO qualification_types(name,expires_required) VALUES (?,?)',[$name,isset($_POST['expires_required'])?1:0]);
    }
    if($action==='requirement') {
        require_role('recruiter');$type=(int)($_POST['type_id']??0);$detail=trim((string)($_POST['detail']??''));
        if(!$jobId||!row('SELECT id FROM qualification_types WHERE id=?',[$type])||mb_strlen($detail)>190){refuse(422, t('Choose an existing project and qualification.'));}
        q('INSERT INTO project_qualifications(job_id,type_id,required_detail) VALUES (?,?,?) ON DUPLICATE KEY UPDATE required_detail=VALUES(required_detail)',[$jobId,$type,$detail]);
        $scope=mb_substr(trim((string)($_POST['trade_scope']??'')),0,190);
        if($scope!=='')q('INSERT INTO project_qualification_scope(job_id,type_id,trade) VALUES (?,?,?) ON DUPLICATE KEY UPDATE trade=VALUES(trade)',[$jobId,$type,$scope]);else q('DELETE FROM project_qualification_scope WHERE job_id=? AND type_id=?',[$jobId,$type]);
        log_activity('configured qualification requirement','project',$jobId);
    }
    if($action==='record') {
        $type=(int)($_POST['type_id']??0);$issued=(string)($_POST['issued_on']??'');$expires=(string)($_POST['expires_on']??'');
        $detail=trim((string)($_POST['detail']??''));$evidence=trim((string)($_POST['evidence_reference']??''));
        if(!$cid||!row('SELECT id FROM qualification_types WHERE id=?',[$type])||!$evidence||mb_strlen($evidence)>190||mb_strlen($detail)>190||($issued&&!valid_date($issued))||($expires&&!valid_date($expires))||($issued&&$expires&&$expires<$issued)){refuse(422, t('Valid qualification dates and evidence reference required.'));}
        db()->beginTransaction();
        q('INSERT INTO candidate_qualifications(candidate_id,type_id,detail,issued_on,expires_on,evidence_reference) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE detail=VALUES(detail),issued_on=VALUES(issued_on),expires_on=VALUES(expires_on),evidence_reference=VALUES(evidence_reference),status=\'pending\',reviewed_by=NULL,reviewed_at=NULL',[$cid,$type,$detail,$issued?:null,$expires?:null,$evidence]);
        $qid=(int)val('SELECT id FROM candidate_qualifications WHERE candidate_id=? AND type_id=?',[$cid,$type]);
        q('INSERT INTO qualification_events(qualification_id,user_id,action,note) VALUES (?,?,?,?)',[$qid,uid(),'submitted',json_encode(['detail'=>$detail,'issued_on'=>$issued,'expires_on'=>$expires,'evidence'=>$evidence],JSON_THROW_ON_ERROR)]);db()->commit();
    }
    if($action==='review') {
        require_role('recruiter');$qid=(int)($_POST['qualification_id']??0);$status=(string)($_POST['status']??'');$note=trim((string)($_POST['note']??''));
        if(!in_array($status,['verified','rejected'],true)||!$note||mb_strlen($note)>2000){refuse(422, t('A review decision and evidence note are required.'));}
        db()->beginTransaction();$record=row('SELECT c.*,t.expires_required FROM candidate_qualifications c JOIN qualification_types t ON t.id=c.type_id WHERE c.id=? AND c.candidate_id=? FOR UPDATE',[$qid,$cid]);
        if(!$record||($status==='verified'&&($record['expires_required']&&!$record['expires_on']||$record['expires_on']&&$record['expires_on']<date('Y-m-d')||$record['issued_on']&&$record['issued_on']>date('Y-m-d')))){db()->rollBack();refuse(422, t('An expired or incomplete qualification cannot be verified.'));}
        q('UPDATE candidate_qualifications SET status=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?',[$status,uid(),$qid]);
        q('INSERT INTO qualification_events(qualification_id,user_id,action,note) VALUES (?,?,?,?)',[$qid,uid(),$status,$note]);db()->commit();
    }
    log_activity('qualification update','candidate',$cid,$action);redirect('/qualifications'.($own||!$cid?'':'?candidate_id='.$cid));
}
$types=rows('SELECT * FROM qualification_types ORDER BY name');
$requirements=!$own?rows('SELECT r.*,t.name FROM project_qualifications r JOIN qualification_types t ON t.id=r.type_id WHERE r.job_id=?',[$jobId]):[];
$candidates=!$own?rows('SELECT id,full_name FROM candidates ORDER BY full_name LIMIT 1000'):[];
$records=$cid?rows('SELECT c.*,t.name FROM candidate_qualifications c JOIN qualification_types t ON t.id=c.type_id WHERE c.candidate_id=? ORDER BY c.expires_on,t.name',[$cid]):[];
$events=$cid?rows('SELECT e.*,u.name FROM qualification_events e JOIN candidate_qualifications c ON c.id=e.qualification_id JOIN users u ON u.id=e.user_id WHERE c.candidate_id=? ORDER BY e.id DESC LIMIT 100',[$cid]):[];
$expiring=!$own?rows("SELECT c.id,c.candidate_id,n.full_name,t.name,c.expires_on FROM candidate_qualifications c JOIN candidates n ON n.id=c.candidate_id JOIN qualification_types t ON t.id=c.type_id WHERE c.status='verified' AND c.expires_on<=DATE_ADD(CURDATE(),INTERVAL 30 DAY) ORDER BY c.expires_on LIMIT 100"):[];
render('qualifications',compact('own','cid','jobId','types','requirements','candidates','records','events','expiring'));
