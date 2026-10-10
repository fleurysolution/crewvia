<?php
require_role('payroll');$jobId=(int)(current_job()['id'] ?? 0);$week=week_ending($_GET['week'] ?? null);
$sheets=rows("SELECT t.*,s.result_json snapshot_json,p.pay_rate,p.bill_rate,p.per_diem_rate,c.full_name,e.adp_employee_id,e.employment_type,e.payment_method,e.salary_per_period FROM timesheets t JOIN placements p ON p.id=t.placement_id JOIN candidates c ON c.id=p.candidate_id LEFT JOIN employee_profiles e ON e.candidate_id=c.id LEFT JOIN pay_snapshots s ON s.timesheet_id=t.id WHERE p.job_id=? AND t.week_ending=? AND t.status IN ('approved','paid') ORDER BY c.full_name",[$jobId,$week]);
$job=current_job();$settings=array_column(rows('SELECT * FROM platform_settings'),'setting_value','setting_key');
if($_SERVER['REQUEST_METHOD']==='POST') {
 $method=$_POST['method'] ?? '';$ref=trim((string)($_POST['reference'] ?? ''));$id=(int)($_POST['timesheet_id'] ?? 0);
 if($ref && in_array($method,['direct_deposit','check','cash'],true)) {
  db()->beginTransaction();$sheet=row("SELECT t.id FROM timesheets t JOIN placements p ON p.id=t.placement_id WHERE t.id=? AND p.job_id=? AND t.status='approved' FOR UPDATE",[$id,$jobId]);
  if($sheet) { q('INSERT INTO payroll_payments(timesheet_id,method,reference,paid_by) VALUES (?,?,?,?)',[$id,$method,$ref,uid()]);q("UPDATE timesheets SET status='paid' WHERE id=?",[$id]); }
  db()->commit();log_activity('recorded payroll payment','timesheet',$id);redirect('/payroll-export?week='.$week);
 }
}
if(isset($_GET['export'])) {
 $adp=$_GET['export']==='adp_run';$company=$settings['adp_company_code'] ?? '';$code=$settings['adp_hours_code'] ?? '';$diemCode=$settings['adp_perdiem_code'] ?? '';$otCode=$settings['adp_overtime_code']??'';
 if($adp && (!$company || !$code || !preg_match('/^[A-Za-z0-9_-]+$/',$company) || !preg_match('/^[A-Za-z0-9_-]+$/',$code))) { refuse(422, t('Configure ADP RUN company and earnings codes from your actual ADP template first.')); }
 foreach($sheets as $sheet) if($adp && (!$sheet['adp_employee_id'] || !preg_match('/^[A-Za-z0-9_-]+$/',$sheet['adp_employee_id']) || !in_array($sheet['employment_type'],['hourly','salaried'],true) || ($sheet['employment_type']==='salaried' && $sheet['salary_per_period']===null) || ((float)$sheet['per_diem_days']>0 && !$diemCode) || (float)$sheet['expenses']>0)) { refuse(422, t('ADP export blocked: check employee IDs/categories, salary setup, per-diem code and separately reconcile expenses.')); }
 foreach($sheets as $sheet){$m=week_money($sheet,$sheet,$job?:[]);if($adp&&($m['overtime_hours']??0)>0&&(!$otCode||!preg_match('/^[A-Za-z0-9_-]+$/',$otCode))){refuse(422, t('Configure the ADP overtime earnings code before export.'));}if($adp&&(($m['double_hours']??0)>0||($m['holiday_hours']??0)>0)){refuse(422, t('Double-time and holiday hours have no ADP earnings code yet. Use the review export and enter them in ADP by hand.'));}if($adp&&($m['paid_leave_hours']??0)>0){refuse(422, t('Paid leave hours have no ADP earnings code yet. Use the review export and enter them in ADP by hand.'));}}
 header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="'.($adp?'adp-run-draft':'payroll-review').'-'.$week.'.csv"');$out=fopen('php://output','w');
 if($adp) { fputcsv($out,['##GENERIC## V1.0']);fputcsv($out,['IID','Pay Frequency','Pay Period Start Date','Pay Period End Date','Employee ID','Earnings Code','Pay Hours','Dollars','Separate Check','Worked In Dept','Rate Code']); }
 else fputcsv($out,['Employee','ADP ID','Employment category','Payment method','Worked hours','Paid hours','Gross calculated pay','Per diem','Expenses','Status']);
 foreach($sheets as $sheet) {
  $m=week_money($sheet,$sheet,$job ?: []);
  if($adp) {
   $prefix=[$company,'W',date('m/d/Y',strtotime($week.' -6 days')),date('m/d/Y',strtotime($week)),$sheet['adp_employee_id']];
   fputcsv($out,[...$prefix,$code,$sheet['employment_type']==='salaried'?'':($m['regular_hours']??$m['paid_hours']),$sheet['employment_type']==='salaried'?$sheet['salary_per_period']:'','','',1]);
   if(($m['overtime_hours']??0)>0)fputcsv($out,[...$prefix,$otCode,$m['overtime_hours'],'','','',1]);
   if($m['per_diem']>0) fputcsv($out,[...$prefix,$diemCode,'',$m['per_diem'],'','','']);
  }else { $name=preg_match('/^[=+@-]/',$sheet['full_name'])?"'".$sheet['full_name']:$sheet['full_name'];fputcsv($out,[$name,$sheet['adp_employee_id'],$sheet['employment_type'],$sheet['payment_method'],$m['worked'],$m['paid_hours'],$m['pay_total'],$m['per_diem'],$m['expenses'],$sheet['status']]); }
 }fclose($out);exit;
}
render('payroll-export',compact('sheets','week'));
