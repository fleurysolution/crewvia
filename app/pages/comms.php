<?php
require_login();
if($_SERVER['REQUEST_METHOD']==='POST') {
 $do=$_POST['do'] ?? ''; $cid=(int)($_POST['channel_id'] ?? 0);
 if($do==='channel') {
  require_role('recruiter');$job=current_job();$name=trim((string)($_POST['name'] ?? ''));
  if($job && $name) { q('INSERT INTO channels(job_id,name,created_by) VALUES (?,?,?)',[$job['id'],$name,uid()]);$cid=(int)db()->lastInsertId();q('INSERT INTO channel_members(channel_id,user_id) VALUES (?,?)',[$cid,uid()]); }
 }
 if($do==='member') {
  require_role('recruiter');$member=(int)($_POST['user_id'] ?? 0);
  $channel=row('SELECT id FROM channels WHERE id=? AND job_id=?',[$cid,(int)(current_job()['id'] ?? 0)]);
  if($channel && row('SELECT id FROM users WHERE id=? AND is_active=1',[$member])) q('INSERT IGNORE INTO channel_members(channel_id,user_id) VALUES (?,?)',[$cid,$member]);
 }
 if($do==='message') {
  if(!row('SELECT user_id FROM channel_members WHERE channel_id=? AND user_id=?',[$cid,uid()])) { refuse(403, t('Channel membership required.')); }
  $message=trim((string)($_POST['message'] ?? ''));
  if($message && strlen($message)<=4000) {
   db()->beginTransaction();q('INSERT INTO channel_messages(channel_id,user_id,message) VALUES (?,?,?)',[$cid,uid(),$message]);
   q("INSERT INTO notifications(user_id,message,target) SELECT user_id,?,? FROM channel_members WHERE channel_id=? AND user_id<>?",['New channel message','/comms?channel_id='.$cid,$cid,uid()]);db()->commit();
  }
 }
 redirect('/comms?channel_id='.$cid);
}
$channels=rows('SELECT c.* FROM channels c JOIN channel_members m ON m.channel_id=c.id WHERE m.user_id=? ORDER BY c.name',[uid()]);
$cid=(int)($_GET['channel_id'] ?? ($channels[0]['id'] ?? 0));
$allowed=row('SELECT user_id FROM channel_members WHERE channel_id=? AND user_id=?',[$cid,uid()]);
$messages=$allowed?rows('SELECT m.*,u.name FROM channel_messages m JOIN users u ON u.id=m.user_id WHERE m.channel_id=? ORDER BY m.id DESC LIMIT 100',[$cid]):[];
$people=can('recruiter')?rows('SELECT id,name FROM users WHERE is_active=1 ORDER BY name'):[];
render('comms',compact('channels','cid','messages','people','allowed'));
