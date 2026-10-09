<?php
require_role('payroll');$jobId=(int)(current_job()['id'] ?? 0);
if($_SERVER['REQUEST_METHOD']==='POST') {
 $do=$_POST['do'] ?? '';
 if($do==='invoice') {
  $vendor=trim((string)($_POST['vendor_name'] ?? ''));$ref=trim((string)($_POST['reference'] ?? ''));$amount=$_POST['amount'] ?? '';$due=$_POST['due_on'] ?? '';
  if(!$jobId || !$vendor || !$ref || !is_numeric($amount) || (float)$amount<=0 || !valid_date($due)) { refuse(422, t('Valid vendor invoice details required.')); }
  if(!row('SELECT id FROM vendor_invoices WHERE job_id=? AND vendor_name=? AND reference=?',[$jobId,$vendor,$ref])) q('INSERT INTO vendor_invoices(job_id,vendor_name,reference,amount,due_on) VALUES (?,?,?,?,?)',[$jobId,$vendor,$ref,(float)$amount,$due]);
 }
 if($do==='approve') q("UPDATE vendor_invoices SET status='approved' WHERE id=? AND job_id=? AND status='received'",[(int)($_POST['invoice_id'] ?? 0),$jobId]);
 if($do==='pay') {
  $method=$_POST['payment_method'] ?? '';$ref=trim((string)($_POST['payment_reference'] ?? ''));
  if($ref && in_array($method,['transfer','check','cash','card'],true)) q("UPDATE vendor_invoices SET status='paid',payment_method=?,payment_reference=?,paid_at=NOW() WHERE id=? AND job_id=? AND status='approved'",[$method,$ref,(int)($_POST['invoice_id'] ?? 0),$jobId]);
 }
 log_activity('vendor invoice workflow','project',$jobId,$do);redirect('/accounts-payable');
}
$invoices=rows('SELECT * FROM vendor_invoices WHERE job_id=? ORDER BY id DESC',[$jobId]);render('accounts-payable',compact('invoices'));
