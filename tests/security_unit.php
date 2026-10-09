<?php
declare(strict_types=1);
$root=is_dir(__DIR__.'/test-app')?__DIR__.'/test-app':dirname(__DIR__);
require $root.'/app/security.php';require $root.'/app/gmail.php';
$results=[];
function verify_case(string $name,bool $pass): void{global $results;if(!$pass)throw new RuntimeException($name);$results[]=$name;echo 'PASS '.$name.PHP_EOL;}
$secret=totp_base32('12345678901234567890');
verify_case('Base32 round trip',totp_decode($secret)==='12345678901234567890');
foreach([59=>'94287082',1111111109=>'07081804',1111111111=>'14050471',1234567890=>'89005924',2000000000=>'69279037',20000000000=>'65353130'] as $time=>$expected)verify_case('RFC 6238 vector '.$time,totp_code($secret,intdiv($time,30),8)===$expected);
verify_case('Current authenticator code accepted',totp_match($secret,'287082',-1,59)===1);
verify_case('Used authenticator code cannot replay',totp_match($secret,'287082',1,59)===null);
verify_case('Malformed authenticator code rejected',totp_match($secret,'287082\n',-1,59)===null);
verify_case('Stale authenticator code rejected',totp_match($secret,'287082',-1,300)===null);
$mime=gmail_compose('office@test.invalid','candidate@test.invalid',"Hello\r\nBcc: victim@test.invalid",'Private body');
verify_case('Subject cannot inject mail headers',!str_contains($mime,"\r\nBcc:"));
verify_case('Email body encoded independently',str_contains($mime,base64_encode('Private body')));
try{gmail_compose("office@test.invalid\r\nBcc: victim@test.invalid",'candidate@test.invalid','Hi','Hello');$rejected=false;}catch(InvalidArgumentException $e){$rejected=true;}
verify_case('Address header injection rejected',$rejected);
file_put_contents(__DIR__.'/security-unit-results.json',json_encode($results,JSON_PRETTY_PRINT));
