<?php
require_login();
// P5-M109: following a notice marks it read, then opens what it points to (a path in Crewvia only).
if (isset($_GET['open'])) {
    $n = row('SELECT id, target FROM notifications WHERE id = ? AND user_id = ?', [(int) $_GET['open'], uid()]);
    if ($n) {
        q('UPDATE notifications SET read_at = COALESCE(read_at, NOW()) WHERE id = ?', [(int) $n['id']]);
        $to = (string) $n['target'];
        redirect($to !== '' && $to[0] === '/' && ! str_starts_with($to, '//') ? $to : '/notifications');
    }
    redirect('/notifications');
}
if($_SERVER['REQUEST_METHOD']==='POST') { q('UPDATE notifications SET read_at=NOW() WHERE user_id=? AND id=?',[uid(),(int)($_POST['id'] ?? 0)]); redirect('/notifications'); }
$notifications=rows('SELECT * FROM notifications WHERE user_id=? AND held=0 ORDER BY id DESC LIMIT 100',[uid()]);
render('notifications',compact('notifications'));
