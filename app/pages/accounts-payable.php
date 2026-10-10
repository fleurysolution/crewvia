<?php
require_role('payroll');$jobId=(int)(current_job()['id'] ?? 0);
if($_SERVER['REQUEST_METHOD']==='POST') {
 $do=$_POST['do'] ?? '';
 if($do==='invoice') {
  $vendor=trim((string)($_POST['vendor_name'] ?? ''));$ref=trim((string)($_POST['reference'] ?? ''));$amount=$_POST['amount'] ?? '';$due=$_POST['due_on'] ?? '';
  if(!$jobId || !$vendor || !$ref || !is_numeric($amount) || (float)$amount<=0 || !valid_date($due)) { refuse(422, t('Valid vendor invoice details required.')); }
  $po=(int)($_POST['purchase_order_id'] ?? 0);
  if($po && !row("SELECT id FROM purchase_orders WHERE id=? AND job_id=? AND status IN ('approved','closed')",[$po,$jobId])) { refuse(422, t('Choose an approved purchase order on this project, or none.')); }
  if(!row('SELECT id FROM vendor_invoices WHERE job_id=? AND vendor_name=? AND reference=?',[$jobId,$vendor,$ref])) q('INSERT INTO vendor_invoices(job_id,vendor_name,reference,amount,due_on,purchase_order_id) VALUES (?,?,?,?,?,?)',[$jobId,$vendor,$ref,(float)$amount,$due,$po?:null]);
 }
 if($do==='approve') q("UPDATE vendor_invoices SET status='approved' WHERE id=? AND job_id=? AND status='received'",[(int)($_POST['invoice_id'] ?? 0),$jobId]);
 if($do==='pay') {
  $method=$_POST['payment_method'] ?? '';$ref=trim((string)($_POST['payment_reference'] ?? ''));
  if($ref && in_array($method,['transfer','check','cash','card'],true)) q("UPDATE vendor_invoices SET status='paid',payment_method=?,payment_reference=?,paid_at=NOW() WHERE id=? AND job_id=? AND status='approved'",[$method,$ref,(int)($_POST['invoice_id'] ?? 0),$jobId]);
 }
 log_activity('vendor invoice workflow','project',$jobId,$do);redirect('/accounts-payable');
}
$invoices=rows('SELECT v.*,o.reference AS po_reference FROM vendor_invoices v LEFT JOIN purchase_orders o ON o.id=v.purchase_order_id WHERE v.job_id=? ORDER BY v.id DESC',[$jobId]);
$orders=rows("SELECT id,reference,vendor_name,total FROM purchase_orders WHERE job_id=? AND status IN ('approved','closed') ORDER BY id DESC",[$jobId]);
render('accounts-payable',compact('invoices','orders'));
