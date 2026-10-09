<?php
/** A configured requirement needs an independently reviewed, current record. */
function qualification_gaps(int $candidateId,int $jobId,?string $until=null): array
{
    $until=$until??date('Y-m-d');
    return rows("SELECT t.name,r.required_detail FROM project_qualifications r JOIN qualification_types t ON t.id=r.type_id LEFT JOIN candidate_qualifications c ON c.type_id=r.type_id AND c.candidate_id=? LEFT JOIN project_qualification_scope scope ON scope.job_id=r.job_id AND scope.type_id=r.type_id WHERE r.job_id=? AND (scope.trade IS NULL OR EXISTS(SELECT 1 FROM placements p LEFT JOIN assignment_details d ON d.placement_id=p.id WHERE p.job_id=r.job_id AND p.candidate_id=? AND p.status NOT IN ('completed','cancelled') AND (d.trade IS NULL OR TRIM(d.trade)='' OR LOWER(TRIM(d.trade))=LOWER(TRIM(scope.trade))))) AND (c.id IS NULL OR c.status<>'verified' OR (c.issued_on IS NOT NULL AND c.issued_on>CURDATE()) OR (t.expires_required=1 AND c.expires_on IS NULL) OR (c.expires_on IS NOT NULL AND c.expires_on<?) OR (r.required_detail<>'' AND LOWER(TRIM(r.required_detail))<>LOWER(TRIM(c.detail))))",[$candidateId,$jobId,$candidateId,$until]);
}
