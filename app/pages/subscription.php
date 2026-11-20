<?php
require_role('admin');require __DIR__.'/../saas.php';require_once __DIR__.'/../saas-renewal.php';
$error=null;$month=(string)($_POST['month'] ?? $_GET['month'] ?? date('Y-m'));
try { $usage=saas_usage($month); } catch(Throwable $e) { $error=t($e->getMessage());$month=date('Y-m');$usage=saas_usage($month); }
$subscription=row('SELECT * FROM saas_subscription WHERE id=1');$review=null;$notice=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        $action=$_POST['do'] ?? '';
        if ($action==='snapshot') {
            if ($usage['month']>=date('Y-m-01')) throw new RuntimeException(t('Only closed months can be frozen.'));
            q('INSERT IGNORE INTO saas_usage_snapshots(period_month,unique_workers,basis,candidate_ids_json,recorded_by) VALUES (?,?,?,?,?)',[$usage['month'],$usage['count'],$usage['basis'],json_encode($usage['ids']),uid()]);
            log_activity('froze monthly SaaS usage','subscription',1,$month);redirect('/subscription?month='.$month);
        }
        // P5-M114: the renewal terms are shown, and agreed to, before Stripe Checkout opens.
        if ($action==='review' || $action==='checkout') {
            $quantity=filter_var($_POST['quantity'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
            if (!$quantity || !saas_configured()) throw new RuntimeException(t('Configure Stripe and choose 1–100,000 contracted seats.'));
            $terms=saas_renewal_terms($quantity,locale(),saas_price(),time());
            if ($action==='review') { $review=['quantity'=>$quantity]+$terms; }
            elseif (($_POST['consent'] ?? '')!=='1') { $review=['quantity'=>$quantity]+$terms;throw new RuntimeException(t('Tick the box to agree to the automatic renewal before you pay.')); }
            elseif (!hash_equals($terms['hash'],(string)($_POST['terms_hash'] ?? ''))) { $review=['quantity'=>$quantity]+$terms;throw new RuntimeException(t('The terms have changed since you read them. Read them again, then agree.')); }
        }
        if ($action==='checkout') {
            if (parse_url($config['app_url'],PHP_URL_SCHEME)!=='https') throw new RuntimeException(t('Billing requires a configured HTTPS workspace URL.'));
            if(!empty($subscription['stripe_subscription_id']))throw new RuntimeException(t('Use the billing portal to manage the existing subscription.'));
            if($subscription['last_checkout_url']&&strtotime($subscription['checkout_expires_at'].' UTC')>time()){header('Location: '.$subscription['last_checkout_url'],true,303);exit;}
            // Persist the request before any provider call; a timeout must reuse the same key.
            db()->beginTransaction();q('SELECT id FROM saas_subscription WHERE id=1 FOR UPDATE');
            $attempt=row('SELECT * FROM saas_checkout_attempts WHERE completed_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE');
            if($attempt && ((int)$attempt['quantity']!==$quantity || strtotime($attempt['created_at'])<time()-23*3600))throw new RuntimeException(t('An unresolved checkout needs billing reconciliation before changing seats or starting another attempt.'));
            if(!$attempt){q('INSERT INTO saas_checkout_attempts(request_key,quantity,requested_email) VALUES (?,?,?)',[bin2hex(random_bytes(16)),$quantity,user()['email']]);$attempt=row('SELECT * FROM saas_checkout_attempts WHERE id=?',[(int)db()->lastInsertId()]);}
            // What the customer was shown and agreed to, word for word, kept 3 years.
            $consentId=saas_record_consent($terms,$quantity,locale(),uid(),(string)user()['email'],$attempt['request_key'],substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45));
            db()->commit();
            db()->beginTransaction();$subscription=row('SELECT * FROM saas_subscription WHERE id=1 FOR UPDATE');
            if (!empty($subscription['stripe_subscription_id'])) throw new RuntimeException(t('Use the billing portal to manage the existing subscription.'));
            if ($subscription['last_checkout_url'] && strtotime($subscription['checkout_expires_at'].' UTC')>time()) {
                $url=$subscription['last_checkout_url'];db()->commit();header('Location: '.$url, true,303);exit;
            }
            if (!$subscription['stripe_customer_id']) {
                $customer=saas_api('POST','/customers',['email'=>$attempt['requested_email'],'metadata'=>['workspace_id'=>$config['tenant_id']]],'workforce-customer-'.$config['tenant_id']);
                if (!preg_match('/^cus_[a-zA-Z0-9]+$/D',(string)($customer['id'] ?? ''))) throw new RuntimeException(t('Invalid billing customer.'));
                q('UPDATE saas_subscription SET stripe_customer_id=? WHERE id=1',[$customer['id']]);$subscription['stripe_customer_id']=$customer['id'];
            }
            $session=saas_api('POST','/checkout/sessions',['mode'=>'subscription','customer'=>$subscription['stripe_customer_id'],'client_reference_id'=>$config['tenant_id'],
                'line_items'=>[['price'=>$config['stripe_price_id'],'quantity'=>$quantity]],'subscription_data'=>['metadata'=>['workspace_id'=>$config['tenant_id']]],
                'metadata'=>['workspace_id'=>$config['tenant_id']],'success_url'=>rtrim($config['app_url'],'/').'/subscription?checkout=returned','cancel_url'=>rtrim($config['app_url'],'/').'/subscription'],
                'workforce-checkout-'.$attempt['request_key']);
            $url=(string)($session['url'] ?? '');
            if (parse_url($url,PHP_URL_SCHEME)!=='https' || parse_url($url,PHP_URL_HOST)!=='checkout.stripe.com') throw new RuntimeException(t('Invalid checkout URL.'));
            q('UPDATE saas_subscription SET last_checkout_id=?,last_checkout_url=?,checkout_expires_at=? WHERE id=1',[$session['id'],$url,gmdate('Y-m-d H:i:s',(int)$session['expires_at'])]);
            q('UPDATE saas_renewal_consents SET checkout_session_id=? WHERE id=?',[$session['id'],$consentId]);
            q('UPDATE saas_checkout_attempts SET completed_at=NOW() WHERE id=?',[$attempt['id']]);
            db()->commit();header('Location: '.$url,true,303);exit;
        }
        // P5-M114: the customer cancels here; it takes effect at the end of the period already paid for.
        if ($action==='cancel_renewal' || $action==='keep_renewal') {
            saas_set_cancel_at_period_end($action==='cancel_renewal');
            log_activity($action==='cancel_renewal'?'cancelled the subscription renewal':'kept the subscription','subscription',1);
            redirect('/subscription?renewal='.($action==='cancel_renewal'?'cancelled':'kept'));
        }
        if ($action==='portal') {
            if (!$subscription['stripe_customer_id']) throw new RuntimeException(t('No billing customer registered.'));
            $portal=saas_api('POST','/billing_portal/sessions',['customer'=>$subscription['stripe_customer_id'],'return_url'=>rtrim($config['app_url'],'/').'/subscription']);
            $url=(string)($portal['url'] ?? '');
            if (parse_url($url,PHP_URL_SCHEME)!=='https' || parse_url($url,PHP_URL_HOST)!=='billing.stripe.com') throw new RuntimeException(t('Invalid billing portal URL.'));
            header('Location: '.$url,true,303);exit;
        }
    } catch(Throwable $e) { if(db()->inTransaction()) db()->rollBack();$error=t($e->getMessage()); }
}
$snapshots=rows('SELECT * FROM saas_usage_snapshots ORDER BY period_month DESC LIMIT 24');
$subscription=row('SELECT * FROM saas_subscription WHERE id=1');
$price=null;if(saas_configured()){try{$price=saas_price();}catch(Throwable $e){$error=$error ?? t($e->getMessage());}}
$invoices=rows('SELECT * FROM saas_invoices ORDER BY id DESC LIMIT 12');
$notice=match($_GET['renewal'] ?? ''){'cancelled'=>t('Renewal cancelled. You keep access until the end of the period already paid for, and no further charge is made.'),'kept'=>t('Your subscription will renew as before.'),default=>null};
render('subscription',compact('subscription','usage','month','snapshots','error','review','price','invoices','notice'));
