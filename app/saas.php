<?php
declare(strict_types=1);

require_once __DIR__ . '/integrations.php';

function saas_configured(): bool
{
    global $config;
    return ($config['commercial_mode'] ?? 'demo') === 'subscription'
        && preg_match('/^[a-z0-9-]{3,64}$/D', (string)($config['tenant_id'] ?? ''))
        && preg_match('/^sk_(test|live)_/', (string)($config['stripe_secret_key'] ?? ''))
        && preg_match('/^price_/', (string)($config['stripe_price_id'] ?? ''));
}

function saas_api(string $method, string $endpoint, array $params = [], ?string $idempotency = null): array
{
    global $config;
    if (!saas_configured()) throw new RuntimeException('Stripe subscription configuration is incomplete.');
    if (!preg_match('#^/(customers|checkout/sessions|billing_portal/sessions|subscriptions)(/[a-zA-Z0-9_]+)?$#D', $endpoint)) throw new RuntimeException('Invalid billing endpoint.');
    $headers = ['Authorization: Bearer ' . $config['stripe_secret_key'], 'Stripe-Version: 2024-06-20', 'Content-Type: application/x-www-form-urlencoded'];
    if ($idempotency) $headers[] = 'Idempotency-Key: ' . $idempotency;
    // A read, or a write carrying its idempotency key, is safe to repeat: Stripe answers the key's first result again (P4-M04).
    $r = integration_request('stripe', $method, 'https://api.stripe.com/v1' . $endpoint, $headers, $method !== 'GET' ? http_build_query($params) : null, $method === 'GET' || $idempotency !== null, 20);
    $data = $r['body'] !== '' ? json_decode($r['body'], true) : null;
    if ($r['status'] < 200 || $r['status'] >= 300 || !is_array($data)) {
        integration_event('stripe', $method . ' ' . preg_replace('#/[a-zA-Z0-9_]{6,}$#', '/{id}', $endpoint), 'failed', null, 'HTTP ' . $r['status']);
        throw new RuntimeException('Billing provider unavailable. Please retry or contact support.');
    }
    return $data;
}

/** Signature uses raw bytes, constant-time comparison and a short replay window. */
function saas_verify_event(string $payload, string $header, string $secret, ?int $now = null): array
{
    if (!$secret || strlen($payload) > 1048576) throw new RuntimeException('Invalid webhook.');
    $timestamp = null; $signatures = [];
    foreach (explode(',', $header) as $part) {
        [$key,$value] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($key==='t' && ctype_digit($value)) $timestamp=(int)$value;
        if ($key==='v1') $signatures[]=$value;
    }
    if (!$timestamp || abs(($now ?? time())-$timestamp)>300) throw new RuntimeException('Expired webhook.');
    $expected=hash_hmac('sha256', $timestamp.'.'.$payload, $secret); $valid=false;
    foreach ($signatures as $signature) if (hash_equals($expected,$signature)) $valid=true;
    if (!$valid) throw new RuntimeException('Invalid webhook signature.');
    $event=json_decode($payload,true,64,JSON_THROW_ON_ERROR);
    if (!is_array($event) || !is_string($event['id'] ?? null) || !preg_match('/^evt_[a-zA-Z0-9]+$/D',$event['id']) || !is_array($event['data']['object'] ?? null)) throw new RuntimeException('Invalid webhook event.');
    return $event;
}

function saas_usage(string $month): array
{
    if (!preg_match('/^\d{4}-\d{2}$/D',$month) || !valid_date($month.'-01')) throw new RuntimeException('Choose a valid billing month.');
    $start=$month.'-01';$end=date('Y-m-d',strtotime($start.' +1 month'));
    $ids=array_map('intval', array_column(rows("SELECT DISTINCT candidate_id FROM placements WHERE status IN ('on_site','completed') AND start_date IS NOT NULL AND start_date<? AND (end_date IS NULL OR end_date>=?) ORDER BY candidate_id",[$end,$start]),'candidate_id'));
    return ['month'=>$start,'count'=>count($ids),'ids'=>$ids,'basis'=>'Unique on-site/completed workers with assignment dates overlapping month'];
}

function saas_subscription_refresh(string $subscription): void
{
    global $config;
    if (!preg_match('/^sub_[a-zA-Z0-9]+$/D',$subscription)) throw new RuntimeException('Invalid subscription identity.');
    $remote=saas_api('GET','/subscriptions/'.$subscription);
    $local=row('SELECT * FROM saas_subscription WHERE id=1 FOR UPDATE');
    if (($remote['metadata']['workspace_id'] ?? '')!==($config['tenant_id'] ?? '') || ($remote['customer'] ?? '')!==$local['stripe_customer_id']) throw new RuntimeException('Subscription workspace mismatch.');
    $items=$remote['items']['data'] ?? [];
    if (count($items)!==1 || ($items[0]['price']['id'] ?? '')!==$config['stripe_price_id']) throw new RuntimeException('Subscription price mismatch.');
    $status=(string)($remote['status'] ?? 'unknown');
    $period=(int)($remote['current_period_end'] ?? 0);
    q('UPDATE saas_subscription SET stripe_subscription_id=?,status=?,quantity=?,period_end=? WHERE id=1',[$subscription,$status,(int)($items[0]['quantity'] ?? 0),$period?gmdate('Y-m-d H:i:s',$period):null]);
}


/** The webhook secrets in force: a list while one is being rotated, or the single one. */
function saas_webhook_secrets(): array
{
    global $config;
    $list = $config['stripe_webhook_secrets'] ?? [$config['stripe_webhook_secret'] ?? ''];

    return array_values(array_filter(is_array($list) ? $list : [$list], fn($s) => is_string($s) && $s !== ''));
}

/**
 * A Stripe webhook (P4-M04): verified against each secret in force,
 * processed once per event id, for this workspace only, and recorded.
 * A failure answers 503 so that Stripe sends it again. Returns
 * [HTTP status, JSON body].
 */
function stripe_webhook_handle(string $payload, string $header, array $secrets): array
{
    global $config;
    $event = null;
    foreach ($secrets ?: [''] as $secret) {
        try {
            $event = saas_verify_event($payload, $header, $secret);
            break;
        } catch (Throwable $e) {
            $why = $e->getMessage();
        }
    }
    if ($event === null) {
        integration_refusal('stripe', 'webhook', null, $why ?? 'Invalid webhook');
        return [400, '{"error":"Invalid webhook"}'];
    }
    $type = (string) ($event['type'] ?? '');
    try {
        db()->beginTransaction();
        q('SELECT id FROM saas_subscription WHERE id=1 FOR UPDATE');
        if (row('SELECT event_id FROM saas_webhook_events WHERE event_id=?', [$event['id']])) {
            db()->commit();
            integration_event('stripe', 'webhook', 'duplicate', $event['id'], $type);
            return [200, '{"received":true,"duplicate":true}'];
        }
        $object = $event['data']['object'];
        $subscription = null;
        // A shared Stripe account can notify several tenant endpoints. Ignore other workspaces.
        $workspace = $object['metadata']['workspace_id'] ?? $object['client_reference_id'] ?? null;
        if ($workspace !== null && $workspace !== ($config['tenant_id'] ?? '')) {
            db()->commit();
            integration_event('stripe', 'webhook', 'ignored', $event['id'], $type . ': another workspace');
            return [200, '{"received":true,"ignored":true}'];
        }
        $local = row('SELECT * FROM saas_subscription WHERE id=1');
        if (in_array($type, ['invoice.paid', 'invoice.payment_failed'], true) && ($object['customer'] ?? '') !== $local['stripe_customer_id']) {
            db()->commit();
            integration_event('stripe', 'webhook', 'ignored', $event['id'], $type . ': another customer');
            return [200, '{"received":true,"ignored":true}'];
        }
        if ($type === 'checkout.session.completed') {
            if (($object['client_reference_id'] ?? '') !== ($config['tenant_id'] ?? '') || ($object['customer'] ?? '') !== $local['stripe_customer_id']
                || ($object['mode'] ?? '') !== 'subscription' || ($object['id'] ?? '') !== $local['last_checkout_id']) throw new RuntimeException('Checkout workspace mismatch.');
            $subscription = $object['subscription'] ?? null;
        } elseif (str_starts_with($type, 'customer.subscription.')) {
            if (($object['metadata']['workspace_id'] ?? '') !== ($config['tenant_id'] ?? '')) throw new RuntimeException('Webhook workspace mismatch.');
            $subscription = $object['id'] ?? null;
        } elseif (in_array($type, ['invoice.paid', 'invoice.payment_failed'], true)) {
            $subscription = $object['subscription'] ?? null;
            if (!$subscription || $subscription !== $local['stripe_subscription_id'] || ($object['customer'] ?? '') !== $local['stripe_customer_id']) throw new RuntimeException('Invoice workspace mismatch.');
        }
        // The subscription is read back from Stripe, so events arriving out of order still leave its latest state.
        if ($subscription) saas_subscription_refresh($subscription);
        q('INSERT INTO saas_webhook_events(event_id,event_type) VALUES (?,?)', [$event['id'], $type]);
        db()->commit();
        integration_event('stripe', 'webhook', 'ok', $event['id'], $type);
        return [200, '{"received":true}'];
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('[workforce billing] Webhook processing failed: ' . $event['id']);
        integration_event('stripe', 'webhook', 'failed', $event['id'], $type . ': ' . mb_substr($e->getMessage(), 0, 300) . ' (Stripe will send it again)');
        return [503, '{"error":"Processing failed; retry required"}'];
    }
}
