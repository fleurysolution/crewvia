<?php
/** A documented middleware contract, not a claim of native job-board certification. */
function recruiting_webhook_valid(string $body,string $stamp,string $signature,string $secret,?int $now=null): bool
{
    if(strlen($secret)<32||strlen($body)>131072||!ctype_digit($stamp)||abs(($now??time())-(int)$stamp)>300||!preg_match('/^[a-f0-9]{64}$/D',$signature))return false;
    return hash_equals(hash_hmac('sha256',$stamp.'.'.$body,$secret),$signature);
}
function recruiting_webhook_payload(string $body): array
{
    $event=json_decode($body,true,16,JSON_THROW_ON_ERROR);
    if(!is_array($event)||($event['schema']??'')!=='workforce.application.v1')throw new InvalidArgumentException('Unsupported application schema.');
    foreach(['event_id','name','email'] as $key)if(!isset($event[$key])||!is_string($event[$key])||trim($event[$key])===''||mb_strlen($event[$key])>190)throw new InvalidArgumentException('Invalid application identity.');
    if(!filter_var($event['email'],FILTER_VALIDATE_EMAIL)||empty($event['consent'])||$event['consent']!==true||!is_int($event['vacancy_id']??null)||$event['vacancy_id']<1)throw new InvalidArgumentException('Invalid application or consent.');
    $event['phone']=$event['phone']??'';if(!is_string($event['phone'])||mb_strlen($event['phone'])>40)throw new InvalidArgumentException('Invalid application phone.');
    return $event;
}
