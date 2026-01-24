<?php
// api/geocode.php

ini_set('display_errors', 0);
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

if (!function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode(["error" => "cURL is not enabled"]);
    exit;
}

if (!isset($_GET['q'])) {
    echo json_encode([]);
    exit;
}

$query = urlencode($_GET['q']);
$url = "https://nominatim.openstreetmap.org/search?format=json&limit=1&q={$query}";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_USERAGENT, "SlateFreightSystem/1.0 (Local Dev)");
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $httpCode != 200) {
    echo json_encode(["error" => "Map error"]);
    exit;
}

echo $response;
?>