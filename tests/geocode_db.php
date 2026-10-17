<?php
/**
 * P5-M71: a site address turned into its position, city, state and ZIP - with the US Census geocoder (no key)
 * and with Google (a key configured). The answers are stand-ins: nothing leaves the machine.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/geocode.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
$check = static function (string $label, bool $ok, $detail = ''): void {
    if (! $ok) { fwrite(STDERR, "FAIL $label " . json_encode($detail) . "\n"); exit(1); }
    echo "PASS $label\n";
};

$asked = [];
$answer = '';
integration_transport(static function (array $req) use (&$asked, &$answer): array {
    $asked[] = $req['url'];
    return ['status' => 200, 'headers' => [], 'body' => $answer];
});

$answer = json_encode(['result' => ['addressMatches' => [[
    'matchedAddress' => '1 N BROADWAY, GARY, IN, 46402',
    'coordinates' => ['x' => -87.33781234, 'y' => 41.60361234],
    'addressComponents' => ['fromAddress' => '1', 'preDirection' => 'N', 'streetName' => 'BROADWAY', 'suffixType' => '', 'city' => 'GARY', 'state' => 'IN', 'zip' => '46402'],
]]]]);
global $config;
$config['google_maps_key'] = '';
$g = geocode_address('1 N Broadway, Gary IN');
$check('Without a key, the Census geocoder finds the site: its position, city, state and ZIP', $g !== null && $g['lat'] == 41.603612 && $g['lng'] == -87.337812
       && $g['city'] === 'Gary' && $g['state'] === 'IN' && $g['zip'] === '46402' && $g['address'] === '1 N Broadway' && $g['source'] === 'census', $g);
$check('Only the address is sent, to the Census geocoder', str_starts_with($asked[0], 'https://geocoding.geo.census.gov/') && str_contains($asked[0], 'address=1+N+Broadway%2C+Gary+IN'), $asked);

$answer = json_encode(['result' => ['addressMatches' => []]]);
$check('An address the geocoder does not know is not found', geocode_address('99999 Nowhere Road, Nowhere') === null);
$n = count($asked);
$check('A too short address is not sent at all', geocode_address('abc') === null && count($asked) === $n);

$config['google_maps_key'] = 'test-key-not-real';
$answer = json_encode(['results' => [[
    'formatted_address' => '1 N Broadway, Gary, IN 46402, USA',
    'geometry' => ['location' => ['lat' => 41.6036, 'lng' => -87.3378]],
    'address_components' => [
        ['long_name' => '1', 'short_name' => '1', 'types' => ['street_number']],
        ['long_name' => 'North Broadway', 'short_name' => 'N Broadway', 'types' => ['route']],
        ['long_name' => 'Gary', 'short_name' => 'Gary', 'types' => ['locality', 'political']],
        ['long_name' => 'Indiana', 'short_name' => 'IN', 'types' => ['administrative_area_level_1', 'political']],
        ['long_name' => '46402', 'short_name' => '46402', 'types' => ['postal_code']],
    ],
]], 'status' => 'OK']);
$g = geocode_address('1 N Broadway, Gary IN');
$check('With a Google key, Google finds it', $g !== null && $g['lat'] == 41.6036 && $g['city'] === 'Gary' && $g['state'] === 'IN' && $g['zip'] === '46402' && $g['source'] === 'google'
       && str_starts_with(end($asked), 'https://maps.googleapis.com/maps/api/geocode/json?'), $g);
