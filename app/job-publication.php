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

/**
 * P5-M101: the open positions anybody may see - the careers page and the feed a client's own website reads.
 * Open requisitions on running projects, unexpired; nothing about the client (a strike job is confidential).
 */
function public_openings(int $limit = 100): array
{
    return rows("SELECT v.id, v.title, v.description, v.discipline, v.openings,
                         v.shift, v.degree, v.years_experience, v.starts_on,
                         v.requirements, v.pay_rate, v.per_diem_rate, v.guarantee_hours,
                         j.site_city, j.site_state, j.starts_on AS job_starts,
                         j.description AS job_description, j.strike_live,
                         j.lodging_provided, j.travel_provided, j.transport_provided,
                         l.pay_rate AS line_pay, l.per_diem_rate AS line_diem,
                         l.guarantee_hours AS line_guarantee,
                         l.strike_guarantee_hours AS line_strike_guarantee,
                         MAX(r.published_on) AS published_on
                  FROM vacancies v
                  JOIN jobs j ON j.id = v.job_id
                  LEFT JOIN job_order_lines l ON l.id = v.order_line_id
                  LEFT JOIN requisition_publication r ON r.vacancy_id = v.id
                  WHERE v.is_open = 1 AND j.status <> 'closed'
                    AND (r.expires_on IS NULL OR r.expires_on >= CURDATE())
                  GROUP BY v.id
                  ORDER BY v.id DESC
                  LIMIT " . max(1, min(500, $limit)));
}

/**
 * What an applicant is promised for one opening: the requisition's own number, or the one on its line of the scope
 * of work - never another trade's rate - and only the cover the agreement actually includes.
 */
function public_opening_terms(array $o): array
{
    $lineGuarantee = $o['strike_live'] && $o['line_strike_guarantee'] !== null ? $o['line_strike_guarantee'] : $o['line_guarantee'];

    return [
        'pay'       => $o['pay_rate'] ?? $o['line_pay'],
        'diem'      => $o['per_diem_rate'] ?? $o['line_diem'],
        'guarantee' => $o['guarantee_hours'] ?? $lineGuarantee,
        'where'     => trim(($o['site_city'] ?? '') . ', ' . ($o['site_state'] ?? ''), ' ,'),
        'start'     => $o['starts_on'] ?: $o['job_starts'],
        'hotel'     => ! empty($o['lodging_provided']),
        'travel'    => ! empty($o['travel_provided']),
        'transport' => ! empty($o['transport_provided']),
    ];
}
