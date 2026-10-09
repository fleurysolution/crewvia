<?php
// Merge into config.php. Each tenant file must be outside public/.
return [
    'tenant_hosts'=>[
        'agency-a.workforce.example.com'=>'agency-a.php',
        'agency-b.workforce.example.com'=>'agency-b.php',
    ],
];
