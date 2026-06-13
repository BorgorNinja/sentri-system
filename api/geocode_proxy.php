<?php
/**
 * SenTri – Nominatim Reverse Geocode Proxy
 * Routes Nominatim requests server-side to bypass browser CORS restrictions.
 */
session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Lax','cookie_secure'=>!empty($_SERVER['HTTPS'])]);
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['address' => []]); exit;
}

$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$lon = isset($_GET['lon']) ? (float)$_GET['lon'] : null;

if ($lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
    echo json_encode(['address' => []]); exit;
}

$url = sprintf(
    'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=%s&lon=%s',
    urlencode((string)$lat),
    urlencode((string)$lon)
);

$ctx = stream_context_create([
    'http' => [
        'method'  => 'GET',
        'header'  => "User-Agent: SenTri/1.0\r\nAccept-Language: en\r\n",
        'timeout' => 6,
    ]
]);

$response = @file_get_contents($url, false, $ctx);
echo $response !== false ? $response : json_encode(['address' => []]);
