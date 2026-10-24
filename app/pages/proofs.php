<?php
require_login();$isWorker=is_worker_account();global $config;
$key=base64_decode($config['encryption_key'] ?? '',true);$storage=workforce_storage_path($config).'/documents';$configured=$key && strlen($key)===32;
$candidate=$isWorker?row('SELECT candidate_id FROM worker_accounts WHERE user_id=?',[uid()]):null;
if(!$isWorker) require_role('recruiter','payroll');
require_once __DIR__.'/../identity-docs.php';
if($_SERVER['REQUEST_METHOD']==='POST') {
 $do=$_POST['do'] ?? '';
 if($do==='upload') {
  if(!$configured) { refuse(503, t('Secure document storage is not configured or account is not a worker.')); }
  $uploadCandidate=$isWorker?(int)$candidate['candidate_id']:(int)($_POST['candidate_id'] ?? 0);
  if(!row('SELECT id FROM candidates WHERE id=?',[$uploadCandidate])) { refuse(422, t('Select an existing candidate.')); }
  $type=trim((string)($_POST['document_type'] ?? ''));
  if(!$isWorker && !can('recruiter') && !in_array($type,['W4','payroll_authorization','Receipt'],true)) { refuse(403, t('Payroll can upload payroll forms and receipts only.')); }
  $stored=worker_document_store($uploadCandidate,$_FILES['proof'] ?? null,$type);
  if(is_string($stored)) { refuse(422,$stored); }
  log_activity('uploaded proof','document',$stored);
 }
 // P5-M33: an administrator authorizes who else may open validated identity documents.
 if($do==='identity_reader') {
  require_role('admin');
  if($why=id_reader_set((int)($_POST['user_id']??0),($_POST['allowed']??'1')==='1',uid())){refuse(422,$why,'/proofs#identity-readers');}
  flash(t('Saved.'));redirect('/proofs#identity-readers');
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
 // P5-M33: a validated identity document opens only for the worker, an administrator and an authorized reader.
 if(!doc_can_open($doc,$isWorker,$isWorker?(int)$candidate['candidate_id']:null)) {
  if(!(int)$doc['restricted']) { refuse(404, t('Document unavailable.')); }
  log_activity('was refused an identity document','worker_document',(int)$doc['id'],$doc['document_type']);refuse(403, t('This identity document is restricted since it was validated. An administrator can open it.'));
 }
 if(!$configured) { refuse(503, t('Document encryption key is unavailable.')); }
 $raw=file_get_contents($storage.'/'.$doc['storage_name']);require_once __DIR__.'/../crypto.php';$plain=$raw===false?null:crypto_open($raw);
 if($plain===null || !hash_equals($doc['file_hash'],hash('sha256',$plain))) { refuse(500, t('Document integrity check failed.')); }
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

foreach($documents as &$doc){$doc['can_open']=doc_can_open($doc,$isWorker,$isWorker?(int)$candidate['candidate_id']:null);}
unset($doc);
$readers=can('admin')?rows("SELECT u.id,u.name,u.role,(r.user_id IS NOT NULL) AS allowed FROM users u LEFT JOIN identity_readers r ON r.user_id=u.id WHERE u.is_active=1 AND u.role NOT IN ('worker','client','admin') ORDER BY allowed DESC,u.name LIMIT 300"):[];
render('proofs',compact('isWorker','configured','documents','people','readers'));
