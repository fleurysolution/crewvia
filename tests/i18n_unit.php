<?php
$appRoot=is_dir(__DIR__.'/test-app/app')?__DIR__.'/test-app/app':dirname(__DIR__).'/app';
function e($value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
$_SESSION=[];$_GET=[];$_SERVER['HTTP_ACCEPT_LANGUAGE']='fr-FR,es;q=0.9';
$want=$argv[1] ?? 'en';
if($want!=='en')$_SESSION['user']=['locale'=>$want];
require $appRoot.'/i18n.php';
$checks=[];
function check_i18n($label,$ok):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks[]=$label;echo 'PASS '.$label."\n";}
check_i18n('English default despite French browser',locale()===$want);
$fr=lang_catalogue('fr');$es=lang_catalogue('es');
foreach(['pages','views'] as $folder)foreach(['client-portal','client-access','client-orders','account-security','mfa','recover','qualifications','screening-workflow','agency-setup','hours','inbox','join','resumes','offboarding','safety-plan'] as $page){
    if(!is_file($appRoot.'/'.$folder.'/'.$page.'.php'))continue;
    preg_match_all("/\\b(?:t|te)\\(\\s*'((?:\\\\.|[^'\\\\])*)'/",file_get_contents($appRoot.'/'.$folder.'/'.$page.'.php'),$matches);
    foreach($matches[1] as $key){$key=str_replace("\\'","'",$key);check_i18n('Client page catalogue: '.$key,isset($fr[$key],$es[$key]));}
}
foreach(file($appRoot.'/lang/messages.tsv',FILE_IGNORE_NEW_LINES) as $line){$columns=explode("\t",$line);check_i18n('Message catalogues: '.$columns[0],isset($fr[$columns[0]],$es[$columns[0]])&&$fr[$columns[0]]!==''&&$es[$columns[0]]!=='');}
$message=t('Call logged for :name.',['name'=>'Example']);
check_i18n('Named interpolation substitutes',str_contains($message,'Example')&&!str_contains($message,':name'));
check_i18n('Translated output escapes HTML',!str_contains(te('Call logged for :name.',['name'=>'<script>']),'<script>'));
check_i18n('Client screens translated',($want==='en')||t('Client requests')!=='Client requests');
file_put_contents(__DIR__.'/i18n-unit-'.$want.'.json',json_encode($checks,JSON_PRETTY_PRINT));
