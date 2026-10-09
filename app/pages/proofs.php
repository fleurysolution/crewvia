<?php
require_login();$isWorker=is_worker_account();global $config;
$key=base64_decode($config['encryption_key'] ?? '',true);$storage=workforce_storage_path($config).'/documents';$configured=$key && strlen($key)===32;
$candidate=$isWorker?row('SELECT candidate_id FROM worker_accounts WHERE user_id=?',[uid()]):null;
if(!$isWorker) require_role('recruiter','payroll');
if($_SERVER['REQUEST_METHOD']==='POST') {
 $do=$_POST['do'] ?? '';
 if($do==='upload') {
  if(!$configured) { refuse(503, t('Secure document storage is not configured or account is not a worker.')); }
  $uploadCandidate=$isWorker?(int)$candidate['candidate_id']:(int)($_POST['candidate_id'] ?? 0);
  if(!row('SELECT id FROM candidates WHERE id=?',[$uploadCandidate])) { refuse(422, t('Select an existing candidate.')); }
  $file=$_FILES['proof'] ?? null;$type=trim((string)($_POST['document_type'] ?? ''));
  if(!$file || $file['error']!==UPLOAD_ERR_OK || $file['size']>10*1024*1024 || !$type || strlen($type)>120) { refuse(422, t('Choose a document type and a file up to 10 MB.')); }
  if(!$isWorker && !can('recruiter') && !in_array($type,['W4','payroll_authorization','Receipt'],true)) { refuse(403, t('Payroll can upload payroll forms and receipts only.')); }
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
  if(!in_array($mime,['application/pdf','image/jpeg','image/png'],true)) { refuse(422, t('Only PDF, JPEG and PNG documents are accepted.')); }
  $content=file_get_contents($file['tmp_name']);$iv=random_bytes(12);$tag='';$encrypted=openssl_encrypt($content,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
  if($encrypted===false) throw new RuntimeException(t('Encryption failed.'));
  if(!is_dir($storage)) mkdir($storage,0700,true);$name=bin2hex(random_bytes(32));$path=$storage.'/'.$name;
  if(file_put_contents($path,$iv.$tag.$encrypted,LOCK_EX)===false) throw new RuntimeException(t('Document storage unavailable.'));
  try { q('INSERT INTO worker_documents(candidate_id,uploaded_by,document_type,original_name,storage_name,mime_type,file_hash) VALUES (?,?,?,?,?,?,?)',[$uploadCandidate,uid(),$type,mb_substr(basename($file['name']),0,190),$name,$mime,hash('sha256',$content)]); }
  catch(Throwable $e) { unlink($path);throw $e; }
  log_activity('uploaded proof','document',(int)db()->lastInsertId());
 }
 if($do==='review') {
  require_role('recruiter');$status=$_POST['status'] ?? '';
  if(in_array($status,['approved','rejected'],true)) q('UPDATE worker_documents SET status=?,review_note=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?',[$status,trim((string)($_POST['review_note'] ?? '')),uid(),(int)($_POST['document_id'] ?? 0)]);
 }
 redirect('/proofs');
}
if(isset($_GET['download'])) {
 $doc=row('SELECT * FROM worker_documents WHERE id=?',[(int)$_GET['download']]);
 if(!$doc || ($isWorker && (int)$doc['candidate_id']!==(int)$candidate['candidate_id'])) { refuse(404, t('Document unavailable.')); }
 if(!$isWorker && !can('recruiter') && !row("SELECT d.id FROM worker_documents d WHERE d.id=? AND (d.document_type IN ('W4','payroll_authorization') OR EXISTS(SELECT 1 FROM expense_claims e WHERE e.receipt_document_id=d.id))",[$doc['id']])) { refuse(404, t('Document unavailable.')); }
 if(!$configured) { refuse(503, t('Document encryption key is unavailable.')); }
 $raw=file_get_contents($storage.'/'.$doc['storage_name']);$plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
 if($plain===false || !hash_equals($doc['file_hash'],hash('sha256',$plain))) { refuse(500, t('Document integrity check failed.')); }
 // An I-9 or a W-4 carries a social security number. The file is
 // encrypted at rest and checked before it is served, but opening one
 // left no trace at all - so there was no answer to who had seen whose
 // documents. A download is an event worth recording.
 log_activity('downloaded an identity document', 'worker_document', (int) $doc['id'],
  $doc['document_type'] . ' for candidate ' . (int) $doc['candidate_id']);
 header('Content-Type: '.$doc['mime_type']);header('Content-Disposition: attachment; filename="document-'.(int)$doc['id'].($doc['mime_type']==='application/pdf'?'.pdf':($doc['mime_type']==='image/png'?'.png':'.jpg')).'"');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');echo $plain;exit;
}
$documents=$isWorker?rows('SELECT d.*,NULL full_name FROM worker_documents d WHERE candidate_id=? ORDER BY id DESC',[$candidate['candidate_id']]):rows("SELECT d.*,c.full_name FROM worker_documents d JOIN candidates c ON c.id=d.candidate_id WHERE ".(can('recruiter')?'1=1':"(d.document_type IN ('W4','payroll_authorization') OR EXISTS(SELECT 1 FROM expense_claims e WHERE e.receipt_document_id=d.id))").' ORDER BY d.id DESC LIMIT 200');

// People to choose between, instead of a number nothing shows.
$people = rows("SELECT c.id, c.full_name, c.discipline, c.city, c.state
                FROM candidates c
                ORDER BY c.full_name LIMIT 500");

render('proofs',compact('isWorker','configured','documents','people'));
