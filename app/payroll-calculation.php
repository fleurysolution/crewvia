<?php
/** Explicit gross-pay policy, before taxes/deductions handled by the payroll provider. */
function payroll_gross(float $worked,float $guarantee,float $rate,?float $threshold=null,float $multiplier=1.5,?float $salary=null): array
{
    foreach([$worked,$guarantee,$rate,$multiplier] as $number)if(!is_finite($number)||$number<0)throw new InvalidArgumentException('Invalid payroll inputs.');
    if($threshold!==null&&(!is_finite($threshold)||$threshold<0)||$multiplier<1||$salary!==null&&(!is_finite($salary)||$salary<0))throw new InvalidArgumentException('Invalid payroll policy.');
    $paid=max($worked,$guarantee);$overtime=$threshold===null?0.0:max(0.0,$worked-$threshold);
    $requiresReview=$salary!==null&&$overtime>0;
    // Guarantee hours do not create worked overtime. Salaried treatment requires provider review.
    $regular=$paid-$overtime;
    return ['regular_hours'=>$regular,'overtime_hours'=>$overtime,'overtime_multiplier'=>$multiplier,'labour_cost'=>round($salary??($regular*$rate+$overtime*$rate*$multiplier),2),'salary_basis'=>$salary,'requires_provider_review'=>$requiresReview];
}
