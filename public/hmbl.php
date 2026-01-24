<?php
session_start();
include "../api/db.php";
include "../includes/auth_check.php";
include "../includes/role_check.php";

// 1. ROBUST ADMIN CHECK
$role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
$isAdmin = ($role === 'admin' || $role === 'administrator');

// ================= API HELPER: FETCH LOGISTICS ASSETS ================= //
function getLogisticsAssets() {
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

// ================= PREPARE ASSET MAPPING ================= //
$assetMap = [];
$apiData = getLogisticsAssets();

if ($apiData && isset($apiData['success']) && $apiData['success']) {
    $vehicles = $apiData['data']['vehicles']['items'] ?? $apiData['vehicles']['items'] ?? [];
    $cargos   = $apiData['data']['cargos']['items']   ?? $apiData['cargos']['items']   ?? [];
    $allItems = array_merge($vehicles, $cargos);
    
    foreach ($allItems as $item) {
        if (isset($item['asset_name']) && isset($item['tracking_number'])) {
            $cleanName = strtolower(trim($item['asset_name']));
            $assetMap[$cleanName] = $item['tracking_number'];
        }
    }
}

/* ================= CREATE HMBL HANDLER ================= */
if ($isAdmin && isset($_POST['create_hmbl'])) {
    $conso_id = (int) $_POST['consolidation_id'];
    $user_id  = $_SESSION['user_id'];
    
    if (!$conso_id) {
        header("Location: hmbl.php?error=Invalid Consolidation ID");
        exit();
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT status FROM consolidations WHERE consolidation_id=? FOR UPDATE");
        $stmt->bind_param("i", $conso_id);
        $stmt->execute();
        $c = $stmt->get_result()->fetch_assoc();
        
        // Validation 1: Check Status
        if (!$c || $c['status'] !== 'OPEN') throw new Exception("Consolidation is locked or already dispatched.");

        // Validation 2: Check if HMBL already exists
        $chk = $conn->prepare("SELECT 1 FROM hmbl WHERE consolidation_id=?");
        $chk->bind_param("i", $conso_id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) throw new Exception("A Bill of Lading already exists for this trip.");

        // Validation 3: Check Shipments
        $list = $conn->prepare("SELECT s.shipment_id, s.status, s.consolidated FROM shipments s JOIN consolidation_shipments cs ON s.shipment_id = cs.shipment_id WHERE cs.consolidation_id=?");
        $list->bind_param("i", $conso_id);
        $list->execute();
        $result = $list->get_result();
        if ($result->num_rows < 1) throw new Exception("No shipments found in this consolidation.");

        while ($s = $result->fetch_assoc()) {
            if ($s['consolidated'] != 1) throw new Exception("Shipment data mismatch (Not consolidated).");
            if ($s['status'] !== 'CONSOLIDATED') throw new Exception("Shipment status invalid.");
        }

        // --- EXECUTION ---
        $hmbl_no = "HMBL-" . date("Y") . "-" . strtoupper(uniqid());
        $stmt = $conn->prepare("INSERT INTO hmbl (hmbl_no, consolidation_id, shipper, consignee, notify_party, port_of_loading, port_of_discharge, vessel, voyage, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sisssssssi", $hmbl_no, $conso_id, $_POST['shipper'], $_POST['consignee'], $_POST['notify_party'], $_POST['pol'], $_POST['pod'], $_POST['vessel'], $_POST['voyage'], $user_id);
        $stmt->execute();
        $hmbl_id = $conn->insert_id;

        $result->data_seek(0);
        $stmtAttach = $conn->prepare("INSERT INTO hmbl_shipments (hmbl_id, shipment_id) VALUES (?, ?)");
        while ($s = $result->fetch_assoc()) {
            $stmtAttach->bind_param("ii", $hmbl_id, $s['shipment_id']);
            $stmtAttach->execute();
        }

        // Update Consolidation Status
        $stmt = $conn->prepare("UPDATE consolidations SET status='DISPATCH' WHERE consolidation_id=?");
        $stmt->bind_param("i", $conso_id);
        $stmt->execute();

        // Update Shipment Statuses
        $stmt = $conn->prepare("UPDATE shipments s JOIN consolidation_shipments cs ON s.shipment_id = cs.shipment_id SET s.status = 'DISPATCH', s.consolidated = 1 WHERE cs.consolidation_id=?");
        $stmt->bind_param("i", $conso_id);
        $stmt->execute();

        $conn->commit();
        header("Location: hmbl.php?success=1");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        header("Location: hmbl.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

// --- KPI QUERIES ---
$kpi = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM hmbl) as total_issued,
        (SELECT COUNT(*) FROM consolidations c LEFT JOIN hmbl h ON c.consolidation_id = h.consolidation_id WHERE c.status = 'OPEN' AND h.hmbl_id IS NULL) as pending_issuance,
        (SELECT COUNT(DISTINCT h.hmbl_id) 
         FROM hmbl h 
         JOIN consolidation_shipments cs ON h.consolidation_id = cs.consolidation_id
         JOIN shipments s ON cs.shipment_id = s.shipment_id
         WHERE s.priority = 'RUSH') as rush_docs
")->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bill of Lading Generator | Core 1</title>
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
        .table tbody td { vertical-align: middle; font-size: 0.9rem; padding: 1rem 0.75rem; }
        
        /* Dark Mode */
        body.dark-mode { background-color: #121212; color: #e0e0e0; }
        body.dark-mode .header, body.dark-mode .card { background-color: #1e1e1e; border-color: #333; color: #e0e0e0; }
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
        <a href="conso.php"><i class="bi bi-boxes me-2"></i> Consolidation</a>
        <a href="hmbl.php" class="active"><i class="bi bi-file-earmark-pdf me-2"></i> BL Generator</a>
    </div>

    <div class="content <?= !$isAdmin ? 'access-denied-blur' : '' ?>" id="content">
        <div class="header d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
                <div class="hamburger text-secondary me-3" id="hamburger" style="cursor: pointer;"><i class="bi bi-list fs-4"></i></div>
                <h4 class="mb-0 fw-bold text-dark-emphasis">House Bill of Lading</h4>
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
                                <div class="text-uppercase fw-bold text-primary small mb-1">Total Issued</div>
                                <div class="h3 mb-0 fw-bold text-dark"><?= $kpi['total_issued'] ?></div>
                            </div>
                            <div class="kpi-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-file-earmark-text"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card kpi-card h-100 border-start border-4 border-warning">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-uppercase fw-bold text-warning small mb-1">Pending Issuance</div>
                                <div class="h3 mb-0 fw-bold text-dark"><?= $kpi['pending_issuance'] ?></div>
                            </div>
                            <div class="kpi-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-hourglass-split"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card kpi-card h-100 border-start border-4 border-danger">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-uppercase fw-bold text-danger small mb-1">Rush Documents</div>
                                <div class="h3 mb-0 fw-bold text-dark"><?= $kpi['rush_docs'] ?></div>
                            </div>
                            <div class="kpi-icon bg-danger bg-opacity-10 text-danger"><i class="bi bi-lightning-charge"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white py-3">
                            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-printer me-2"></i>Document Generator</h6>
                        </div>
                        <div class="card-body">
                            <?php
                            // KEY FIX: Filter out Consolidations that already have an HMBL
                            // We join hmbl table and check where hmbl_id IS NULL
                            $consos = $conn->query("
                                SELECT c.consolidation_id, c.consolidation_code, c.trip_no, c.origin, c.destination, c.vehicle_set, po.sender_name, po.receiver_name,
                                (SELECT COUNT(*) FROM consolidation_shipments cs JOIN shipments s ON cs.shipment_id = s.shipment_id WHERE cs.consolidation_id = c.consolidation_id AND s.priority = 'RUSH') as rush_count,
                                (SELECT COUNT(*) FROM consolidation_shipments cs WHERE cs.consolidation_id = c.consolidation_id) as total_shipments
                                FROM consolidations c
                                JOIN consolidation_shipments cs ON c.consolidation_id = cs.consolidation_id
                                JOIN shipments s ON cs.shipment_id = s.shipment_id
                                JOIN purchase_orders po ON s.po_id = po.po_id
                                LEFT JOIN hmbl h ON c.consolidation_id = h.consolidation_id 
                                WHERE c.status='OPEN' 
                                AND h.hmbl_id IS NULL 
                                GROUP BY c.consolidation_id
                                ORDER BY c.created_at DESC
                            ");
                            ?>
                            <form method="POST" id="issueBlForm">
                                <div class="mb-4">
                                    <label class="form-label fw-bold small text-muted text-uppercase">1. Select Consolidation</label>
                                    <select name="consolidation_id" id="consoSelect" class="form-select form-select-lg border-primary" required>
                                        <option value="">-- Choose Consolidation --</option>
                                        <?php if ($consos && $consos->num_rows > 0): ?>
                                            <?php while ($c = $consos->fetch_assoc()): ?>
                                                <?php 
                                                    $dbAssetName = strtolower(trim($c['vehicle_set']));
                                                    $trackingNumber = $assetMap[$dbAssetName] ?? '';
                                                    if (!$trackingNumber) {
                                                        foreach ($assetMap as $apiName => $track) {
                                                            if (!empty($dbAssetName) && strpos($apiName, $dbAssetName) === 0) { $trackingNumber = $track; break; }
                                                        }
                                                    }
                                                    $rushLabel = ($c['rush_count'] > 0) ? ' [⚡ RUSH]' : '';
                                                ?>
                                                <option value="<?= $c['consolidation_id'] ?>"
                                                    data-trip="<?= $c['trip_no'] ?>"
                                                    data-shipper="<?= htmlspecialchars($c['sender_name']) ?>"
                                                    data-consignee="<?= htmlspecialchars($c['receiver_name']) ?>"
                                                    data-pol="<?= htmlspecialchars($c['origin']) ?>"
                                                    data-pod="<?= htmlspecialchars($c['destination']) ?>"
                                                    data-vessel="<?= htmlspecialchars($trackingNumber) ?>"
                                                >
                                                    <?= $c['consolidation_code'] ?> (<?= $c['total_shipments'] ?> Items) <?= $rushLabel ?>
                                                </option>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <option value="" disabled>No pending consolidations available.</option>
                                        <?php endif; ?>
                                    </select>
                                    <div class="form-text small"><i class="bi bi-info-circle me-1"></i> Only consolidations without existing BLs are shown.</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold small text-muted text-uppercase">2. Party Details</label>
                                    <div class="row g-2">
                                        <div class="col-12"><input name="shipper" id="shipper" class="form-control form-control-sm" placeholder="Shipper Name" required></div>
                                        <div class="col-12"><input name="consignee" id="consignee" class="form-control form-control-sm" placeholder="Consignee Name" required></div>
                                        <div class="col-12"><input name="notify_party" id="notify" class="form-control form-control-sm" placeholder="Notify Party"></div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold small text-muted text-uppercase">3. Routing & Vessel</label>
                                    <div class="row g-2 mb-2">
                                        <div class="col-6"><input name="pol" id="pol" class="form-control form-control-sm" placeholder="Port of Loading" required></div>
                                        <div class="col-6"><input name="pod" id="pod" class="form-control form-control-sm" placeholder="Port of Discharge" required></div>
                                    </div>
                                    <div class="input-group input-group-sm mb-2">
                                        <span class="input-group-text bg-light"><i class="bi bi-upc-scan"></i></span>
                                        <input name="vessel" id="vessel" class="form-control" placeholder="Asset Tracking ID (Auto)">
                                    </div>
                                    <input name="voyage" id="trip_no" class="form-control form-control-sm bg-light" placeholder="Trip Number" readonly>
                                </div>

                                <button name="create_hmbl" type="submit" class="btn btn-primary w-100 py-2 fw-bold shadow-sm">
                                    <i class="bi bi-file-earmark-lock me-2"></i> Issue & Lock Consolidation
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white py-3">
                            <h6 class="mb-0 fw-bold text-secondary"><i class="bi bi-archive me-2"></i>Issued Documents Registry</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle" id="hmblTable" width="100%">
                                    <thead>
                                        <tr>
                                            <th>HMBL Number</th>
                                            <th>Reference Info</th>
                                            <th>Issuance Date</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $list = $conn->query("
                                            SELECT h.hmbl_id, h.hmbl_no, c.consolidation_code, h.created_at, c.trip_no,
                                            (SELECT COUNT(*) FROM consolidation_shipments cs JOIN shipments s ON cs.shipment_id = s.shipment_id WHERE cs.consolidation_id = h.consolidation_id AND s.priority = 'RUSH') as rush_count,
                                            (SELECT COUNT(*) FROM consolidation_shipments cs WHERE cs.consolidation_id = h.consolidation_id) as total_shipments
                                            FROM hmbl h 
                                            JOIN consolidations c ON h.consolidation_id = c.consolidation_id 
                                            ORDER BY h.created_at DESC
                                        "); 
                                        ?>
                                        <?php while ($h = $list->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold text-primary"><?= $h['hmbl_no'] ?></div>
                                                    <?php if ($h['rush_count'] > 0): ?>
                                                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 mt-1">
                                                            <i class="bi bi-lightning-fill"></i> RUSH
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="small fw-semibold"><?= $h['consolidation_code'] ?></div>
                                                    <div class="small text-muted font-monospace"><?= $h['trip_no'] ?></div>
                                                    <span class="badge bg-light text-secondary border mt-1">
                                                        <i class="bi bi-box-seam"></i> <?= $h['total_shipments'] ?> Items
                                                    </span>
                                                </td>
                                                <td class="small text-muted"><?= date("M d, Y", strtotime($h['created_at'])) ?></td>
                                                <td>
                                                    <a href="view_hmbl.php?id=<?= $h['hmbl_id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                                                        <i class="bi bi-eye me-1"></i> View PDF
                                                    </a>
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
            $('#hmblTable').DataTable({ 
                "order": [[2, "desc"]],
                "pageLength": 8,
                "lengthChange": false,
                "language": { "search": "_INPUT_", "searchPlaceholder": "Search document..." }
            });
        });

        // Loading Effect Logic
        document.getElementById('issueBlForm').addEventListener('submit', function(e) {
            e.preventDefault(); // Stop immediate submission
            const form = this;

            // Simple validation check before showing loader
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            Swal.fire({
                title: 'Syncing Data...',
                text: 'Validating shipment details and generating BL.',
                icon: 'info',
                allowOutsideClick: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            // Simulate 1.5s sync delay
            setTimeout(() => {
                form.submit();
            }, 1500);
        });

        // Auto-Fill Logic
        document.getElementById('consoSelect').addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            const trackingNum = opt.getAttribute('data-vessel');
            const vesselInput = document.getElementById('vessel');
            
            // Populate fields
            document.getElementById('trip_no').value = opt.getAttribute('data-trip') || '';
            document.getElementById('shipper').value = opt.getAttribute('data-shipper') || '';
            document.getElementById('consignee').value = opt.getAttribute('data-consignee') || '';
            document.getElementById('notify').value = opt.getAttribute('data-consignee') || '';
            document.getElementById('pol').value = opt.getAttribute('data-pol') || '';
            document.getElementById('pod').value = opt.getAttribute('data-pod') || '';
            
            // Visual feedback for Asset Tracking
            if (trackingNum) {
                vesselInput.value = trackingNum;
                vesselInput.classList.add('bg-success', 'bg-opacity-10', 'text-success', 'fw-bold');
                setTimeout(() => {
                    vesselInput.classList.remove('bg-success', 'bg-opacity-10', 'text-success', 'fw-bold');
                }, 1500);
            } else {
                vesselInput.value = ""; 
            }
        });

        <?php if (isset($_GET['success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Document Issued',
                text: 'Bill of Lading created successfully.',
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                window.location.href = 'hmbl.php'; // Clean URL
            });
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            Swal.fire({
                icon: 'error',
                title: 'Operation Failed',
                text: '<?= htmlspecialchars($_GET['error']) ?>',
            }).then(() => {
                window.location.href = 'hmbl.php'; // Clean URL
            });
        <?php endif; ?>

        <?php if (!$isAdmin): ?>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: 'error', title: 'Access Denied', text: 'Administrator Access Only.', showConfirmButton: false, footer: '<a href="dashboard.php" class="btn btn-primary btn-sm">Return</a>'
                });
            });
        <?php endif; ?>
    </script>
</body>
</html>