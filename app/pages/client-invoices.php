<?php
require_role('payroll');$job=current_job();$jobId=(int)($job['id'] ?? 0);
if($_SERVER['REQUEST_METHOD']==='POST') {
 $do=$_POST['do'] ?? '';
 if($do==='generate') {
  $from=(string)($_POST['starts_on'] ?? '');$to=(string)($_POST['ends_on'] ?? '');
  if(!valid_date($from) || !valid_date($to) || $to<$from || !$jobId) { refuse(422, t('Valid project and dates required.')); }
  db()->beginTransaction();q('SELECT id FROM jobs WHERE id=? FOR UPDATE',[$jobId]);
  $sheets=rows("SELECT t.*,s.result_json snapshot_json,p.pay_rate,p.bill_rate,p.per_diem_rate,c.full_name FROM timesheets t JOIN placements p ON p.id=t.placement_id JOIN candidates c ON c.id=p.candidate_id LEFT JOIN pay_snapshots s ON s.timesheet_id=t.id LEFT JOIN invoice_timesheets i ON i.timesheet_id=t.id WHERE p.job_id=? AND t.week_ending BETWEEN ? AND ? AND t.status IN ('approved','paid') AND i.timesheet_id IS NULL",[$jobId,$from,$to]);
  $lines=[];$total=0;
  foreach($sheets as $sheet) { $m=week_money($sheet,$sheet,$job);if($m['bill_rate']<=0) { db()->rollBack();flash(t('A client rate is missing; invoice generation stopped.'),'err');redirect('/client-invoices'); }$lines[]=['timesheet_id'=>(int)$sheet['id'],'worker'=>$sheet['full_name'],'week'=>$sheet['week_ending'],'hours'=>$m['worked'],'rate'=>$m['bill_rate'],'amount'=>$m['bill_total']];$total+=$m['bill_total']; }
  if(!$lines) { db()->rollBack();flash(t('No uninvoiced approved sheets in this period.'),'err');redirect('/client-invoices'); }
  $ref='INV-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
  q('INSERT INTO client_invoices(job_id,reference,starts_on,ends_on,total,details_json,created_by) VALUES (?,?,?,?,?,?,?)',[$jobId,$ref,$from,$to,round($total,2),json_encode($lines,JSON_THROW_ON_ERROR),uid()]);$id=(int)db()->lastInsertId();
  foreach($lines as $line) q('INSERT INTO invoice_timesheets(invoice_id,timesheet_id) VALUES (?,?)',[$id,$line['timesheet_id']]);
  db()->commit();log_activity('generated client invoice','invoice',$id,$ref);redirect('/client-invoices?id='.$id);
 }
 if($do==='issue') q("UPDATE client_invoices SET status='issued',issued_at=NOW() WHERE id=? AND job_id=? AND status='draft'",[(int)($_POST['invoice_id'] ?? 0),$jobId]);
 if($do==='paid') q("UPDATE client_invoices SET status='paid',paid_at=NOW() WHERE id=? AND job_id=? AND status='issued'",[(int)($_POST['invoice_id'] ?? 0),$jobId]);
 redirect('/client-invoices');
}
$invoices=rows('SELECT * FROM client_invoices WHERE job_id=? ORDER BY id DESC',[$jobId]);
$invoice=row('SELECT * FROM client_invoices WHERE id=? AND job_id=?',[(int)($_GET['id'] ?? 0),$jobId]);
$attendance=rows("SELECT a.work_date,COUNT(DISTINCT p.candidate_id) workers,SUM(a.hours) hours FROM attendance_records a JOIN placements p ON p.id=a.placement_id WHERE p.job_id=? AND a.status='approved' GROUP BY a.work_date ORDER BY a.work_date DESC LIMIT 90",[$jobId]);
render('client-invoices',compact('invoices','invoice','job','attendance'));
