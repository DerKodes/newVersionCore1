<?php
session_start();
include "../api/db.php";
include "../includes/auth_check.php";
include "../includes/role_check.php";
requireAdmin();

/* ================= CREATE HMBL ================= */
if (isset($_POST['create_hmbl'])) {
    $conso_id = (int) $_POST['consolidation_id'];
    $user_id  = $_SESSION['user_id'];
    if (!$conso_id) die("Invalid consolidation.");

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT status FROM consolidations WHERE consolidation_id=? FOR UPDATE");
        $stmt->bind_param("i", $conso_id);
        $stmt->execute();
        $c = $stmt->get_result()->fetch_assoc();
        if (!$c || $c['status'] !== 'OPEN') throw new Exception("Consolidation locked.");

        $chk = $conn->prepare("SELECT 1 FROM hmbl WHERE consolidation_id=?");
        $chk->bind_param("i", $conso_id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) throw new Exception("BL exists.");

        $list = $conn->prepare("SELECT s.shipment_id, s.status, s.consolidated FROM shipments s JOIN consolidation_shipments cs ON s.shipment_id = cs.shipment_id WHERE cs.consolidation_id=?");
        $list->bind_param("i", $conso_id);
        $list->execute();
        $result = $list->get_result();
        if ($result->num_rows < 1) throw new Exception("No shipments.");

        while ($s = $result->fetch_assoc()) {
            if ($s['consolidated'] != 1) throw new Exception("Shipment not consolidated.");
            if ($s['status'] !== 'CONSOLIDATED') throw new Exception("Must be BEFORE transit.");
        }

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

        $stmt = $conn->prepare("UPDATE consolidations SET status='READY_TO_DISPATCH' WHERE consolidation_id=?");
        $stmt->bind_param("i", $conso_id);
        $stmt->execute();

        $stmt = $conn->prepare("UPDATE shipments s JOIN consolidation_shipments cs ON s.shipment_id = cs.shipment_id SET s.status = 'READY_TO_DISPATCH', s.consolidated = 1 WHERE cs.consolidation_id=?");
        $stmt->bind_param("i", $conso_id);
        $stmt->execute();

        $conn->commit();
        header("Location: hmbl.php?success=1");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        die("HMBL generation failed: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HMBL Generator</title>
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

        /* Tables */
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

        /* 🟢 FORM INPUT FIXES FOR DARK MODE */
        body.dark-mode .form-control,
        body.dark-mode .form-select {
            background-color: #2c2c2c !important;
            border-color: var(--dark-border) !important;
            color: #fff !important;
        }

        body.dark-mode .form-control::placeholder {
            color: #aaa !important;
            opacity: 1;
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
        <a href="conso.php"><i class="bi bi-boxes me-2"></i> Consolidation</a>
        <a href="hmbl.php" class="active"><i class="bi bi-file-earmark-pdf me-2"></i> BL Generator</a>
    </div>

    <div class="content" id="content">
        <div class="header">
            <div class="d-flex align-items-center">
                <div class="hamburger" id="hamburger"><i class="bi bi-list"></i></div>
                <h2 class="mb-0 ms-2" id="pageTitle">House Bill of Lading</h2>
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
                        <h5 class="mb-0 fw-bold text-primary"><i class="bi bi-file-earmark-plus me-2"></i> Issue New HMBL</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $consos = $conn->query("
                            SELECT c.consolidation_id, c.consolidation_code, c.trip_no, c.origin, c.destination, po.sender_name, po.receiver_name
                            FROM consolidations c
                            JOIN consolidation_shipments cs ON c.consolidation_id = cs.consolidation_id
                            JOIN shipments s ON cs.shipment_id = s.shipment_id
                            JOIN purchase_orders po ON s.po_id = po.po_id
                            WHERE c.status='OPEN'
                            GROUP BY c.consolidation_id
                            ORDER BY c.created_at DESC
                        ");
                        ?>
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Select Active Consolidation</label>
                                <select name="consolidation_id" id="consoSelect" class="form-select" required>
                                    <option value="">-- Choose Consolidation --</option>
                                    <?php while ($c = $consos->fetch_assoc()): ?>
                                        <option value="<?= $c['consolidation_id'] ?>"
                                            data-trip="<?= $c['trip_no'] ?>"
                                            data-shipper="<?= htmlspecialchars($c['sender_name']) ?>"
                                            data-consignee="<?= htmlspecialchars($c['receiver_name']) ?>"
                                            data-pol="<?= htmlspecialchars($c['origin']) ?>"
                                            data-pod="<?= htmlspecialchars($c['destination']) ?>">
                                            <?= $c['consolidation_code'] ?> | <?= $c['trip_no'] ?>
                                            (<?= $c['origin'] ?> → <?= $c['destination'] ?>)
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-md-6"><label class="small text-muted">Shipper</label><input name="shipper" id="shipper" class="form-control form-control-sm" placeholder="Shipper" required></div>
                                <div class="col-md-6"><label class="small text-muted">Consignee</label><input name="consignee" id="consignee" class="form-control form-control-sm" placeholder="Consignee" required></div>
                            </div>
                            <div class="mb-2"><label class="small text-muted">Notify Party</label><input name="notify_party" id="notify" class="form-control form-control-sm" placeholder="Notify Party"></div>
                            <div class="row g-2 mb-2">
                                <div class="col-md-6"><label class="small text-muted">Port of Loading</label><input name="pol" id="pol" class="form-control form-control-sm" placeholder="Port of Loading" required></div>
                                <div class="col-md-6"><label class="small text-muted">Port of Discharge</label><input name="pod" id="pod" class="form-control form-control-sm" placeholder="Port of Discharge" required></div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6"><label class="small text-muted">Vessel</label><input name="vessel" class="form-control form-control-sm" placeholder="Vessel / Truck ID"></div>
                                <div class="col-md-6"><label class="small text-muted">Trip No</label><input name="voyage" id="trip_no" class="form-control form-control-sm" placeholder="Trip No" readonly></div>
                            </div>
                            <button name="create_hmbl" class="btn btn-primary w-100"><i class="bi bi-printer me-2"></i> Issue HMBL & Lock</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0 fw-bold text-secondary"><i class="bi bi-archive me-2"></i> Issued Documents</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle" id="hmblTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>HMBL Number</th>
                                        <th>Ref</th>
                                        <th>Date</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $list = $conn->query("SELECT h.hmbl_id, h.hmbl_no, c.consolidation_code, h.created_at FROM hmbl h JOIN consolidations c ON h.consolidation_id = c.consolidation_id ORDER BY h.created_at DESC"); ?>
                                    <?php while ($h = $list->fetch_assoc()): ?>
                                        <tr>
                                            <td class="fw-bold text-primary"><?= $h['hmbl_no'] ?></td>
                                            <td><?= $h['consolidation_code'] ?></td>
                                            <td class="small text-muted"><?= date("M d, Y", strtotime($h['created_at'])) ?></td>
                                            <td><a href="view_hmbl.php?id=<?= $h['hmbl_id'] ?>" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye"></i> View BL</a></td>
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
            $('#hmblTable').DataTable({
                "order": [
                    [2, "desc"]
                ]
            });
        });
        document.getElementById('consoSelect').addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            document.getElementById('trip_no').value = opt.getAttribute('data-trip') || '';
            document.getElementById('shipper').value = opt.getAttribute('data-shipper') || '';
            document.getElementById('consignee').value = opt.getAttribute('data-consignee') || '';
            document.getElementById('notify').value = opt.getAttribute('data-consignee') || '';
            document.getElementById('pol').value = opt.getAttribute('data-pol') || '';
            document.getElementById('pod').value = opt.getAttribute('data-pod') || '';
        });
    </script>
</body>

</html>