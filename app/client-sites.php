<?php
/**
 * P5-M77: each client's sites (RSS, 17 Oct 2026: "does it save it as a site somewhere?").
 *
 * The site of every project is kept on its client - its name, address, city, state, ZIP, position and clock-in
 * radius - so the next project at the same plant chooses it instead of typing it again.
 */

declare(strict_types=1);

function cs_sites(int $clientId): array
{
    return rows('SELECT s.*, (SELECT COUNT(*) FROM jobs j WHERE j.client_id = s.client_id AND j.site_address <=> s.address AND j.site_city <=> s.city) AS projects
                 FROM client_sites s WHERE s.client_id = ? ORDER BY s.name, s.address', [$clientId]);
}

/** Every client's sites, for the project form: [client_id => [site, ...]]. */
function cs_by_client(): array
{
    $all = [];
    foreach (rows('SELECT id, client_id, name, address, city, state, zip, lat, lng, radius_m FROM client_sites ORDER BY name, address') as $s) {
        $all[(int) $s['client_id']][] = $s;
    }

    return $all;
}

/** The project's site, kept on its client: the same address (or, without one, the same name and city) is updated, not doubled. */
function cs_remember(int $jobId): void
{
    $j = row('SELECT client_id, site_name, site_address, site_city, site_state, site_zip, site_lat, site_lng, site_radius_m FROM jobs WHERE id = ?', [$jobId]);
    if (! $j || (trim((string) $j['site_address']) === '' && trim((string) $j['site_name']) === '' && trim((string) $j['site_city']) === '')) {
        return;
    }
    $same = trim((string) $j['site_address']) !== ''
        ? val('SELECT id FROM client_sites WHERE client_id = ? AND LOWER(address) = LOWER(?) AND city <=> ?', [$j['client_id'], trim((string) $j['site_address']), $j['site_city']])
        : val('SELECT id FROM client_sites WHERE client_id = ? AND address IS NULL AND LOWER(COALESCE(name, \'\')) = LOWER(?) AND city <=> ?', [$j['client_id'], trim((string) $j['site_name']), $j['site_city']]);
    $v = [trim((string) $j['site_name']) ?: null, trim((string) $j['site_address']) ?: null, $j['site_city'], $j['site_state'], $j['site_zip'], $j['site_lat'], $j['site_lng'], $j['site_radius_m']];
    if ($same) {
        q('UPDATE client_sites SET name = COALESCE(?, name), address = ?, city = ?, state = ?, zip = ?, lat = COALESCE(?, lat), lng = COALESCE(?, lng), radius_m = COALESCE(?, radius_m) WHERE id = ?', [...$v, $same]);
    } else {
        q('INSERT INTO client_sites (client_id, name, address, city, state, zip, lat, lng, radius_m) VALUES (?,?,?,?,?,?,?,?,?)', [(int) $j['client_id'], ...$v]);
    }
}

function cs_remove(int $clientId, int $siteId): void
{
    q('DELETE FROM client_sites WHERE id = ? AND client_id = ?', [$siteId, $clientId]);
}
