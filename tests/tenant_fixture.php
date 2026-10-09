<?php
require __DIR__.'/test-app/app/bootstrap.php';
if(!in_array($config['db_name'],['workforce_alpha_test','workforce_bravo_test'],true)||getenv('WORKFORCE_SYNTHETIC_TEST')!=='1')exit('Synthetic database required');
$label=$config['tenant_id'];q('UPDATE users SET password_hash=?,must_change_pw=0',[password_hash('TenantTestPassword123!',PASSWORD_DEFAULT)]);
q('INSERT INTO clients(name) VALUES (?)',[$label.' private client']);$client=(int)db()->lastInsertId();
q('INSERT INTO jobs(client_id,title) VALUES (?,?)',[$client,$label.' private project']);
q('INSERT INTO candidates(full_name,email) VALUES (?,?)',[$label.' private candidate','candidate@'.$label.'.test']);
echo "Synthetic tenant fixture ready\n";
