<?php
header("Content-Type: application/json");

// FIX 1: Add error reporting so you can see if something goes wrong
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include "db.php";
include "auth.php";

// ================= VALIDATE JSON =================
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON"]);
    exit();
}

try {
    // ================= INSERT PO =================
    // FIX 2: Removed 'specific_address' (doesn't exist in DB)
    // FIX 3: Added lat/lng columns so the Map works later
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

    $contract = "PO-" . strtoupper(uniqid());

    // FIX 4: Handle optional bank name (Cash payments might have no bank)
    $bank_name = $data['bank_name'] ?? "";
    $package_desc = $data['package_description'] ?? "";

    // FIX 5: Corrected Type String (22 variables)
    // i = integer, s = string, d = double (decimal)
    $stmt->bind_param(
        "isssssssdddddssssdssds", 
        $data['user_id'],           // i
        $contract,                  // s
        $data['sender_name'],       // s
        $data['sender_contact'],    // s
        $data['receiver_name'],     // s
        $data['receiver_contact'],  // s
        $data['origin_address'],    // s
        $data['destination_address'],// s
        
        $data['origin_lat'],        // d (Saved to DB!)
        $data['origin_lng'],        // d
        $data['destination_lat'],   // d
        $data['destination_lng'],   // d
        
        $data['weight'],            // d
        $data['package_type'],      // s
        $package_desc,              // s
        $data['payment_method'],    // s
        $bank_name,                 // s
        $data['distance_km'],       // d
        $data['price'],             // d (Price is decimal in DB)
        
        $data['sla_agreement'],     // s
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
    // FIX 6: Better error message for debugging
    http_response_code(500);
    echo json_encode(["error" => "Database error: " . $e->getMessage()]);
}
?>