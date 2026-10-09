<?php
require_login();require_once __DIR__.'/../contracts.php';require_once __DIR__.'/../docusign.php';
$isWorker=is_worker_account();if(!$isWorker)require_role('recruiter');$jobId=(int)(current_job()['id']??0);
$candidate=$isWorker?(int)val('SELECT candidate_id FROM worker_accounts WHERE user_id=?',[uid()]):0;
function scoped_contract(int $id,bool $worker,int $candidate,int $job): ?array {
 return row('SELECT c.*,a.candidate_id,v.job_id,u.id worker_user_id,u.name worker_name,u.email worker_email FROM employment_contracts c JOIN applications a ON a.id=c.application_id JOIN vacancies v ON v.id=a.vacancy_id LEFT JOIN worker_accounts w ON w.candidate_id=a.candidate_id LEFT JOIN users u ON u.id=w.user_id WHERE c.id=? AND '.($worker?'a.candidate_id=?':'v.job_id=?'),[$id,$worker?$candidate:$job]);
}
if(isset($_GET['receipt'])){
 $c=scoped_contract((int)$_GET['receipt'],$isWorker,$candidate,$jobId);if(!$c||$c['status']!=='signed'){refuse(404, t('Contract unavailable.'));}
 $files=rows('SELECT kind,file_hash,created_at FROM contract_files WHERE contract_id=?',[$c['id']]);
 $record=['schema'=>'workforce.contract.signature.v1','contract_id'=>$c['id'],'revision'=>$c['revision'],'application_id'=>$c['application_id'],'title'=>$c['title'],'terms'=>$c['terms'],'content_hash'=>$c['content_hash'],'signature_method'=>$c['method'],'signed_at'=>$c['signed_at'],'signer_name'=>$c['signer_name'],'signer_user_id'=>$c['signer_user_id'],'envelope_id'=>$c['envelope_id'],'files'=>$files,'events'=>rows("SELECT action,details_json,created_at FROM contract_events WHERE contract_id=? AND action IN ('electronic_consent','signed','provider_completion_verified') ORDER BY id",[$c['id']])];
 header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="contract-'.$c['id'].'-signature-record.json"');echo json_encode($record,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
}
if(isset($_GET['download'])){
 $file=row('SELECT * FROM contract_files WHERE id=?',[(int)$_GET['download']]);$c=$file?scoped_contract((int)$file['contract_id'],$isWorker,$candidate,$jobId):null;
 if(!$c||($isWorker&&in_array($c['status'],['draft','approved'],true))){refuse(404, t('Contract unavailable.'));}
 header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="contract-'.(int)$c['id'].'-'.$file['kind'].'.pdf"');echo contract_read_pdf($file);exit;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 $do=$_POST['do']??'';$id=(int)($_POST['contract_id']??0);
 try{
 if($do==='policy'){
  require_role('admin');$method=$_POST['method']??'internal';if(!in_array($method,['internal','docusign'],true))throw new RuntimeException('Invalid signature method.');
  db()->beginTransaction();q('SELECT id FROM jobs WHERE id=? FOR UPDATE',[$jobId]);
  q('INSERT INTO contract_policies(job_id,signature_method,required_before_deployment) VALUES (?,?,?) ON DUPLICATE KEY UPDATE signature_method=VALUES(signature_method),required_before_deployment=VALUES(required_before_deployment)',[$jobId,$method,isset($_POST['required'])?1:0]);
  q('DELETE FROM contract_document_requirements WHERE job_id=?',[$jobId]);
  foreach(array_unique(preg_split('/\r?\n/',trim((string)($_POST['document_types']??'')))) as $type){$type=trim($type);if($type){if(mb_strlen($type)>120)throw new RuntimeException('Document type too long.');q('INSERT INTO contract_document_requirements(job_id,document_type) VALUES (?,?)',[$jobId,$type]);}}
  log_activity('updated contract policy','job',$jobId,json_encode(['method'=>$method,'documents'=>$_POST['document_types']??'']));db()->commit();
 }elseif($do==='draft'){
  require_role('recruiter');$aid=(int)($_POST['application_id']??0);$title=trim((string)($_POST['title']??''));$terms=trim((string)($_POST['terms']??''));
  if(!$title||!$terms||mb_strlen($title)>190||strlen($terms)>60000)throw new RuntimeException('Contract title and terms are required.');
  db()->beginTransaction();$a=row("SELECT a.* FROM applications a JOIN vacancies v ON v.id=a.vacancy_id WHERE a.id=? AND v.job_id=? FOR UPDATE",[$aid,$jobId]);
  if(!$a||in_array($a['stage'],['accepted','rejected','withdrawn'],true))throw new RuntimeException('Application is not available for a new contract.');
  if(contract_document_gaps((int)$a['candidate_id'],$jobId))throw new RuntimeException('Approve required documents before preparing the contract.');
  $old=contract_latest($aid);if($old&&!in_array($old['status'],['draft','approved','declined','expired','voided'],true))throw new RuntimeException('Close the active contract before preparing a revision.');
  if($old&&in_array($old['status'],['draft','approved'],true)){q("UPDATE employment_contracts SET status='voided' WHERE id=?",[$old['id']]);contract_event((int)$old['id'],'superseded');}
  $method=val('SELECT signature_method FROM contract_policies WHERE job_id=?',[$jobId])?:'internal';$days=max(1,min(90,(int)($_POST['days']??14)));
  q('INSERT INTO employment_contracts(application_id,revision,title,terms,content_hash,method,expires_at,created_by,signature_page,signature_x,signature_y) VALUES (?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL ? DAY),?,?,?,?)',[$aid,($old['revision']??0)+1,$title,$terms,hash('sha256',$terms),$method,$days,uid(),max(1,min(1000,(int)($_POST['signature_page']??1))),max(0,min(2000,(int)($_POST['signature_x']??40))),max(0,min(2000,(int)($_POST['signature_y']??60)))]);
  $id=(int)db()->lastInsertId();contract_event($id,'draft_created',['revision'=>($old['revision']??0)+1]);db()->commit();
 }else{
  $c=scoped_contract($id,$isWorker,$candidate,$jobId);if(!$c){refuse(404, t('Contract unavailable.'));}
  if($do==='signing_url'){
   if(!$isWorker||$c['method']!=='docusign'||!in_array($c['status'],['issued','viewed'],true)||strtotime($c['expires_at'])<=time())throw new RuntimeException('Contract cannot be signed in its current state.');
   contract_event($id,'provider_signing_opened');redirect(docusign_signing_url($c,['id'=>uid(),'name'=>$c['worker_name'],'email'=>$c['worker_email']]));
  }
  if($do==='send_provider'){
   require_role('recruiter');docusign_settings();if($c['method']!=='docusign'||$c['status']!=='approved'||!$c['worker_user_id'])throw new RuntimeException('Approve the contract and activate the candidate account first.');
   db()->beginTransaction();q('SELECT id FROM jobs WHERE id=? FOR UPDATE',[$jobId]);$fresh=row('SELECT * FROM employment_contracts WHERE id=? FOR UPDATE',[$id]);
   if($fresh['status']!=='approved'||strtotime($fresh['expires_at'])<=time()||contract_document_gaps((int)$c['candidate_id'],$jobId)||!contract_screening_ready((int)$c['application_id'],$jobId))throw new RuntimeException('Complete document and screening reviews before issuing the contract.');
   $transaction=sprintf('%s-%s-%s-%s-%s',bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(6)));
   q("UPDATE employment_contracts SET status='sending',provider_transaction_id=? WHERE id=?",[$transaction,$id]);contract_event($id,'provider_dispatch_started');db()->commit();
   $c['provider_transaction_id']=$transaction;
   try{$envelope=docusign_send($c,['id'=>$c['worker_user_id'],'name'=>$c['worker_name'],'email'=>$c['worker_email']]);q("UPDATE employment_contracts SET status='issued',envelope_id=?,issued_at=NOW() WHERE id=? AND status='sending'",[$envelope,$id]);contract_issue_application($c);contract_event($id,'provider_issued',['envelope_id'=>$envelope]);}catch(Throwable $e){q("UPDATE employment_contracts SET provider_error='Reconciliation required' WHERE id=?",[$id]);throw $e;}
  }elseif($do==='reconcile'){
   require_role('recruiter');if($c['method']!=='docusign'||in_array($c['status'],['draft','approved','signed'],true))throw new RuntimeException('No pending provider contract.');
   docusign_reconcile($c);
  }else{
   db()->beginTransaction();$locked=row('SELECT * FROM employment_contracts WHERE id=? FOR UPDATE',[$id]);$latest=contract_latest((int)$locked['application_id']);
   if((int)$latest['id']!==$id)throw new RuntimeException('A newer contract revision exists.');
   if(!in_array($locked['status'],['signed','voided','declined'],true)&&strtotime($locked['expires_at'])<=time()){
    q("UPDATE employment_contracts SET status='expired' WHERE id=?",[$id]);contract_event($id,'expired');db()->commit();throw new RuntimeException('Contract has expired.');
   }
   if($do==='attach'){
    require_role('recruiter');if($locked['status']!=='draft')throw new RuntimeException('Only draft attachments can be added.');$f=$_FILES['contract_pdf']??null;
    if(!$f||$f['error']!==UPLOAD_ERR_OK||$f['size']>15*1024*1024||(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name'])!=='application/pdf')throw new RuntimeException('A PDF up to 15 MB is required.');
    $bytes=file_get_contents($f['tmp_name']);contract_store_pdf($id,'original',$bytes);$hash=hash('sha256',$locked['terms']."\nPDF-SHA256:".hash('sha256',$bytes));q('UPDATE employment_contracts SET content_hash=? WHERE id=?',[$hash,$id]);contract_event($id,'original_attached',['file_hash'=>hash('sha256',$bytes)]);
   }elseif($do==='approve'){
    require_role('recruiter');if($locked['status']!=='draft')throw new RuntimeException('Only a draft can be approved.');if(contract_document_gaps((int)$c['candidate_id'],$jobId))throw new RuntimeException('Approve required documents before preparing the contract.');
    q("UPDATE employment_contracts SET status='approved',approved_by=? WHERE id=?",[uid(),$id]);contract_event($id,'internally_approved');
   }elseif($do==='issue'){
    require_role('recruiter');q('SELECT id FROM jobs WHERE id=? FOR UPDATE',[$jobId]);
    if($locked['method']!=='internal'||$locked['status']!=='approved'||!$c['worker_user_id'])throw new RuntimeException('Approve the contract and activate the candidate account first.');
    if(contract_document_gaps((int)$c['candidate_id'],$jobId)||!contract_screening_ready((int)$c['application_id'],$jobId))throw new RuntimeException('Complete document and screening reviews before issuing the contract.');
    q("UPDATE employment_contracts SET status='issued',issued_at=NOW() WHERE id=?",[$id]);contract_issue_application($locked);contract_event($id,'issued',['approved_proofs'=>rows("SELECT d.id,d.document_type,d.file_hash FROM worker_documents d JOIN contract_document_requirements r ON r.document_type=d.document_type AND r.job_id=? WHERE d.candidate_id=? AND d.status='approved'",[$jobId,$c['candidate_id']])]);
    q('INSERT INTO notifications(user_id,message,target) VALUES (?,?,?)',[$c['worker_user_id'],'Contract ready for review and signature','/contracts']);
   }elseif(in_array($do,['sign','decline','upload_signed'],true)){
    if(!$isWorker||!in_array($locked['status'],['issued','viewed'],true)||($do==='sign'&&$locked['method']!=='internal'))throw new RuntimeException('Contract cannot be signed in its current state.');
    if($do==='sign'){
     if(contract_document_gaps((int)$c['candidate_id'],(int)$c['job_id'])||!contract_screening_ready((int)$c['application_id'],(int)$c['job_id']))throw new RuntimeException('Complete document and screening reviews before issuing the contract.');
     $name=trim((string)($_POST['signer_name']??''));if(!$name||mb_strlen($name)>190||!isset($_POST['consent'])||!hash_equals($locked['content_hash'],$_POST['content_hash']??''))throw new RuntimeException('Read the current contract and provide your name and consent.');
     $a=row('SELECT a.*,v.job_id FROM applications a JOIN vacancies v ON v.id=a.vacancy_id WHERE a.id=? FOR UPDATE',[$locked['application_id']]);contract_event($id,'electronic_consent',['content_hash'=>$locked['content_hash'],'consent'=>true,'signer_name'=>$name]);contract_accept($locked,$a,uid(),$name);
    }elseif($do==='decline'){q("UPDATE employment_contracts SET status='declined' WHERE id=?",[$id]);contract_event($id,'declined',['reason'=>mb_substr(trim((string)($_POST['reason']??'')),0,2000)]);
    }else{
     if($locked['method']!=='internal')throw new RuntimeException('Use the provider signing flow for this contract.');$f=$_FILES['signed_pdf']??null;
     if(!$f||$f['error']!==UPLOAD_ERR_OK||$f['size']>15*1024*1024)throw new RuntimeException('A PDF up to 15 MB is required.');
     contract_store_pdf($id,'uploaded_signed',file_get_contents($f['tmp_name']));q("UPDATE employment_contracts SET status='uploaded_review' WHERE id=?",[$id]);contract_event($id,'signed_upload_pending_review');
    }
   }elseif($do==='approve_upload'){
    require_role('recruiter');$note=trim((string)($_POST['review_note']??''));if(contract_document_gaps((int)$c['candidate_id'],$jobId)||!contract_screening_ready((int)$c['application_id'],$jobId))throw new RuntimeException('Complete document and screening reviews before issuing the contract.');if($locked['method']!=='internal'||$locked['status']!=='uploaded_review'||!$note)throw new RuntimeException('Review the uploaded signature and record verification notes.');
    $a=row('SELECT a.*,v.job_id FROM applications a JOIN vacancies v ON v.id=a.vacancy_id WHERE a.id=? FOR UPDATE',[$locked['application_id']]);contract_event($id,'uploaded_signature_verified',['review_note'=>$note]);contract_accept($locked,$a,(int)$c['worker_user_id'],$c['worker_name']);
   }elseif($do==='void'){
    require_role('recruiter');if($locked['method']!=='internal'&&!in_array($locked['status'],['draft','approved'],true))throw new RuntimeException('Void the envelope with the provider and reconcile its status.');if($locked['status']==='signed')throw new RuntimeException('A signed contract cannot be changed.');q("UPDATE employment_contracts SET status='voided' WHERE id=?",[$id]);contract_event($id,'voided');
   }else throw new RuntimeException('Invalid contract action.');
   db()->commit();
  }
 }
 flash(t('Contract updated.'));
 }catch(Throwable $e){
 if(db()->inTransaction())db()->rollBack();
 if($e instanceof PDOException){
  // The reason was being thrown away: every database failure logged the
  // same fixed sentence, so an "Illegal mix of collations" that made it
  // impossible to issue any contract left nothing to diagnose it with.
  // The detail goes to the server log, never to the screen, and a short
  // reference ties the two together.
  $reference=bin2hex(random_bytes(4));
  error_log('[contracts] '.$reference.' '.$e->getMessage());
  flash(t('Contract update failed. Give your administrator this reference: :reference',['reference'=>$reference]),'err');
 } else flash(t($e->getMessage()),'err');
}
 redirect('/contracts'.($id?'?id='.$id:''));
}
$selected=isset($_GET['id'])?scoped_contract((int)$_GET['id'],$isWorker,$candidate,$jobId):null;
if(isset($_GET['id'])&&(!$selected||($isWorker&&in_array($selected['status'],['draft','approved'],true)))){refuse(404, t('Contract unavailable.'));}
if($selected&&$isWorker&&$selected['status']==='issued'){
 q("UPDATE employment_contracts SET status='viewed' WHERE id=? AND status='issued'",[$selected['id']]);contract_event((int)$selected['id'],'viewed');$selected['status']='viewed';
}
$contracts=rows('SELECT c.*,p.full_name FROM employment_contracts c JOIN applications a ON a.id=c.application_id JOIN candidates p ON p.id=a.candidate_id JOIN vacancies v ON v.id=a.vacancy_id WHERE '.($isWorker?"a.candidate_id=? AND c.status NOT IN ('draft','approved')":'v.job_id=?').' ORDER BY c.id DESC LIMIT 100',[$isWorker?$candidate:$jobId]);
$files=$selected?rows('SELECT * FROM contract_files WHERE contract_id=?',[$selected['id']]):[];$events=$selected?rows('SELECT * FROM contract_events WHERE contract_id=? ORDER BY id DESC LIMIT 100',[$selected['id']]):[];
$requirements=rows('SELECT document_type FROM contract_document_requirements WHERE job_id=?',[$jobId]);$policy=row('SELECT * FROM contract_policies WHERE job_id=?',[$jobId]);
// Ready first: an application at offer is what a contract is normally
// prepared against, and burying it under people nobody has called yet
// is how the wrong one gets picked.
$applications=$isWorker?[]:rows("SELECT a.id,a.stage,c.full_name,v.title FROM applications a JOIN candidates c ON c.id=a.candidate_id JOIN vacancies v ON v.id=a.vacancy_id WHERE v.job_id=? AND a.stage NOT IN ('accepted','rejected','withdrawn') ORDER BY FIELD(a.stage,'offered','interview','screening','new'), c.full_name LIMIT 200",[$jobId]);
render('contracts',compact('isWorker','contracts','selected','files','events','requirements','policy','applications'));
