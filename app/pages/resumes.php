<?php
require_login();$own=is_worker_account();if(!$own)require_role('recruiter');
$cid=$own?(int)val('SELECT candidate_id FROM worker_accounts WHERE user_id=?',[uid()]):(int)($_GET['candidate_id']??0);
// P5-M37: the office attaches what the candidate sent elsewhere (a job board, an email): a CV, a cover letter, another document.
if(!$own && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='attach') {
    require_once __DIR__.'/../intake.php';
    $target=(int)($_POST['candidate_id']??0);$kind=in_array($_POST['kind']??'',['cv','cover_letter','other'],true)?$_POST['kind']:'cv';
    $back=(string)($_POST['back']??'');$back=preg_match('#^/hiring\?v=\d+&app=\d+$#',$back)?$back:'/candidates/'.$target;
    if(!val('SELECT id FROM candidates WHERE id=?',[$target])){refuse(404,t('That person does not exist.'),'/candidates');}
    try{$file=intake_resume_prepare();}catch(RuntimeException $e){refuse(422,$e->getMessage(),$back);}
    if(!$file){refuse(422,t('Choose a PDF or DOCX file up to 5 MB.'),$back);}
    q('INSERT INTO candidate_resumes(candidate_id,kind,storage_name,file_hash,extension,original_name,attached_by) VALUES (?,?,?,?,?,?,?)',[$target,$kind,$file['storage_name'],$file['file_hash'],$file['extension'],$file['original_name'],uid()]);
    q('INSERT INTO candidate_events(candidate_id,user_id,event_type,detail) VALUES (?,?,?,?)',[$target,uid(),'document attached',['cv'=>'CV','cover_letter'=>'Cover letter','other'=>'Document'][$kind].': '.$file['original_name']]);
    log_activity('attached a candidate document','candidate',$target,$kind);
    flash(t('Attached. It is on their file, encrypted.'));redirect($back);
}
if(isset($_GET['download'])) {
    $doc=row('SELECT * FROM candidate_resumes WHERE id=?',[(int)$_GET['download']]);
    if(!$doc||($own&&(int)$doc['candidate_id']!==$cid)){refuse(404, t('Document unavailable.'));}
    $key=base64_decode($config['encryption_key']??'',true);if(!$key||strlen($key)!==32){refuse(503, t('Document encryption key is unavailable.'));}
    $path=workforce_storage_path($config).'/resumes/'.$doc['storage_name'];
    if(!is_file($path)){refuse(404, t('Document unavailable.'));}
    $raw=file_get_contents($path);require_once __DIR__.'/../crypto.php';$plain=$raw===false?null:crypto_open($raw);
    if($plain===null||!hash_equals($doc['file_hash'],hash('sha256',$plain))){refuse(500, t('Document integrity check failed.'));}
    log_activity('downloaded a CV','resume',(int)$doc['id']);header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="resume-'.(int)$doc['id'].'.'.$doc['extension'].'"');echo $plain;exit;
}
$resumes=$cid?rows('SELECT r.*,c.full_name FROM candidate_resumes r JOIN candidates c ON c.id=r.candidate_id WHERE r.candidate_id=? ORDER BY r.id DESC',[$cid]):(!$own?rows('SELECT r.*,c.full_name FROM candidate_resumes r JOIN candidates c ON c.id=r.candidate_id ORDER BY r.id DESC LIMIT 100'):[]);
render('resumes',compact('resumes'));
