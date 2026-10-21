<?php
/**
 * P5-M101: the open positions as JSON, for the agency's own website. The site lists them in its own design; the
 * "Apply" button opens the Crewvia application for that requisition, so every applicant lands in Crewvia.
 *
 * The same positions and the same terms as /careers - pay, guaranteed hours, daily allowance, the cover actually
 * agreed - and nothing about the client. Public, read-only, readable from any website (CORS), cached 5 minutes.
 * /job-feed stays the Indeed feed (published requisitions only). The RSS website (VeloraWeb) reads this feed: its
 * fields are frozen - see docs/implementation/P5-M101_REPORT.md before renaming or removing one.
 */

declare(strict_types=1);

require_once __DIR__ . '/../job-publication.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET only']);
    exit;
}

$base = rtrim((string) ($config['app_url'] ?? ''), '/');
$num = static fn($v): ?float => $v === null || $v === '' ? null : round((float) $v, 2);
$jobs = [];
foreach (public_openings(200) as $o) {
    $terms = public_opening_terms($o);
    $jobs[] = [
        'id'                    => (int) $o['id'],
        'title'                 => (string) $o['title'],
        'discipline'            => (string) ($o['discipline'] ?: 'other'),
        'city'                  => (string) ($o['site_city'] ?? ''),
        'state'                 => (string) ($o['site_state'] ?? ''),
        'shift'                 => $o['shift'] !== null && $o['shift'] !== '' ? (string) $o['shift'] : null,
        'starts_on'             => $terms['start'] ?: null,
        'openings'              => (int) $o['openings'],
        'summary'               => (string) ($o['description'] ?? ''),
        'about'                 => (string) ($o['job_description'] ?? ''),
        'years_experience'      => $o['years_experience'] !== null ? (int) $o['years_experience'] : null,
        'degree'                => $o['degree'] !== null && $o['degree'] !== '' ? (string) $o['degree'] : null,
        'requirements'          => $o['requirements'] !== null && $o['requirements'] !== '' ? (string) $o['requirements'] : null,
        'pay_per_hour'          => $num($terms['pay']),
        'guaranteed_hours_week' => $terms['guarantee'] !== null ? (int) $terms['guarantee'] : null,
        'per_diem_per_day'      => $num($terms['diem']),
        'hotel_paid'            => $terms['hotel'],
        'travel_paid'           => $terms['travel'],
        'transport_on_site'     => $terms['transport'],
        'posted_on'             => $o['published_on'] ?: null,
        'apply_url'             => $base . '/apply?id=' . (int) $o['id'],
    ];
}

echo json_encode([
    'format'       => 'crewvia-careers-v1',
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'currency'     => 'USD',
    'careers_url'  => $base . '/careers',
    'jobs'         => $jobs,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
