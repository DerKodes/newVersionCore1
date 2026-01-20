<?php
$API_TOKEN = "CORE3_SECURE_TOKEN_2025";

if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

$headers = getallheaders();
if (!isset($headers['Authorization']) || $headers['Authorization'] !== $API_TOKEN) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit();
}
