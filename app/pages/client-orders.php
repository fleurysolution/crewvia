<?php
require_role('recruiter');
if($_SERVER['REQUEST_METHOD']==='POST') {
    $action=(string)($_POST['do'] ?? '');
    if($action==='create') {
        $clientId=(int)($_POST['client_id'] ?? 0);
        $title=trim((string)($_POST['title'] ?? ''));
        $description=trim((string)($_POST['description'] ?? ''));
        $source=(string)($_POST['source'] ?? '');
        $count=filter_var($_POST['headcount'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        $start=trim((string)($_POST['starts_on'] ?? ''));
        $end=trim((string)($_POST['ends_on'] ?? ''));
        if(!row('SELECT id FROM clients WHERE id=?',[$clientId]) || !$title || mb_strlen($title)>190 || !$description || mb_strlen($description)>10000 || !$count || !in_array($source,['email','phone','meeting','other'],true) || ($start && !valid_date($start)) || ($end && !valid_date($end)) || ($start && $end && $end<$start)) {refuse(422, t('Enter valid client request details.'));}
        db()->beginTransaction();
        try {
            q('INSERT INTO client_orders(client_id,title,description,source,source_reference,requested_by,headcount,site_city,site_state,starts_on,ends_on,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[$clientId,$title,$description,$source,mb_substr(trim((string)($_POST['source_reference'] ?? '')),0,190),mb_substr(trim((string)($_POST['requested_by'] ?? '')),0,190),$count,mb_substr(trim((string)($_POST['site_city'] ?? '')),0,120),mb_substr(trim((string)($_POST['site_state'] ?? '')),0,40),$start?:null,$end?:null,uid()]);
            $orderId=(int)db()->lastInsertId();
            q('INSERT INTO client_order_events(order_id,user_id,action,note) VALUES (?,?,?,?)',[$orderId,uid(),'created',$description]);db()->commit();
        }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
        log_activity('recorded client request','client_order',$orderId,$source);
    } elseif(in_array($action,['update','convert'],true)) {
        $orderId=(int)($_POST['order_id'] ?? 0);
        $note=trim((string)($_POST['note'] ?? ''));
        db()->beginTransaction();
        try {
            $order=row('SELECT * FROM client_orders WHERE id=? FOR UPDATE',[$orderId]);
            if(!$order || mb_strlen($note)>10000) {db()->rollBack();refuse(404, t('Client request unavailable.'));}
            if($action==='convert') {
                if($order['status']!=='approved' || $order['job_id'] || $order['headcount']>65535) {db()->rollBack();refuse(422, t('Approve the request before creating its project; split requests above 65,535 workers.'));}
                q("INSERT INTO jobs(client_id,title,description,site_city,site_state,headcount_target,starts_on,ends_on,status) VALUES (?,?,?,?,?,?,?,?,'planning')",[$order['client_id'],$order['title'],$order['description'],$order['site_city'],$order['site_state'],$order['headcount'],$order['starts_on'],$order['ends_on']]);
                $jobId=(int)db()->lastInsertId();

                // The request becomes the first line of the scope of work.
                // A project with a headcount and no scope has nothing to
                // raise a requisition against and no rate for anybody
                // placed on it, so the line is created with the agreement.
                q('INSERT INTO job_order_lines(job_id,role_title,discipline,quantity,sort_order,notes)
                   VALUES (?,?,?,?,1,?)',
                  [$jobId, $order['title'], 'other', max(1,(int)$order['headcount']),
                   'Created from the client request. Set the trade and its rates on the scope of work.']);
                q("UPDATE client_orders SET status='converted',job_id=? WHERE id=?",[$jobId,$orderId]);
            } else {
                $status=(string)($_POST['status'] ?? '');
                if(!$note || !in_array($status,['new','reviewing','approved','declined'],true) || $order['status']==='converted'){db()->rollBack();refuse(422, t('Enter a request update and valid status.'));}
                $description=trim((string)($_POST['description'] ?? $order['description']));
                $headcount=filter_var($_POST['headcount'] ?? $order['headcount'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
                if(!$description || mb_strlen($description)>10000 || !$headcount){db()->rollBack();refuse(422, t('Enter valid client request details.'));}
                if($description!==$order['description'] || $headcount!==(int)$order['headcount']) $status='reviewing';
                q('UPDATE client_orders SET status=?,description=?,headcount=? WHERE id=?',[$status,$description,$headcount,$orderId]);
                $note.="\n".json_encode(['previous_description'=>$order['description'],'description'=>$description,'previous_headcount'=>(int)$order['headcount'],'headcount'=>$headcount],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            }
            q('INSERT INTO client_order_events(order_id,user_id,action,note) VALUES (?,?,?,?)',[$orderId,uid(),$action==='convert'?'converted':$status,$note]);db()->commit();
        }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
        log_activity('updated client request','client_order',$orderId,$action);
    }
    redirect('/client-orders');
}
$clients=rows('SELECT id,name FROM clients ORDER BY name');
$page=max(1,min(100000,(int)($_GET['page']??1)));$offset=($page-1)*100;
$orders=rows('SELECT o.*,c.name client_name FROM client_orders o JOIN clients c ON c.id=o.client_id ORDER BY o.id DESC LIMIT 100 OFFSET '.$offset);
$visibleIds=$orders?implode(',',array_map('intval',array_column($orders,'id'))):'0';
$events=rows('SELECT e.*,u.name FROM client_order_events e JOIN users u ON u.id=e.user_id WHERE e.order_id IN ('.$visibleIds.') ORDER BY e.id DESC');
render('client-orders',compact('clients','orders','events','page'));
