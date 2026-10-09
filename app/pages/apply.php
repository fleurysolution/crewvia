<?php
$id=(int)($_GET['id'] ?? $_POST['vacancy_id'] ?? 0);
$vacancy=row("SELECT v.*,j.title project,j.site_city,j.site_state,r.published_on,r.expires_on,r.employment_type,s.timezone FROM vacancies v JOIN jobs j ON j.id=v.job_id LEFT JOIN requisition_publication r ON r.vacancy_id=v.id LEFT JOIN project_operating_settings s ON s.job_id=j.id WHERE v.id=? AND v.is_open=1 AND j.status<>'closed' AND (r.expires_on IS NULL OR r.expires_on>=CURDATE())",[$id]);
if(!$vacancy) { refuse(404, t('Vacancy unavailable.')); }
require_once __DIR__.'/../intake.php';
if($_SERVER['REQUEST_METHOD']==='POST'){intake_receive($id);exit;}
require __DIR__.'/../job-publication.php';$jobSchema=workforce_job_schema($vacancy,$config);
require_once __DIR__.'/../screening.php';
// What this role asks everybody. The answers are scored on submission.
$screening=screening_questions((int)$vacancy['job_id'],(int)$vacancy['id']);
render('apply',compact('vacancy','jobSchema','screening'));
