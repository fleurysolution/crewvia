<?php
require_login();$own=is_worker_account();if(!$own)require_role('recruiter');$jobId=(int)(current_job()['id']??0);
$applicationId=(int)($_GET['application_id']??$_POST['application_id']??0);
$application=$own?row('SELECT a.*,v.job_id,v.title,c.full_name FROM applications a JOIN vacancies v ON v.id=a.vacancy_id JOIN candidates c ON c.id=a.candidate_id JOIN worker_accounts w ON w.candidate_id=c.id WHERE a.id=? AND w.user_id=?',[$applicationId,uid()]):row('SELECT a.*,v.job_id,v.title,c.full_name FROM applications a JOIN vacancies v ON v.id=a.vacancy_id JOIN candidates c ON c.id=a.candidate_id WHERE a.id=? AND v.job_id=?',[$applicationId,$jobId]);
if($applicationId&&!$application){refuse(404, t('Application unavailable in this project.'));}
if($application)$jobId=(int)$application['job_id'];
if($_SERVER['REQUEST_METHOD']==='POST') {
    $do=(string)($_POST['do']??'');
    if($do==='question') {
        require_role('recruiter');$question=trim((string)($_POST['question']??''));
        if(!$jobId||!$question||mb_strlen($question)>500){refuse(422, t('Enter a screening question for the selected project.'));}
        q('INSERT INTO screening_questions(job_id,question,required) VALUES (?,?,?)',[$jobId,$question,isset($_POST['required'])?1:0]);
    }
    if($do==='answers'&&$application) {
        db()->beginTransaction();
        foreach(rows('SELECT id,required FROM screening_questions WHERE job_id=?',[$jobId]) as $q) {
            $answer=trim((string)($_POST['answer'][$q['id']]??''));
            if(mb_strlen($answer)>4000){db()->rollBack();refuse(422, t('Screening answer is too long.'));}
            q('INSERT INTO screening_answers(application_id,question_id,answer,recorded_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE answer=VALUES(answer),recorded_by=VALUES(recorded_by)',[$applicationId,$q['id'],$answer,uid()]);
        }
        q('INSERT INTO application_events(application_id,user_id,stage,note) VALUES (?,?,?,?)',[$applicationId,uid(),$application['stage'],'Screening questionnaire updated']);db()->commit();
    }
    if($do==='contact') {
        require_role('recruiter');$kind=(string)($_POST['kind']??'');$outcome=trim((string)($_POST['outcome']??''));$note=trim((string)($_POST['note']??''));$follow=(string)($_POST['follow_up_at']??'');
        if(!$application||!in_array($kind,['call','email','interview','note'],true)||!$outcome||mb_strlen($outcome)>190||mb_strlen($note)>4000||($follow&&!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$follow))){refuse(422, t('Valid contact details and outcome required.'));}
        q('INSERT INTO recruiting_contacts(application_id,user_id,kind,outcome,note,follow_up_at) VALUES (?,?,?,?,?,?)',[$applicationId,uid(),$kind,$outcome,$note,$follow?str_replace('T',' ',$follow).':00':null]);
    }
    log_activity('screening workflow update','application',$applicationId,$do);redirect('/screening-workflow'.($applicationId?'?application_id='.$applicationId:''));
}
$questions=rows('SELECT * FROM screening_questions WHERE job_id=? ORDER BY id',[$jobId]);
$answers=$application?array_column(rows('SELECT question_id,answer FROM screening_answers WHERE application_id=?',[$applicationId]),'answer','question_id'):[];
$contacts=$application&&!$own?rows('SELECT c.*,u.name FROM recruiting_contacts c JOIN users u ON u.id=c.user_id WHERE c.application_id=? ORDER BY c.id DESC LIMIT 100',[$applicationId]):[];
$applications=$own?rows('SELECT a.id,v.title FROM applications a JOIN vacancies v ON v.id=a.vacancy_id JOIN worker_accounts w ON w.candidate_id=a.candidate_id WHERE w.user_id=?',[uid()]):rows('SELECT a.id,CONCAT(c.full_name,\' · \',v.title) title FROM applications a JOIN vacancies v ON v.id=a.vacancy_id JOIN candidates c ON c.id=a.candidate_id WHERE v.job_id=? ORDER BY a.id DESC LIMIT 300',[$jobId]);
render('screening-workflow',compact('own','application','applicationId','applications','questions','answers','contacts'));
