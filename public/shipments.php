<?php
session_start();
include "../api/db.php";
include "../includes/auth_check.php";

/* ================= CREATE SHIPMENT FROM PO ================= */
if (isset($_POST['create_shipment'])) {

    $po_id = (int) $_POST['po_id'];

    $stmt = $conn->prepare("
        SELECT *
        FROM purchase_orders
        WHERE po_id = ? AND status = 'APPROVED'
    ");
    $stmt->bind_param("i", $po_id);
    $stmt->execute();
    $po = $stmt->get_result()->fetch_assoc();

    if (!$po) {
        die("Purchase Order not approved or invalid.");
    }

    $shipment_code = "SHIP-" . strtoupper(uniqid());

    $stmt = $conn->prepare("
        INSERT INTO shipments
        (po_id, shipment_code, origin, destination, transport_mode, status, consolidated)
        VALUES (?, ?, ?, ?, ?, 'BOOKED', 0)
    ");
    $stmt->bind_param(
        "issss",
        $po_id,
        $shipment_code,
        $po['origin_address'],
        $po['destination_address'],
        $po['transport_mode']
    );
    $stmt->execute();

    $stmt = $conn->prepare("
        UPDATE purchase_orders
        SET status = 'BOOKED'
        WHERE po_id = ?
    ");
    $stmt->bind_param("i", $po_id);
    $stmt->execute();

    header("Location: shipments.php?created=1");
    exit();
}

/* ================= UPDATE STATUS ================= */
if (isset($_POST['update_status'])) {

    $shipment_id = (int) $_POST['shipment_id'];
    $new_status  = $_POST['status'];

    $check = $conn->query("
        SELECT s.status, s.consolidated, cs.consolidation_id
        FROM shipments s
        LEFT JOIN consolidation_shipments cs
            ON s.shipment_id = cs.shipment_id
        WHERE s.shipment_id = $shipment_id
    ")->fetch_assoc();

    if (!$check) {
        die("Invalid shipment.");
    }

    // 🔒 HBL hard lock
    if ($check['status'] === 'BL_ISSUED') {
        die("Shipment locked by HMBL.");
    }

    /* ================= IN TRANSIT RULE ================= */
    if ($new_status === 'IN_TRANSIT') {
        if (
            $check['consolidated'] != 1 &&
            !in_array($check['status'], ['READY_TO_DISPATCH', 'BL_ISSUED'])
        ) {
            die("Shipment must be CONSOLIDATED or READY TO DISPATCH before IN TRANSIT.");
        }
    }

    if ($new_status === 'ARRIVED' && $check['status'] !== 'IN_TRANSIT') {
        die("Shipment must be IN TRANSIT before ARRIVED.");
    }

    if ($new_status === 'DELIVERED' && $check['status'] !== 'ARRIVED') {
        die("Shipment must ARRIVE before DELIVERED.");
    }

    // Apply update
    $stmt = $conn->prepare("
        UPDATE shipments
        SET status = ?
        WHERE shipment_id = ?
    ");
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

/* ================= HELPER: SMART STATUS UI ================= */
function getProgressBar($status)
{
    // GROUP 1: PREPARATION STAGES (Show Badges)
    if ($status === 'BOOKED') {
        return '<span class="badge rounded-pill bg-secondary px-3 py-2"><i class="bi bi-journal-plus me-1"></i> BOOKED</span>';
    }
    
    if ($status === 'CONSOLIDATED') {
        return '<span class="badge rounded-pill bg-info text-dark px-3 py-2"><i class="bi bi-box-seam me-1"></i> CONSOLIDATED</span>';
    }
    
    if ($status === 'READY_TO_DISPATCH') {
        return '<span class="badge rounded-pill bg-warning text-dark px-3 py-2"><i class="bi bi-box-arrow-right me-1"></i> READY TO DISPATCH</span>';
    }

    // GROUP 2: MOVEMENT STAGES (Show Progress Bar)
    $steps = ['IN_TRANSIT', 'ARRIVED', 'DELIVERED'];
    $currentStep = array_search($status, $steps);
    
    // Default fallback
    if ($currentStep === false) return '<span class="badge bg-light text-dark border">'.$status.'</span>';

    // Calculate width: 33%, 66%, 100%
    $width = (($currentStep + 1) / count($steps)) * 100;
    
    // Color Logic
    $color = ($status == 'DELIVERED') ? 'bg-success' : 'bg-primary';
    if ($status == 'ARRIVED') $color = 'bg-warning'; // Orange for attention

    return '
    <div class="d-flex justify-content-between small text-muted mb-1" style="font-size: 0.70rem; text-transform: uppercase; font-weight: bold;">
        <span>Transit</span>
        <span>Arrived</span>
        <span>Done</span>
    </div>
    <div class="progress shadow-sm" style="height: 8px; border-radius: 4px;">
        <div class="progress-bar progress-bar-striped progress-bar-animated '.$color.'" role="progressbar" style="width: '.$width.'%"></div>
    </div>
    <div class="text-center mt-1">
        <span class="badge '.$color.' rounded-pill">'.$status.'</span>
    </div>';
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
    <link rel="stylesheet" href="../assets/leaflet.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.css" />

    <style>
        .leaflet-routing-container { display: none !important; }

        /* Global Font & Transition */
        body { font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; transition: background-color 0.3s, color 0.3s; }
        
        /* Layout Fixes */
        body.sidebar-closed .sidebar { margin-left: -250px; }
        body.sidebar-closed .content { margin-left: 0; width: 100%; }
        .content { width: calc(100% - 250px); margin-left: 250px; transition: all 0.3s; }
        @media (max-width: 768px) { .content { width: 100%; margin-left: 0; } }

        /* Dark Mode Variables */
        :root {
            --dark-bg: #121212; --dark-card: #1e1e1e; --dark-text: #e0e0e0;
            --dark-border: #333333; --dark-table-head: #2c2c2c; --dark-hover: rgba(255,255,255,0.05);
        }

        /* Dark Mode Styles */
        body.dark-mode { background-color: var(--dark-bg) !important; color: var(--dark-text) !important; }
        
        body.dark-mode .card { background-color: var(--dark-card); border: 1px solid var(--dark-border); color: var(--dark-text); }
        body.dark-mode .card-header { background-color: rgba(255, 255, 255, 0.05) !important; border-bottom: 1px solid var(--dark-border); }
        body.dark-mode .card-header h5 { color: #fff !important; }
        
        body.dark-mode .header { background-color: var(--dark-card); border-bottom: 1px solid var(--dark-border); color: var(--dark-text); }
        
        /* Tables */
        body.dark-mode .table { color: var(--dark-text); border-color: var(--dark-border); --bs-table-bg: transparent; }
        body.dark-mode .table .table-light { background-color: var(--dark-table-head); color: #fff; border-color: var(--dark-border); }
        body.dark-mode .table .table-light th { background-color: var(--dark-table-head); color: #fff; border-bottom: 1px solid var(--dark-border); }
        body.dark-mode .table tbody td { background-color: var(--dark-card); color: var(--dark-text); border-color: var(--dark-border); }
        body.dark-mode .table-hover tbody tr:hover td { background-color: var(--dark-hover); color: #fff; }
        body.dark-mode .table .text-primary { color: #6ea8fe !important; } /* Lighter blue links */

        /* Dropdowns & Forms */
        body.dark-mode .form-select, body.dark-mode input[type="search"] { background-color: #2c2c2c; border-color: var(--dark-border); color: #fff; }
        body.dark-mode .dropdown-menu { background-color: var(--dark-card); border: 1px solid var(--dark-border); }
        body.dark-mode .dropdown-item { color: var(--dark-text); }
        body.dark-mode .dropdown-item:hover { background-color: #333; color: #fff; }
        
        body.dark-mode .text-muted { color: #a0a0a0 !important; }
        
        /* Modal Fix */
        body.dark-mode .modal-content { background-color: var(--dark-card); border: 1px solid var(--dark-border); color: var(--dark-text); }
        body.dark-mode .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
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
                    <label class="theme-switch">
                        <input type="checkbox" id="themeToggle">
                        <span class="slider"></span>
                    </label>
                </div>
                <div class="dropdown">
                    <button class="btn btn-outline-secondary dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i>
                        <span class="d-none d-md-block small"><?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin') ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><a class="dropdown-item" href="#">Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="#" onclick="confirmLogout()">Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <?php if (isset($_GET['created'])): ?>
            <?php endif; ?>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0 fw-bold text-primary"><i class="bi bi-truck me-2"></i> Active Shipments</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="shipmentTable">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Tracking</th>
                                <th>Status</th>
                                <th>Consolidation</th>
                                <th>Map</th>
                                <th>Change Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($s = $list->fetch_assoc()): ?>
                                <tr>
                                    <td><?= $s['shipment_id'] ?></td>
                                    <td class="fw-bold text-primary"><?= htmlspecialchars($s['shipment_code']) ?></td>

                                    <td style="min-width: 180px;">
                                        <?= getProgressBar($s['status']) ?>
                                    </td>

                                    <td><?= $s['consolidation_code'] ?: '<span class="text-muted small">Not Assigned</span>' ?></td>

                                    <td class="text-center">
                                        <?php if ($s['status'] === 'IN_TRANSIT' || $s['status'] === 'ARRIVED'): ?>
                                            <button class="btn btn-outline-primary btn-sm rounded-pill px-3 shadow-sm fw-bold"
                                                onclick='openShipmentMap(
                                                    <?= json_encode($s['origin']) ?>, 
                                                    <?= json_encode($s['destination']) ?>, 
                                                    <?= json_encode($s['shipment_code']) ?>
                                                )'>
                                                <i class="bi bi-geo-alt-fill me-1"></i> Track
                                            </button>
                                        <?php elseif ($s['status'] === 'DELIVERED'): ?>
                                            <button class="btn btn-outline-success btn-sm rounded-pill px-3" disabled>
                                                <i class="bi bi-check-circle-fill me-1"></i> Done
                                            </button>
                                        <?php else: ?>
                                            <span class="badge bg-light text-secondary border rounded-pill px-3 py-2">
                                                <i class="bi bi-hourglass-split me-1"></i> Wait
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if ($s['status'] === 'BL_ISSUED'): ?>
                                            <span class="text-danger fw-bold"><i class="bi bi-lock-fill"></i> LOCKED</span>
                                        <?php else: ?>
                                            <form method="POST" class="d-flex gap-1">
                                                <input type="hidden" name="shipment_id" value="<?= $s['shipment_id'] ?>">
                                                <select name="status" class="form-select form-select-sm">
                                                    <?php
                                                    $statuses = ['BOOKED','CONSOLIDATED','READY_TO_DISPATCH','IN_TRANSIT','ARRIVED','DELIVERED'];
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

    <div class="modal fade" id="mapModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="mapTitle"><i class="bi bi-map me-2"></i> Shipment Tracking</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <div id="shipmentMap" style="width: 100%; height: 500px;"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script src="../scripts/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.js"></script>
    <script src="../scripts/shipment_map.js" defer></script>

    <script src="../assets/main.js"></script>

    <script>
        $(document).ready(function() {
            // DataTables
            $('#shipmentTable').DataTable({
                "order": [[ 0, "desc" ]]
            });
        });
    </script>
</body>
</html>