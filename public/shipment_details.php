<?php
session_start();
include "../api/db.php";
include "../includes/auth_check.php";

if (!isset($_GET['id'])) {
    header("Location: shipments.php");
    exit();
}

$shipment_id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'];

// ================== HANDLE NEW TRACKING UPDATE ==================
if (isset($_POST['add_update'])) {
    $status = $_POST['status'];
    $location = $_POST['location'];
    $remarks = $_POST['remarks'];

    // 1. Insert into Tracking History
    $stmt = $conn->prepare("INSERT INTO shipment_tracking (shipment_id, status, location, remarks, created_by) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("isssi", $shipment_id, $status, $location, $remarks, $user_id);
    $stmt->execute();

    // 2. Update Main Shipment Status
    $stmt = $conn->prepare("UPDATE shipments SET status = ? WHERE shipment_id = ?");
    $stmt->bind_param("si", $status, $shipment_id);
    $stmt->execute();

    header("Location: shipment_details.php?id=$shipment_id&updated=1");
    exit();
}

// ================== FETCH SHIPMENT DATA ==================
$query = "
    SELECT 
        s.*, 
        po.sender_name, po.sender_contact, 
        po.receiver_name, po.receiver_contact,
        po.contract_number,
        c.consolidation_code,
        h.hmbl_no, h.hmbl_id
    FROM shipments s
    JOIN purchase_orders po ON s.po_id = po.po_id
    LEFT JOIN consolidation_shipments cs ON s.shipment_id = cs.shipment_id
    LEFT JOIN consolidations c ON cs.consolidation_id = c.consolidation_id
    LEFT JOIN hmbl_shipments hs ON s.shipment_id = hs.shipment_id
    LEFT JOIN hmbl h ON hs.hmbl_id = h.hmbl_id
    WHERE s.shipment_id = ?
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $shipment_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if (!$data) die("Shipment not found.");

// ================== FETCH TRACKING HISTORY ==================
$history = $conn->query("SELECT * FROM shipment_tracking WHERE shipment_id = $shipment_id ORDER BY created_at DESC");

/* Helper: Status Badge (Modern Style) */
function getStatusBadge($status) {
    if ($status === 'BOOKED') return '<span class="badge bg-secondary-subtle text-secondary border border-secondary px-2 rounded-pill">BOOKED</span>';
    if ($status === 'IN_TRANSIT') return '<span class="badge bg-primary-subtle text-primary border border-primary px-2 rounded-pill"><i class="bi bi-truck"></i> MOVING</span>';
    if ($status === 'ARRIVED') return '<span class="badge bg-warning-subtle text-warning border border-warning px-2 rounded-pill"><i class="bi bi-geo-alt"></i> ARRIVED</span>';
    if ($status === 'DELIVERED') return '<span class="badge bg-success-subtle text-success border border-success px-2 rounded-pill"><i class="bi bi-check-lg"></i> DELIVERED</span>';
    return '<span class="badge bg-light text-dark border">' . $status . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tracking #<?= $data['shipment_code'] ?> | Core 1</title>

    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="../assets/dark-mode.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    
    <link rel="stylesheet" href="../assets/leaflet.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.css" />
    <link rel="shortcut icon" href="../assets/slate.png" type="image/x-icon">

    <style>
        :root { --primary-color: #4e73df; --secondary-color: #858796; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background-color: #f8f9fc; }
        
        /* Layout */
        body.sidebar-closed .sidebar { margin-left: -250px; }
        body.sidebar-closed .content { margin-left: 0; width: 100%; }
        .content { width: calc(100% - 250px); margin-left: 250px; transition: all 0.3s ease; }
        @media (max-width: 768px) { .content { width: 100%; margin-left: 0; } }
        
        /* Header */
        .header { background: #fff; border-bottom: 1px solid #e3e6f0; padding: 1rem 1.5rem; position: sticky; top: 0; z-index: 100; box-shadow: 0 .15rem 1.75rem 0 rgba(58,59,69,.15); }
        
        /* Cards */
        .card { border: none; border-radius: 0.75rem; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1); transition: transform 0.2s; }
        
        /* Map Container */
        .map-container { border-radius: 0.75rem; overflow: hidden; border: 1px solid #e3e6f0; }
        .leaflet-routing-container { display: none !important; }

        /* Timeline Styles */
        .timeline { position: relative; padding-left: 2rem; border-left: 2px solid #e3e6f0; margin-left: 1rem; }
        .timeline-item { position: relative; margin-bottom: 2rem; }
        .timeline-dot { 
            position: absolute; left: -2.6rem; top: 0; 
            width: 20px; height: 20px; border-radius: 50%; 
            background: #fff; border: 4px solid var(--primary-color); 
            box-shadow: 0 0 0 4px rgba(255,255,255,1);
        }
        .timeline-date { font-size: 0.8rem; color: #858796; font-weight: 600; margin-bottom: 0.25rem; }
        .timeline-content { background: #fff; padding: 1rem; border-radius: 0.5rem; border: 1px solid #e3e6f0; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }

        /* Dark Mode */
        body.dark-mode { background-color: #121212; color: #e0e0e0; }
        body.dark-mode .header, body.dark-mode .card, body.dark-mode .timeline-content { background-color: #1e1e1e; border-color: #333; color: #e0e0e0; }
        body.dark-mode .timeline { border-left-color: #333; }
        body.dark-mode .timeline-dot { box-shadow: 0 0 0 4px #1e1e1e; }
        body.dark-mode .form-control, body.dark-mode .form-select { background-color: #2c2c2c; border-color: #444; color: #fff; }
        body.dark-mode .map-container { border-color: #333; }
        body.dark-mode .list-group-item { background-color: #1e1e1e; border-color: #333; color: #e0e0e0; }
    </style>
</head>

<body>

    <div class="sidebar" id="sidebar">
        <div class="logo"><img src="../assets/slate.png" alt="Logo"></div>
        <div class="system-name">CORE TRANSACTION 1</div>
        <a href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a>
        <a href="pu_order.php"><i class="bi bi-cart me-2"></i> Purchase Orders</a>
        <a href="shipments.php" class="active"><i class="bi bi-truck me-2"></i> Shipment Booking</a>
        <a href="conso.php"><i class="bi bi-boxes me-2"></i> Consolidation</a>
        <a href="hmbl.php"><i class="bi bi-file-earmark-pdf me-2"></i> BL Generator</a>
    </div>

    <div class="content" id="content">
        <div class="header d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
                <div class="hamburger text-secondary me-3" id="hamburger" style="cursor: pointer;"><i class="bi bi-list fs-4"></i></div>
                <div>
                    <div class="d-flex align-items-center gap-2">
                        <h4 class="mb-0 fw-bold text-dark-emphasis"><?= $data['shipment_code'] ?></h4>
                        <?= getStatusBadge($data['status']) ?>
                    </div>
                    <small class="text-muted">Contract PO: <?= $data['contract_number'] ?></small>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <div class="theme-toggle-container d-flex align-items-center">
                    <i class="bi bi-moon-stars me-2 text-muted"></i>
                    <label class="theme-switch"><input type="checkbox" id="themeToggle"><span class="slider"></span></label>
                </div>
                <a href="shipments.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                    <i class="bi bi-arrow-left me-1"></i> Back
                </a>
            </div>
        </div>

        <div class="container-fluid p-4">
            
            <div class="row g-4">
                <div class="col-lg-4">
                    
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-info-circle me-2"></i>Shipment Info</h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-6">
                                    <small class="text-uppercase text-muted fw-bold" style="font-size: 0.7rem;">Transport Mode</small>
                                    <div class="fw-semibold"><i class="bi bi-truck me-1"></i> <?= $data['transport_mode'] ?></div>
                                </div>
                                <div class="col-6">
                                    <small class="text-uppercase text-muted fw-bold" style="font-size: 0.7rem;">Weight</small>
                                    <div class="fw-semibold"><i class="bi bi-box me-1"></i> <?= $data['weight'] ?> kg</div>
                                </div>
                                <div class="col-12"><hr class="my-2 text-secondary opacity-25"></div>
                                <div class="col-12">
                                    <div class="d-flex align-items-center mb-3">
                                        <i class="bi bi-circle-fill text-success me-2 small"></i>
                                        <div>
                                            <small class="text-muted d-block" style="line-height: 1;">Origin</small>
                                            <span class="fw-bold"><?= $data['origin'] ?></span>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-geo-alt-fill text-danger me-2 small"></i>
                                        <div>
                                            <small class="text-muted d-block" style="line-height: 1;">Destination</small>
                                            <span class="fw-bold"><?= $data['destination'] ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="mb-0 fw-bold text-secondary"><i class="bi bi-people me-2"></i>Involved Parties</h6>
                        </div>
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item p-3">
                                <small class="text-muted text-uppercase fw-bold" style="font-size: 0.7rem;">Shipper</small>
                                <div class="fw-bold text-dark"><?= $data['sender_name'] ?></div>
                                <small class="text-muted"><i class="bi bi-telephone me-1"></i> <?= $data['sender_contact'] ?></small>
                            </li>
                            <li class="list-group-item p-3">
                                <small class="text-muted text-uppercase fw-bold" style="font-size: 0.7rem;">Consignee</small>
                                <div class="fw-bold text-dark"><?= $data['receiver_name'] ?></div>
                                <small class="text-muted"><i class="bi bi-telephone me-1"></i> <?= $data['receiver_contact'] ?></small>
                            </li>
                        </ul>
                    </div>

                    <div class="card shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h6 class="mb-0 fw-bold text-secondary"><i class="bi bi-files me-2"></i>Documents</h6>
                        </div>
                        <div class="list-group list-group-flush">
                            <?php if ($data['hmbl_no']): ?>
                                <a href="view_hmbl.php?id=<?= $data['hmbl_id'] ?>" target="_blank" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center p-3">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-danger bg-opacity-10 text-danger rounded p-2 me-3"><i class="bi bi-file-earmark-pdf"></i></div>
                                        <div>
                                            <div class="fw-semibold">House Bill of Lading</div>
                                            <small class="text-muted"><?= $data['hmbl_no'] ?></small>
                                        </div>
                                    </div>
                                    <i class="bi bi-box-arrow-up-right text-muted"></i>
                                </a>
                            <?php else: ?>
                                <div class="p-3 text-center small text-muted fst-italic">No BL Issued</div>
                            <?php endif; ?>

                            <?php if ($data['consolidation_code']): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center p-3">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-info bg-opacity-10 text-info rounded p-2 me-3"><i class="bi bi-box-seam"></i></div>
                                        <div>
                                            <div class="fw-semibold">Consolidation</div>
                                            <small class="text-muted"><?= $data['consolidation_code'] ?></small>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

                <div class="col-lg-8">
                    
                    <div class="card shadow-sm mb-4">
                        <div class="card-body p-0">
                            <div class="map-container">
                                <div id="shipmentMap" style="height: 350px; width: 100%;"></div>
                            </div>
                        </div>
                    </div>

                    <div class="card shadow-sm border-primary border-top border-3 mb-4">
                        <div class="card-body">
                            <h6 class="fw-bold text-primary mb-3"><i class="bi bi-pencil-square me-2"></i>Update Tracking Status</h6>
                            <form method="POST" class="row g-2">
                                <div class="col-md-3">
                                    <select name="status" class="form-select" required>
                                        <option value="">Select Status...</option>
                                        <option value="IN_TRANSIT">In Transit</option>
                                        <option value="ARRIVED">Arrived at Location</option>
                                        <option value="CUSTOMS_HOLD">Customs Hold</option>
                                        <option value="OUT_FOR_DELIVERY">Out for Delivery</option>
                                        <option value="DELIVERED">Delivered</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <input name="location" class="form-control" placeholder="Current Location (e.g. Manila Port)" required>
                                </div>
                                <div class="col-md-3">
                                    <input name="remarks" class="form-control" placeholder="Remarks (Optional)">
                                </div>
                                <div class="col-md-2">
                                    <button name="add_update" class="btn btn-primary w-100 fw-bold">Update</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <h6 class="fw-bold text-secondary mb-3 ps-2"><i class="bi bi-clock-history me-2"></i>Tracking History</h6>
                    <div class="timeline">
                        <?php if ($history->num_rows > 0): ?>
                            <?php while ($row = $history->fetch_assoc()): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot"></div>
                                    <div class="timeline-date"><?= date("M d, Y • h:i A", strtotime($row['created_at'])) ?></div>
                                    <div class="timeline-content">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <div class="fw-bold text-dark"><?= $row['status'] ?></div>
                                            <div class="badge bg-light text-secondary border"><i class="bi bi-geo-alt-fill text-danger me-1"></i> <?= $row['location'] ?></div>
                                        </div>
                                        <?php if ($row['remarks']): ?>
                                            <p class="mb-0 small text-muted fst-italic">"<?= $row['remarks'] ?>"</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="text-muted small ps-2">No tracking updates available yet.</div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../scripts/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.js"></script>
    <script src="../scripts/shipment_map.js?v=<?php echo time(); ?>"></script>
    <script src="../assets/main.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize embedded map
            initEmbeddedMap(
                "<?= htmlspecialchars($data['origin']) ?>",
                "<?= htmlspecialchars($data['destination']) ?>"
            );
        });
    </script>
</body>
</html>