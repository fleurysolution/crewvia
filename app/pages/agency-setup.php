<?php
require_role('admin');require_once __DIR__.'/../agency-templates.php';
$templates=agency_templates();$jobId=(int)(current_job()['id']??0);
if($_SERVER['REQUEST_METHOD']==='POST') {
    $key=(string)($_POST['template']??'');$trade=trim((string)($_POST['trade_scope']??''));
    if(!$jobId||!isset($templates[$key])||mb_strlen($trade)>190){refuse(422, t('Choose an existing project and service template.'));}
    $template=$templates[$key];db()->beginTransaction();q('SELECT id FROM jobs WHERE id=? FOR UPDATE',[$jobId]);
    foreach($template['questions'] as $question)if(!row('SELECT id FROM screening_questions WHERE job_id=? AND question=?',[$jobId,$question]))q('INSERT INTO screening_questions(job_id,question) VALUES (?,?)',[$jobId,$question]);
    foreach($template['tasks'] as $task)if(!row('SELECT id FROM onboarding_requirements WHERE job_id=? AND title=?',[$jobId,$task])) {
        q('INSERT INTO onboarding_requirements(job_id,title,instructions) VALUES (?,?,?)',[$jobId,$task,'Agency must provide approved project-specific instructions.']);$rid=(int)db()->lastInsertId();
        q("INSERT IGNORE INTO onboarding_tasks(placement_id,requirement_id) SELECT id,? FROM placements WHERE job_id=? AND status NOT IN ('completed','cancelled')",[$rid,$jobId]);
    }
    foreach($template['qualifications'] as $name=>$expiry) {
        q('INSERT IGNORE INTO qualification_types(name,expires_required) VALUES (?,?)',[$name,$expiry?1:0]);$type=(int)val('SELECT id FROM qualification_types WHERE name=?',[$name]);
        q('INSERT IGNORE INTO project_qualifications(job_id,type_id) VALUES (?,?)',[$jobId,$type]);
        if($trade!=='')q('INSERT INTO project_qualification_scope(job_id,type_id,trade) VALUES (?,?,?) ON DUPLICATE KEY UPDATE trade=VALUES(trade)',[$jobId,$type,$trade]);
    }
    q('INSERT INTO platform_settings(setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',['project_service_'.$jobId,$key]);
    db()->commit();log_activity('applied agency template','project',$jobId,$key);flash(t('Service template applied. Review project instructions and requirements before recruitment.'));redirect('/agency-setup');
}
render('agency-setup',compact('templates','jobId'));
