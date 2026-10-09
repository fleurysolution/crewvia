<?php
/** Public intake never merges an unverified identity and never executes a resume. */
function intake_resume_prepare(): ?array
{
    global $config;$file=$_FILES['resume']??null;
    if(!$file||$file['error']===UPLOAD_ERR_NO_FILE)return null;
    $key=base64_decode($config['encryption_key']??'',true);
    if(!$key||strlen($key)!==32)throw new RuntimeException(t('Secure document storage is not configured.'));
    if($file['error']!==UPLOAD_ERR_OK||$file['size']>5*1024*1024||!is_uploaded_file($file['tmp_name']))throw new RuntimeException(t('Choose a PDF or DOCX resume up to 5 MB.'));
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$extension=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
    if($extension==='pdf'&&$mime==='application/pdf')$kind='pdf';
    elseif($extension==='docx'&&in_array($mime,['application/zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document'],true)&&class_exists('ZipArchive')) {
        $zip=new ZipArchive();if($zip->open($file['tmp_name'])!==true)throw new RuntimeException(t('Invalid resume format.'));
        $safe=$zip->locateName('word/document.xml')!==false;$total=0;
        for($i=0;$i<$zip->numFiles;$i++){$entry=$zip->statIndex($i);$name=strtolower($entry['name']);$total+=$entry['size'];if(str_contains($name,'..')||str_starts_with($name,'/')||str_contains($name,'vbaproject')||str_contains($name,'embeddings/')||preg_match('/\.(exe|dll|js|vbs|bat|cmd|ps1|bin)$/',$name))$safe=false;}
        $zip->close();if(!$safe||$total>30*1024*1024)throw new RuntimeException(t('Invalid resume format.'));$kind='docx';
    } else throw new RuntimeException(t('Choose a PDF or DOCX resume up to 5 MB.'));
    $raw=file_get_contents($file['tmp_name']);$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($raw,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
    if($cipher===false)throw new RuntimeException(t('Encryption failed.'));
    $folder=workforce_storage_path($config).'/resumes';if(!is_dir($folder))mkdir($folder,0700,true);
    $name=bin2hex(random_bytes(32));$path=$folder.'/'.$name;
    if(file_put_contents($path,$iv.$tag.$cipher,LOCK_EX)===false)throw new RuntimeException(t('Document storage unavailable.'));
    return ['path'=>$path,'storage_name'=>$name,'file_hash'=>hash('sha256',$raw),'extension'=>$kind,'original_name'=>mb_substr(basename($file['name']),0,190)];
}
function intake_receive(?int $vacancyId=null): void
{
    require_once __DIR__.'/security.php';
    if(!security_rate_limit('public-application',(string)($_SERVER['REMOTE_ADDR']??''),20,3600)||time()-(int)($_SESSION['last_application']??0)<60){http_response_code(429);exit(t('Please wait before submitting again.'));}
    $name=trim((string)($_POST['name']??''));$email=trim((string)($_POST['email']??''));$phone=trim((string)($_POST['phone']??''));
    if(!$name||mb_strlen($name)>190||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190||strlen($phone)>40||!isset($_POST['consent'])){http_response_code(422);exit(t('Valid contact details and consent are required.'));}
    try{$resume=intake_resume_prepare();}catch(RuntimeException $e){http_response_code(422);exit(e($e->getMessage()));}
    db()->beginTransaction();
    try {
        if(is_worker_account()&&mb_strtolower((string)user()['email'])===mb_strtolower($email))$cid=(int)val('SELECT candidate_id FROM worker_accounts WHERE user_id=?',[uid()]);
        else {q('INSERT INTO candidates(full_name,email,phone,source) VALUES (?,?,?,?)',[$name,$email,$phone,$vacancyId?'Platform application':'Talent pool application']);$cid=(int)db()->lastInsertId();}
        q('INSERT INTO candidate_events(candidate_id,event_type,detail) VALUES (?,?,?)',[$cid,'application received',$vacancyId?'Platform vacancy '.$vacancyId:'Public talent pool; applicant consent recorded']);
        $applicationId = null;

        if ($vacancyId) {
            q('INSERT INTO applications(candidate_id,vacancy_id) VALUES (?,?)', [$cid, $vacancyId]);
            $applicationId = (int) db()->lastInsertId();
        }
        if($resume)q('INSERT INTO candidate_resumes(candidate_id,storage_name,file_hash,extension,original_name) VALUES (?,?,?,?,?)',[$cid,$resume['storage_name'],$resume['file_hash'],$resume['extension'],$resume['original_name']]);
        // The questionnaire, scored as it arrives. A knockout answer
        // flags the application; it never rejects anybody by itself.
        if ($applicationId && ! empty($_POST['screening']) && is_array($_POST['screening'])) {
            require_once __DIR__ . '/screening.php';

            $given = [];

            foreach ($_POST['screening'] as $questionId => $answer) {
                if (is_scalar($answer)) {
                    $given[(int) $questionId] = (string) $answer;
                }
            }

            screening_record_answers($applicationId, $given);
        }

        db()->commit();$_SESSION['last_application']=time();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();if($resume&&is_file($resume['path']))unlink($resume['path']);throw $e;}
    render('application_received');
}
