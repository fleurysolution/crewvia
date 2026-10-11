<?php
require __DIR__.'/../saas.php';
header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { http_response_code(405);exit('{"error":"POST required"}'); }
[$status,$body]=stripe_webhook_handle((string)file_get_contents('php://input'),(string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''),saas_webhook_secrets());
http_response_code($status);echo $body;
