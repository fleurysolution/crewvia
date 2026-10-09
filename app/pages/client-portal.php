<?php
// This portal never uses the staff's selected project or an unscoped candidate ID.
require_role('client');
if(user()['role']!=='client') {refuse(403, t('Client portal account required.'));}
$projects=rows('SELECT j.id,j.title,j.site_city,j.site_state,j.status,c.name client_name FROM jobs j JOIN clients c ON c.id=j.client_id JOIN client_access a ON a.client_id=j.client_id WHERE a.user_id=? ORDER BY j.id DESC',[uid()]);
$jobId=(int)($_GET['job_id'] ?? $_POST['job_id'] ?? ($projects[0]['id'] ?? 0));
$project=null;
foreach($projects as $allowed)if((int)$allowed['id']===$jobId)$project=$allowed;
if($jobId && !$project) {refuse(404, t('Project unavailable.'));}
if($_SERVER['REQUEST_METHOD']==='POST') {
    $recordId=(int)($_POST['attendance_id'] ?? 0);
    $decision=(string)($_POST['decision'] ?? '');
    $note=trim((string)($_POST['note'] ?? ''));
    if(!$project || !in_array($decision,['confirmed','disputed'],true) || mb_strlen($note)>1000 || ($decision==='disputed' && !$note)) {refuse(422, t('Choose a valid decision and explain any disputed attendance.'));}
    db()->beginTransaction();
    try {
        $record=row("SELECT r.id FROM attendance_records r JOIN placements p ON p.id=r.placement_id JOIN jobs j ON j.id=p.job_id JOIN client_access a ON a.client_id=j.client_id WHERE r.id=? AND p.job_id=? AND a.user_id=? AND r.status='approved' FOR UPDATE",[$recordId,$jobId,uid()]);
        if(!$record){db()->rollBack();refuse(404, t('Attendance record unavailable.'));}
        q('INSERT INTO client_attendance_reviews(attendance_id,user_id,decision,note) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),decision=VALUES(decision),note=VALUES(note)',[$recordId,uid(),$decision,$note]);
        db()->commit();
    } catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
    log_activity('client attendance review','attendance',$recordId,$decision);
    redirect('/client-portal?job_id='.$jobId);
}
$crewPage=max(1,min(10000,(int)($_GET['crew_page'] ?? 1)));
$attendancePage=max(1,min(10000,(int)($_GET['attendance_page'] ?? 1)));
$invoicePage=max(1,min(10000,(int)($_GET['invoice_page'] ?? 1)));
$requestPage=max(1,min(10000,(int)($_GET['request_page'] ?? 1)));
$crew=$project?rows("SELECT p.id,c.full_name,p.status,d.trade,d.shift_label,u.name supervisor FROM placements p JOIN candidates c ON c.id=p.candidate_id LEFT JOIN assignment_details d ON d.placement_id=p.id LEFT JOIN users u ON u.id=d.supervisor_id WHERE p.job_id=? AND p.status NOT IN ('cancelled') ORDER BY c.full_name,p.id LIMIT 100 OFFSET ".(($crewPage-1)*100),[$jobId]):[];
$attendance=$project?rows("SELECT r.id,r.work_date,r.hours,c.full_name,v.decision,v.note FROM attendance_records r JOIN placements p ON p.id=r.placement_id JOIN candidates c ON c.id=p.candidate_id LEFT JOIN client_attendance_reviews v ON v.attendance_id=r.id WHERE p.job_id=? AND r.status='approved' ORDER BY r.work_date DESC,r.id DESC LIMIT 100 OFFSET ".(($attendancePage-1)*100),[$jobId]):[];
$invoices=$project?rows("SELECT id,reference,starts_on,ends_on,total,status FROM client_invoices WHERE job_id=? AND status IN ('issued','paid') ORDER BY id DESC LIMIT 100 OFFSET ".(($invoicePage-1)*100),[$jobId]):[];
$orders=rows('SELECT o.id,o.title,o.description,o.status,o.source,o.created_at FROM client_orders o JOIN client_access a ON a.client_id=o.client_id WHERE a.user_id=? ORDER BY o.id DESC LIMIT 100 OFFSET '.(($requestPage-1)*100),[uid()]);
$invoiceId=(int)($_GET['invoice_id'] ?? 0);
$invoice=null;$invoiceLines=[];
if($invoiceId){
    $invoice=row("SELECT i.* FROM client_invoices i JOIN jobs j ON j.id=i.job_id JOIN client_access a ON a.client_id=j.client_id WHERE i.id=? AND i.job_id=? AND a.user_id=? AND i.status IN ('issued','paid')",[$invoiceId,$jobId,uid()]);
    if(!$invoice){refuse(404, t('Invoice unavailable.'));}
    $invoiceLines=json_decode($invoice['details_json'],true,32,JSON_THROW_ON_ERROR);
}
$onSite=$project?(int)val("SELECT COUNT(*) FROM placements WHERE job_id=? AND status='on_site'",[$jobId]):0;
$logistics=$project?row("SELECT
 (SELECT COUNT(*) FROM lodging l JOIN placements p ON p.id=l.placement_id WHERE p.job_id=? AND l.status IN ('held','booked','checked_in')) rooms,
 (SELECT COUNT(*) FROM travel t JOIN placements p ON p.id=t.placement_id WHERE p.job_id=? AND t.mode='flight' AND t.status IN ('booked','completed')) flights,
 (SELECT COUNT(*) FROM vehicle_assignments v JOIN placements p ON p.id=v.placement_id WHERE p.job_id=? AND v.checked_out_at IS NOT NULL AND v.checked_in_at IS NULL) vehicles,
 (SELECT COUNT(*) FROM equipment_issues i JOIN placements p ON p.id=i.placement_id WHERE p.job_id=? AND i.returned_at IS NULL) equipment",[$jobId,$jobId,$jobId,$jobId]):null;
$clientEquipment=$project?rows('SELECT e.name,e.asset_tag,o.serial_number,c.full_name,i.issued_at,i.returned_at FROM equipment_issues i JOIN equipment e ON e.id=i.equipment_id JOIN equipment_ownership o ON o.equipment_id=e.id JOIN placements p ON p.id=i.placement_id JOIN candidates c ON c.id=p.candidate_id JOIN jobs j ON j.id=p.job_id WHERE p.job_id=? AND o.owner_type=\'client\' AND o.client_id=j.client_id ORDER BY i.id DESC LIMIT 100',[$jobId]):[];
// Download the complete selected date range, not merely the screen's latest 300 rows.
// No email, tax, pay rate or screening details.
if(($_GET['export'] ?? '')==='attendance' && $project){
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="client-attendance.csv"');
    $from=(string)($_GET['from'] ?? date('Y-m-01'));$to=(string)($_GET['to'] ?? date('Y-m-d'));
    if(!valid_date($from)||!valid_date($to)||$to<$from||strtotime($to)-strtotime($from)>366*86400){refuse(422, t('Choose a reporting period of no more than 366 days.'));}
    $attendance=rows("SELECT r.work_date,r.hours,c.full_name,v.decision FROM attendance_records r JOIN placements p ON p.id=r.placement_id JOIN candidates c ON c.id=p.candidate_id LEFT JOIN client_attendance_reviews v ON v.attendance_id=r.id WHERE p.job_id=? AND r.status='approved' AND r.work_date BETWEEN ? AND ? ORDER BY r.work_date,c.full_name",[$jobId,$from,$to]);
    $out=fopen('php://output','w');
    fputcsv($out,[t('Date'),t('Employee'),t('Hours'),t('Client review')]);
    foreach($attendance as $record)fputcsv($out,array_map('csv_cell',[$record['work_date'],$record['full_name'],$record['hours'],t($record['decision'] ?? 'Awaiting review')]));
    fclose($out);exit;
}
$crewCount=$project?(int)val("SELECT COUNT(*) FROM placements WHERE job_id=? AND status<>'cancelled'",[$jobId]):0;
$attendanceCount=$project?(int)val("SELECT COUNT(*) FROM attendance_records r JOIN placements p ON p.id=r.placement_id WHERE p.job_id=? AND r.status='approved'",[$jobId]):0;
$invoiceCount=$project?(int)val("SELECT COUNT(*) FROM client_invoices WHERE job_id=? AND status IN ('issued','paid')",[$jobId]):0;
$requestCount=(int)val('SELECT COUNT(*) FROM client_orders o JOIN client_access a ON a.client_id=o.client_id WHERE a.user_id=?',[uid()]);
render('client-portal',compact('projects','project','jobId','crew','attendance','invoices','orders','invoice','invoiceLines','onSite','crewPage','attendancePage','invoicePage','requestPage','crewCount','attendanceCount','invoiceCount','requestCount','logistics','clientEquipment'));
