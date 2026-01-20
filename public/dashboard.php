<?php
// ================== SECURITY & SESSION ==================
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.use_strict_mode', 1);

if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', 1);
}

session_start();

if (!isset($_SESSION["Email"])) {
    header("Location: ../login/login.php");
    exit();
}

include "../api/db.php";

// ================== KPI QUERIES ==================
$kpiShipments = $conn->query("SELECT COUNT(*) c FROM shipments WHERE status!='DELIVERED'")->fetch_assoc()['c'];
$kpiConso     = $conn->query("SELECT COUNT(*) c FROM consolidations WHERE status='OPEN'")->fetch_assoc()['c'];
$kpiEvents    = $conn->query("SELECT COUNT(*) c FROM shipment_tracking")->fetch_assoc()['c'];
$kpiPOs       = $conn->query("SELECT COUNT(*) c FROM purchase_orders WHERE status='PENDING'")->fetch_assoc()['c'];

// ================== RECENT SHIPMENTS ==================
$recent = $conn->query("
    SELECT s.*, p.sender_name, p.created_at AS po_date
    FROM shipments s
    JOIN purchase_orders p ON s.po_id = p.po_id
    ORDER BY s.created_at DESC
    LIMIT 10
");

// ================== HISTORY FEED ==================
$history = $conn->query("
    SELECT 
        shipment_code, 
        status, 
        CASE 
            WHEN status = 'BOOKED' THEN origin 
            ELSE destination 
        END as location,
        created_at as updated_at
    FROM shipments
    ORDER BY created_at DESC
    LIMIT 6
");

/* ================= HELPER: STATUS BADGE ================= */
function getStatusBadge($status)
{
    if ($status === 'BOOKED') return '<span class="badge rounded-pill bg-secondary">BOOKED</span>';
    if ($status === 'CONSOLIDATED') return '<span class="badge rounded-pill bg-info text-dark">CONSOLIDATED</span>';
    if ($status === 'READY_TO_DISPATCH') return '<span class="badge rounded-pill bg-warning text-dark">READY</span>';
    if ($status === 'IN_TRANSIT') return '<span class="badge rounded-pill bg-primary"><i class="bi bi-truck-flat"></i> MOVING</span>';
    if ($status === 'ARRIVED') return '<span class="badge rounded-pill bg-warning text-dark"><i class="bi bi-geo-alt"></i> ARRIVED</span>';
    if ($status === 'DELIVERED') return '<span class="badge rounded-pill bg-success"><i class="bi bi-check-lg"></i> DONE</span>';
    return '<span class="badge bg-light text-dark border">' . $status . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - CORE 1</title>

    <link rel="stylesheet" href="../assets/style.css"> 
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">

    <style>
        /* 1. Global Font & Transition */
        body {
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            transition: background-color 0.3s, color 0.3s;
        }

        /* 2. Layout Fixes */
        body.sidebar-closed .sidebar { margin-left: -250px; }
        body.sidebar-closed .content { margin-left: 0; width: 100%; }
        .content { width: calc(100% - 250px); margin-left: 250px; transition: all 0.3s; }
        @media (max-width: 768px) { .content { width: 100%; margin-left: 0; } }

        /* ================= DARK MODE VARIABLES ================= */
        :root {
            --dark-bg: #121212;       /* Deep Dark Background */
            --dark-card: #1e1e1e;     /* Slightly lighter for cards */
            --dark-text: #e0e0e0;     /* Soft White Text */
            --dark-border: #333333;   /* Subtle Borders */
            --dark-table-head: #2c2c2c; /* Header for tables */
            --dark-hover: rgba(255,255,255,0.05); /* Hover effect */
        }

        /* ================= DARK MODE APPLIED ================= */
        body.dark-mode {
            background-color: var(--dark-bg) !important;
            color: var(--dark-text) !important;
        }

        /* --- Cards --- */
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

        /* --- Header Bar --- */
        body.dark-mode .header {
            background-color: var(--dark-card);
            border-bottom: 1px solid var(--dark-border);
            color: var(--dark-text);
        }

        /* --- TABLES & TBODY FIXES (CRITICAL) --- */
        body.dark-mode .table {
            color: var(--dark-text);
            border-color: var(--dark-border);
            --bs-table-bg: transparent; /* Reset Bootstrap var */
        }
        
        /* 1. Header (Thead) */
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

        /* 2. Body Cells (Tbody TD) */
        body.dark-mode .table tbody td {
            background-color: var(--dark-card); /* Match card bg */
            color: var(--dark-text);
            border-color: var(--dark-border);
        }

        /* 3. Hover Effect on Rows */
        body.dark-mode .table-hover tbody tr:hover td {
            background-color: var(--dark-hover);
            color: #fff;
        }

        /* 4. Fix Links inside Tables (Blue text) */
        body.dark-mode .table .text-primary {
            color: #6ea8fe !important; /* Light blue for visibility */
        }

        /* --- List Group (Operational Feed) --- */
        body.dark-mode .list-group-item {
            background-color: var(--dark-card);
            border-color: var(--dark-border);
            color: var(--dark-text);
        }

        /* --- Inputs & Dropdowns --- */
        body.dark-mode .form-control, 
        body.dark-mode .form-select, 
        body.dark-mode input[type="search"] {
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

        /* Text Muted Fix */
        body.dark-mode .text-muted {
            color: #a0a0a0 !important; 
        }
        
        /* KPI Cards (Remove borders to keep them clean) */
        .kpi-card { border: none; }
    </style>
</head>

<body>

    <div class="sidebar" id="sidebar">
        <div class="logo"><img src="../assets/slate.png" alt="Logo"></div>
        <div class="system-name">CORE TRANSACTION 1</div>
        <a href="dashboard.php" class="active"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a>
        <a href="pu_order.php"><i class="bi bi-cart me-2"></i> Purchase Orders</a>
        <a href="shipments.php"><i class="bi bi-truck me-2"></i> Shipment Booking</a>
        <a href="conso.php"><i class="bi bi-boxes me-2"></i> Consolidation</a>
        <a href="hmbl.php"><i class="bi bi-file-earmark-pdf me-2"></i> BL Generator</a>
    </div>

    <div class="content" id="content">

        <div class="header">
            <div class="d-flex align-items-center">
                <div class="hamburger" id="hamburger"><i class="bi bi-list"></i></div>
                <h2 class="mb-0 ms-2" id="pageTitle">Dashboard <span class="system-title text-primary small">| CORE 1</span></h2>
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
                        <li><a class="dropdown-item" href="#"><i class="bi bi-gear me-2"></i> Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="#" onclick="confirmLogout()"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card kpi-card bg-gradient-primary h-100 text-white">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase mb-1 small">Active Shipments</h6>
                            <h2 class="mb-0 fw-bold"><?= $kpiShipments ?></h2>
                        </div>
                        <i class="bi bi-truck kpi-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card kpi-card bg-gradient-info h-100 text-white">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase mb-1 small">Consolidations</h6>
                            <h2 class="mb-0 fw-bold"><?= $kpiConso ?></h2>
                        </div>
                        <i class="bi bi-layers-half kpi-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card kpi-card bg-gradient-warning h-100 text-white">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase mb-1 small">Pending POs</h6>
                            <h2 class="mb-0 fw-bold"><?= $kpiPOs ?></h2>
                        </div>
                        <i class="bi bi-clipboard-data kpi-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card kpi-card bg-gradient-success h-100 text-white">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase mb-1 small">Total Events</h6>
                            <h2 class="mb-0 fw-bold"><?= $kpiEvents ?></h2>
                        </div>
                        <i class="bi bi-activity kpi-icon"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-transparent py-3">
                        <h5 class="mb-0 fw-bold text-primary"><i class="bi bi-clock-history me-2"></i> Recent Activity</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive p-3">
                            <table class="table table-hover align-middle" id="dashboardTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Code</th>
                                        <th>Route</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($r = $recent->fetch_assoc()): ?>
                                        <tr>
                                            <td class="fw-bold text-primary"><?= $r['shipment_code'] ?></td>
                                            <td>
                                                <div class="small text-truncate" style="max-width: 150px;"><?= $r['origin'] ?></div>
                                                <div class="small text-truncate" style="max-width: 150px;"><?= $r['destination'] ?></div>
                                            </td>
                                            <td><?= getStatusBadge($r['status']) ?></td>
                                            <td class="small"><?= date("M d", strtotime($r['created_at'])) ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-transparent py-3">
                        <h5 class="mb-0 fw-bold text-secondary"><i class="bi bi-broadcast me-2"></i> Operational Feed</h5>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php if ($history->num_rows > 0): ?>
                            <?php while ($h = $history->fetch_assoc()): ?>
                                <div class="list-group-item border-0 border-bottom py-3">
                                    <div class="d-flex w-100 justify-content-between mb-1">
                                        <small class="fw-bold text-primary"><?= $h['shipment_code'] ?></small>
                                        <small class="text-muted" style="font-size: 0.7rem;">
                                            <?= date("H:i", strtotime($h['updated_at'])) ?>
                                        </small>
                                    </div>
                                    <p class="mb-1 small">
                                        <i class="bi bi-record-circle text-success me-1"></i>
                                        Status: <strong><?= $h['status'] ?></strong>
                                    </p>
                                    <small class="text-muted"><i class="bi bi-pin-map"></i> <?= $h['location'] ?></small>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-inbox fs-1"></i><br>No recent events
                            </div>
                        <?php endif; ?>
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
            // DataTables
            $('#dashboardTable').DataTable({
                "pageLength": 5,
                "lengthChange": false,
                "searching": false,
                "ordering": false,
                "info": false
            });
        });
    </script>

</body>
</html>