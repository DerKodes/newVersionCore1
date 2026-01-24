<?php
// 1. Headers for REST API
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");

// 2. Include Database Connection
// Assuming this file is inside the 'api' folder alongside 'db.php'
include_once 'db.php'; 

// 3. Initialize Response Array
$response = [
    "success" => false,
    "count" => 0,
    "data" => [],
    "message" => ""
];

try {
    // 4. Check Connection
    if (!isset($conn)) {
        throw new Exception("Database connection failed.");
    }

    // 5. Build Query (Matches the logic in your hmbl.php)
    $sql = "SELECT 
                h.hmbl_id, 
                h.hmbl_no, 
                c.consolidation_code, 
                h.created_at,
                c.trip_no,
                -- Subquery for RUSH count
                (SELECT COUNT(*) 
                 FROM consolidation_shipments cs 
                 JOIN shipments s ON cs.shipment_id = s.shipment_id 
                 WHERE cs.consolidation_id = h.consolidation_id AND s.priority = 'RUSH') as rush_count,
                
                -- Subquery for Total Shipments
                (SELECT COUNT(*) 
                 FROM consolidation_shipments cs 
                 WHERE cs.consolidation_id = h.consolidation_id) as total_shipments

            FROM hmbl h 
            JOIN consolidations c ON h.consolidation_id = c.consolidation_id 
            ORDER BY h.created_at DESC";

    $result = $conn->query($sql);

    if ($result) {
        $data = [];
        while ($row = $result->fetch_assoc()) {
            // Format data types for JSON (numbers vs strings)
            $row['hmbl_id'] = (int)$row['hmbl_id'];
            $row['rush_count'] = (int)$row['rush_count'];
            $row['total_shipments'] = (int)$row['total_shipments'];
            
            // Optional: Format date for frontend
            $row['formatted_date'] = date("M d, Y", strtotime($row['created_at']));
            
            $data[] = $row;
        }

        $response['success'] = true;
        $response['count'] = count($data);
        $response['data'] = $data;
        $response['message'] = "Documents retrieved successfully.";
        
        // Return 200 OK
        http_response_code(200);
    } else {
        throw new Exception("Query failed: " . $conn->error);
    }

} catch (Exception $e) {
    // Handle Errors
    http_response_code(500);
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

// 6. Output JSON
echo json_encode($response);
exit();
?>