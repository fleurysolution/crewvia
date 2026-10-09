<?php
require_login();$isWorker=is_worker_account();$job=current_job();$jobId=(int)($job['id'] ?? 0);
if($_SERVER['REQUEST_METHOD']==='POST') {
 $do=$_POST['do'] ?? '';
 if($do==='content') {
  require_role('recruiter');$kind=$_POST['kind'] ?? ''; $url=trim((string)($_POST['resource_url'] ?? ''));
  if($url && (!filter_var($url,FILTER_VALIDATE_URL) || parse_url($url,PHP_URL_SCHEME)!=='https')) { refuse(422, t('Use an HTTPS resource URL.')); }
  if($jobId && in_array($kind,['training','safety','legal'],true)) {
   db()->beginTransaction();q('INSERT INTO learning_content(job_id,kind,title,content,resource_url) VALUES (?,?,?,?,?)',[$jobId,$kind,trim((string)$_POST['title']),trim((string)$_POST['content']),$url?:null]);$id=(int)db()->lastInsertId();
   q('INSERT IGNORE INTO learning_assignments(content_id,user_id) SELECT ?,w.user_id FROM worker_accounts w JOIN placements p ON p.candidate_id=w.candidate_id WHERE p.job_id=?',[$id,$jobId]); db()->commit();
  }
 }
 if($do==='complete') { q('UPDATE learning_assignments SET completed_at=NOW() WHERE content_id=? AND user_id=? AND completed_at IS NULL',[(int)($_POST['content_id'] ?? 0),uid()]); log_activity('completed learning','content',(int)($_POST['content_id'] ?? 0)); }
 if($do==='incident') {
  $incidentJob=(int)($_POST['job_id'] ?? $jobId);
  $allowed=$isWorker?row('SELECT p.id FROM placements p JOIN worker_accounts w ON w.candidate_id=p.candidate_id WHERE w.user_id=? AND p.job_id=?',[uid(),$incidentJob]):row('SELECT id FROM jobs WHERE id=?',[$incidentJob]);
  $severity=$_POST['severity'] ?? 'low';$desc=trim((string)($_POST['description'] ?? ''));
  if($allowed && $desc && in_array($severity,['low','medium','high'],true)) q('INSERT INTO safety_incidents(job_id,reported_by,description,severity) VALUES (?,?,?,?)',[$incidentJob,uid(),$desc,$severity]);
 }
 if($do==='resolve') { require_role('recruiter');q("UPDATE safety_incidents SET status='resolved' WHERE id=? AND job_id=?",[(int)($_POST['incident_id'] ?? 0),$jobId]); }
 redirect('/learning');
}
if($isWorker) {
 q('INSERT IGNORE INTO learning_assignments(content_id,user_id) SELECT l.id,? FROM learning_content l JOIN placements p ON p.job_id=l.job_id JOIN worker_accounts w ON w.candidate_id=p.candidate_id WHERE w.user_id=?',[uid(),uid()]);
 $content=rows('SELECT l.*,a.completed_at FROM learning_content l JOIN learning_assignments a ON a.content_id=l.id WHERE a.user_id=? ORDER BY l.kind,l.id',[uid()]);
 $incidents=rows('SELECT * FROM safety_incidents WHERE reported_by=? ORDER BY id DESC',[uid()]);
 $jobs=rows('SELECT DISTINCT j.id,j.title FROM jobs j JOIN placements p ON p.job_id=j.id JOIN worker_accounts w ON w.candidate_id=p.candidate_id WHERE w.user_id=?',[uid()]);
} else {
 require_role('recruiter','hotels','payroll','supervisor');
 $content=user()['role']==='supervisor'?rows('SELECT DISTINCT l.*,NULL completed_at FROM learning_content l JOIN placements p ON p.job_id=l.job_id JOIN assignment_details d ON d.placement_id=p.id WHERE d.supervisor_id=? ORDER BY l.kind,l.id',[uid()]):rows('SELECT l.*,NULL completed_at FROM learning_content l WHERE job_id=? ORDER BY kind,id',[$jobId]);
 $incidents=user()['role']==='supervisor'?rows('SELECT * FROM safety_incidents WHERE reported_by=? ORDER BY id DESC',[uid()]):rows('SELECT * FROM safety_incidents WHERE job_id=? ORDER BY id DESC',[$jobId]);$jobs=user()['role']==='supervisor'?rows('SELECT DISTINCT j.id,j.title FROM jobs j JOIN placements p ON p.job_id=j.id JOIN assignment_details d ON d.placement_id=p.id WHERE d.supervisor_id=?',[uid()]):($job?[$job]:[]);
}
$progress=can('recruiter')?rows('SELECT l.title,u.name,a.completed_at FROM learning_assignments a JOIN learning_content l ON l.id=a.content_id JOIN users u ON u.id=a.user_id WHERE l.job_id=? ORDER BY u.name,l.title',[$jobId]):[];
render('learning',compact('content','incidents','jobs','progress','isWorker'));
