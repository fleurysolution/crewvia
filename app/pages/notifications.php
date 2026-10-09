<?php
require_login();
if($_SERVER['REQUEST_METHOD']==='POST') { q('UPDATE notifications SET read_at=NOW() WHERE user_id=? AND id=?',[uid(),(int)($_POST['id'] ?? 0)]); redirect('/notifications'); }
$notifications=rows('SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 100',[uid()]);
render('notifications',compact('notifications'));
