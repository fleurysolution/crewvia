<?php
// Copy outside public/ into tenants/agency-a.php; unique database/user/key per agency.
return [
    'tenant_id'=>'agency-a',
    'db_host'=>'localhost', 'db_name'=>'workforce_agency_a', 'db_user'=>'workforce_agency_a', 'db_pass'=>'',
    'app_name'=>'Crewvia', 'app_url'=>'https://agency-a.workforce.example.com',
    'encryption_key'=>'', 'debug'=>false, 'secure_cookies'=>true,
    'commercial_mode'=>'demo', // demo, subscription, dedicated. Platform-controlled configuration.
    'stripe_secret_key'=>getenv('WORKFORCE_STRIPE_SECRET_KEY') ?: '',
    'stripe_webhook_secret'=>getenv('WORKFORCE_AGENCY_A_WEBHOOK_SECRET') ?: '',
    'stripe_price_id'=>'', // Approved recurring per-seat price for this contract.
    'saas_enforce_subscription'=>false, // Enable only after real test-mode acceptance.
];
