<?php
function contract_event(int $id,string $action,array $details=[]): void {q('INSERT INTO contract_events(contract_id,user_id,action,details_json) VALUES (?,?,?,?)',[$id,uid()?:null,$action,json_encode($details,JSON_THROW_ON_ERROR)]);}
function contract_document_gaps(int $candidate,int $job): array {
 $lock=db()->inTransaction()?' FOR UPDATE':'';$requirements=rows('SELECT document_type FROM contract_document_requirements WHERE job_id=?'.$lock,[$job]);$gaps=[];
 foreach($requirements as $requirement){$proof=row('SELECT id,status FROM worker_documents WHERE candidate_id=? AND document_type=? ORDER BY id DESC LIMIT 1'.$lock,[$candidate,$requirement['document_type']]);if(!$proof||$proof['status']!=='approved')$gaps[]=$requirement;}return $gaps;
}
function contract_screening_ready(int $application,int $job): bool {
 return !(int)val("SELECT COUNT(*) FROM screening_checks WHERE application_id=? AND status<>'passed'",[$application]) && !(int)val("SELECT COUNT(*) FROM screening_questions q LEFT JOIN screening_answers a ON a.question_id=q.id AND a.application_id=? WHERE q.job_id=? AND q.required=1 AND (a.answer IS NULL OR TRIM(a.answer)='')",[$application,$job]);
}
function contract_latest(int $application): ?array {return row('SELECT * FROM employment_contracts WHERE application_id=? ORDER BY revision DESC LIMIT 1',[$application]);}
function contract_deployment_pending(int $candidate,int $job): bool {
 if(!val('SELECT required_before_deployment FROM contract_policies WHERE job_id=?',[$job]))return false;
 $contracts=rows('SELECT c.* FROM employment_contracts c JOIN applications a ON a.id=c.application_id JOIN vacancies v ON v.id=a.vacancy_id WHERE a.candidate_id=? AND v.job_id=? AND c.revision=(SELECT MAX(n.revision) FROM employment_contracts n WHERE n.application_id=c.application_id)',[$candidate,$job]);
 if(!$contracts)return true;foreach($contracts as $c)if($c['status']!=='signed')return true;return false;
}
function contract_issue_application(array $contract): void {
 $a=row('SELECT stage FROM applications WHERE id=?',[$contract['application_id']]);if(!$a||in_array($a['stage'],['accepted','rejected','withdrawn'],true))throw new RuntimeException('Contract cannot be signed in its current state.');
 q("UPDATE applications SET stage='offered' WHERE id=? AND stage NOT IN ('accepted','rejected','withdrawn')",[$contract['application_id']]);
 q("INSERT INTO application_events(application_id,user_id,stage,note) VALUES (?,?,'offered',?)",[$contract['application_id'],uid()?:$contract['created_by'],$contract['terms']]);
}
function contract_accept(array $c,array $application,int $user,string $name): void {
 if($application['stage']!=='offered')throw new RuntimeException('Contract cannot be signed in its current state.');
 if(row('SELECT application_id FROM offer_acceptances WHERE application_id=?',[$application['id']]))throw new RuntimeException('Offer already accepted.');
 q("UPDATE employment_contracts SET status='signed',signed_at=NOW(),signer_user_id=?,signer_name=? WHERE id=?",[$user,$name,$c['id']]);
 q("UPDATE applications SET stage='accepted' WHERE id=?",[$application['id']]);
 q('INSERT INTO offer_acceptances(application_id,user_id,offer_text,offer_hash,signer_name) VALUES (?,?,?,?,?)',[$application['id'],$user,$c['terms'],$c['content_hash'],$name]);
 q("INSERT INTO application_events(application_id,user_id,stage,note) VALUES (?,?,'accepted',?)",[$application['id'],$user,'Contract signed: '.$c['content_hash']]);
 $assignment=row("SELECT id FROM placements WHERE candidate_id=? AND job_id=? AND status NOT IN ('completed','cancelled')",[$application['candidate_id'],$application['job_id']]);
 if(!$assignment){require_once __DIR__.'/placement-rates.php';$rates=placement_rates((int)$application['job_id'],application_vacancy((int)$application['id']));q("INSERT INTO placements(candidate_id,job_id,vacancy_id,order_line_id,status,created_by,pay_rate,bill_rate,per_diem_rate,guarantee_hours) VALUES (?,?,?,?,'offered',?,?,?,?,?)",[$application['candidate_id'],$application['job_id'],$rates['vacancy_id'],$rates['order_line_id'],$user,$rates['pay_rate'],$rates['bill_rate'],$rates['per_diem_rate'],$rates['guarantee_hours']]);$assignment=['id'=>(int)db()->lastInsertId()];}
 q('INSERT IGNORE INTO onboarding_tasks(placement_id,requirement_id) SELECT ?,id FROM onboarding_requirements WHERE job_id=?',[$assignment['id'],$application['job_id']]);
 contract_event((int)$c['id'],'signed',['method'=>$c['method'],'content_hash'=>$c['content_hash'],'signer'=>$name]);
 q("INSERT INTO notifications(user_id,message,target) SELECT id,?,? FROM users WHERE role IN ('admin','recruiter') AND is_active=1",['Contract signed; onboarding started','/onboarding']);
}
function contract_store_pdf(int $id,string $kind,string $bytes): void {
 global $config;$key=base64_decode($config['encryption_key']??'',true);if(!$key||strlen($key)!==32)throw new RuntimeException('Secure contract storage unavailable.');
 if(strlen($bytes)>15*1024*1024||!str_starts_with($bytes,'%PDF-'))throw new RuntimeException('A PDF up to 15 MB is required.');
 $directory=workforce_storage_path($config).'/contracts';if(!is_dir($directory))mkdir($directory,0700,true);
 $iv=random_bytes(12);$tag='';$data=openssl_encrypt($bytes,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);if($data===false)throw new RuntimeException('Encryption failed.');
 $name=bin2hex(random_bytes(32));if(file_put_contents($directory.'/'.$name,$iv.$tag.$data,LOCK_EX)===false)throw new RuntimeException('Contract storage unavailable.');
 try{q('INSERT INTO contract_files(contract_id,kind,storage_name,file_hash) VALUES (?,?,?,?)',[$id,$kind,$name,hash('sha256',$bytes)]);}catch(Throwable $e){unlink($directory.'/'.$name);throw $e;}
}
function contract_read_pdf(array $file): string {
 global $config;$key=base64_decode($config['encryption_key']??'',true);$bytes=file_get_contents(workforce_storage_path($config).'/contracts/'.$file['storage_name']);
 $plain=openssl_decrypt(substr($bytes,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($bytes,0,12),substr($bytes,12,16));
 if($plain===false||!hash_equals($file['file_hash'],hash('sha256',$plain)))throw new RuntimeException('Document integrity check failed.');return $plain;
}
