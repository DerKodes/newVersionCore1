<?php
session_start();
include "../api/db.php";
include "../includes/auth_check.php";

/* ================= CREATE SHIPMENT FROM PO ================= */
if (isset($_POST['create_shipment'])) {
    $po_id = (int) $_POST['po_id'];

    // 1. Fetch PO details
    $stmt = $conn->prepare("SELECT * FROM purchase_orders WHERE po_id = ? AND status = 'APPROVED'");
    $stmt->bind_param("i", $po_id);
    $stmt->execute();
    $po = $stmt->get_result()->fetch_assoc();

    if (!$po) die("Purchase Order not approved or invalid.");

    $shipment_code = "SHIP-" . strtoupper(uniqid());

    // Check if 'weight' exists in PO, otherwise default to null
    $weight = isset($po['weight']) ? (float)$po['weight'] : null;

    // 2. Create Shipment in Core 1 (NOW SAVING WEIGHT LOCALLY)
    // Added 'weight' to the INSERT query
    $stmt = $conn->prepare("INSERT INTO shipments (po_id, shipment_code, origin, destination, transport_mode, status, consolidated, weight) VALUES (?, ?, ?, ?, ?, 'BOOKED', 0, ?)");
    // Added 'd' to type string for the decimal/double weight
    $stmt->bind_param("issssd", $po_id, $shipment_code, $po['origin_address'], $po['destination_address'], $po['transport_mode'], $weight);
    $stmt->execute();

    // 3. Update PO Status
    $stmt = $conn->prepare("UPDATE purchase_orders SET status = 'BOOKED' WHERE po_id = ?");
    $stmt->bind_param("i", $po_id);
    $stmt->execute();

    // =========================================================================
    // START: CORE 2 API INTEGRATION
    // =========================================================================
    require_once "../api/core2_integration.php"; 

    $core2Data = [
        'customer_name' => $po['supplier_name'] ?? 'Unknown Supplier', 
        'origin_address' => $po['origin_address'],
        'destination_address' => $po['destination_address'],
        'carrier_type' => $po['transport_mode'],
        'status' => 'pending',
        'weight' => $weight, // Sending the same weight we just saved
        'special_instructions' => 'Ref: ' . $shipment_code
    ];

    $response = Core2Integration::createBooking($core2Data);
    // =========================================================================
    // END: CORE 2 API INTEGRATION
    // =========================================================================

    header("Location: shipments.php?created=1");
    exit();
}

/* ================= UPDATE STATUS ================= */
if (isset($_POST['update_status'])) {
    $shipment_id = (int) $_POST['shipment_id'];
    $new_status  = $_POST['status'];

    $check = $conn->query("SELECT s.status, s.consolidated FROM shipments s WHERE s.shipment_id = $shipment_id")->fetch_assoc();

    if (!$check) die("Invalid shipment.");
    if ($check['status'] === 'BL_ISSUED') die("Shipment locked by HMBL.");

    /* TRANSIT RULES */
    if ($new_status === 'IN_TRANSIT') {
        if ($check['consolidated'] != 1 && !in_array($check['status'], ['READY_TO_DISPATCH', 'BL_ISSUED'])) {
            die("Shipment must be CONSOLIDATED or READY TO DISPATCH before IN TRANSIT.");
        }
    }
    if ($new_status === 'ARRIVED' && $check['status'] !== 'IN_TRANSIT') die("Shipment must be IN TRANSIT before ARRIVED.");
    if ($new_status === 'DELIVERED' && $check['status'] !== 'ARRIVED') die("Shipment must ARRIVE before DELIVERED.");

    $stmt = $conn->prepare("UPDATE shipments SET status = ? WHERE shipment_id = ?");
    $stmt->bind_param("si", $new_status, $shipment_id);
    $stmt->execute();

    header("Location: shipments.php?updated=1");
    exit();
}

/* ================= FETCH SHIPMENTS ================= */
$list = $conn->query("
    SELECT s.*, c.consolidation_code
    FROM shipments s
    LEFT JOIN consolidation_shipments cs ON s.shipment_id = cs.shipment_id
    LEFT JOIN consolidations c ON cs.consolidation_id = c.consolidation_id
    ORDER BY s.created_at DESC
");

/* ================= HELPER: PROGRESS BAR ================= */
function getProgressBar($status)
{
    if ($status === 'BOOKED') return '<span class="badge rounded-pill bg-secondary px-3 py-2"><i class="bi bi-journal-plus me-1"></i> BOOKED</span>';
    if ($status === 'CONSOLIDATED') return '<span class="badge rounded-pill bg-info text-dark px-3 py-2"><i class="bi bi-box-seam me-1"></i> CONSOLIDATED</span>';
    if ($status === 'READY_TO_DISPATCH') return '<span class="badge rounded-pill bg-warning text-dark px-3 py-2"><i class="bi bi-box-arrow-right me-1"></i> READY TO DISPATCH</span>';

    $steps = ['IN_TRANSIT', 'ARRIVED', 'DELIVERED'];
    $currentStep = array_search($status, $steps);

    if ($currentStep === false) return '<span class="badge bg-light text-dark border">' . $status . '</span>';

    $width = (($currentStep + 1) / count($steps)) * 100;
    $color = ($status == 'DELIVERED') ? 'bg-success' : (($status == 'ARRIVED') ? 'bg-warning' : 'bg-primary');

    return '
    <div class="d-flex justify-content-between small text-muted mb-1" style="font-size: 0.70rem; font-weight: bold;">
        <span>Transit</span><span>Arrived</span><span>Done</span>
    </div>
    <div class="progress shadow-sm" style="height: 8px; border-radius: 4px;">
        <div class="progress-bar progress-bar-striped progress-bar-animated ' . $color . '" style="width: ' . $width . '%"></div>
    </div>
    <div class="text-center mt-1"><span class="badge ' . $color . ' rounded-pill">' . $status . '</span></div>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shipment Booking</title>

    <link rel="stylesheet" href="../assets/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <link rel="shortcut icon" href="../assets/slate.png" type="image/x-icon">


    <style>
        /* Global & Layout */
        body {
            font-family: 'Segoe UI', sans-serif;
            transition: background 0.3s, color 0.3s;
        }

        body.sidebar-closed .sidebar {
            margin-left: -250px;
        }

        body.sidebar-closed .content {
            margin-left: 0;
            width: 100%;
        }

        .content {
            width: calc(100% - 250px);
            margin-left: 250px;
            transition: all 0.3s;
        }

        @media (max-width: 768px) {
            .content {
                width: 100%;
                margin-left: 0;
            }
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

        /* Dark Mode Variables */
        :root {
            --dark-bg: #121212;
            --dark-card: #1e1e1e;
            --dark-text: #e0e0e0;
            --dark-border: #333;
            --dark-table-head: #2c2c2c;
        }

        /* Dark Mode Styles */
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
            color: var(--dark-text);
        }

        body.dark-mode .card-header {
            background: rgba(255, 255, 255, 0.05) !important;
            border-bottom: 1px solid var(--dark-border);
        }

        body.dark-mode .card-header h5 {
            color: #fff !important;
        }

        /* Tables */
        body.dark-mode .table {
            color: var(--dark-text);
            border-color: var(--dark-border);
            --bs-table-bg: transparent;
        }

        body.dark-mode .table .table-light {
            background: var(--dark-table-head);
            color: #fff;
            border-color: var(--dark-border);
        }

        body.dark-mode .table .table-light th {
            background: var(--dark-table-head);
            color: #fff;
            border-bottom: 1px solid var(--dark-border);
        }

        body.dark-mode .table tbody td {
            background: var(--dark-card);
            color: var(--dark-text);
            border-color: var(--dark-border);
        }

        body.dark-mode .table-hover tbody tr:hover td {
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
        }

        body.dark-mode .table .text-primary {
            color: #6ea8fe !important;
        }

        body.dark-mode .text-muted {
            color: #a0a0a0 !important;
        }

        /* Forms */
        body.dark-mode .form-select {
            background: #2c2c2c;
            border-color: var(--dark-border);
            color: #fff;
        }

        body.dark-mode .dropdown-menu {
            background: var(--dark-card);
            border: 1px solid var(--dark-border);
        }

        body.dark-mode .dropdown-item {
            color: var(--dark-text);
        }

        body.dark-mode .dropdown-item:hover {
            background: #333;
            color: #fff;
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

    <div class="content" id="content">

        <div class="header">
            <div class="d-flex align-items-center">
                <div class="hamburger" id="hamburger"><i class="bi bi-list"></i></div>
                <h2 class="mb-0 ms-2" id="pageTitle">Shipment Booking</h2>
            </div>

            <div class="theme-toggle-container">
                <div class="d-flex align-items-center me-3">
                    <span class="theme-label me-2 small">Dark Mode</span>
                    <label class="theme-switch"><input type="checkbox" id="themeToggle"><span class="slider"></span></label>
                </div>
                <div class="dropdown">
                    <button class="btn btn-outline-secondary dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i>
                        <span class="d-none d-md-block small"><?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin') ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><a class="dropdown-item" href="#">Settings</a></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item text-danger" href="#" onclick="confirmLogout()">Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 mt-4">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0 fw-bold text-primary"><i class="bi bi-truck me-2"></i> Active Shipments</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="shipmentTable">
                        <thead class="table-light">
                            <tr>
                                <th>Tracking</th>
                                <th>Status</th>
                                <th>Consolidation</th>
                                <th class="text-center">Action</th>
                                <th>Change Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($s = $list->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold text-primary"><?= htmlspecialchars($s['shipment_code']) ?></td>

                                    <td style="min-width: 180px;">
                                        <?= getProgressBar($s['status']) ?>
                                    </td>

                                    <td><?= $s['consolidation_code'] ?: '<span class="text-muted small">Not Assigned</span>' ?></td>

                                    <td class="text-center">
                                        <a href="shipment_details.php?id=<?= $s['shipment_id'] ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3 shadow-sm fw-bold">
                                            <i class="bi bi-folder2-open me-1"></i> View File / Track
                                        </a>
                                    </td>

                                    <td>
                                        <?php if ($s['status'] === 'BL_ISSUED'): ?>
                                            <span class="text-danger fw-bold"><i class="bi bi-lock-fill"></i> LOCKED</span>
                                        <?php else: ?>
                                            <form method="POST" class="d-flex gap-1">
                                                <input type="hidden" name="shipment_id" value="<?= $s['shipment_id'] ?>">
                                                <select name="status" class="form-select form-select-sm">
                                                    <?php
                                                    $statuses = ['BOOKED', 'CONSOLIDATED', 'READY_TO_DISPATCH', 'IN_TRANSIT', 'ARRIVED', 'DELIVERED'];
                                                    foreach ($statuses as $st):
                                                        if ($st === 'IN_TRANSIT' && !$s['consolidated']) continue;
                                                    ?>
                                                        <option value="<?= $st ?>" <?= $s['status'] === $st ? 'selected' : '' ?>>
                                                            <?= $st ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button name="update_status" class="btn btn-sm btn-primary"><i class="bi bi-check"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../assets/main.js"></script>

    <script>
        $(document).ready(function() {
            $('#shipmentTable').DataTable({
                "order": [
                    [0, "desc"]
                ]
            });
        });
    </script>
</body>

</html>