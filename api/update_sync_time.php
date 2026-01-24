<?php
// FILE: core1/api/update_sync_time.php

// 1. Set JSON Headers
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST");

// 2. Set Timezone to Philippines (Realtime)
date_default_timezone_set('Asia/Manila');

// 3. Generate Timestamp
$current_time = date("M d, Y h:i:s A"); // Format: Jan 24, 2026 07:05:30 PM

// 4. Define File Path (Same folder as this script)
$file_path = "last_sync.php";

try {
    // 5. Write to last_sync.php
    if (file_put_contents($file_path, $current_time) !== false) {
        echo json_encode([
            "status" => "success",
            "message" => "Timestamp updated successfully.",
            "last_sync" => $current_time
        ]);
    } else {
        throw new Exception("Permission denied. Could not write to $file_path.");
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
?>