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
  // Paid in full: recorded as a payment applied to the bill (P3-M04). Part payments are made under Payables.
  require_once __DIR__.'/../balances.php';$iid=(int)($_POST['invoice_id'] ?? 0);
  $bill=row("SELECT id,vendor_name FROM vendor_invoices WHERE id=? AND job_id=? AND status='approved'",[$iid,$jobId]);$open=$bill?bal_invoice('ap',$iid):null;
  if(!$bill || !$open || $open['balance']<=0) { refuse(422, t('Only an approved bill with something left to pay can be paid.')); }
  db()->beginTransaction();[$pid,$why]=bal_record('ap',(string)$bill['vendor_name'],date('Y-m-d'),number_format($open['balance'],2,'.',''),(string)($_POST['payment_method'] ?? ''),(string)($_POST['payment_reference'] ?? ''),'',[$iid=>number_format($open['balance'],2,'.','')]);
  if($why!==null) { db()->rollBack();refuse(422,$why); } db()->commit();
 }
 log_activity('vendor invoice workflow','project',$jobId,$do);redirect('/accounts-payable');
}
$invoices=rows('SELECT v.*,o.reference AS po_reference FROM vendor_invoices v LEFT JOIN purchase_orders o ON o.id=v.purchase_order_id WHERE v.job_id=? ORDER BY v.id DESC',[$jobId]);
$orders=rows("SELECT id,reference,vendor_name,total FROM purchase_orders WHERE job_id=? AND status IN ('approved','closed') ORDER BY id DESC",[$jobId]);
render('accounts-payable',compact('invoices','orders'));
