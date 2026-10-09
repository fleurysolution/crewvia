<?php
require_role('supervisor');
$team=rows("SELECT p.id,p.status,c.full_name,d.trade,d.shift_label,j.title FROM assignment_details d JOIN placements p ON p.id=d.placement_id JOIN candidates c ON c.id=p.candidate_id JOIN jobs j ON j.id=p.job_id WHERE d.supervisor_id=? AND p.status NOT IN ('completed','cancelled') ORDER BY j.title,c.full_name",[uid()]);
render('my-team',compact('team'));
