<?php
/**
 * P5-M71: GET /geocode?address=... - the position, city, state and ZIP of a site address, for the forms of
 * Projects and the plant (public/assets/site-address.js). Staff who set up projects only.
 */

require_role('admin');
require_once __DIR__ . '/../geocode.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$found = geocode_address((string) ($_GET['address'] ?? ''));
http_response_code($found ? 200 : 404);
echo json_encode($found ? ['found' => true] + $found : ['found' => false, 'message' => t('Address not found. Check it, or type the latitude and longitude, or use My position here on the site.')]);
