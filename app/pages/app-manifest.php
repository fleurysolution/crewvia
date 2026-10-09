<?php
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit;}
$brand=val("SELECT setting_value FROM platform_settings WHERE setting_key='brand_name'")?:$config['app_name'];
header('Content-Type: application/manifest+json; charset=utf-8');
echo json_encode(['id'=>'/','name'=>$brand,'short_name'=>mb_substr($brand,0,20),'description'=>'Workforce operations by Fleury Solutions','start_url'=>'/','scope'=>'/','display'=>'standalone','background_color'=>'#F5F7FB','theme_color'=>'#0D2137','lang'=>locale(),'icons'=>[['src'=>'/assets/app-icon-192.png','sizes'=>'192x192','type'=>'image/png','purpose'=>'any'],['src'=>'/assets/app-icon-512.png','sizes'=>'512x512','type'=>'image/png','purpose'=>'any'],['src'=>'/assets/app-icon.svg','sizes'=>'any','type'=>'image/svg+xml','purpose'=>'any']]],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
