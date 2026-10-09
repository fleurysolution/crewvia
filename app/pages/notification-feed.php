<?php
require_login();header('Content-Type: application/json');header('Cache-Control: no-store');
echo json_encode(rows('SELECT id,message,target FROM notifications WHERE user_id=? AND read_at IS NULL AND id>? ORDER BY id LIMIT 20',[uid(),(int)($_GET['after'] ?? 0)]));
