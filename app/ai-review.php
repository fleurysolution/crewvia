<?php
function workforce_ai_input(string $criteria, string $evidence): array
{
    if(!$criteria || !$evidence || mb_strlen($criteria)>10000 || mb_strlen($evidence)>12000) throw new RuntimeException('Provide bounded job criteria and job-relevant evidence.');
    $evidence=preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i','[contact removed]',$evidence);
    $evidence=preg_replace('/\b\+?\d[\d ()-]{7,}\d\b/','[number removed]',$evidence);
    return ['job_criteria'=>$criteria,'reviewer_supplied_qualifications'=>$evidence];
}
function workforce_ai_result(string $text): array
{
    $text=trim($text);if(str_starts_with($text,'```')) $text=preg_replace('/^```(?:json)?\s*|\s*```$/','',$text);
    $result=json_decode($text,true,32,JSON_THROW_ON_ERROR);
    if(!is_array($result) || !is_string($result['summary'] ?? null)) throw new RuntimeException('AI returned an invalid review.');
    foreach(['matched_requirements','missing_evidence','screening_questions'] as $key) {
        if(!is_array($result[$key] ?? null) || count($result[$key])>20) throw new RuntimeException('AI returned an invalid review.');
        foreach($result[$key] as $item) if(!is_string($item) || mb_strlen($item)>2000) throw new RuntimeException('AI returned an invalid review.');
    }
    if(!is_string($result['draft_message'] ?? null) || mb_strlen($result['summary'])>4000 || mb_strlen($result['draft_message'])>4000) throw new RuntimeException('AI returned an invalid review.');
    // Never consume any model-provided score, rejection or hiring command.
    return array_intersect_key($result,array_flip(['summary','matched_requirements','missing_evidence','screening_questions','draft_message']));
}
