<?php
/**
 * Locations (T8): where people and sites are, without calling anyone.
 *
 * Coordinates come from the US Census Bureau's 2023 Gazetteer, bundled in
 * install/gazetteer (see build-gazetteer.py there): a ZIP code when one is
 * given, otherwise the city and state. Nothing is sent to a map service.
 *
 * A candidate's coordinates are kept on the candidate (geo_lat, geo_lng)
 * with what they were worked out from (geo_basis). Whatever screen or
 * import changes a city, state or ZIP, geo_refresh_candidates() notices
 * the difference and works them out again, so no screen has to remember.
 */

declare(strict_types=1);

const GEO_EARTH_MILES = 3958.8;

/** @return array<string,string> two-letter code => name, the states the Census lists */
function geo_states(): array
{
    return ['AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut',
            'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois',
            'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
            'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska',
            'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina',
            'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'PR' => 'Puerto Rico', 'RI' => 'Rhode Island',
            'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia',
            'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming'];
}

/** "tx", "Texas", " TX." => "TX"; anything else => null. */
function geo_state_code(?string $state): ?string
{
    $s = strtoupper(trim(preg_replace('/[^A-Za-z ]/', '', (string) $state)));
    if ($s === '') {
        return null;
    }
    if (strlen($s) === 2 && isset(geo_states()[$s])) {
        return $s;
    }
    $code = array_search($s, array_map('strtoupper', geo_states()), true);

    return $code === false ? null : (string) $code;
}

/** A city as the gazetteer keys it: "St. Louis" and "Saint Louis" => "saint louis". Same rules as build-gazetteer.py. */
function geo_place_key(?string $city): string
{
    $s = (string) $city;
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        $s = $ascii !== false ? $ascii : $s;
    }
    $s = strtolower($s);
    $s = preg_replace("/[.'`\"^~]/", '', $s);
    $s = preg_replace('/[-\/,]/', ' ', $s);
    $words = preg_split('/\s+/', trim($s)) ?: [];
    $map = ['st' => 'saint', 'ste' => 'sainte', 'ft' => 'fort', 'mt' => 'mount'];

    return implode(' ', array_map(static fn($w) => $map[$w] ?? $w, array_filter($words, 'strlen')));
}

/** The five digits of a US ZIP code ("30012-4411" => "30012"), or null. */
function geo_zip(?string $zip): ?string
{
    return preg_match('/^\s*(\d{5})(?:-\d{4})?\s*$/', (string) $zip, $m) ? $m[1] : null;
}

/** @return array{lat:float,lng:float,from:string}|null where a ZIP code, or else a city and state, is */
function geo_point(?string $city, ?string $state, ?string $zip = null): ?array
{
    if (($z = geo_zip($zip)) && ($r = row('SELECT lat, lng FROM geo_zips WHERE zip = ?', [$z]))) {
        return ['lat' => (float) $r['lat'], 'lng' => (float) $r['lng'], 'from' => 'zip'];
    }
    $code = geo_state_code($state);
    $key = geo_place_key($city);
    if ($code && $key !== '' && ($r = row('SELECT lat, lng FROM geo_places WHERE state = ? AND place_key = ?', [$code, $key]))) {
        return ['lat' => (float) $r['lat'], 'lng' => (float) $r['lng'], 'from' => 'city'];
    }

    return null;
}

/** Where a project's site is, from its ZIP code or its city and state. */
function geo_site(array $job): ?array
{
    return geo_point($job['site_city'] ?? null, $job['site_state'] ?? null, $job['site_zip'] ?? null);
}

/** Works out the coordinates of every candidate whose city, state or ZIP changed since last time. Returns how many. */
/** One candidate's point, from the city, state and ZIP on their file (an application, say). */
function geo_refresh_candidate(int $candidateId): void
{
    $c = row('SELECT id, city, state, zip FROM candidates WHERE id = ?', [$candidateId]);
    if (! $c) {
        return;
    }
    $p = geo_point($c['city'], $c['state'], $c['zip']);
    q('UPDATE candidates SET geo_lat = ?, geo_lng = ?, geo_from = ?, geo_state = ?, geo_basis = ? WHERE id = ?',
      [$p['lat'] ?? null, $p['lng'] ?? null, $p['from'] ?? null, geo_state_code($c['state']),
       implode('|', [(string) ($c['city'] ?? ''), (string) ($c['state'] ?? ''), (string) ($c['zip'] ?? '')]), $candidateId]);
}

function geo_refresh_candidates(int $limit = 20000): int
{
    $basis = "CONCAT_WS('|', COALESCE(city, ''), COALESCE(state, ''), COALESCE(zip, ''))";
    $stale = rows("SELECT id, city, state, zip, $basis AS basis FROM candidates WHERE geo_basis IS NULL OR geo_basis <> $basis LIMIT " . max(1, $limit));
    foreach ($stale as $c) {
        $p = geo_point($c['city'], $c['state'], $c['zip']);
        q('UPDATE candidates SET geo_lat = ?, geo_lng = ?, geo_from = ?, geo_state = ?, geo_basis = ? WHERE id = ?',
          [$p['lat'] ?? null, $p['lng'] ?? null, $p['from'] ?? null, geo_state_code($c['state']), $c['basis'], (int) $c['id']]);
    }

    return count($stale);
}

/**
 * The distance in miles from a point to candidate alias $a, as SQL, with its arguments.
 * @return array{0:string,1:array}
 */
function geo_distance_sql(array $point, string $a = 'c'): array
{
    return ['(' . GEO_EARTH_MILES . " * 2 * ASIN(SQRT(POW(SIN(RADIANS($a.geo_lat - ?) / 2), 2)
             + COS(RADIANS(?)) * COS(RADIANS($a.geo_lat)) * POW(SIN(RADIANS($a.geo_lng - ?) / 2), 2))))",
            [$point['lat'], $point['lat'], $point['lng']]];
}

/**
 * "Within $miles of $point" for candidate alias $a, as SQL, with its arguments: a box
 * first, which the index on geo_lat narrows, then the true distance.
 * @return array{0:string,1:array}
 */
function geo_within_sql(array $point, int $miles, string $a = 'c'): array
{
    $dLat = $miles / 69.0;
    $dLng = $miles / max(1.0, 69.0 * cos(deg2rad($point['lat'])));
    [$distance, $args] = geo_distance_sql($point, $a);

    return ["($a.geo_lat BETWEEN ? AND ? AND $a.geo_lng BETWEEN ? AND ? AND $distance <= ?)",
            array_merge([$point['lat'] - $dLat, $point['lat'] + $dLat, $point['lng'] - $dLng, $point['lng'] + $dLng], $args, [$miles])];
}

/** @return int[] the distances a recruiter chooses from */
function geo_radii(): array
{
    return [25, 50, 100, 250, 500];
}
