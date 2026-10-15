<?php
require_login();$own=is_worker_account();
// P5-M44: a supervisor reviewing or interviewing an applicant opens that applicant's documents, and only those.
if(!$own&&!can('recruiter')&&isset($_GET['download'])&&can('supervisor')){require_once __DIR__.'/../reviews.php';$rvDoc=row('SELECT candidate_id FROM candidate_resumes WHERE id=?',[(int)$_GET['download']]);if(!$rvDoc||!review_can_see_candidate(uid(),(int)$rvDoc['candidate_id'])){refuse(404,t('Document unavailable.'));}}
elseif(!$own)require_role('recruiter');
$cid=$own?(int)val('SELECT candidate_id FROM worker_accounts WHERE user_id=?',[uid()]):(int)($_GET['candidate_id']??0);
// P5-M37: the office attaches what the candidate sent elsewhere (a job board, an email): a CV, a cover letter, another document.
if(!$own && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='attach') {
    require_once __DIR__.'/../intake.php';
    $target=(int)($_POST['candidate_id']??0);$kind=isset(candidate_document_kinds()[$_POST['kind']??''])?$_POST['kind']:'cv';
    $back=(string)($_POST['back']??'');$back=preg_match('#^/hiring\?v=\d+&app=\d+$#',$back)?$back:'/candidates/'.$target;
    if(!val('SELECT id FROM candidates WHERE id=?',[$target])){refuse(404,t('That person does not exist.'),'/candidates');}
    try{$file=intake_resume_prepare();}catch(RuntimeException $e){refuse(422,$e->getMessage(),$back);}
    if(!$file){refuse(422,t('Choose a PDF or DOCX file up to 5 MB.'),$back);}
    candidate_document_file($target,$kind,$file,uid());
    log_activity('attached a candidate document','candidate',$target,$kind);
    flash(t('Attached. It is on their file, encrypted.'));redirect($back);
}
// P5-M38: a job board's CVs, many at once, onto the applicants of one requisition.
// Each file is matched to an applicant by the name in its file name ("ResumeMarcsonDurolin.pdf").
// A file that matches nobody, or more than one person, is reported and attached to nobody.
if(!$own && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='attach_bulk') {
    require_once __DIR__.'/../intake.php';
    $vacancyId=(int)($_POST['vacancy_id']??0);
    if(!val('SELECT id FROM vacancies WHERE id=?',[$vacancyId])){refuse(404,t('That requisition does not exist.'),'/resumes');}
    $key=static fn(string $s):string=>strtolower(preg_replace('/[^A-Za-z]/','',iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s)?:$s));
    $people=[];
    foreach(rows('SELECT c.id,c.full_name FROM applications a JOIN candidates c ON c.id=a.candidate_id WHERE a.vacancy_id=?',[$vacancyId]) as $p){$people[$key((string)$p['full_name'])][]=(int)$p['id'];}
    $files=$_FILES['resumes']??null;$done=0;$skipped=[];
    $count=is_array($files['name']??null)?count($files['name']):0;
    for($i=0;$i<$count;$i++){
        $name=(string)$files['name'][$i];
        $original=$name;
        // P5-M39: "Attachment__FirstLast__original.pdf" is another document the applicant sent, kept under its own name.
        if(preg_match('/^Attachment__(.+?)__(.+)$/',$name,$am)){$kind='other';$who=$key($am[1]);$original=$am[2];}
        else{
            $stem=preg_replace('/\.(pdf|docx|txt)$/i','',$name);
            $kind=preg_match('/^cover\s*letter/i',$stem)?'cover_letter':'cv';
            $who=$key(preg_replace('/^(resume|cv|cover\s*letter)[\s_-]*/i','',$stem));
        }
        $match=$people[$who]??[];
        if(count($match)!==1){$skipped[]=$name.' ('.(count($match)?t('more than one applicant has this name'):t('no applicant with this name')).')';continue;}
        // P5-M39: a cover letter typed on the job board arrives as text; it goes on the application, where the recruiter reads it.
        if($kind==='cover_letter'&&preg_match('/\.txt$/i',$name)){
            $text=trim((string)@file_get_contents($files['tmp_name'][$i],false,null,0,20000));
            if($text===''||!mb_check_encoding($text,'UTF-8')){$skipped[]=$name.' ('.t('the text could not be read').')';continue;}
            $appId=(int)val('SELECT id FROM applications WHERE candidate_id=? AND vacancy_id=? ORDER BY id DESC LIMIT 1',[$match[0],$vacancyId]);
            $note='Cover letter (from the job board):'."
".$text;
            if(val('SELECT id FROM application_events WHERE application_id=? AND note=?',[$appId,$note])){$skipped[]=$name.' ('.t('already on file').')';continue;}
            q('INSERT INTO application_events(application_id,user_id,stage,note) VALUES (?,?,(SELECT stage FROM applications WHERE id=?),?)',[$appId,uid(),$appId,$note]);
            q('INSERT INTO candidate_events(candidate_id,user_id,event_type,detail) VALUES (?,?,?,?)',[$match[0],uid(),'cover letter',mb_substr($text,0,250)]);
            $done++;continue;
        }
        $existing=(int)val('SELECT COUNT(*) FROM candidate_resumes WHERE candidate_id=? AND kind=? AND original_name=?',[$match[0],$kind,mb_substr(basename($original),0,190)]);
        if($existing){$skipped[]=$name.' ('.t('already on file').')';continue;}
        $_FILES['resume']=['name'=>$name,'type'=>$files['type'][$i],'tmp_name'=>$files['tmp_name'][$i],'error'=>$files['error'][$i],'size'=>$files['size'][$i]];
        try{$file=intake_resume_prepare();}catch(RuntimeException $e){$skipped[]=$name.' ('.$e->getMessage().')';continue;}
        if(!$file){$skipped[]=$name;continue;}
        $file['original_name']=mb_substr(basename($original),0,190);
        q('INSERT INTO candidate_resumes(candidate_id,kind,storage_name,file_hash,extension,original_name,attached_by) VALUES (?,?,?,?,?,?,?)',[$match[0],$kind,$file['storage_name'],$file['file_hash'],$file['extension'],$file['original_name'],uid()]);
        q('INSERT INTO candidate_events(candidate_id,user_id,event_type,detail) VALUES (?,?,?,?)',[$match[0],uid(),'document attached',['cv'=>'CV','cover_letter'=>'Cover letter','other'=>'Document'][$kind].': '.$file['original_name']]);
        $done++;
    }
    log_activity('attached candidate documents in bulk','vacancy',$vacancyId,$done.' attached, '.count($skipped).' not');
    flash(tn($done,':n document attached.',':n documents attached.').($skipped?' '.t('Not attached:').' '.implode('; ',array_slice($skipped,0,30)):''),$skipped?'err':'ok');
    redirect('/resumes?v='.$vacancyId);
}
if(isset($_GET['download'])) {
    $doc=row('SELECT * FROM candidate_resumes WHERE id=?',[(int)$_GET['download']]);
    if(!$doc||($own&&(int)$doc['candidate_id']!==$cid)){refuse(404, t('Document unavailable.'));}
    $key=base64_decode($config['encryption_key']??'',true);if(!$key||strlen($key)!==32){refuse(503, t('Document encryption key is unavailable.'));}
    $path=workforce_storage_path($config).'/resumes/'.$doc['storage_name'];
    if(!is_file($path)){refuse(404, t('Document unavailable.'));}
    $raw=file_get_contents($path);require_once __DIR__.'/../crypto.php';$plain=$raw===false?null:crypto_open($raw);
    if($plain===null||!hash_equals($doc['file_hash'],hash('sha256',$plain))){refuse(500, t('Document integrity check failed.'));}
    // P5-M50: a PDF is read in the browser (View); a Word file, or Download, saves it. The name the office sees is the file's own.
    $inline=isset($_GET['view'])&&$doc['extension']==='pdf';
    $fname=preg_replace('/[^A-Za-z0-9._ -]/','_',(string)$doc['original_name'])?:('document-'.(int)$doc['id'].'.'.$doc['extension']);
    log_activity($inline?'viewed a candidate document':'downloaded a CV','resume',(int)$doc['id']);
    header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');
    if($inline){header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="'.$fname.'"');}
    else{header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="'.$fname.'"');}
    echo $plain;exit;
}
// P5-M42: the office's list finds a person's documents by name, kind or requisition, newest first.
$docQ=trim((string)($_GET['q']??''));$docKind=(string)($_GET['kind']??'');$docVacancy=(int)($_GET['v']??0);
if($cid){$resumes=rows('SELECT r.*,c.full_name FROM candidate_resumes r JOIN candidates c ON c.id=r.candidate_id WHERE r.candidate_id=? ORDER BY r.id DESC',[$cid]);$docTotal=count($resumes);}
elseif(!$own){
    require_once __DIR__.'/../intake.php';
    $w=['1'];$a=[];
    if($docQ!==''){$w[]='(c.full_name LIKE ? OR r.original_name LIKE ?)';$a[]='%'.$docQ.'%';$a[]='%'.$docQ.'%';}
    if(isset(candidate_document_kinds()[$docKind])){$w[]='r.kind=?';$a[]=$docKind;}
    if($docVacancy){$w[]='EXISTS (SELECT 1 FROM applications x WHERE x.candidate_id=r.candidate_id AND x.vacancy_id=?)';$a[]=$docVacancy;}
    $docTotal=(int)val('SELECT COUNT(*) FROM candidate_resumes r JOIN candidates c ON c.id=r.candidate_id WHERE '.implode(' AND ',$w),$a);
    $resumes=rows('SELECT r.*,c.full_name,(SELECT a.id FROM applications a WHERE a.candidate_id=r.candidate_id ORDER BY a.id DESC LIMIT 1) app_id,(SELECT a.vacancy_id FROM applications a WHERE a.candidate_id=r.candidate_id ORDER BY a.id DESC LIMIT 1) app_vacancy FROM candidate_resumes r JOIN candidates c ON c.id=r.candidate_id WHERE '.implode(' AND ',$w).' ORDER BY r.id DESC LIMIT 200',$a);
}else{$resumes=[];$docTotal=0;}
$bulkVacancies=$own?[]:rows("SELECT v.id,v.title,j.title project,(SELECT COUNT(*) FROM applications a WHERE a.vacancy_id=v.id) applicants FROM vacancies v JOIN jobs j ON j.id=v.job_id WHERE v.is_open=1 ORDER BY j.title,v.title");
$bulkVacancy=(int)($_GET['v']??0);
render('resumes',compact('resumes','bulkVacancies','bulkVacancy','docQ','docKind','docVacancy','docTotal','own'));
