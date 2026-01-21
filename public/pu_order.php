<?php
session_start();

include "../api/db.php";
include "../includes/auth_check.php";
include "../includes/role_check.php";

/* ================= CREATE PO LOGIC ================= */
if (isset($_POST['create_po'])) {

    $contract = "PO-" . strtoupper(uniqid());
    $user_id = $_SESSION['user_id'];

    if (empty($_POST['transport_mode'])) {
        die("❌ Error: Transport mode is required.");
    }

    $stmt = $conn->prepare("
    INSERT INTO purchase_orders
    (
        user_id, contract_number, 
        sender_name, sender_contact,
        receiver_name, receiver_contact,
        origin_address, destination_address, 
        origin_lat, origin_lng, 
        destination_lat, destination_lng,
        transport_mode,
        weight, package_type, package_description,
        payment_method, bank_name, 
        distance_km, price,
        sla_agreement, ai_estimated_time, target_delivery_date
    )
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    if (!$stmt) {
        die("Database Prepare Failed: " . $conn->error);
    }

    $package_desc = $_POST['package_description'] ?? "";
    $bank_name = $_POST['bank_name'] ?? "";
    $o_lat = 0.0;
    $o_lng = 0.0;
    $d_lat = 0.0;
    $d_lng = 0.0;

    $stmt->bind_param(
        "isssssssddddsdssssddsss",
        $user_id,
        $contract,
        $_POST['sender_name'],
        $_POST['sender_contact'],
        $_POST['receiver_name'],
        $_POST['receiver_contact'],
        $_POST['origin_address'],
        $_POST['destination_address'],
        $o_lat,
        $o_lng,
        $d_lat,
        $d_lng,
        $_POST['transport_mode'],
        $_POST['weight'],
        $_POST['package_type'],
        $package_desc,
        $_POST['payment_method'],
        $bank_name,
        $_POST['distance_km'],
        $_POST['price'],
        $_POST['sla_agreement'],
        $_POST['ai_estimated_time'],
        $_POST['target_delivery_date']
    );

    if ($stmt->execute()) {
        header("Location: pu_order.php?created=1");
        exit();
    } else {
        die("❌ Database Execute Failed: " . $stmt->error);
    }
}

/* ================= APPROVE / REJECT LOGIC ================= */
if (isset($_POST['update_status'])) {
    requireAdmin();
    $stmt = $conn->prepare("UPDATE purchase_orders SET status=? WHERE po_id=?");
    $stmt->bind_param("si", $_POST['status'], $_POST['po_id']);
    $stmt->execute();
    header("Location: pu_order.php?updated=1");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Orders</title>

    <link rel="stylesheet" href="../assets/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">

    <style>
        /* Global Font & Transition */
        body {
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            transition: background-color 0.3s, color 0.3s;
        }

        /* Layout Fixes */
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

        /* 🟢 STICKY HEADER FIX */
        .header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background-color: #fff;
            /* Default Light Mode BG */
            border-bottom: 1px solid #e3e6f0;
            padding: 15px 25px;
            /* Ensure padding matches style.css */
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            /* Optional shadow */
        }

        /* Dark Mode Variables */
        :root {
            --dark-bg: #121212;
            --dark-card: #1e1e1e;
            --dark-text: #e0e0e0;
            --dark-border: #333333;
            --dark-table-head: #2c2c2c;
            --dark-hover: rgba(255, 255, 255, 0.05);
        }

        /* Dark Mode Styles */
        body.dark-mode {
            background-color: var(--dark-bg) !important;
            color: var(--dark-text) !important;
        }

        /* Dark Mode Sticky Header */
        body.dark-mode .header {
            background-color: var(--dark-card) !important;
            border-bottom: 1px solid var(--dark-border);
            color: var(--dark-text);
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

        body.dark-mode .text-muted {
            color: #a0a0a0 !important;
        }

        /* Inputs & Dropdowns */
        body.dark-mode .form-control,
        body.dark-mode .form-select,
        body.dark-mode input[type="search"] {
            background-color: #2c2c2c;
            border-color: var(--dark-border);
            color: #fff;
        }

        body.dark-mode .form-control::placeholder {
            color: #aaa;
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
    </style>
</head>

<body>

    <div class="sidebar" id="sidebar">
        <div class="logo"><img src="../assets/slate.png" alt="Logo"></div>
        <div class="system-name">CORE TRANSACTION 1</div>
        <a href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a>
        <a href="pu_order.php" class="active"><i class="bi bi-cart me-2"></i> Purchase Orders</a>
        <a href="shipments.php"><i class="bi bi-truck me-2"></i> Shipment Booking</a>
        <a href="conso.php"><i class="bi bi-boxes me-2"></i> Consolidation</a>
        <a href="hmbl.php"><i class="bi bi-file-earmark-pdf me-2"></i> BL Generator</a>
    </div>

    <div class="content" id="content">

        <div class="header">
            <div class="d-flex align-items-center">
                <div class="hamburger" id="hamburger"><i class="bi bi-list"></i></div>
                <h2 class="mb-0 ms-2" id="pageTitle">Purchase Orders</h2>
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
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item text-danger" href="#" onclick="confirmLogout()">Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-4 mt-4">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0 fw-bold text-primary"><i class="bi bi-plus-circle me-2"></i> Create New Order</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label small text-muted">Sender Details</label>
                            <input name="sender_name" class="form-control mb-2" placeholder="Sender Name" required>
                            <input name="sender_contact" class="form-control" placeholder="Contact Number" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label small text-muted">Receiver Details</label>
                            <input name="receiver_name" class="form-control mb-2" placeholder="Receiver Name" required>
                            <input name="receiver_contact" class="form-control" placeholder="Contact Number" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Origin Address</label>
                            <input type="text" name="origin_address" class="form-control" placeholder="Complete address..." required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Destination Address</label>
                            <input type="text" name="destination_address" class="form-control" placeholder="Complete address..." required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <input name="weight" type="number" step="0.01" class="form-control" placeholder="Weight (kg)" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <input name="package_type" class="form-control" placeholder="Package Type" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <select name="transport_mode" class="form-select" required>
                                <option value="">Select Mode</option>
                                <option value="SEA">Sea Freight</option>
                                <option value="AIR">Air Freight</option>
                                <option value="LAND">Land Freight</option>
                            </select>
                        </div>
                    </div>

                    <textarea name="package_description" class="form-control mb-3" placeholder="Description / Remarks"></textarea>

                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <select name="payment_method" class="form-select">
                                <option value="CASH">Cash</option>
                                <option value="BANK">Bank Transfer</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <input name="bank_name" class="form-control" placeholder="Bank Name (If Bank)">
                        </div>
                        <div class="col-md-3 mb-3">
                            <input name="distance_km" type="number" step="0.01" class="form-control" placeholder="Distance (KM)">
                        </div>
                        <div class="col-md-3 mb-3">
                            <input name="price" type="number" step="0.01" class="form-control" placeholder="Price (PHP)">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <input name="sla_agreement" class="form-control" placeholder="SLA (e.g. 24h)">
                        </div>
                        <div class="col-md-4 mb-3">
                            <input name="ai_estimated_time" class="form-control" placeholder="AI ETA">
                        </div>
                        <div class="col-md-4 mb-3">
                            <input type="date" name="target_delivery_date" class="form-control">
                        </div>
                    </div>

                    <div class="text-end">
                        <button name="create_po" class="btn btn-primary px-4 shadow-sm"><i class="bi bi-check-lg me-2"></i> Submit Order</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0 fw-bold text-secondary"><i class="bi bi-list-ul me-2"></i> Recent Orders</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <?php $pos = $conn->query("SELECT * FROM purchase_orders ORDER BY created_at DESC"); ?>
                    <table class="table table-hover align-middle" id="poTable">
                        <thead class="table-light">
                            <tr>
                                <th>Contract</th>
                                <th>Sender</th>
                                <th>Receiver</th>
                                <th>Status</th>
                                <th>Mode</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($po = $pos->fetch_assoc()) { ?>
                                <tr>
                                    <td class="fw-bold text-primary"><?= $po['contract_number'] ?></td>
                                    <td><?= $po['sender_name'] ?></td>
                                    <td><?= $po['receiver_name'] ?></td>
                                    <td>
                                        <?php
                                        $badgeClass = 'secondary';
                                        $icon = 'bi-circle';
                                        if ($po['status'] == 'APPROVED') {
                                            $badgeClass = 'success';
                                            $icon = 'bi-check-circle';
                                        }
                                        if ($po['status'] == 'REJECTED') {
                                            $badgeClass = 'danger';
                                            $icon = 'bi-x-circle';
                                        }
                                        if ($po['status'] == 'BOOKED') {
                                            $badgeClass = 'info text-dark';
                                            $icon = 'bi-journal-check';
                                        }
                                        ?>
                                        <span class="badge bg-<?= $badgeClass ?> rounded-pill px-3">
                                            <i class="bi <?= $icon ?> me-1"></i> <?= $po['status'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border"><?= $po['transport_mode'] ?></span>
                                    </td>
                                    <td>
                                        <?php if ($_SESSION['role'] === 'ADMIN' && $po['status'] === 'PENDING') { ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="po_id" value="<?= $po['po_id'] ?>">
                                                <button name="status" value="APPROVED" class="btn btn-outline-success btn-sm rounded-circle shadow-sm" title="Approve">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                                <button name="status" value="REJECTED" class="btn btn-outline-danger btn-sm rounded-circle shadow-sm" title="Reject">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                                <input type="hidden" name="update_status">
                                            </form>
                                        <?php } ?>

                                        <?php if ($_SESSION['role'] === 'ADMIN' && $po['status'] === 'APPROVED') { ?>
                                            <form method="POST" action="shipments.php" style="display:inline;">
                                                <input type="hidden" name="po_id" value="<?= $po['po_id'] ?>">
                                                <button name="create_shipment" class="btn btn-primary btn-sm rounded-pill px-3 shadow-sm">
                                                    Create Shipment <i class="bi bi-arrow-right ms-1"></i>
                                                </button>
                                            </form>
                                        <?php } ?>

                                        <?php if ($po['status'] === 'BOOKED') { ?>
                                            <span class="text-muted small"><i class="bi bi-lock-fill"></i> Processed</span>
                                        <?php } ?>
                                    </td>
                                </tr>
                            <?php } ?>
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
            $('#poTable').DataTable({
                "order": [
                    [0, "desc"]
                ]
            });
        });
    </script>

</body>

</html>