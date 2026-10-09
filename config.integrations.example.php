<?php
// Merge into each tenant's private configuration. Never copy BPMS credentials.
return [
    'hiring_organization_name'=>'',
    'email_delivery_enabled'=>false,
    'email_from'=>'', // Approved mailbox on a verified domain; server mail relay must be configured.
    'recruiting_webhooks'=>[], // Per-partner enabled flag + random secret, see install/CONNECTOR-CONTRACT.md.
    'ai_enabled'=>false,
    'anthropic_api_key'=>getenv('WORKFORCE_ANTHROPIC_API_KEY') ?: '',
    'ai_model'=>'', // Choose a currently supported model for the configured account.
    'ai_daily_limit'=>20,
];
