<?php
// api/geocode.php

// 1. Prevent HTML errors from breaking the JSON response
ini_set('display_errors', 0);
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

// 2. Check if cURL is enabled
if (!function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode(["error" => "cURL is not enabled in php.ini"]);
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

// 3. CRITICAL FIX: User-Agent is REQUIRED by Nominatim
curl_setopt($ch, CURLOPT_USERAGENT, "SlateFreightSystem/1.0 (Local Dev)");

// 4. CRITICAL FIX: Disable SSL Verification (Fixes 500 error on localhost)
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// 5. Handle Errors
if ($response === false) {
    http_response_code(500);
    echo json_encode(["error" => "cURL Error: " . $curlError]);
    exit;
}

if ($httpCode != 200) {
    http_response_code(500);
    echo json_encode(["error" => "Map server error: " . $httpCode]);
    exit;
}

echo $response;
?>