<?php
/**
 * Copy to config.php and fill in. config.php is never committed.
 */
return [
    'db_host'  => 'localhost',
    'db_name'  => 'rssops',
    'db_user'  => 'rssops',
    'db_pass'  => '',
    'app_name' => 'Crewvia',
    'app_url' => 'https://staffing.example.com',
    // Generate with PHP: echo base64_encode(random_bytes(32)); Keep outside public/.
    'encryption_key' => '',
    // Minutes of inactivity before a session ends. 0 turns it off.
    'idle_timeout_minutes' => 30,
    'debug'    => false,
    'commercial_mode' => 'demo',
    'email_delivery_enabled' => false,
    'ai_enabled' => false,
    'recruiting_webhooks' => [],
    'docusign' => ['enabled' => false],
];
