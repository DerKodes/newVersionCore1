<?php
session_start();

// Adjust these paths if your file structure is different
include "../api/db.php";
include "../includes/auth_check.php";
include "../includes/role_check.php";

// 1. ROBUST ADMIN CHECK
$role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
$isAdmin = ($role === 'admin' || $role === 'administrator');

// ================= API HELPER FUNCTION ================= //
function getLogisticsAssets()
{
    $apiUrl = "http://192.168.100.130/logistics1/api/assets.php?action=cargos_vehicles";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2); 
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200 && $response) return json_decode($response, true);
    return null;
}

$apiAssets = getLogisticsAssets();
$vehicles = [];

if ($apiAssets && isset($apiAssets['success']) && $apiAssets['success']) {
    if (isset($apiAssets['data']['vehicles']['items'])) {
        foreach ($apiAssets['data']['vehicles']['items'] as $v) {
            $vehicles[] = ['id' => $v['id'], 'name' => $v['asset_name'], 'type' => 'Vehicle', 'status' => $v['status']];
        }
    }
    if (isset($apiAssets['data']['cargos']['items'])) {
        foreach ($apiAssets['data']['cargos']['items'] as $c) {
            $vehicles[] = ['id' => $c['id'], 'name' => $c['asset_name'], 'type' => 'Cargo', 'status' => $c['status']];
        }
    }
}

/* ================= CREATE CONSOLIDATION ================= */
if ($isAdmin && isset($_POST['create_consolidation'])) {
    $vehicle_asset = $_POST['vehicle_asset'] ?? 'Unknown';
    if (empty($_POST['shipments'])) die("Select at least one shipment.");

    $trip_no = "TRIP-" . date("Ymd") . "-" . strtoupper(substr(uniqid(), -5));
    $shipments = array_map('intval', $_POST['shipments']);
    $user_id = $_SESSION['user_id'];

    $conn->begin_transaction();
    try {
        /* Reference */
        $stmt = $conn->prepare("SELECT transport_mode, origin, destination FROM shipments WHERE shipment_id = ? AND consolidated = 0 FOR UPDATE");
        $stmt->bind_param("i", $shipments[0]);
        $stmt->execute();
        $first = $stmt->get_result()->fetch_assoc();
        if (!$first) throw new Exception("Invalid shipment selection.");

        /* Validate */
        foreach ($shipments as $sid) {
            $stmt = $conn->prepare("SELECT transport_mode, origin, destination FROM shipments WHERE shipment_id = ? FOR UPDATE");
            $stmt->bind_param("i", $sid);
            $stmt->execute();
            $s = $stmt->get_result()->fetch_assoc();

            if (empty($s['transport_mode']) || $s['transport_mode'] !== $first['transport_mode']) {
                throw new Exception("Shipments must have the same Transport Mode (e.g., all Land).");
            }
        }

        /* Create */
        $code = "CONSO-" . strtoupper(uniqid());
        $stmt = $conn->prepare("INSERT INTO consolidations (consolidation_code, trip_no, vehicle_set, transport_mode, origin, destination, status, created_by) VALUES (?, ?, ?, ?, ?, ?, 'OPEN', ?)");
        $stmt->bind_param("ssssssi", $code, $trip_no, $vehicle_asset, $first['transport_mode'], $first['origin'], $first['destination'], $user_id);
        $stmt->execute();
        $conso_id = $conn->insert_id;

        /* Attach */
        $stmtAttach = $conn->prepare("INSERT INTO consolidation_shipments (consolidation_id, shipment_id) VALUES (?, ?)");
        $stmtUpdate = $conn->prepare("UPDATE shipments SET consolidated = 1, status = 'CONSOLIDATED' WHERE shipment_id = ?");

        foreach ($shipments as $sid) {
            $stmtAttach->bind_param("ii", $conso_id, $sid);
            $stmtAttach->execute();
            $stmtUpdate->bind_param("i", $sid);
            $stmtUpdate->execute();
        }

        $conn->commit();
        header("Location: conso.php?success=1");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        die("Consolidation failed: " . $e->getMessage());
    }
}

/* ================= DECONSOLIDATE ================= */
if ($isAdmin && isset($_POST['deconsolidate'])) {
    $conso_id = (int) $_POST['consolidation_id'];
    $reason = trim($_POST['reason']);
    $user_id = $_SESSION['user_id'];

    if (!$conso_id || empty($reason)) die("Invalid request.");

    $chk = $conn->prepare("SELECT 1 FROM hmbl WHERE consolidation_id=?");
    $chk->bind_param("i", $conso_id);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) die("Locked by HMBL.");

    $conn->begin_transaction();

    $list = $conn->query("SELECT shipment_id FROM consolidation_shipments WHERE consolidation_id=$conso_id");
    while ($s = $list->fetch_assoc()) {
        $log = $conn->prepare("INSERT INTO deconsolidation_logs (consolidation_id, shipment_id, deconsolidated_by, reason) VALUES (?, ?, ?, ?)");
        $log->bind_param("iiis", $conso_id, $s['shipment_id'], $user_id, $reason);
        $log->execute();

        $reset = $conn->prepare("UPDATE shipments SET consolidated = 0, status = 'BOOKED' WHERE shipment_id = ?");
        $reset->bind_param("i", $s['shipment_id']);
        $reset->execute();
    }

    $conn->query("DELETE FROM consolidation_shipments WHERE consolidation_id=$conso_id");
    $stmt = $conn->prepare("UPDATE consolidations SET status='DECONSOLIDATED' WHERE consolidation_id=?");
    $stmt->bind_param("i", $conso_id);
    $stmt->execute();

    $conn->commit();
    header("Location: conso.php?decon=1");
    exit();
}

// --- KPI QUERIES ---
$kpi = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM consolidations WHERE status = 'OPEN' OR status = 'DISPATCH') as active_trips,
        (SELECT COUNT(*) FROM shipments WHERE consolidated = 1 AND status != 'DELIVERED') as in_transit_items,
        (SELECT COUNT(*) FROM shipments WHERE status = 'BOOKED' AND consolidated = 0) as pending_allocations
")->fetch_assoc();


function getStatusBadge($status)
{
    $status = strtoupper(trim($status ?? ''));
    if ($status === 'OPEN') return '<span class="badge bg-success-subtle text-success border border-success px-3 rounded-pill"><i class="bi bi-unlock me-1"></i> OPEN</span>';
    if ($status === 'DISPATCH' || $status === 'READY_TO_DISPATCH') return '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning px-3 rounded-pill"><i class="bi bi-truck me-1"></i> READY</span>';
    if ($status === 'DECONSOLIDATED') return '<span class="badge bg-secondary-subtle text-secondary border border-secondary px-3 rounded-pill">DECONSOLIDATED</span>';
    if ($status === '') return '<span class="badge bg-light text-dark border">UNKNOWN</span>';
    return '<span class="badge bg-light text-dark border">' . htmlspecialchars($status) . '</span>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consolidation Management | Core 1</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="../assets/dark-mode.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
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
        .kpi-card:hover { transform: translateY(-3px); }
        .kpi-icon { width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; border-radius: 50%; font-size: 1.2rem; }
        
        /* Table */
        .table thead th { background-color: #f8f9fc; color: #858796; font-weight: 700; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #e3e6f0; }
        .table tbody td { vertical-align: middle; font-size: 0.9rem; padding: 0.75rem; }
        
        /* Dark Mode */
        body.dark-mode { background-color: #121212; color: #e0e0e0; }
        body.dark-mode .header, body.dark-mode .card, body.dark-mode .modal-content { background-color: #1e1e1e; border-color: #333; color: #e0e0e0; }
        body.dark-mode .table { color: #e0e0e0; --bs-table-bg: transparent; }
        body.dark-mode .table thead th { background-color: #2c2c2c; border-color: #444; color: #ccc; }
        body.dark-mode .table tbody td { border-color: #333; }
        body.dark-mode .form-control, body.dark-mode .form-select { background-color: #2c2c2c; border-color: #444; color: #fff; }
        
        .access-denied-blur { filter: blur(8px); pointer-events: none; opacity: 0.6; }
    </style>
</head>

<body>

    <div class="sidebar" id="sidebar">
        <div class="logo"><img src="../assets/slate.png" alt="Logo"></div>
        <div class="system-name">CORE TRANSACTION 1</div>
        <a href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a>
        <a href="pu_order.php"><i class="bi bi-cart me-2"></i> Purchase Orders</a>
        <a href="shipments.php"><i class="bi bi-truck me-2"></i> Shipment Booking</a>
        <a href="conso.php" class="active"><i class="bi bi-boxes me-2"></i> Consolidation</a>
        <a href="hmbl.php"><i class="bi bi-file-earmark-pdf me-2"></i> BL Generator</a>
    </div>

    <div class="content <?= !$isAdmin ? 'access-denied-blur' : '' ?>" id="content">
        <div class="header d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
                <div class="hamburger text-secondary me-3" id="hamburger" style="cursor: pointer;"><i class="bi bi-list fs-4"></i></div>
                <h4 class="mb-0 fw-bold text-dark-emphasis">Consolidation Management</h4>
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
                        <li><a class="dropdown-item" href="#">Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="#" onclick="confirmLogout()">Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="container-fluid p-4">
            
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="card kpi-card h-100 border-start border-4 border-primary">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-uppercase fw-bold text-primary small mb-1">Active Trips</div>
                                <div class="h3 mb-0 fw-bold text-dark"><?= $kpi['active_trips'] ?></div>
                            </div>
                            <div class="kpi-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-map"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card kpi-card h-100 border-start border-4 border-info">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-uppercase fw-bold text-info small mb-1">Items in Transit</div>
                                <div class="h3 mb-0 fw-bold text-dark"><?= $kpi['in_transit_items'] ?></div>
                            </div>
                            <div class="kpi-icon bg-info bg-opacity-10 text-info"><i class="bi bi-box-seam"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card kpi-card h-100 border-start border-4 border-warning">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-uppercase fw-bold text-warning small mb-1">Pending Allocation</div>
                                <div class="h3 mb-0 fw-bold text-dark"><?= $kpi['pending_allocations'] ?></div>
                            </div>
                            <div class="kpi-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-inboxes"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-diagram-3 me-2"></i>Route Optimizer</h6>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-primary bg-primary-subtle border-primary-subtle d-flex justify-content-between align-items-center mb-3">
                                <div class="d-flex align-items-center">
                                    <div class="spinner-grow spinner-grow-sm text-primary me-2" role="status" id="aiLoading" style="display:none;"></div>
                                    <div>
                                        <strong class="text-primary-emphasis"><i class="bi bi-cpu-fill me-1"></i> AI Planner</strong>
                                        <div id="aiStatus" class="small text-muted" style="font-size: 0.8rem;">Ready to optimize routes</div>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm" onclick="runAiOptimization()">
                                    <i class="bi bi-magic me-1"></i> Auto-Group
                                </button>
                            </div>

                            <?php
                            $eligible = $conn->query("
                                SELECT s.* FROM shipments s 
                                WHERE s.status IN ('BOOKED') AND s.consolidated = 0 
                                AND NOT EXISTS (SELECT 1 FROM consolidation_shipments cs WHERE cs.shipment_id = s.shipment_id)
                                ORDER BY origin, destination
                            ");
                            ?>
                            
                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label fw-bold small text-muted text-uppercase">Logistics Asset</label>
                                    <select name="vehicle_asset" class="form-select" required>
                                        <option value="">-- Select Cargo/Vehicle --</option>
                                        <?php if (!empty($vehicles)): ?>
                                            <?php foreach ($vehicles as $v): ?>
                                                <option value="<?= htmlspecialchars($v['name']) ?>" <?= $v['status'] !== 'Operational' ? 'disabled' : '' ?>>
                                                    <?= htmlspecialchars($v['name']) ?> (<?= htmlspecialchars($v['type']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        <optgroup label="Manual Entry">
                                            <option value="Generic Truck A">Generic Truck A</option>
                                            <option value="Generic Van B">Generic Van B</option>
                                        </optgroup>
                                    </select>
                                </div>

                                <label class="form-label fw-bold small text-muted text-uppercase">Available Shipments</label>
                                <div class="table-responsive border rounded mb-3 bg-white" style="max-height: 400px; overflow-y: auto;">
                                    <table class="table table-hover table-sm mb-0" id="shipmentSelectionTable">
                                        <thead class="table-light sticky-top">
                                            <tr>
                                                <th style="width: 40px;" class="text-center"><i class="bi bi-check-lg"></i></th>
                                                <th>Code</th>
                                                <th>Route Info</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($eligible && $eligible->num_rows > 0): ?>
                                                <?php while ($s = $eligible->fetch_assoc()): ?>
                                                    <tr>
                                                        <td class="text-center"><input class="form-check-input" type="checkbox" name="shipments[]" value="<?= $s['shipment_id'] ?>"></td>
                                                        <td class="small fw-bold text-primary"><?= $s['shipment_code'] ?></td>
                                                        <td class="small">
                                                            <div class="d-flex align-items-center">
                                                                <span class="text-truncate fw-semibold origin-text" data-address="<?= htmlspecialchars($s['origin']) ?>" style="max-width: 80px;" title="<?= htmlspecialchars($s['origin']) ?>"><?= explode(',', $s['origin'])[0] ?></span>
                                                                
                                                                <i class="bi bi-arrow-right mx-1 text-muted"></i>
                                                                
                                                                <span class="text-truncate fw-semibold destination-text" data-address="<?= htmlspecialchars($s['destination']) ?>" style="max-width: 80px;" title="<?= htmlspecialchars($s['destination']) ?>"><?= explode(',', $s['destination'])[0] ?></span>
                                                            </div>
                                                            <div class="text-muted" style="font-size: 0.75rem;"><?= $s['transport_mode'] ?></div>
                                                        </td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            <?php else: ?>
                                                <tr><td colspan="3" class="text-center text-muted py-4 small">No eligible shipments found.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <button name="create_consolidation" class="btn btn-success w-100 py-2 fw-bold shadow-sm">
                                    <i class="bi bi-check-circle me-2"></i> Create Consolidation
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    
                    <div class="card shadow-sm border-0 border-top border-4 border-danger mb-4">
                        <div class="card-body py-3">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <h6 class="text-danger fw-bold m-0"><i class="bi bi-cone-striped me-2"></i>Trip Breakdown (Emergency)</h6>
                            </div>
                            <form method="POST" class="row g-2 align-items-center" onsubmit="return confirmFormAction(event, 'This will revert shipments to BOOKED.', 'warning')">
                                <div class="col-md-5">
                                    <select name="consolidation_id" class="form-select form-select-sm" required>
                                        <option value="">Select Open Trip...</option>
                                        <?php
                                        $deconList = $conn->query("SELECT c.consolidation_id, c.consolidation_code, c.vehicle_set FROM consolidations c LEFT JOIN hmbl h ON h.consolidation_id = c.consolidation_id WHERE c.status = 'OPEN' AND h.hmbl_id IS NULL");
                                        while ($c = $deconList->fetch_assoc()): ?>
                                            <option value="<?= $c['consolidation_id'] ?>"><?= $c['consolidation_code'] ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <input type="text" name="reason" class="form-control form-control-sm" placeholder="Reason for breakdown..." required>
                                </div>
                                <div class="col-md-2">
                                    <button name="deconsolidate" class="btn btn-outline-danger btn-sm w-100 fw-bold">Apply</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h6 class="mb-0 fw-bold text-secondary"><i class="bi bi-truck me-2"></i>Active Fleet Consolidations</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle" id="consoTable" width="100%">
                                    <thead>
                                        <tr>
                                            <th>Trip Reference</th>
                                            <th>Route & Asset</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $query = "SELECT c.*, COUNT(cs.shipment_id) as total_shipments, GROUP_CONCAT(s.shipment_code SEPARATOR ', ') as included_codes FROM consolidations c LEFT JOIN consolidation_shipments cs ON c.consolidation_id = cs.consolidation_id LEFT JOIN shipments s ON cs.shipment_id = s.shipment_id GROUP BY c.consolidation_id ORDER BY c.created_at DESC";
                                        $list = $conn->query($query);
                                        ?>
                                        <?php if ($list): while ($c = $list->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold text-primary"><?= $c['consolidation_code'] ?></div>
                                                    <div class="small text-muted font-monospace"><?= $c['trip_no'] ?></div>
                                                    <span class="badge bg-light text-dark border mt-1" title="Items"><i class="bi bi-box-seam me-1"></i> <?= $c['total_shipments'] ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center mb-1">
                                                        <span class="fw-semibold small text-truncate" style="max-width: 100px;"><?= explode(',', $c['origin'])[0] ?></span>
                                                        <i class="bi bi-arrow-right mx-2 text-muted small"></i>
                                                        <span class="fw-semibold small text-truncate" style="max-width: 100px;"><?= explode(',', $c['destination'])[0] ?></span>
                                                    </div>
                                                    <div class="small text-muted"><i class="bi bi-truck me-1"></i> <?= htmlspecialchars($c['vehicle_set']) ?></div>
                                                </td>
                                                <td><?= getStatusBadge($c['status']) ?></td>
                                            </tr>
                                        <?php endwhile; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs/dist/tf.min.js"></script>
    <script src="../scripts/ai_consolidation.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../assets/main.js"></script>

    <script>
        $(document).ready(function() {
            $('#consoTable').DataTable({
                "order": [[0, "desc"]],
                "pageLength": 5,
                "lengthChange": false,
                "language": { "search": "_INPUT_", "searchPlaceholder": "Search trips..." }
            });
        });

        <?php if (!$isAdmin): ?>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Access Denied',
                    text: 'Administrator Access Only.',
                    showConfirmButton: false,
                    footer: '<a href="dashboard.php" class="btn btn-primary btn-sm">Return</a>'
                });
            });
        <?php endif; ?>
    </script>
</body>
</html>