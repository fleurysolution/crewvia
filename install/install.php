<?php
/** Fresh commercial installation. Never imports another agency's records. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only');}
if(in_array('--reset',$argv,true))exit("Reset is disabled. Use a separate empty database.\n");
require __DIR__.'/../app/bootstrap.php';
$email=trim((string)(getenv('WORKFORCE_ADMIN_EMAIL')?:getenv('RSS_ADMIN_EMAIL')));
if(!filter_var($email,FILTER_VALIDATE_EMAIL))exit("Set WORKFORCE_ADMIN_EMAIL for the new agency administrator.\n");
$sql=file_get_contents(__DIR__.'/schema.sql');
foreach(preg_split('/;\s*\n/',$sql) as $chunk) {
    $lines=array_filter(explode("\n",$chunk),fn($line)=>!str_starts_with(ltrim($line),'--'));
    $statement=trim(implode("\n",$lines));if($statement!=='')db()->exec($statement);
}
require __DIR__.'/upgrade.php';
if(!row('SELECT id FROM users WHERE email=?',[$email])) {
    $password=bin2hex(random_bytes(12));
    q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,'admin',1)",[ucfirst(strtok($email,'@')),$email,password_hash($password,PASSWORD_DEFAULT)]);
    echo 'Administrator: '.$email.' Temporary password: '.$password.PHP_EOL;
}
echo "Blank installation ready. No legacy candidates or projects imported.\n";
