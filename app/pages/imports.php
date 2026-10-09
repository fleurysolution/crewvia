<?php
require_role('admin');require __DIR__.'/../gmail.php';require __DIR__.'/../import-reader.php';require_once __DIR__.'/../import-fields.php';
$error=null;$preview=$_SESSION['import_preview'] ?? null;$fields=['full_name','email','phone','city','state','trade','employee_number','start_date','end_date','shift','employment_type','payment_method','adp_employee_id','pay_rate','bill_rate','per_diem_rate','salary_per_period'];
if($_SERVER['REQUEST_METHOD']==='POST') {
 $do=$_POST['do'] ?? '';
 try {
  if($do==='preview') {
   $file=$_FILES['sheet'] ?? null;
   if(!$file || $file['error']!==UPLOAD_ERR_OK || $file['size']>10*1024*1024) throw new RuntimeException(t('Choose a CSV or XLSX file up to 10 MB.'));
   $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));$data=read_import($file['tmp_name'],$ext);
   if(count($data)<2) throw new RuntimeException(t('Header row and at least one data row required.'));
   $headers=array_shift($data);$headers=array_map(fn($x)=>trim((string)$x),$headers);$headers[0]=ltrim($headers[0],"\xEF\xBB\xBF");
   $preview=['headers'=>$headers,'data'=>$data,'hash'=>hash_file('sha256',$file['tmp_name']),'filename'=>basename($file['name'])];
   // Encrypt staging data; no raw spreadsheet is left in a public folder.
   $_SESSION['import_preview']=token_encrypt($preview);$preview=$_SESSION['import_preview'];
  }
  if($do==='commit') {
   if(!$preview) throw new RuntimeException(t('Preview a file first.'));$input=token_decrypt($preview);$mapping=$_POST['mapping'] ?? [];
   if(($mapping['full_name'] ?? '')==='') throw new RuntimeException(t('Map the employee name column.'));
   if(row('SELECT id FROM import_batches WHERE file_hash=?',[$input['hash']])) throw new RuntimeException(t('This exact file was already imported.'));
   $jobId=(int)(current_job()['id'] ?? 0);db()->beginTransaction();
   q('INSERT INTO import_batches(filename,file_hash,job_id,imported_by,row_count) VALUES (?,?,?,?,?)',[$input['filename'],$input['hash'],$jobId?:null,uid(),count($input['data'])]);$batch=(int)db()->lastInsertId();
   foreach($input['data'] as $i=>$source) {
    $item=[];foreach($fields as $field) { $column=$mapping[$field] ?? ''; $item[$field]=$column!==''?trim((string)($source[(int)$column] ?? '')):''; }
    $name=$item['full_name'];$cid=null;$status='invalid';$note='Missing name or invalid field length.';
    try {
     $start=import_date_value($item['start_date']);$end=import_date_value($item['end_date']);
     if($start&&$end&&$end<$start)throw new InvalidArgumentException('Invalid assignment date interval.');
     $pay=import_amount_value($item['pay_rate']);$bill=import_amount_value($item['bill_rate']);$diem=import_amount_value($item['per_diem_rate']);$salary=import_amount_value($item['salary_per_period']);
     $employment=$item['employment_type']?:'hourly';$payment=$item['payment_method']?:'direct_deposit';
     if(!in_array($employment,['hourly','salaried','contractor','external'],true)||!in_array($payment,['direct_deposit','check','cash'],true)||mb_strlen($item['adp_employee_id'])>120||mb_strlen($item['shift'])>120)throw new InvalidArgumentException('Invalid imported employee setup.');
    }catch(InvalidArgumentException $e){q('INSERT INTO import_records(batch_id,row_number,encrypted_source,status,note) VALUES (?,?,?,?,?)',[$batch,$i+2,token_encrypt(['headers'=>$input['headers'],'values'=>$source]),'invalid',$e->getMessage()]);continue;}

    if($name && strlen($name)<=190 && strlen($item['email'])<=190 && strlen($item['phone'])<=40 && strlen($item['city'])<=120 && strlen($item['state'])<=40 && strlen($item['employee_number'])<=120) {
     $match=$item['employee_number']?row('SELECT candidate_id FROM employee_profiles WHERE employee_number=?',[$item['employee_number']]):null;
     if(!$match && $item['email']) $match=row('SELECT id candidate_id FROM candidates WHERE email=?',[$item['email']]);
     if($match) { $status='duplicate_review';$note='Existing employee number or email. Identity must be reviewed; existing records were not changed.';$cid=(int)$match['candidate_id']; }
     else if($item['email'] && !filter_var($item['email'],FILTER_VALIDATE_EMAIL)) { $note='Invalid email.'; }
     else {
      q("INSERT INTO candidates(full_name,email,phone,city,state,source) VALUES (?,?,?,?,?,'Spreadsheet import')",[$name,$item['email']?:null,$item['phone']?:null,$item['city']?:null,$item['state']?:null]);$cid=(int)db()->lastInsertId();
      q('INSERT INTO employee_profiles(candidate_id,employee_number,employment_type,payment_method,adp_employee_id,salary_per_period) VALUES (?,?,?,?,?,?)',[$cid,$item['employee_number']?:null,$employment,$payment,$item['adp_employee_id']?:null,$salary]);
      if($jobId) { q("INSERT INTO placements(candidate_id,job_id,status,created_by,start_date,end_date,pay_rate,bill_rate,per_diem_rate) VALUES (?,?,'offered',?,?,?,?,?,?)",[$cid,$jobId,uid(),$start,$end,$pay,$bill,$diem]);$pid=(int)db()->lastInsertId();if($item['trade']||$item['shift']) q('INSERT INTO assignment_details(placement_id,trade,shift_label) VALUES (?,?,?)',[$pid,mb_substr($item['trade']?:'Unassigned',0,190),$item['shift']]); }
      $status='imported';$note='Identity imported; screening/onboarding remains required.';
      q('INSERT INTO candidate_events(candidate_id,user_id,event_type,detail) VALUES (?,?,?,?)',[$cid,uid(),'spreadsheet import',$input['filename'].' row '.($i+2)]);
     }
    }
    q('INSERT INTO import_records(batch_id,row_number,candidate_id,encrypted_source,status,note) VALUES (?,?,?,?,?,?)',[$batch,$i+2,$cid,token_encrypt(['headers'=>$input['headers'],'values'=>$source]),$status,$note]);
   }
   db()->commit();unset($_SESSION['import_preview']);log_activity('imported spreadsheet','batch',$batch);redirect('/imports');
  }
 }catch(Throwable $e) { if(db()->inTransaction()) db()->rollBack();$error=t($e->getMessage()); }
}
$preview=$preview?token_decrypt($preview):null;
$batches=rows('SELECT b.*,SUM(r.status="imported") imported,SUM(r.status="duplicate_review") duplicates,SUM(r.status="invalid") invalid FROM import_batches b LEFT JOIN import_records r ON r.batch_id=b.id GROUP BY b.id ORDER BY b.id DESC');
$reviews=rows("SELECT r.id,r.batch_id,r.row_number,r.candidate_id,r.status,r.note,c.full_name FROM import_records r LEFT JOIN candidates c ON c.id=r.candidate_id WHERE r.status<>'imported' ORDER BY r.id DESC LIMIT 100");
render('imports',compact('preview','fields','batches','reviews','error'));
