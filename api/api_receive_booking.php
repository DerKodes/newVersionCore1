<?php
// FILE: core1/api/api_receive_booking.php
// PURPOSE: Receive booking data (Security Removed)

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// 1. DATABASE CONNECTION
require_once 'db.php'; 

// 2. RECEIVE DATA
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

if (empty($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No data received']);
    exit();
}

try {
    // 3. EXTRACT VARIABLES
    $c1_uid           = 1; // Default System User ID

    // Try 'contract_number' first, fallback to 'tracking_code'
    $contract_number  = $input['contract_number'] ?? $input['tracking_code'] ?? '';

    if (empty($contract_number)) {
        throw new Exception("Contract Number is required.");
    }

    $sender           = $input['sender_name'] ?? '';
    $s_contact        = $input['sender_contact'] ?? '';
    $receiver         = $input['receiver_name'] ?? '';
    $r_contact        = $input['receiver_contact'] ?? '';
    $origin           = $input['origin_address'] ?? '';
    $dest             = $input['destination_address'] ?? '';

    // GPS COORDINATES
    $c1_origin_lat    = isset($input['origin_lat']) ? floatval($input['origin_lat']) : 0.0;
    $c1_origin_lng    = isset($input['origin_lng']) ? floatval($input['origin_lng']) : 0.0;
    $c1_dest_lat      = isset($input['dest_lat']) ? floatval($input['dest_lat']) : 0.0;
    $c1_dest_lng      = isset($input['dest_lng']) ? floatval($input['dest_lng']) : 0.0;

    $c1_mode          = 'LAND'; 
    
    $weight           = floatval($input['weight'] ?? 0);
    $type             = $input['package_type'] ?? '';
    $desc             = $input['package_description'] ?? '';
    $method           = $input['payment_method'] ?? '';
    $bank             = $input['bank_name'] ?? '';
    $km               = floatval($input['distance_km'] ?? 0);
    $price            = floatval($input['price'] ?? 0);
    $sla              = !empty($input['sla_agreement']) ? $input['sla_agreement'] : 'Standard SLA';
    $ai_time          = $input['ai_estimated_time'] ?? '';
    $target_date      = !empty($input['target_delivery_date']) ? $input['target_delivery_date'] : date('Y-m-d', strtotime('+3 days'));
    $c1_status        = 'PENDING';

    // 4. CHECK FOR DUPLICATE (UPSERT LOGIC)
    $check_stmt = $conn->prepare("SELECT po_id FROM purchase_orders WHERE contract_number = ?");
    $check_stmt->bind_param("s", $contract_number);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows > 0) {
        // --- UPDATE EXISTING RECORD ---
        $existing = $result->fetch_assoc();
        $po_id = $existing['po_id'];

        $update_sql = "UPDATE purchase_orders SET 
            origin_lat=?, origin_lng=?, destination_lat=?, destination_lng=?, 
            price=?, ai_estimated_time=?, updated_at=NOW() 
            WHERE po_id=?";
            
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("dddddsi", 
            $c1_origin_lat, $c1_origin_lng, $c1_dest_lat, $c1_dest_lng, 
            $price, $ai_time, $po_id
        );
        
        $action_taken = "UPDATED";
        $stmt->execute();
    } else {
        // --- INSERT NEW RECORD ---
        $sql = "INSERT INTO purchase_orders (
            user_id, contract_number, 
            sender_name, sender_contact, receiver_name, receiver_contact, 
            origin_address, destination_address, 
            origin_lat, origin_lng, destination_lat, destination_lng, 
            transport_mode, weight, package_type, package_description, 
            payment_method, bank_name, 
            distance_km, price, 
            sla_agreement, ai_estimated_time, target_delivery_date, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmt = $conn->prepare($sql);
        // Bind Params (24 total)
        $stmt->bind_param(
            "isssssssddddsdssssddssss",
            $c1_uid, $contract_number, 
            $sender, $s_contact, $receiver, $r_contact, 
            $origin, $dest, 
            $c1_origin_lat, $c1_origin_lng, $c1_dest_lat, $c1_dest_lng, 
            $c1_mode, $weight, $type, $desc, 
            $method, $bank, 
            $km, $price, 
            $sla, $ai_time, $target_date, $c1_status
        );
        
        $action_taken = "INSERTED";
        $stmt->execute();
        $po_id = $stmt->insert_id;
    }

    if ($stmt->error) {
        throw new Exception("Database Error: " . $stmt->error);
    }

    echo json_encode([
        'success' => true,
        'message' => "Data successfully processed ($action_taken).",
        'po_id' => $po_id,
        'action' => $action_taken,
        'contract_saved' => $contract_number
    ]);

    $stmt->close();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ]);
}

$conn->close();
?>