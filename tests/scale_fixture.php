<?php
/** Synthetic volume only. Explicit safety guard rejects a customer database. */
require __DIR__.'/test-app/app/bootstrap.php';
if(PHP_SAPI!=='cli'||($config['db_name']??'')!=='rss_ops_test'||getenv('WORKFORCE_SYNTHETIC_TEST')!=='1')exit('Synthetic test database required');
$projects=[];$pdo=db();$pdo->beginTransaction();
q("INSERT INTO clients(name) VALUES ('Synthetic scale client')");$client=(int)$pdo->lastInsertId();
$candidate=$pdo->prepare('INSERT INTO candidates(full_name,email,source) VALUES (?,?,?)');
$placement=$pdo->prepare("INSERT INTO placements(candidate_id,job_id,status,start_date) VALUES (?,?,'on_site','2026-10-01')");
$profile=$pdo->prepare('INSERT INTO employee_profiles(candidate_id) VALUES (?)');
for($project=1;$project<=10;$project++) {
    q("INSERT INTO jobs(client_id,title,status,site_city,site_state) VALUES (?,?,'active','Synthetic city','TX')",[$client,'Synthetic project '.$project]);$job=(int)$pdo->lastInsertId();$projects[]=$job;
    q('INSERT INTO vacancies(job_id,title,description) VALUES (?,?,?)',[$job,'Synthetic vacancy '.$project,'Synthetic qualifications']);$vacancy=(int)$pdo->lastInsertId();
    for($worker=1;$worker<=1000;$worker++) {
        $candidate->execute(['Synthetic worker '.$project.' '.str_pad((string)$worker,4,'0',STR_PAD_LEFT),'synthetic-'.$project.'-'.$worker.'@test.invalid','Synthetic test']);$cid=(int)$pdo->lastInsertId();
        $placement->execute([$cid,$job]);$profile->execute([$cid]);q('INSERT INTO applications(candidate_id,vacancy_id) VALUES (?,?)',[$cid,$vacancy]);
    }
}
$pdo->commit();echo json_encode(['projects'=>$projects,'synthetic_workers'=>10000]);
