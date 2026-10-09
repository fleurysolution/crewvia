<?php
// Merge these values into config.php. Keep config.php outside the public root.
return [
 'google_client_id'=>'',
 'google_client_secret'=>'',
 'google_redirect_uri'=>'https://YOUR-DOMAIN/google-callback',
 // Generate once: php -r "echo base64_encode(random_bytes(32));"
 // Back up securely. Changing the key invalidates stored Gmail connections.
 'encryption_key'=>'',
];
