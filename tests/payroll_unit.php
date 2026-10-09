<?php
require (is_dir(__DIR__.'/test-app')?__DIR__.'/test-app':dirname(__DIR__)).'/app/payroll-calculation.php';
$cases=[];
function payroll_assert(string $name,bool $result): void{global $cases;if(!$result)throw new RuntimeException($name);$cases[]=$name;echo 'PASS '.$name.PHP_EOL;}
$a=payroll_gross(41,50,50);payroll_assert('Existing guarantee retained',$a['labour_cost']===2500.0);
$a=payroll_gross(41,50,50,40,1.5);payroll_assert('Worked overtime added above guarantee regular floor',$a['overtime_hours']===1.0&&$a['regular_hours']===49.0&&$a['labour_cost']===2525.0);
$a=payroll_gross(32,50,50,40,1.5);payroll_assert('Unworked guarantee does not create overtime',$a['overtime_hours']===0.0&&$a['labour_cost']===2500.0);
$a=payroll_gross(48,0,20,40,1.5);payroll_assert('Weekly overtime arithmetic',$a['labour_cost']===1040.0);
$a=payroll_gross(38,0,20,null,1.5,1500);payroll_assert('Salary basis retained',$a['labour_cost']===1500.0);
$a=payroll_gross(45,0,20,40,1.5,1500);payroll_assert('Salaried overtime requires provider reconciliation',$a['requires_provider_review']&&$a['labour_cost']===1500.0);
try{payroll_gross(NAN,0,20);$failed=false;}catch(InvalidArgumentException $e){$failed=true;}payroll_assert('Invalid amounts rejected',$failed);
file_put_contents(__DIR__.'/payroll-unit-results.json',json_encode($cases,JSON_PRETTY_PRINT));
