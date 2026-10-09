<?php
function offboarding_pending(int $placementId,int $jobId): int
{
    return (int)val("SELECT COUNT(*) FROM offboarding_requirements r LEFT JOIN offboarding_tasks t ON t.requirement_id=r.id AND t.placement_id=? WHERE r.job_id=? AND (t.status IS NULL OR t.status<>'approved')",[$placementId,$jobId]);
}
