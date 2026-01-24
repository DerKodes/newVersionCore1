<?php
session_start();
include "../api/db.php";
include "../includes/auth_check.php";

/* =================================================================================
   1. BACKEND LOGIC (PRESERVED)
   ================================================================================= */

// --- CREATE SHIPMENT (SINGLE OR BULK) ---
if (isset($_POST['create_shipment']) || isset($_POST['bulk_create_shipment'])) {
    $po_ids = [];
    if (isset($_POST['po_id'])) {
        $po_ids[] = (int)$_POST['po_id'];
    } elseif (isset($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
        $po_ids = array_map('intval', $_POST['selected_ids']);
    }

    if (empty($po_ids)) {
        header("Location: pu_order.php?error=No items selected");
        exit();
    }

    require_once "../api/core2_integration.php";
    $success_count = 0;

    foreach ($po_ids as $po_id) {
        $stmt = $conn->prepare("SELECT * FROM purchase_orders WHERE po_id = ? AND status = 'APPROVED'");
        $stmt->bind_param("i", $po_id);
        $stmt->execute();
        $po = $stmt->get_result()->fetch_assoc();

        if (!$po) continue;

        $shipment_code = "SHIP-" . strtoupper(uniqid());
        $weight = isset($po['weight']) ? (float)$po['weight'] : null;

        $priority = 'STANDARD';
        if (isset($po['sla_agreement']) && (strpos(strtoupper($po['sla_agreement']), 'RUSH') !== false || strpos($po['sla_agreement'], '24H') !== false)) {
            $priority = 'RUSH';
        }

        // Insert into local Shipments table
        $stmt_insert = $conn->prepare("INSERT INTO shipments (po_id, shipment_code, origin, destination, transport_mode, status, consolidated, weight, priority) VALUES (?, ?, ?, ?, ?, 'BOOKED', 0, ?, ?)");
        $stmt_insert->bind_param("issssds", $po_id, $shipment_code, $po['origin_address'], $po['destination_address'], $po['transport_mode'], $weight, $priority);

        if ($stmt_insert->execute()) {
            $stmt_update = $conn->prepare("UPDATE purchase_orders SET status = 'BOOKED' WHERE po_id = ?");
            $stmt_update->bind_param("i", $po_id);
            $stmt_update->execute();

            // --- CORE 2 INTEGRATION (UPDATED) ---
            $core2Data = [
                // Use sender_name from PO as customer name
                'customer_name' => $po['sender_name'] ?? $po['supplier_name'] ?? 'Unknown Sender',
                'origin_address' => $po['origin_address'],
                'destination_address' => $po['destination_address'],
                'carrier_type' => $po['transport_mode'],
                'status' => 'pending',
                'weight' => $weight,
                'special_instructions' => 'Ref: ' . $shipment_code . ($priority === 'RUSH' ? ' [RUSH ORDER]' : ''),

                // --- ADDED GPS COORDINATES ---
                'origin_lat' => $po['origin_lat'] ?? null,
                'origin_lng' => $po['origin_lng'] ?? null,
                'destination_lat' => $po['destination_lat'] ?? null,
                'destination_lng' => $po['destination_lng'] ?? null
            ];

            try {
                Core2Integration::createBooking($core2Data);
            } catch (Exception $e) {
                // Silently fail integration if Core 2 is down, but keep local shipment
                // error_log("Core 2 Sync Failed: " . $e->getMessage());
            }

            $success_count++;
        }
    }
    header("Location: shipments.php?created=" . $success_count);
    exit();
}

// --- UPDATE STATUS ---
if (isset($_POST['update_status'])) {
    $shipment_id = (int) $_POST['shipment_id'];
    $new_status  = $_POST['status'];

    $check = $conn->query("SELECT s.status, s.consolidated FROM shipments s WHERE s.shipment_id = $shipment_id")->fetch_assoc();
    if (!$check) die("Invalid shipment.");
    if ($check['status'] === 'BL_ISSUED') die("Shipment locked by HMBL.");

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

    // Core 3 Sync
    $q_contract = $conn->prepare("SELECT po.contract_number FROM shipments s JOIN purchase_orders po ON s.po_id = po.po_id WHERE s.shipment_id = ?");
    $q_contract->bind_param("i", $shipment_id);
    $q_contract->execute();
    $res_contract = $q_contract->get_result()->fetch_assoc();
    $contract_no = $res_contract['contract_number'] ?? null;

    if ($contract_no) {
        $core3_update_url = "http://192.168.100.130/last/update_shipment_api.php";
        $postData = json_encode(["contract_number" => $contract_no, "status" => $new_status]);
        $ch = curl_init($core3_update_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);
    }
    header("Location: shipments.php?updated=1");
    exit();
}

// --- FETCH DATA & KPI COUNTS ---
$list = $conn->query("SELECT s.*, c.consolidation_code FROM shipments s LEFT JOIN consolidation_shipments cs ON s.shipment_id = cs.shipment_id LEFT JOIN consolidations c ON cs.consolidation_id = c.consolidation_id ORDER BY s.created_at DESC");

// Simple KPI Counters
$kpi = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'IN_TRANSIT' THEN 1 ELSE 0 END) as in_transit,
        SUM(CASE WHEN priority = 'RUSH' THEN 1 ELSE 0 END) as rush,
        SUM(CASE WHEN status = 'BOOKED' OR status = 'CONSOLIDATED' THEN 1 ELSE 0 END) as pending
    FROM shipments
")->fetch_assoc();

// --- HELPER FOR UI ---
function getProgressBar($status)
{
    if ($status === 'BOOKED') return '<span class="badge bg-secondary-subtle text-secondary border border-secondary px-3 rounded-pill">BOOKED</span>';
    if ($status === 'CONSOLIDATED') return '<span class="badge bg-info-subtle text-info border border-info px-3 rounded-pill">CONSOLIDATED</span>';
    if ($status === 'READY_TO_DISPATCH') return '<span class="badge bg-warning-subtle text-warning border border-warning px-3 rounded-pill">READY</span>';

    $steps = ['IN_TRANSIT', 'ARRIVED', 'DELIVERED'];
    $currentStep = array_search($status, $steps);
    if ($currentStep === false) return '<span class="badge bg-light text-dark">' . $status . '</span>';

    $width = (($currentStep + 1) / count($steps)) * 100;
    $color = ($status == 'DELIVERED') ? 'bg-success' : (($status == 'ARRIVED') ? 'bg-warning' : 'bg-primary');

    return '
    <div class="d-flex justify-content-between small text-muted mb-1 fw-semibold" style="font-size: 0.65rem;">
        <span>Transit</span><span>Arrived</span><span>Done</span>
    </div>
    <div class="progress" style="height: 6px; border-radius: 10px; background-color: #e9ecef;">
        <div class="progress-bar ' . $color . '" role="progressbar" style="width: ' . $width . '%"></div>
    </div>
    <div class="text-center mt-1"><span class="badge ' . $color . ' rounded-pill shadow-sm" style="font-size: 0.7em;">' . $status . '</span></div>';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Shipment Booking | Core 1</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="../assets/dark-mode.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <link rel="shortcut icon" href="../assets/slate.png" type="image/x-icon">

    <style>
        :root {
            --primary-color: #4e73df;
            --secondary-color: #858796;
        }

        body {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            background-color: #f8f9fc;
        }

        /* Sidebar & Layout */
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
            transition: all 0.3s ease;
        }

        @media (max-width: 768px) {
            .content {
                width: 100%;
                margin-left: 0;
            }
        }

        /* Header */
        .header {
            background: #fff;
            border-bottom: 1px solid #e3e6f0;
            padding: 1rem 1.5rem;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 .15rem 1.75rem 0 rgba(58, 59, 69, .15);
        }

        /* Cards & KPI */
        .card {
            border: none;
            border-radius: 0.75rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
            transition: transform 0.2s;
        }

        .kpi-card:hover {
            transform: translateY(-3px);
        }

        .kpi-icon {
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 1.2rem;
        }

        /* Table Styles */
        .table thead th {
            background-color: #f8f9fc;
            color: #858796;
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid #e3e6f0;
        }

        .table tbody td {
            vertical-align: middle;
            font-size: 0.9rem;
            padding: 1rem 0.75rem;
        }

        .tracking-code {
            font-family: 'Roboto Mono', monospace;
            font-weight: 600;
            color: var(--primary-color);
            letter-spacing: 0.5px;
        }

        /* Badges & Inputs */
        .badge {
            font-weight: 600;
            padding: 0.5em 0.8em;
        }

        .form-select-sm {
            border-radius: 0.35rem 0 0 0.35rem;
            font-size: 0.85rem;
        }

        .btn-action-go {
            border-radius: 0 0.35rem 0.35rem 0;
        }

        /* Dark Mode Overrides */
        body.dark-mode {
            background-color: #121212;
            color: #e0e0e0;
        }

        body.dark-mode .header,
        body.dark-mode .card {
            background-color: #1e1e1e;
            border-color: #333;
            color: #e0e0e0;
        }

        body.dark-mode .table {
            color: #e0e0e0;
            --bs-table-bg: transparent;
        }

        body.dark-mode .table thead th {
            background-color: #2c2c2c;
            border-color: #444;
            color: #ccc;
        }

        body.dark-mode .table tbody td {
            border-color: #333;
        }

        body.dark-mode .form-control,
        body.dark-mode .form-select {
            background-color: #2c2c2c;
            border-color: #444;
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
        <div class="header d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
                <div class="hamburger text-secondary me-3" id="hamburger" style="cursor: pointer;"><i class="bi bi-list fs-4"></i></div>
                <h4 class="mb-0 fw-bold text-dark-emphasis">Shipment Management</h4>
            </div>

            <div class="d-flex align-items-center gap-3">
                <div class="theme-toggle-container d-flex align-items-center">
                    <i class="bi bi-moon-stars me-2 text-muted"></i>
                    <label class="theme-switch"><input type="checkbox" id="themeToggle"><span class="slider"></span></label>
                </div>
                <div class="dropdown">
                    <button class="btn btn-light border dropdown-toggle d-flex align-items-center gap-2 rounded-pill px-3" type="button" data-bs-toggle="dropdown">
                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; font-size: 0.8rem;">
                            <?= strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1)) ?>
                        </div>
                        <span class="d-none d-md-block small fw-semibold"><?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin') ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                        <li><a class="dropdown-item" href="#"><i class="bi bi-gear me-2"></i> Settings</a></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item text-danger" href="#" onclick="confirmLogout()"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="container-fluid p-4">

            <div class="row g-3 mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="card kpi-card h-100 border-start border-4 border-primary">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-uppercase fw-bold text-primary small mb-1">Total Shipments</div>
                                <div class="h3 mb-0 fw-bold text-dark"><?= $kpi['total'] ?></div>
                            </div>
                            <div class="kpi-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-box-seam"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card kpi-card h-100 border-start border-4 border-warning">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-uppercase fw-bold text-warning small mb-1">Pending/Booked</div>
                                <div class="h3 mb-0 fw-bold text-dark"><?= $kpi['pending'] ?></div>
                            </div>
                            <div class="kpi-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-clock-history"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card kpi-card h-100 border-start border-4 border-info">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-uppercase fw-bold text-info small mb-1">In Transit</div>
                                <div class="h3 mb-0 fw-bold text-dark"><?= $kpi['in_transit'] ?></div>
                            </div>
                            <div class="kpi-icon bg-info bg-opacity-10 text-info"><i class="bi bi-truck"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card kpi-card h-100 border-start border-4 border-danger">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-uppercase fw-bold text-danger small mb-1">Rush Orders</div>
                                <div class="h3 mb-0 fw-bold text-dark"><?= $kpi['rush'] ?></div>
                            </div>
                            <div class="kpi-icon bg-danger bg-opacity-10 text-danger"><i class="bi bi-lightning-fill"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow mb-4">
                <div class="card-header bg-white py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 fw-bold text-primary"><i class="bi bi-list-task me-2"></i>Active Shipments Registry</h6>
                    <button class="btn btn-sm btn-outline-primary rounded-pill"><i class="bi bi-download me-1"></i> Export CSV</button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="shipmentTable" width="100%" cellspacing="0">
                            <thead>
                                <tr>
                                    <th>Tracking Number</th>
                                    <th>Status & Progress</th>
                                    <th>Consolidation Ref</th>
                                    <th>Details</th>
                                    <th style="min-width: 160px;">Update Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($s = $list->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <div class="tracking-code fs-6">
                                                <i class="bi bi-hash text-muted me-1"></i><?= htmlspecialchars($s['shipment_code']) ?>
                                            </div>
                                            <?php if (isset($s['priority']) && $s['priority'] === 'RUSH'): ?>
                                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 mt-1">
                                                    <i class="bi bi-lightning-fill"></i> RUSH
                                                </span>
                                            <?php endif; ?>
                                            <div class="small text-muted mt-1">
                                                <i class="bi bi-geo-alt"></i> <?= explode(',', $s['destination'])[0] ?>
                                            </div>
                                        </td>

                                        <td style="width: 250px;">
                                            <?= getProgressBar($s['status']) ?>
                                        </td>

                                        <td>
                                            <?php if ($s['consolidation_code']): ?>
                                                <a href="conso.php?search=<?= $s['consolidation_code'] ?>" class="text-decoration-none fw-semibold">
                                                    <i class="bi bi-box-seam me-1"></i><?= $s['consolidation_code'] ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="badge bg-light text-muted border fw-normal">Unassigned</span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <a href="shipment_details.php?id=<?= $s['shipment_id'] ?>" class="btn btn-sm btn-light border text-primary">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                        </td>

                                        <td>
                                            <?php if ($s['status'] === 'BL_ISSUED'): ?>
                                                <div class="d-flex align-items-center text-secondary small p-2 bg-light rounded">
                                                    <i class="bi bi-lock-fill me-2 fs-5"></i>
                                                    <span class="fw-semibold">Locked (BL Issued)</span>
                                                </div>
                                            <?php else: ?>
                                                <form method="POST">
                                                    <input type="hidden" name="shipment_id" value="<?= $s['shipment_id'] ?>">
                                                    <div class="input-group input-group-sm">
                                                        <select name="status" class="form-select form-select-sm border-secondary-subtle">
                                                            <?php
                                                            $statuses = ['BOOKED', 'CONSOLIDATED', 'READY_TO_DISPATCH', 'IN_TRANSIT', 'ARRIVED', 'DELIVERED'];
                                                            foreach ($statuses as $st):
                                                                if ($st === 'IN_TRANSIT' && !$s['consolidated']) continue;
                                                            ?>
                                                                <option value="<?= $st ?>" <?= $s['status'] === $st ? 'selected' : '' ?>><?= str_replace('_', ' ', $st) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button name="update_status" class="btn btn-primary btn-action-go" title="Update Status">
                                                            <i class="bi bi-arrow-right"></i>
                                                        </button>
                                                    </div>
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
                ],
                "pageLength": 10,
                "language": {
                    "search": "_INPUT_",
                    "searchPlaceholder": "Search tracking..."
                }
            });

            // Re-apply tooltip if table redraws
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
            var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl)
            })
        });
    </script>
</body>

</html>