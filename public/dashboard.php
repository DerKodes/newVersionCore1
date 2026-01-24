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
$kpiPOs       = $conn->query("SELECT COUNT(*) c FROM purchase_orders WHERE status='PENDING'")->fetch_assoc()['c'];

// CHART DATA: Status Distribution
$stat_booked    = $conn->query("SELECT COUNT(*) c FROM shipments WHERE status='BOOKED'")->fetch_assoc()['c'];
$stat_transit   = $conn->query("SELECT COUNT(*) c FROM shipments WHERE status='IN_TRANSIT'")->fetch_assoc()['c'];
$stat_arrived   = $conn->query("SELECT COUNT(*) c FROM shipments WHERE status='ARRIVED'")->fetch_assoc()['c'];
$stat_delivered = $conn->query("SELECT COUNT(*) c FROM shipments WHERE status='DELIVERED'")->fetch_assoc()['c'];

// --- TRANSPORT MODE SPLIT ---
$modeQuery = $conn->query("SELECT transport_mode, COUNT(*) as c FROM shipments GROUP BY transport_mode");
$modeLabels = [];
$modeData = [];
while($row = $modeQuery->fetch_assoc()) {
    $modeLabels[] = $row['transport_mode'];
    $modeData[] = $row['c'];
}

// --- TOP 5 DESTINATIONS ---
$destQuery = $conn->query("SELECT destination, COUNT(*) as c FROM shipments GROUP BY destination ORDER BY c DESC LIMIT 5");
$destLabels = [];
$destData = [];
while($row = $destQuery->fetch_assoc()) {
    $shortDest = explode(',', $row['destination'])[0]; 
    $destLabels[] = $shortDest;
    $destData[] = $row['c'];
}

// ================== WEEKLY VOLUME ==================
$volumeData = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $volumeData[$date] = 0;
}

$volQuery = $conn->query("
    SELECT DATE(created_at) as d, COUNT(*) as c 
    FROM shipments 
    WHERE created_at >= DATE(NOW()) - INTERVAL 7 DAY 
    GROUP BY DATE(created_at)
");

while ($row = $volQuery->fetch_assoc()) {
    if (isset($volumeData[$row['d']])) {
        $volumeData[$row['d']] = $row['c'];
    }
}

$chartLabels = [];
$chartCounts = [];
foreach ($volumeData as $date => $count) {
    $chartLabels[] = date('D', strtotime($date));
    $chartCounts[] = $count;
}

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
    if ($status === 'BOOKED') return '<span class="badge bg-secondary-subtle text-secondary border border-secondary px-2 rounded-pill">BOOKED</span>';
    if ($status === 'CONSOLIDATED') return '<span class="badge bg-info-subtle text-info border border-info px-2 rounded-pill">CONSOLIDATED</span>';
    if ($status === 'READY_TO_DISPATCH') return '<span class="badge bg-warning-subtle text-warning border border-warning px-2 rounded-pill">READY</span>';
    if ($status === 'IN_TRANSIT') return '<span class="badge bg-primary-subtle text-primary border border-primary px-2 rounded-pill"><i class="bi bi-truck"></i> MOVING</span>';
    if ($status === 'ARRIVED') return '<span class="badge bg-warning text-dark border border-warning px-2 rounded-pill"><i class="bi bi-geo-alt"></i> ARRIVED</span>';
    if ($status === 'DELIVERED') return '<span class="badge bg-success-subtle text-success border border-success px-2 rounded-pill"><i class="bi bi-check-lg"></i> DONE</span>';
    return '<span class="badge bg-light text-dark border">' . $status . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard | Core 1</title>

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
        
        /* Cards & KPI */
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
        body.dark-mode .list-group-item { background-color: #1e1e1e; border-color: #333; color: #e0e0e0; }
        
        /* Charts */
        canvas { max-height: 300px; }
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

        <div class="header d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
                <div class="hamburger text-secondary me-3" id="hamburger" style="cursor: pointer;"><i class="bi bi-list fs-4"></i></div>
                <div>
                    <h4 class="mb-0 fw-bold text-dark-emphasis">Analytics Dashboard</h4>
                    <small class="text-muted"><?= date('l, F j, Y') ?></small>
                </div>
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
                                <div class="text-uppercase fw-bold text-primary small mb-1">Active Shipments</div>
                                <div class="h3 mb-0 fw-bold text-dark"><?= $kpiShipments ?></div>
                            </div>
                            <div class="kpi-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-truck"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card kpi-card h-100 border-start border-4 border-info">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-uppercase fw-bold text-info small mb-1">Consolidations</div>
                                <div class="h3 mb-0 fw-bold text-dark"><?= $kpiConso ?></div>
                            </div>
                            <div class="kpi-icon bg-info bg-opacity-10 text-info"><i class="bi bi-layers-half"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card kpi-card h-100 border-start border-4 border-warning">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-uppercase fw-bold text-warning small mb-1">Pending POs</div>
                                <div class="h3 mb-0 fw-bold text-dark"><?= $kpiPOs ?></div>
                            </div>
                            <div class="kpi-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-clipboard-data"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-lg-8">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-clock-history me-2"></i>Recent Activity</h6>
                            <a href="shipments.php" class="btn btn-sm btn-light border">View All</a>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" id="dashboardTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Tracking Code</th>
                                            <th>Route</th>
                                            <th>Sender</th>
                                            <th>Status</th>
                                            <th>Updated</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($r = $recent->fetch_assoc()): ?>
                                            <tr>
                                                <td class="fw-bold text-primary"><?= $r['shipment_code'] ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center small">
                                                        <span class="text-truncate" style="max-width: 80px;"><?= explode(',', $r['origin'])[0] ?></span>
                                                        <i class="bi bi-arrow-right mx-2 text-muted"></i>
                                                        <span class="text-truncate" style="max-width: 80px;"><?= explode(',', $r['destination'])[0] ?></span>
                                                    </div>
                                                </td>
                                                <td class="small"><?= $r['sender_name'] ?></td>
                                                <td><?= getStatusBadge($r['status']) ?></td>
                                                <td class="small text-muted"><?= date("M d, H:i", strtotime($r['created_at'])) ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white py-3">
                            <h6 class="mb-0 fw-bold text-secondary"><i class="bi bi-broadcast me-2"></i>Operational Feed</h6>
                        </div>
                        <div class="list-group list-group-flush">
                            <?php if ($history->num_rows > 0): ?>
                                <?php while ($h = $history->fetch_assoc()): ?>
                                    <div class="list-group-item border-0 border-bottom py-3">
                                        <div class="d-flex w-100 justify-content-between mb-1">
                                            <span class="fw-bold text-dark small"><?= $h['shipment_code'] ?></span>
                                            <small class="text-muted" style="font-size: 0.75rem;"><?= date("H:i", strtotime($h['updated_at'])) ?></small>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span class="badge bg-light text-dark border small"><?= $h['status'] ?></span>
                                            <small class="text-muted text-truncate" style="max-width: 150px;">
                                                <i class="bi bi-pin-map-fill me-1"></i> <?= explode(',', $h['location'])[0] ?>
                                            </small>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox fs-1 opacity-50"></i><br>No recent events
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-lg-8">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white py-3 d-flex justify-content-between">
                            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-bar-chart-line me-2"></i>Weekly Volume</h6>
                            <small class="text-muted">Last 7 Days</small>
                        </div>
                        <div class="card-body">
                            <canvas id="volumeChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white py-3">
                            <h6 class="mb-0 fw-bold text-secondary"><i class="bi bi-airplane-engines me-2"></i>Transport Mode</h6>
                        </div>
                        <div class="card-body d-flex justify-content-center align-items-center">
                            <canvas id="modeChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-lg-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white py-3">
                            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-map me-2"></i>Top 5 Destinations</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="destChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white py-3">
                            <h6 class="mb-0 fw-bold text-secondary"><i class="bi bi-pie-chart me-2"></i>Status Breakdown</h6>
                        </div>
                        <div class="card-body d-flex justify-content-center align-items-center">
                            <canvas id="statusChart" style="max-height: 250px;"></canvas>
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script src="../assets/main.js"></script>

    <script>
        $(document).ready(function() {
            // Initialize DataTables for better interactivity
            $('#dashboardTable').DataTable({
                "pageLength": 5,
                "lengthChange": false,
                "searching": true,
                "ordering": false,
                "language": { "search": "", "searchPlaceholder": "Search activity..." }
            });

            // --- CHART 1: STATUS DISTRIBUTION (Doughnut) ---
            const ctxStatus = document.getElementById('statusChart').getContext('2d');
            new Chart(ctxStatus, {
                type: 'doughnut',
                data: {
                    labels: ['Booked', 'In Transit', 'Arrived', 'Delivered'],
                    datasets: [{
                        data: [<?= $stat_booked ?>, <?= $stat_transit ?>, <?= $stat_arrived ?>, <?= $stat_delivered ?>],
                        backgroundColor: ['#858796', '#4e73df', '#f6c23e', '#1cc88a'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'right' } },
                    cutout: '65%'
                }
            });

            // --- CHART 2: WEEKLY VOLUME (Bar) ---
            const ctxVolume = document.getElementById('volumeChart').getContext('2d');
            new Chart(ctxVolume, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($chartLabels) ?>,
                    datasets: [{
                        label: 'Shipments',
                        data: <?= json_encode($chartCounts) ?>,
                        backgroundColor: '#4e73df',
                        borderRadius: 4,
                        barThickness: 30
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true, grid: { borderDash: [2] } },
                        x: { grid: { display: false } }
                    },
                    plugins: { legend: { display: false } }
                }
            });

            // --- CHART 3: TRANSPORT MODE (Pie) ---
            const ctxMode = document.getElementById('modeChart').getContext('2d');
            new Chart(ctxMode, {
                type: 'pie',
                data: {
                    labels: <?= json_encode($modeLabels) ?>,
                    datasets: [{
                        data: <?= json_encode($modeData) ?>,
                        backgroundColor: ['#36b9cc', '#e74a3b', '#4e73df', '#f6c23e'],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' } }
                }
            });

            // --- CHART 4: TOP DESTINATIONS (Horizontal Bar) ---
            const ctxDest = document.getElementById('destChart').getContext('2d');
            new Chart(ctxDest, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($destLabels) ?>,
                    datasets: [{
                        label: 'Shipments',
                        data: <?= json_encode($destData) ?>,
                        backgroundColor: '#1cc88a',
                        borderRadius: 4,
                        barThickness: 20
                    }]
                },
                options: {
                    indexAxis: 'y', // Makes it horizontal
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { beginAtZero: true, grid: { display: false } },
                        y: { grid: { display: false } }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        });
    </script>
</body>
</html>