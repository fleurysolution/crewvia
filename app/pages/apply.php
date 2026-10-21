<?php
$id=(int)($_GET['id'] ?? $_POST['vacancy_id'] ?? 0);
$vacancy=row("SELECT v.*,COALESCE(v.pay_rate,l.pay_rate) pay_rate,COALESCE(v.per_diem_rate,l.per_diem_rate) per_diem_rate,j.title project,j.site_city,j.site_state,j.lodging_provided,j.travel_provided,j.transport_provided,COALESCE(v.guarantee_hours,IF(j.strike_live AND l.strike_guarantee_hours IS NOT NULL,l.strike_guarantee_hours,l.guarantee_hours)) guarantee,r.published_on,r.expires_on,r.employment_type,s.timezone FROM vacancies v JOIN jobs j ON j.id=v.job_id LEFT JOIN job_order_lines l ON l.id=v.order_line_id LEFT JOIN requisition_publication r ON r.vacancy_id=v.id LEFT JOIN project_operating_settings s ON s.job_id=j.id WHERE v.id=? AND v.is_open=1 AND j.status<>'closed' AND (r.expires_on IS NULL OR r.expires_on>=CURDATE())",[$id]);
if(!$vacancy) { refuse(404, t('Vacancy unavailable.')); }
require_once __DIR__.'/../intake.php';
// P5-M102: a refused application comes back with its entries and the reason, not as a line of text on a blank page.
$applyError=$applyError??null;
if($_SERVER['REQUEST_METHOD']==='POST'&&$applyError===null){$applyError=intake_problem();if($applyError===null){intake_receive($id);exit;}}
$old=$_SERVER['REQUEST_METHOD']==='POST'?$_POST:[];if($applyError!==null)http_response_code(422);
require __DIR__.'/../job-publication.php';$jobSchema=workforce_job_schema($vacancy,$config);
require_once __DIR__.'/../screening.php';
// What this role asks everybody. The answers are scored on submission.
$screening=screening_questions((int)$vacancy['job_id'],(int)$vacancy['id']);
render('apply',compact('vacancy','jobSchema','screening','applyError','old'));
