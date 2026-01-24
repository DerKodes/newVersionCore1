<?php
header("Content-Type: application/json");

// Enable error reporting for debugging
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include "db.php";
// include "auth.php"; // Uncomment if you need API Key authentication

// ================= VALIDATE JSON =================
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON"]);
    exit();
}

// FIX: Check if contract_number is provided since we removed auto-generation
if (empty($data['contract_number'])) {
    http_response_code(400);
    echo json_encode(["error" => "Contract number is required"]);
    exit();
}

try {
    // ================= INSERT PO =================
    $stmt = $conn->prepare("
        INSERT INTO purchase_orders
        (
            user_id, contract_number, 
            sender_name, sender_contact,
            receiver_name, receiver_contact,
            origin_address, destination_address, 
            origin_lat, origin_lng, 
            destination_lat, destination_lng,
            weight, package_type, package_description,
            payment_method, bank_name, 
            distance_km, price,
            sla_agreement, ai_estimated_time, target_delivery_date
        )
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    // FIX: Use the contract number from the input Data
    $contract = $data['contract_number'];

    // Handle optional fields
    $bank_name = $data['bank_name'] ?? "";
    $package_desc = $data['package_description'] ?? "";

    // Bind parameters
    // i = integer, s = string, d = double (decimal)
    $stmt->bind_param(
        "isssssssdddddssssdssds", 
        $data['user_id'],       // i
        $contract,              // s (Now using input data)
        $data['sender_name'],   // s
        $data['sender_contact'],// s
        $data['receiver_name'], // s
        $data['receiver_contact'],// s
        $data['origin_address'],// s
        $data['destination_address'],// s
        
        $data['origin_lat'],    // d
        $data['origin_lng'],    // d
        $data['destination_lat'],// d
        $data['destination_lng'],// d
        
        $data['weight'],        // d
        $data['package_type'],  // s
        $package_desc,          // s
        $data['payment_method'],// s
        $bank_name,             // s
        $data['distance_km'],   // d
        $data['price'],         // d
        
        $data['sla_agreement'], // s
        $data['ai_estimated_time'], // s
        $data['target_delivery_date'] // s
    );

    $stmt->execute();

    echo json_encode([
        "status" => "success",
        "contract_number" => $contract,
        "po_id" => $conn->insert_id
    ]);

} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database error: " . $e->getMessage()]);
}
?>