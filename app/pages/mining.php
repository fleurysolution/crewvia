<?php
require_role('recruiter');$query=trim((string)($_GET['q'] ?? ''));
$matches=$query?rows('SELECT id,full_name,discipline,degree,city,state,stage FROM candidates WHERE full_name LIKE ? OR degree LIKE ? OR resume_text LIKE ? OR notes LIKE ? ORDER BY full_name LIMIT 100',array_fill(0,4,'%'.str_replace(['%','_'],['\%','\_'],$query).'%')):[];
render('mining',compact('query','matches'));
