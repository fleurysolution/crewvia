<?php
require __DIR__.'/../job-publication.php';header('Content-Type: application/json; charset=utf-8');
$vacancies=rows("SELECT v.*,j.site_city,j.site_state,p.published_on,p.expires_on,p.employment_type FROM vacancies v JOIN jobs j ON j.id=v.job_id JOIN requisition_publication p ON p.vacancy_id=v.id WHERE v.is_open=1 AND j.status<>'closed' AND p.expires_on>=CURDATE() ORDER BY v.id LIMIT 1000");
$jobs=[];foreach($vacancies as $vacancy) {$schema=workforce_job_schema($vacancy,$config);if($schema)$jobs[]=$schema;}
echo json_encode(['format'=>'workforce-public-jobs-v1','jobs'=>$jobs],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
