<?php
require_role('payroll');require_once __DIR__.'/../compensation.php';compensation_apply_due();$jobId=(int)(current_job()['id'] ?? 0);$week=week_ending($_GET['week'] ?? null);
$sheets=rows("SELECT t.*,s.result_json snapshot_json,p.pay_rate,p.bill_rate,p.per_diem_rate,c.full_name,e.adp_employee_id,e.employment_type,e.payment_method,e.salary_per_period FROM timesheets t JOIN placements p ON p.id=t.placement_id JOIN candidates c ON c.id=p.candidate_id LEFT JOIN employee_profiles e ON e.candidate_id=c.id LEFT JOIN pay_snapshots s ON s.timesheet_id=t.id WHERE p.job_id=? AND t.week_ending=? AND t.status IN ('approved','paid') ORDER BY c.full_name",[$jobId,$week]);
$job=current_job();$settings=array_column(rows('SELECT * FROM platform_settings'),'setting_value','setting_key');
require_once __DIR__.'/../connectors.php';$mode=connector_mode('adp_run');
// P4-M05: a payroll file kept is handed out again only as it was made.
if(isset($_GET['download'])){$e=row('SELECT * FROM payroll_exports WHERE id=?',[(int)$_GET['download']]);
 if(!$e){refuse(404,t('That payroll file does not exist.'));}
 if(!hash_equals((string)$e['sha256'],hash('sha256',(string)$e['csv']))){refuse(409,t('This payroll file no longer matches its recorded fingerprint. Nothing was downloaded.'));}
 log_activity('downloaded a payroll file again','payroll_export',(int)$e['id']);
 header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="'.($e['kind']==='review'?'payroll-review':($e['kind']==='adp_test'?'adp-run-TEST':'adp-run')).'-'.$e['week_ending'].'-'.$e['id'].'.csv"');header('X-Content-SHA256: '.$e['sha256']);echo $e['csv'];exit;}
if($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['do']??'',['confirm_import','reconcile'],true)) {
 if($_POST['do']==='confirm_import'){
  db()->beginTransaction();$why=adp_confirm_import((int)($_POST['export_id']??0),(string)($_POST['batch_ref']??''),(string)($_POST['result']??''),(string)($_POST['note']??''));
  if($why!==null){db()->rollBack();refuse(422,$why);}db()->commit();log_activity('recorded an ADP import','payroll_export',(int)$_POST['export_id'],(string)$_POST['result']);
  flash(t('ADP import recorded.'));redirect('/payroll-export?week='.$week.'#adp-files');
 }
 $f=$_FILES['register']??null;$csvText=$f&&($f['error']??1)===UPLOAD_ERR_OK&&$f['size']<=2*1024*1024?(string)file_get_contents($f['tmp_name']):'';
 db()->beginTransaction();[$rid,$why]=adp_reconcile($week,$csvText,(string)($_POST['id_column']??''),(string)($_POST['gross_column']??''));
 if($why!==null){db()->rollBack();refuse(422,$why);}
 q("INSERT INTO platform_settings(setting_key,setting_value) VALUES ('adp_register_id_column',?),('adp_register_gross_column',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",[mb_substr(trim((string)$_POST['id_column']),0,60),mb_substr(trim((string)$_POST['gross_column']),0,60)]);
 db()->commit();log_activity('reconciled an ADP payroll register','adp_reconciliation',$rid,$week);flash(t('ADP register compared with Crewvia.'));redirect('/payroll-export?week='.$week.'&reconciliation='.$rid.'#reconcile');
}
if($_SERVER['REQUEST_METHOD']==='POST') {
 $method=$_POST['method'] ?? '';$ref=trim((string)($_POST['reference'] ?? ''));$id=(int)($_POST['timesheet_id'] ?? 0);
 if($ref && in_array($method,['direct_deposit','check','cash'],true)) {
  db()->beginTransaction();$sheet=row("SELECT t.id FROM timesheets t JOIN placements p ON p.id=t.placement_id WHERE t.id=? AND p.job_id=? AND t.status='approved' FOR UPDATE",[$id,$jobId]);
  if($sheet) { q('INSERT INTO payroll_payments(timesheet_id,method,reference,paid_by) VALUES (?,?,?,?)',[$id,$method,$ref,uid()]);q("UPDATE timesheets SET status='paid' WHERE id=?",[$id]); }
  db()->commit();log_activity('recorded payroll payment','timesheet',$id);redirect('/payroll-export?week='.$week);
 }
}
if(isset($_GET['export'])) {
 // The provider code as set now: a week frozen before a code was given
 // still exports under it.
 $itemCodes=array_column(rows('SELECT code,provider_code FROM pay_items WHERE provider_code IS NOT NULL'),'provider_code','code');
 $adp=$_GET['export']==='adp_run';$company=$settings['adp_company_code'] ?? '';$code=$settings['adp_hours_code'] ?? '';$diemCode=$settings['adp_perdiem_code'] ?? '';$otCode=$settings['adp_overtime_code']??'';
 if($adp && (!$company || !$code || !preg_match('/^[A-Za-z0-9_-]+$/',$company) || !preg_match('/^[A-Za-z0-9_-]+$/',$code))) { refuse(422, t('Configure ADP RUN company and earnings codes from your actual ADP template first.')); }
 foreach($sheets as $sheet) if($adp && (!$sheet['adp_employee_id'] || !preg_match('/^[A-Za-z0-9_-]+$/',$sheet['adp_employee_id']) || !in_array($sheet['employment_type'],['hourly','salaried'],true) || ($sheet['employment_type']==='salaried' && $sheet['salary_per_period']===null) || ((float)$sheet['per_diem_days']>0 && !$diemCode) || (float)$sheet['expenses']>0)) { refuse(422, t('ADP export blocked: check employee IDs/categories, salary setup, per-diem code and separately reconcile expenses.')); }
 foreach($sheets as $sheet){$m=week_money($sheet,$sheet,$job?:[]);if($adp&&($m['overtime_hours']??0)>0&&(!$otCode||!preg_match('/^[A-Za-z0-9_-]+$/',$otCode))){refuse(422, t('Configure the ADP overtime earnings code before export.'));}if($adp&&(($m['double_hours']??0)>0||($m['holiday_hours']??0)>0)){refuse(422, t('Double-time and holiday hours have no ADP earnings code yet. Use the review export and enter them in ADP by hand.'));}if($adp&&($m['paid_leave_hours']??0)>0){refuse(422, t('Paid leave hours have no ADP earnings code yet. Use the review export and enter them in ADP by hand.'));}foreach(($m['gross_to_net']['deductions']??[]) as $d){$d['provider_code']=$itemCodes[$d['code']]??$d['provider_code'];if($adp&&(!$d['provider_code']||!preg_match('/^[A-Za-z0-9_-]+$/',$d['provider_code']))){refuse(422, t('A deduction has no ADP deduction code: :item. Set its provider code on the Pay items page, or use the review export.',['item'=>$d['label']]));}}}
 // P4-M05: ADP's connector state. Disabled: no ADP file. Sandbox: a TEST file, for ADP's preview or a test company.
 if($adp&&$mode==='disabled'){refuse(422,t('The ADP connector is switched off. An administrator turns it on under Integrations.'));}
 $out=fopen('php://temp','r+');
 if($adp) { fputcsv($out,['##GENERIC## V1.0']);fputcsv($out,['IID','Pay Frequency','Pay Period Start Date','Pay Period End Date','Employee ID','Earnings Code','Pay Hours','Dollars','Separate Check','Worked In Dept','Rate Code']); }
 else fputcsv($out,['Employee','ADP ID','Employment category','Payment method','Worked hours','Paid hours','Gross calculated pay','Per diem','Expenses','Status','Gross wages','Deductions','Net before taxes','Employer contributions']);
 foreach($sheets as $sheet) {
  $m=week_money($sheet,$sheet,$job ?: []);
  if($adp) {
   $prefix=[$company,'W',date('m/d/Y',strtotime($week.' -6 days')),date('m/d/Y',strtotime($week)),$sheet['adp_employee_id']];
   fputcsv($out,[...$prefix,$code,$sheet['employment_type']==='salaried'?'':($m['regular_hours']??$m['paid_hours']),$sheet['employment_type']==='salaried'?$sheet['salary_per_period']:'','','',1]);
   if(($m['overtime_hours']??0)>0)fputcsv($out,[...$prefix,$otCode,$m['overtime_hours'],'','','',1]);
   if($m['per_diem']>0) fputcsv($out,[...$prefix,$diemCode,'',$m['per_diem'],'','','']);
   foreach(($m['gross_to_net']['deductions']??[]) as $d) fputcsv($out,[...$prefix,$itemCodes[$d['code']]??$d['provider_code'],'',number_format(-(float)$d['amount'],2,'.',''),'','','']);
  }else { $name=preg_match('/^[=+@-]/',$sheet['full_name'])?"'".$sheet['full_name']:$sheet['full_name'];fputcsv($out,[$name,$sheet['adp_employee_id'],$sheet['employment_type'],$sheet['payment_method'],$m['worked'],$m['paid_hours'],$m['pay_total'],$m['per_diem'],$m['expenses'],$sheet['status'],$m['gross_to_net']['gross_wages']??'',$m['gross_to_net']['total_deductions']??'',$m['gross_to_net']['net_before_tax']??'',$m['gross_to_net']['total_employer']??'']); }
 }
 rewind($out);$csvFile=(string)stream_get_contents($out);fclose($out);
 $kind=!$adp?'review':($mode==='live'?'adp_live':'adp_test');
 $exportId=adp_export_record($kind,$week,$jobId?:null,$csvFile,max(0,substr_count($csvFile,"\n")-($adp?2:1)),count(array_unique(array_column($sheets,'full_name'))));
 log_activity($adp?'exported an ADP file':'exported the payroll review','payroll_export',$exportId,$week);
 header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="'.($kind==='review'?'payroll-review':($kind==='adp_test'?'adp-run-TEST':'adp-run')).'-'.$week.'.csv"');header('X-Content-SHA256: '.hash('sha256',$csvFile));header('X-Payroll-Export: '.$exportId);
 echo $csvFile;exit;
}
$exports=rows('SELECT x.*,u.name AS by_name,c.name AS confirmed_name FROM payroll_exports x LEFT JOIN users u ON u.id=x.created_by LEFT JOIN users c ON c.id=x.confirmed_by WHERE x.week_ending=? ORDER BY x.id DESC LIMIT 20',[$week]);
$recon=isset($_GET['reconciliation'])?row('SELECT * FROM adp_reconciliations WHERE id=?',[(int)$_GET['reconciliation']]):row('SELECT * FROM adp_reconciliations WHERE week_ending=? ORDER BY id DESC LIMIT 1',[$week]);
$registerColumns=[$settings['adp_register_id_column']??'Employee ID',$settings['adp_register_gross_column']??'Gross Pay'];
render('payroll-export',compact('sheets','week','mode','exports','recon','registerColumns'));
