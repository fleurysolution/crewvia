<?php
$appRoot=is_dir(__DIR__.'/test-app/app')?__DIR__.'/test-app/app':dirname(__DIR__).'/app';
require $appRoot.'/ai-review.php';
require $appRoot.'/job-publication.php';
$checks=[];
function check_recruiting(string $name,bool $ok):void {global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;echo 'PASS '.$name."\n";}
function invalid_review(callable $f):bool{try{$f();return false;}catch(Throwable $e){return true;}}
$input=workforce_ai_input('Welding experience required','Five years welding. Contact person@example.com or 555-123-4567.');
check_recruiting('AI evidence removes email and phone',!str_contains($input['reviewer_supplied_qualifications'],'person@example.com')&&!str_contains($input['reviewer_supplied_qualifications'],'555-123-4567'));
check_recruiting('AI rejects oversized evidence',invalid_review(fn()=>workforce_ai_input('Criteria',str_repeat('x',12001))));
$result=['summary'=>'Review evidence','matched_requirements'=>['Welding'],'missing_evidence'=>['Availability'],'screening_questions'=>['What dates are available?'],'draft_message'=>'Please confirm availability.','score'=>100,'reject'=>true];
$safe=workforce_ai_result(json_encode($result));
check_recruiting('AI does not consume hiring commands',!isset($safe['score'])&&!isset($safe['reject']));
check_recruiting('AI rejects malformed provider output',invalid_review(fn()=>workforce_ai_result('{"summary":"x"}')));
$vacancy=['id'=>1,'title'=>'Welder','description'=>'Welding experience required','published_on'=>date('Y-m-d'),'expires_on'=>date('Y-m-d',time()+86400),'site_city'=>'Houston','site_state'=>'TX','is_open'=>1,'timezone'=>'America/Chicago'];
$config=['app_url'=>'https://agency.example','hiring_organization_name'=>'Synthetic agency'];
check_recruiting('Google job data identifies hiring organization',workforce_job_schema($vacancy,$config)['hiringOrganization']['name']==='Synthetic agency');
check_recruiting('Google job data requires legal hiring organization',workforce_job_schema($vacancy,['app_url'=>'https://agency.example'])===null);
check_recruiting('Google job data requires HTTPS',workforce_job_schema($vacancy,array_replace($config,['app_url'=>'http://agency.example']))===null);
check_recruiting('Expired Google job data is suppressed',workforce_job_schema(array_replace($vacancy,['expires_on'=>'2000-01-01']),$config)===null);
check_recruiting('Closed Google job data is suppressed',workforce_job_schema(array_replace($vacancy,['is_open'=>0]),$config)===null);
file_put_contents(__DIR__.'/recruiting-unit-results.json',json_encode($checks,JSON_PRETTY_PRINT));
