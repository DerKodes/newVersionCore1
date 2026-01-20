<?php
session_start();

include "../api/db.php";
include "../includes/auth_check.php";
include "../includes/role_check.php";

requireAdmin();

/* ================= CREATE CONSOLIDATION ================= */
if (isset($_POST['create_consolidation'])) {

    $vehicle_set = $_POST['vehicle_set'] ?? 'A';

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

            if (empty($s['transport_mode']) || $s['transport_mode'] !== $first['transport_mode'] || strcasecmp(trim($s['origin']), trim($first['origin'])) !== 0 || strcasecmp(trim($s['destination']), trim($first['destination'])) !== 0) {
                throw new Exception("All shipments must share same route and mode.");
            }
        }

        /* Create */
        $code = "CONSO-" . strtoupper(uniqid());
        $stmt = $conn->prepare("INSERT INTO consolidations (consolidation_code, trip_no, vehicle_set, transport_mode, origin, destination, status, created_by) VALUES (?, ?, ?, ?, ?, ?, 'OPEN', ?)");
        $stmt->bind_param("ssssssi", $code, $trip_no, $vehicle_set, $first['transport_mode'], $first['origin'], $first['destination'], $user_id);
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
if (isset($_POST['deconsolidate'])) {
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

function getStatusBadge($status)
{
    if ($status === 'OPEN') return '<span class="badge rounded-pill bg-success">OPEN</span>';
    if ($status === 'READY_TO_DISPATCH') return '<span class="badge rounded-pill bg-warning text-dark">READY</span>';
    if ($status === 'DECONSOLIDATED') return '<span class="badge rounded-pill bg-secondary">DECONSOLIDATED</span>';
    return '<span class="badge bg-light text-dark border">' . $status . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consolidation</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">

    <style>
        body {
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            transition: background-color 0.3s, color 0.3s;
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

        /* Dark Mode */
        :root {
            --dark-bg: #121212;
            --dark-card: #1e1e1e;
            --dark-text: #e0e0e0;
            --dark-border: #333333;
            --dark-table-head: #2c2c2c;
            --dark-hover: rgba(255, 255, 255, 0.05);
        }

        body.dark-mode {
            background-color: var(--dark-bg) !important;
            color: var(--dark-text) !important;
        }

        body.dark-mode .card {
            background-color: var(--dark-card);
            border: 1px solid var(--dark-border);
            color: var(--dark-text);
        }

        body.dark-mode .card-header {
            background-color: rgba(255, 255, 255, 0.05) !important;
            border-bottom: 1px solid var(--dark-border);
        }

        body.dark-mode .card-header h5 {
            color: #fff !important;
        }

        body.dark-mode .header {
            background-color: var(--dark-card);
            border-bottom: 1px solid var(--dark-border);
            color: var(--dark-text);
        }

        body.dark-mode .table {
            color: var(--dark-text);
            border-color: var(--dark-border);
            --bs-table-bg: transparent;
        }

        body.dark-mode .table .table-light {
            background-color: var(--dark-table-head);
            color: #fff;
            border-color: var(--dark-border);
        }

        body.dark-mode .table .table-light th {
            background-color: var(--dark-table-head);
            color: #fff;
            border-bottom: 1px solid var(--dark-border);
        }

        body.dark-mode .table tbody td {
            background-color: var(--dark-card);
            color: var(--dark-text);
            border-color: var(--dark-border);
        }

        body.dark-mode .table-hover tbody tr:hover td {
            background-color: var(--dark-hover);
            color: #fff;
        }

        body.dark-mode .table .text-primary {
            color: #6ea8fe !important;
        }

        body.dark-mode .form-control,
        body.dark-mode .form-select {
            background-color: #2c2c2c;
            border-color: var(--dark-border);
            color: #fff;
        }

        body.dark-mode .dropdown-menu {
            background-color: var(--dark-card);
            border: 1px solid var(--dark-border);
        }

        body.dark-mode .dropdown-item {
            color: var(--dark-text);
        }

        body.dark-mode .dropdown-item:hover {
            background-color: #333;
            color: #fff;
        }

        body.dark-mode .text-muted {
            color: #a0a0a0 !important;
        }
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

    <div class="content" id="content">
        <div class="header">
            <div class="d-flex align-items-center">
                <div class="hamburger" id="hamburger"><i class="bi bi-list"></i></div>
                <h2 class="mb-0 ms-2" id="pageTitle">Consolidation Management</h2>
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

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0 fw-bold text-primary"><i class="bi bi-plus-square me-2"></i> Create New Consolidation</h5>
                    </div>
                    <div class="card-body">
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
                                <label class="form-label fw-bold">Select Vehicle Set</label>
                                <select name="vehicle_set" class="form-select" required>
                                    <option value="">-- Choose Set --</option>
                                    <option value="A">SET A</option>
                                    <option value="B">SET B</option>
                                    <option value="C">SET C</option>
                                    <option value="D">SET D</option>
                                </select>
                            </div>
                            <label class="form-label fw-bold">Select Shipments to Group</label>
                            <div class="table-responsive border rounded mb-3" style="max-height: 400px; overflow-y: auto;">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light sticky-top">
                                        <tr>
                                            <th style="width: 40px;"><i class="bi bi-check-lg"></i></th>
                                            <th>Code</th>
                                            <th>Route</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($eligible->num_rows > 0): ?>
                                            <?php while ($s = $eligible->fetch_assoc()): ?>
                                                <tr>
                                                    <td><input class="form-check-input" type="checkbox" name="shipments[]" value="<?= $s['shipment_id'] ?>"></td>
                                                    <td class="small fw-bold text-primary"><?= $s['shipment_code'] ?></td>
                                                    <td class="small">
                                                        <div class="text-truncate" style="max-width: 150px;"><?= $s['origin'] ?></div>
                                                        <i class="bi bi-arrow-down text-muted"></i>
                                                        <div class="text-truncate" style="max-width: 150px;"><?= $s['destination'] ?></div>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="3" class="text-center text-muted py-4">No eligible shipments found.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <button name="create_consolidation" class="btn btn-primary w-100"><i class="bi bi-box-seam me-2"></i> Consolidate Selected</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card shadow-sm border-0 border-start border-danger border-4 mb-4">
                    <div class="card-body">
                        <h5 class="card-title text-danger mb-3"><i class="bi bi-exclamation-triangle me-2"></i> Deconsolidate (Emergency)</h5>
                        <form method="POST" class="row g-2 align-items-center" onsubmit="return confirmFormAction(event, 'This will revert all shipments to BOOKED status.', 'warning')">
                            <div class="col-md-5">
                                <select name="consolidation_id" class="form-select form-select-sm" required>
                                    <option value="">Select Consolidation...</option>
                                    <?php
                                    $deconList = $conn->query("
                                        SELECT c.consolidation_id, c.consolidation_code, c.vehicle_set 
                                        FROM consolidations c
                                        LEFT JOIN hmbl h ON h.consolidation_id = c.consolidation_id
                                        WHERE c.status = 'OPEN' AND h.hmbl_id IS NULL
                                    ");
                                    while ($c = $deconList->fetch_assoc()):
                                    ?>
                                        <option value="<?= $c['consolidation_id'] ?>"><?= $c['consolidation_code'] ?> (SET <?= $c['vehicle_set'] ?>)</option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="col-md-5"><input type="text" name="reason" class="form-control form-control-sm" placeholder="Reason for breakdown..." required></div>
                            <div class="col-md-2"><button name="deconsolidate" class="btn btn-outline-danger btn-sm w-100">Apply</button></div>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0 fw-bold text-secondary"><i class="bi bi-list-task me-2"></i> Active Consolidations</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle" id="consoTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Code</th>
                                        <th>Route</th>
                                        <th>Status</th>
                                        <th>Set</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $list = $conn->query("SELECT * FROM consolidations ORDER BY created_at DESC"); ?>
                                    <?php while ($c = $list->fetch_assoc()): ?>
                                        <tr>
                                            <td class="fw-bold text-primary">
                                                <?= $c['consolidation_code'] ?>
                                                <div class="small text-muted fw-normal"><?= $c['trip_no'] ?></div>
                                            </td>
                                            <td>
                                                <div class="small text-truncate" style="max-width: 120px;"><?= $c['origin'] ?></div>
                                                <i class="bi bi-arrow-right text-muted" style="font-size: 0.7rem;"></i>
                                                <div class="small text-truncate" style="max-width: 120px;"><?= $c['destination'] ?></div>
                                            </td>
                                            <td><?= getStatusBadge($c['status']) ?></td>
                                            <td><span class="badge bg-dark">SET <?= $c['vehicle_set'] ?></span></td>
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

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../assets/main.js"></script>
    <script>
        $(document).ready(function() {
            $('#consoTable').DataTable({
                "order": [
                    [0, "desc"]
                ],
                "pageLength": 5,
                "lengthChange": false
            });
        });
    </script>
</body>

</html>