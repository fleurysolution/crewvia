<?php
/**
 * P5-M71: a site address turned into its position, for the clock-in geofence (P5-M67), and its city, state
 * and ZIP filled in (RSS, 17 Oct 2026: "give the system the site address, and the latitude and longitude
 * pull automatically").
 *
 * With a Google Maps key in the configuration (google_maps_key, never in the repository), Google's
 * Geocoding API is used; without one, the US Census Bureau geocoder, which needs no key and covers US
 * street addresses. Either way the address is sent and nothing else.
 */

declare(strict_types=1);

require_once __DIR__ . '/integrations.php';

/** The address found: ['lat', 'lng', 'address', 'city', 'state', 'zip', 'source'], or null. */
function geocode_address(string $address): ?array
{
    global $config;
    $address = trim(preg_replace('/\s+/', ' ', $address));
    if (mb_strlen($address) < 5 || mb_strlen($address) > 250) {
        return null;
    }
    $send = integration_transport();
    $key = (string) ($config['google_maps_key'] ?? '');
    if ($key !== '') {
        $r = $send(['method' => 'GET', 'url' => 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query(['address' => $address, 'key' => $key, 'region' => 'us']),
                    'headers' => ['Accept: application/json'], 'body' => null, 'timeout' => 10]);
        $hit = json_decode((string) ($r['body'] ?? ''), true)['results'][0] ?? null;
        if (! $hit || ! isset($hit['geometry']['location']['lat'])) {
            return null;
        }
        $part = static function (string $type, string $form = 'long_name') use ($hit): string {
            foreach ($hit['address_components'] ?? [] as $c) {
                if (in_array($type, $c['types'] ?? [], true)) {
                    return (string) $c[$form];
                }
            }
            return '';
        };

        return ['lat' => round((float) $hit['geometry']['location']['lat'], 6), 'lng' => round((float) $hit['geometry']['location']['lng'], 6),
                'address' => trim($part('street_number') . ' ' . $part('route')), 'city' => $part('locality') ?: $part('sublocality') ?: $part('administrative_area_level_3'),
                'state' => $part('administrative_area_level_1', 'short_name'), 'zip' => $part('postal_code'), 'matched' => (string) ($hit['formatted_address'] ?? ''), 'source' => 'google'];
    }
    $r = $send(['method' => 'GET', 'url' => 'https://geocoding.geo.census.gov/geocoder/locations/onelineaddress?' . http_build_query(['address' => $address, 'benchmark' => 'Public_AR_Current', 'format' => 'json']),
                'headers' => ['Accept: application/json'], 'body' => null, 'timeout' => 15]);
    $hit = json_decode((string) ($r['body'] ?? ''), true)['result']['addressMatches'][0] ?? null;
    if (! $hit || ! isset($hit['coordinates']['y'])) {
        return null;
    }
    $c = $hit['addressComponents'] ?? [];
    $street = trim(implode(' ', array_filter([$c['fromAddress'] ?? '', $c['preQualifier'] ?? '', $c['preDirection'] ?? '', $c['preType'] ?? '', $c['streetName'] ?? '', $c['suffixType'] ?? '', $c['suffixDirection'] ?? ''])));
    // The Census gives the house number as the range's start; the number typed is kept when there is one.
    if (preg_match('/^\s*(\d+[A-Za-z]?)\s/', $address, $m) && $street !== '') {
        $street = preg_replace('/^\d+[A-Za-z]?\s/', '', $street);
        $street = $m[1] . ' ' . $street;
    }

    return ['lat' => round((float) $hit['coordinates']['y'], 6), 'lng' => round((float) $hit['coordinates']['x'], 6), 'address' => ucwords(strtolower($street)),
            'city' => ucwords(strtolower((string) ($c['city'] ?? ''))), 'state' => strtoupper((string) ($c['state'] ?? '')), 'zip' => (string) ($c['zip'] ?? ''),
            'matched' => (string) ($hit['matchedAddress'] ?? ''), 'source' => 'census'];
}
