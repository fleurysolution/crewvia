<?php
declare(strict_types=1);

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
    $headers = ['Authorization: Bearer ' . $config['stripe_secret_key'], 'Stripe-Version: 2024-06-20'];
    if ($idempotency) $headers[] = 'Idempotency-Key: ' . $idempotency;
    $curl = curl_init('https://api.stripe.com/v1' . $endpoint);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>20,
        CURLOPT_HTTPHEADER=>$headers, CURLOPT_CUSTOMREQUEST=>$method]);
    if ($method !== 'GET') curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($params));
    $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    $data = $body ? json_decode($body, true) : null;
    if ($status < 200 || $status >= 300 || !is_array($data)) throw new RuntimeException('Billing provider unavailable. Please retry or contact support.');
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
