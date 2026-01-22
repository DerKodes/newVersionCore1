<?php
// Allow access from anywhere (No security/CORS restrictions)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");

// Include your existing database connection
// Adjust path if your api folder structure is different
include "db.php"; 

try {
    // Select shipments joining with consolidations to get status codes
    $sql = "SELECT s.*, c.consolidation_code 
            FROM shipments s
            LEFT JOIN consolidation_shipments cs ON s.shipment_id = cs.shipment_id
            LEFT JOIN consolidations c ON cs.consolidation_id = c.consolidation_id
            ORDER BY s.created_at DESC";

    $result = $conn->query($sql);

    $shipments = [];

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Optional: Format numeric values if needed
            if(isset($row['weight'])) {
                $row['weight'] = (float)$row['weight'];
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