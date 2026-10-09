<?php
require __DIR__.'/../saas.php';
header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { http_response_code(405);exit('{"error":"POST required"}'); }
try {
    $event=saas_verify_event((string)file_get_contents('php://input'),(string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''),(string)($config['stripe_webhook_secret'] ?? ''));
} catch (Throwable $e) { http_response_code(400);exit('{"error":"Invalid webhook"}'); }
try {
    db()->beginTransaction();q('SELECT id FROM saas_subscription WHERE id=1 FOR UPDATE');
    if (row('SELECT event_id FROM saas_webhook_events WHERE event_id=?',[$event['id']])) { db()->commit();exit('{"received":true,"duplicate":true}'); }
    $object=$event['data']['object'];$type=(string)($event['type'] ?? '');$subscription=null;
    // A shared Stripe account can notify several tenant endpoints. Ignore other workspaces.
    $workspace=$object['metadata']['workspace_id'] ?? $object['client_reference_id'] ?? null;
    if ($workspace!==null && $workspace!==($config['tenant_id'] ?? '')) {db()->commit();exit('{"received":true,"ignored":true}');}
    if(in_array($type,['invoice.paid','invoice.payment_failed'],true)) {
        $local=row('SELECT * FROM saas_subscription WHERE id=1');
        if(($object['customer'] ?? '')!==$local['stripe_customer_id']) {db()->commit();exit('{"received":true,"ignored":true}');}
    }
    if ($type==='checkout.session.completed') {
        $local=row('SELECT * FROM saas_subscription WHERE id=1');
        if (($object['client_reference_id'] ?? '')!==($config['tenant_id'] ?? '') || ($object['customer'] ?? '')!==$local['stripe_customer_id'] || ($object['mode'] ?? '')!=='subscription' || ($object['id'] ?? '')!==$local['last_checkout_id']) throw new RuntimeException('Checkout workspace mismatch.');
        $subscription=$object['subscription'] ?? null;
    } elseif (str_starts_with($type,'customer.subscription.')) {
        if (($object['metadata']['workspace_id'] ?? '')!==($config['tenant_id'] ?? '')) throw new RuntimeException('Webhook workspace mismatch.');
        $subscription=$object['id'] ?? null;
    } elseif (in_array($type,['invoice.paid','invoice.payment_failed'],true)) {
        $subscription=$object['subscription'] ?? null;
        $local=row('SELECT * FROM saas_subscription WHERE id=1');
        if (!$subscription || $subscription!==$local['stripe_subscription_id'] || ($object['customer'] ?? '')!==$local['stripe_customer_id']) throw new RuntimeException('Invoice workspace mismatch.');
    }
    if ($subscription) saas_subscription_refresh($subscription);
    q('INSERT INTO saas_webhook_events(event_id,event_type) VALUES (?,?)',[$event['id'],$type]);
    db()->commit();echo '{"received":true}';
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('[workforce billing] Webhook processing failed: '.$event['id']);
    http_response_code(503);echo '{"error":"Processing failed; retry required"}';
}
