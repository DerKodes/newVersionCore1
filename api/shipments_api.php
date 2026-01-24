<?php
// FILE: core1/api/shipment_api.php

// Allow access from anywhere (No security/CORS restrictions)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");

// Include your existing database connection
include "db.php"; 

try {
    // UPDATED QUERY: 
    // Added GPS coordinates from the purchase_orders table
    
    $sql = "SELECT s.*, 
                   c.consolidation_code, 
                   po.sender_name, 
                   po.contract_number,
                   po.origin_lat, 
                   po.origin_lng, 
                   po.destination_lat, 
                   po.destination_lng
            FROM shipments s
            LEFT JOIN consolidation_shipments cs ON s.shipment_id = cs.shipment_id
            LEFT JOIN consolidations c ON cs.consolidation_id = c.consolidation_id
            LEFT JOIN purchase_orders po ON s.po_id = po.po_id
            ORDER BY s.created_at DESC";

    $result = $conn->query($sql);

    $shipments = [];

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Optional: Format numeric values
            if(isset($row['weight'])) {
                $row['weight'] = (float)$row['weight'];
            }
            
            // Cast coordinates to floats (ensures JSON sends them as numbers, not strings)
            if(isset($row['origin_lat'])) $row['origin_lat'] = (float)$row['origin_lat'];
            if(isset($row['origin_lng'])) $row['origin_lng'] = (float)$row['origin_lng'];
            if(isset($row['destination_lat'])) $row['destination_lat'] = (float)$row['destination_lat'];
            if(isset($row['destination_lng'])) $row['destination_lng'] = (float)$row['destination_lng'];

            // Fallback for sender
            if(empty($row['sender_name'])) {
                $row['sender_name'] = "Unknown Sender";
            }
            // Fallback for contract number
            if(empty($row['contract_number'])) {
                $row['contract_number'] = "N/A"; 
            }
            
            $shipments[] = $row;
        }
    }

    // Return success response
    echo json_encode([
        "success" => true,
        "count" => count($shipments),
        "data" => $shipments
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    // Return error response
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error: " . $e->getMessage()
    ]);
}
?>