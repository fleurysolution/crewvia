<?php
function workforce_job_schema(array $vacancy, array $configuration): ?array
{
    $organization=trim((string)($configuration['hiring_organization_name'] ?? ''));
    if(!$organization || empty($vacancy['published_on']) || empty($vacancy['expires_on']) || empty($vacancy['site_city']) || empty($vacancy['site_state']) || empty($vacancy['is_open'])) return null;
    if($vacancy['expires_on']<date('Y-m-d')) return null;
    $url=rtrim((string)($configuration['app_url'] ?? ''),'/').'/apply?id='.(int)$vacancy['id'];
    if(parse_url($url,PHP_URL_SCHEME)!=='https') return null;
    return ['@context'=>'https://schema.org','@type'=>'JobPosting','title'=>$vacancy['title'],
        'description'=>$vacancy['description'],'datePosted'=>$vacancy['published_on'],'validThrough'=>(new DateTimeImmutable($vacancy['expires_on'].' 23:59:59',new DateTimeZone($vacancy['timezone'] ?? 'America/New_York')))->format(DATE_ATOM),
        'employmentType'=>$vacancy['employment_type'] ?? 'TEMPORARY','url'=>$url,
        'identifier'=>['@type'=>'PropertyValue','name'=>$organization,'value'=>(string)$vacancy['id']],
        'hiringOrganization'=>['@type'=>'Organization','name'=>$organization],
        'jobLocation'=>['@type'=>'Place','address'=>['@type'=>'PostalAddress','addressLocality'=>$vacancy['site_city'],'addressRegion'=>$vacancy['site_state'],'addressCountry'=>'US']]];
}
