<?php
require_role('recruiter','payroll');$search=trim((string)($_GET['q'] ?? ''));$page=max(1,(int)($_GET['page'] ?? 1));$offset=($page-1)*100;
$employees=rows("SELECT c.id,c.full_name,c.email,c.phone,e.employment_type,e.availability,e.rehire_status,(SELECT COUNT(*) FROM placements p WHERE p.candidate_id=c.id AND p.status IN ('confirmed','travelling','on_site')) deployments FROM candidates c LEFT JOIN employee_profiles e ON e.candidate_id=c.id WHERE c.full_name LIKE ? OR c.email LIKE ? ORDER BY c.full_name LIMIT 100 OFFSET ".$offset,['%'.$search.'%','%'.$search.'%']);
render('employees',compact('employees','search','page'));
