<?php
/**
 * /twilio-webhook (P5-M08): what Twilio sends back. Public, so every call
 * is checked against Twilio's signature over the agency's own address
 * (config app_url and the path asked for), never the Host header.
 *   ?kind=status   a text's delivery status
 *   ?kind=inbound  a reply (or STOP)
 *   ?kind=call     a call's status
 *   ?kind=voice    what to do when the staff member answers: connect the person
 */

declare(strict_types=1);

require_once __DIR__ . '/../twilio.php';

header('Content-Type: text/xml; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('<?xml version="1.0" encoding="UTF-8"?><Response/>');
}
$kind = (string) ($_GET['kind'] ?? '');
if (! in_array($kind, ['status', 'inbound', 'call', 'voice'], true)) {
    http_response_code(404);
    exit('<?xml version="1.0" encoding="UTF-8"?><Response/>');
}
$app = parse_url((string) ($config['app_url'] ?? ''));
$origin = ($app['scheme'] ?? 'https') . '://' . ($app['host'] ?? '') . (isset($app['port']) ? ':' . $app['port'] : '');
[$status, $xml] = tw_webhook($kind, $_POST, (string) ($_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? ''), $origin . (string) ($_SERVER['REQUEST_URI'] ?? ''));
http_response_code($status);
echo $xml;
