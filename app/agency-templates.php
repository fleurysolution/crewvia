<?php
/** Generic starting checklists; agency reviewers select what actually applies. */
function agency_templates(): array
{
    return [
        'warehouse'=>['name'=>'Warehouse staffing','questions'=>['Which warehouse equipment have you operated?','Which shifts and locations are you available for?'],'qualifications'=>['Forklift authorization'=>true],'tasks'=>['Site induction','PPE and equipment acknowledgement']],
        'production'=>['name'=>'Production & manufacturing','questions'=>['Describe your production or machine operating experience.','Which shifts and locations are you available for?'],'qualifications'=>[],'tasks'=>['Site induction','Machine safety orientation','PPE and equipment acknowledgement']],
        'skilled_trades'=>['name'=>'Skilled trades','questions'=>['Describe your trade experience and the work you can perform.','Which required licences or qualifications do you currently hold?'],'qualifications'=>['Trade qualification'=>true],'tasks'=>['Site induction','PPE and equipment acknowledgement']],
        'drivers'=>['name'=>'Driver staffing','questions'=>['What licence class and endorsements do you hold?','Describe your relevant driving and employment history.'],'qualifications'=>['CDL qualification'=>true,'Medical qualification review'=>true,'Driving record review'=>true,'Employment history review'=>false],'tasks'=>['Vehicle use agreement','Site driving and route orientation']],
        'strike'=>['name'=>'Strike replacement','questions'=>['Which roles, shifts and travel dates are you available for?','Can you meet the project mobilisation requirements?'],'qualifications'=>[],'tasks'=>['Site induction','Transport and accommodation briefing','Site safety and conduct briefing','PPE and equipment acknowledgement']],
        'security'=>['name'=>'Site security staffing','questions'=>['Describe your relevant site security experience.','Which security licences do you hold for the work location?'],'qualifications'=>['Security licence'=>true],'tasks'=>['Site risk and access briefing','Incident escalation briefing','Site safety and conduct briefing']],
    ];
}
