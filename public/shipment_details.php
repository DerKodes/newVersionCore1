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

/* Helper: Status Badge */
function getBadge($status)
{
    if ($status === 'BOOKED') return 'secondary';
    if ($status === 'IN_TRANSIT') return 'primary';
    if ($status === 'ARRIVED') return 'warning text-dark';
    if ($status === 'DELIVERED') return 'success';
    return 'light text-dark border';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shipment #<?= $data['shipment_code'] ?></title>

    <link rel="stylesheet" href="../assets/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../assets/leaflet.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.css" />

    <style>
        /* Base */
        body {
            font-family: 'Segoe UI', sans-serif;
            transition: background 0.3s, color 0.3s;
        }

        .sidebar {
            z-index: 1001;
        }

        .leaflet-routing-container {
            display: none !important;
        }

        /* Sticky Header */
        .header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: #fff;
            border-bottom: 1px solid #e3e6f0;
            padding: 15px 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        /* Timeline CSS */
        .timeline {
            position: relative;
            padding-left: 30px;
            border-left: 2px solid #e3e6f0;
        }

        .timeline-item {
            position: relative;
            margin-bottom: 30px;
        }

        .timeline-dot {
            position: absolute;
            left: -36px;
            top: 0;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #0d6efd;
            border: 2px solid #fff;
        }

        .timeline-date {
            font-size: 0.85rem;
            color: #6c757d;
            margin-bottom: 5px;
        }

        .timeline-content {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e3e6f0;
        }

        /* Dark Mode */
        :root {
            --dark-bg: #121212;
            --dark-card: #1e1e1e;
            --dark-text: #e0e0e0;
            --dark-border: #333;
        }

        body.dark-mode {
            background: var(--dark-bg) !important;
            color: var(--dark-text) !important;
        }

        body.dark-mode .header {
            background: var(--dark-card) !important;
            border-bottom: 1px solid var(--dark-border);
        }

        body.dark-mode .card {
            background: var(--dark-card);
            border: 1px solid var(--dark-border);
        }

        body.dark-mode .card-header {
            border-bottom: 1px solid var(--dark-border);
            background: rgba(255, 255, 255, 0.05) !important;
        }

        body.dark-mode .card-header h5,
        body.dark-mode h6 {
            color: #fff !important;
        }

        /* Dark Mode Timeline */
        body.dark-mode .timeline {
            border-left-color: var(--dark-border);
        }

        body.dark-mode .timeline-content {
            background: #2c2c2c;
            border-color: var(--dark-border);
        }

        body.dark-mode .timeline-dot {
            border-color: var(--dark-card);
        }

        body.dark-mode .form-control,
        body.dark-mode .form-select {
            background: #2c2c2c;
            border-color: var(--dark-border);
            color: #fff;
        }

        body.dark-mode .text-muted {
            color: #a0a0a0 !important;
        }

        /* Layout Fixes */
        body.sidebar-closed .sidebar {
            margin-left: -250px;
        }

        .content {
            width: calc(100% - 250px);
            margin-left: 250px;
            transition: all 0.3s;
        }

        body.sidebar-closed .content {
            margin-left: 0;
            width: 100%;
        }

        @media(max-width:768px) {
            .content {
                width: 100%;
                margin-left: 0;
            }
        }
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

    <div class="content">
        <div class="header">
            <div class="d-flex align-items-center">
                <div class="hamburger" id="hamburger"><i class="bi bi-list"></i></div>
                <div>
                    <h4 class="mb-0 ms-2 fw-bold text-primary"><?= $data['shipment_code'] ?></h4>
                    <span class="ms-2 badge bg-<?= getBadge($data['status']) ?>"><?= $data['status'] ?></span>
                </div>
            </div>

            <div class="theme-toggle-container">
                <label class="theme-switch me-3">
                    <input type="checkbox" id="themeToggle"><span class="slider"></span>
                </label>
                <a href="shipments.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back</a>
            </div>
        </div>

        <div class="container-fluid py-4">
            <div class="row g-4">

                <div class="col-lg-4">
                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-header bg-white">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-folder2-open me-2"></i> Shipment File</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-6">
                                    <small class="text-muted d-block">Origin</small>
                                    <strong><?= $data['origin'] ?></strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block">Destination</small>
                                    <strong><?= $data['destination'] ?></strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block">Transport Mode</small>
                                    <span class="badge bg-light text-dark border"><?= $data['transport_mode'] ?></span>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block">Contract PO</small>
                                    <span class="text-primary"><?= $data['contract_number'] ?></span>
                                </div>
                            </div>
                            <hr>
                            <div class="mb-2">
                                <small class="text-muted">Shipper:</small><br>
                                <?= $data['sender_name'] ?> (<?= $data['sender_contact'] ?>)
                            </div>
                            <div>
                                <small class="text-muted">Consignee:</small><br>
                                <?= $data['receiver_name'] ?> (<?= $data['receiver_contact'] ?>)
                            </div>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-white">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-paperclip me-2"></i> Documents</h5>
                        </div>
                        <div class="list-group list-group-flush">
                            <?php if ($data['hmbl_no']): ?>
                                <a href="view_hmbl.php?id=<?= $data['hmbl_id'] ?>" target="_blank" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-file-earmark-pdf text-danger me-2"></i> House Bill of Lading</span>
                                    <span class="badge bg-secondary"><?= $data['hmbl_no'] ?></span>
                                </a>
                            <?php else: ?>
                                <div class="p-3 text-center text-muted small">
                                    No HMBL Issued yet.
                                </div>
                            <?php endif; ?>

                            <?php if ($data['consolidation_code']): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-box-seam text-primary me-2"></i> Consolidation</span>
                                    <span class="badge bg-info text-dark"><?= $data['consolidation_code'] ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8">

                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-header bg-white">
                            <h5 class="mb-0 fw-bold text-primary"><i class="bi bi-map me-2"></i> Live Route</h5>
                        </div>
                        <div class="card-body p-0">
                            <div id="shipmentMap" style="height: 350px; width: 100%;"></div>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-header bg-white">
                            <h5 class="mb-0 fw-bold text-primary"><i class="bi bi-broadcast me-2"></i> Update Status</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" class="row g-2">
                                <div class="col-md-3">
                                    <select name="status" class="form-select" required>
                                        <option value="">New Status...</option>
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
                                    <button name="add_update" class="btn btn-primary w-100"><i class="bi bi-send"></i> Update</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-white">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2"></i> Tracking History</h5>
                        </div>
                        <div class="card-body">
                            <div class="timeline mt-3">
                                <?php if ($history->num_rows > 0): ?>
                                    <?php while ($row = $history->fetch_assoc()): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-date">
                                                <i class="bi bi-calendar3 me-1"></i> <?= date("M d, Y h:i A", strtotime($row['created_at'])) ?>
                                            </div>
                                            <div class="timeline-content">
                                                <h6 class="fw-bold mb-1 text-<?= getBadge($row['status']) ?>">
                                                    <?= $row['status'] ?>
                                                </h6>
                                                <p class="mb-1"><i class="bi bi-geo-alt-fill text-danger"></i> <?= $row['location'] ?></p>
                                                <?php if ($row['remarks']): ?>
                                                    <small class="text-muted">"<?= $row['remarks'] ?>"</small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <div class="text-center text-muted py-5">
                                        <i class="bi bi-hourglass-split fs-1"></i><br>
                                        No tracking updates yet.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
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
            // Automatically initialize the map with data from PHP
            initEmbeddedMap(
                "<?= htmlspecialchars($data['origin']) ?>",
                "<?= htmlspecialchars($data['destination']) ?>"
            );
        });
    </script>
</body>

</html>