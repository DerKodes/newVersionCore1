<?php
// FILE: core1/api/sync_core3_bookings.php

// 1. DISABLE SECURITY / CORS
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: *");

// Enable Error Reporting for debugging (Log only)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

include "db.php";

// 2. CORE 3 API CONFIGURATION
$core3_url = "http://192.168.100.130/core3/api/bookshipment_api.php";
$api_token = "CORE3_SECURE_TOKEN_2025"; // MUST MATCH the token in Core 3's auth.php
$system_user_id = 1;

// 3. CONNECT TO REST API
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $core3_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

// [CRITICAL FIX] Add Authorization Header matching auth.php
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: " . $api_token,
    "Content-Type: application/json"
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// 4. CHECK CONNECTION
if (!$response) {
    echo json_encode([
        "status" => "error",
        "message" => "Could not connect to Core 3 API. Error: " . $curlError
    ]);
    exit();
}

// Check for HTTP 401/403 Errors specifically
if ($httpCode === 401 || $httpCode === 403) {
    echo json_encode([
        "status" => "error",
        "message" => "Authorization Failed. Check API Token.",
        "http_code" => $httpCode
    ]);
    exit();
}

$json = json_decode($response);

$data_list = [];
// Handle different JSON structures (Object with 'data' key OR direct Array)
if (isset($json->data) && is_array($json->data)) {
    $data_list = $json->data;
} elseif (is_array($json)) {
    $data_list = $json;
} else {
    // If response is valid JSON but not the expected array structure
    echo json_encode([
        "status" => "error",
        "message" => "Invalid data format from Core 3.",
        "raw_response" => substr($response, 0, 200) // Log first 200 chars
    ]);
    exit();
}

$new_count = 0;
$updated_count = 0;
$error_count = 0;

// 5. PROCESS DATA
foreach ($data_list as $item) {
    // Cast to object to handle both array/object formats
    $item = (object)$item;

    $contract_no = $item->contract_number ?? "";

    if (empty($contract_no)) {
        continue;
    }

    // --- MAP BASIC DATA ---
    $sender_name    = $item->sender_name ?? "Unknown Sender";
    $receiver_name  = $item->receiver_name ?? "Unknown Receiver";
    $origin_addr    = $item->origin_address ?? "Unknown Origin";
    $dest_addr      = $item->destination_address ?? "Unknown Destination";

    // --- MAP COORDINATES ---
    // Core 3 sends "dest_lat", but Core 1 DB uses "destination_lat"
    $origin_lat     = isset($item->origin_lat) ? (float)$item->origin_lat : 0.0;
    $origin_lng     = isset($item->origin_lng) ? (float)$item->origin_lng : 0.0;
    $dest_lat       = isset($item->dest_lat)   ? (float)$item->dest_lat   : 0.0;
    $dest_lng       = isset($item->dest_lng)   ? (float)$item->dest_lng   : 0.0;

    $sender_contact = $item->sender_contact ?? "N/A";
    $receiver_contact = $item->receiver_contact ?? "N/A";

    $mode = "LAND";
    if (isset($item->origin_island) && isset($item->destination_island)) {
        if (strcasecmp($item->origin_island, $item->destination_island) !== 0) {
            $mode = "SEA";
        }
    }

    $weight     = isset($item->weight) ? (float)$item->weight : 1.00;
    $pkg_type   = $item->package_type ?? "Box";
    $pkg_desc   = $item->package_description ?? "Imported via API";
    $pay_method = $item->payment_method ?? "COD";
    $bank_name  = $item->bank_name ?? "";
    $dist_km    = isset($item->distance_km) ? (float)$item->distance_km : 0.00;
    $price      = isset($item->price) ? (float)$item->price : 0.00;
    $sla        = "SLA-48H (Standard)";
    $ai_eta     = $item->ai_estimated_time ?? "";
    $target_date = $item->target_delivery_date ?? date('Y-m-d', strtotime('+3 days'));
    $status     = "PENDING";

    // --- CHECK IF EXISTS ---
    $check_stmt = $conn->prepare("SELECT po_id FROM purchase_orders WHERE contract_number = ?");
    $check_stmt->bind_param("s", $contract_no);
    $check_stmt->execute();
    $existing = $check_stmt->get_result()->fetch_assoc();

    if ($existing) {
        // --- UPDATE RECORD ---
        try {
            // Update coordinates and specific fields if they changed
            $update_stmt = $conn->prepare("UPDATE purchase_orders SET origin_lat=?, origin_lng=?, destination_lat=?, destination_lng=?, ai_estimated_time=? WHERE po_id=?");

            $update_stmt->bind_param("ddddsi", $origin_lat, $origin_lng, $dest_lat, $dest_lng, $ai_eta, $existing['po_id']);

            if ($update_stmt->execute()) {
                $updated_count++;
            }
        } catch (Exception $e) {
            $error_count++;
            error_log("Update Failed PO ID {$existing['po_id']}: " . $e->getMessage());
        }
        continue;
    }

    // --- INSERT NEW RECORD ---
    try {
        $stmt = $conn->prepare("
            INSERT INTO purchase_orders 
            (user_id, contract_number, sender_name, sender_contact, receiver_name, receiver_contact, 
             origin_address, destination_address, 
             origin_lat, origin_lng, destination_lat, destination_lng, 
             transport_mode, weight, package_type, package_description, 
             payment_method, bank_name, distance_km, price, sla_agreement, ai_estimated_time, target_delivery_date, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "isssssssddddsdssssddssss",
            $system_user_id,
            $contract_no,
            $sender_name,
            $sender_contact,
            $receiver_name,
            $receiver_contact,
            $origin_addr,
            $dest_addr,
            $origin_lat,
            $origin_lng,
            $dest_lat,
            $dest_lng,
            $mode,
            $weight,
            $pkg_type,
            $pkg_desc,
            $pay_method,
            $bank_name,
            $dist_km,
            $price,
            $sla,
            $ai_eta,
            $target_date,
            $status
        );

        if ($stmt->execute()) {
            $new_count++;
        }
    } catch (Exception $e) {
        $error_count++;
        error_log("Insert Failed for Contract $contract_no: " . $e->getMessage());
    }
}

// Save Timestamp
$current_time = date("M d, Y h:i A");
file_put_contents("last_sync.txt", $current_time);

echo json_encode([
    "status" => "success",
    "message" => "Sync completed.",
    "imported" => $new_count,
    "updated" => $updated_count,
    "db_errors" => $error_count,
    "last_sync" => $current_time
]);
