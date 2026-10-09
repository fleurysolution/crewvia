<?php
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit;}
header('Content-Type: application/xml; charset=utf-8');
$base=rtrim((string)($config['app_url']??''),'/');
echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
if(parse_url($base,PHP_URL_SCHEME)==='https') {
    foreach(rows("SELECT v.id FROM vacancies v JOIN jobs j ON j.id=v.job_id JOIN requisition_publication p ON p.vacancy_id=v.id WHERE v.is_open=1 AND j.status<>'closed' AND p.expires_on>=CURDATE() ORDER BY v.id") as $job)echo '<url><loc>'.htmlspecialchars($base.'/apply?id='.$job['id'],ENT_XML1|ENT_QUOTES,'UTF-8').'</loc></url>';
}
echo '</urlset>';
