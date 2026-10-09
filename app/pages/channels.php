<?php
require_role('recruiter');$jobId=(int)(current_job()['id'] ?? 0);
$catalog=['google_jobs'=>['Google Jobs','Structured job pages · indexing acceptance required'],
    'linkedin'=>['LinkedIn','Talent Solutions partner access required'],
    'indeed'=>['Indeed','ATS partner access and Indeed Apply integration required'],
    'handshake'=>['Handshake','Employer integration availability must be confirmed'],
    'simplify'=>['Simplify','Employer distribution/integration availability must be confirmed'],
    'flexjobs'=>['FlexJobs','Employer distribution/integration availability must be confirmed'],
    'ai_apply'=>['AI Apply','Employer distribution/integration availability must be confirmed'],
    'zoho'=>['Zoho Recruit','Optional ATS connector · not required for internal recruiting']];
if($_SERVER['REQUEST_METHOD']==='POST') {
    $id=(int)($_POST['vacancy_id'] ?? 0);$channel=$_POST['channel'] ?? '';
    if(!isset($catalog[$channel]) || !row('SELECT id FROM vacancies WHERE id=? AND job_id=?',[$id,$jobId])) {refuse(422, t('Invalid channel or requisition.'));}
    q('INSERT IGNORE INTO recruitment_channel_requests(vacancy_id,channel,requested_by) VALUES (?,?,?)',[$id,$channel,uid()]);log_activity('requested recruitment channel','vacancy',$id,$channel);redirect('/channels');
}
$vacancies=rows('SELECT id,title FROM vacancies WHERE job_id=? ORDER BY id DESC',[$jobId]);
$requests=rows('SELECT r.*,v.title FROM recruitment_channel_requests r JOIN vacancies v ON v.id=r.vacancy_id WHERE v.job_id=? ORDER BY r.id DESC',[$jobId]);
render('channels',compact('catalog','vacancies','requests'));
