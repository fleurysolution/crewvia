<?php
require_login();$own=is_worker_account();if(!$own)require_role('recruiter');
$cid=$own?(int)val('SELECT candidate_id FROM worker_accounts WHERE user_id=?',[uid()]):(int)($_GET['candidate_id']??0);
if(isset($_GET['download'])) {
    $doc=row('SELECT * FROM candidate_resumes WHERE id=?',[(int)$_GET['download']]);
    if(!$doc||($own&&(int)$doc['candidate_id']!==$cid)){refuse(404, t('Document unavailable.'));}
    $key=base64_decode($config['encryption_key']??'',true);if(!$key||strlen($key)!==32){refuse(503, t('Document encryption key is unavailable.'));}
    $path=workforce_storage_path($config).'/resumes/'.$doc['storage_name'];
    if(!is_file($path)){refuse(404, t('Document unavailable.'));}
    $raw=file_get_contents($path);$plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
    if($plain===false||!hash_equals($doc['file_hash'],hash('sha256',$plain))){refuse(500, t('Document integrity check failed.'));}
    header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="resume-'.(int)$doc['id'].'.'.$doc['extension'].'"');echo $plain;exit;
}
$resumes=$cid?rows('SELECT r.*,c.full_name FROM candidate_resumes r JOIN candidates c ON c.id=r.candidate_id WHERE r.candidate_id=? ORDER BY r.id DESC',[$cid]):(!$own?rows('SELECT r.*,c.full_name FROM candidate_resumes r JOIN candidates c ON c.id=r.candidate_id ORDER BY r.id DESC LIMIT 100'):[]);
render('resumes',compact('resumes'));
