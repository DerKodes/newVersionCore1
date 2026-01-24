<?php
session_start();
include "../api/db.php";
include "../includes/auth_check.php";
include "../includes/role_check.php";

/* =================================================================================
   1. BACKEND LOGIC
   ================================================================================= */

// --- CREATE PO LOGIC ---
if (isset($_POST['create_po'])) {
    $contract = trim($_POST['contract_number']); 
    $user_id = $_SESSION['user_id'];

    if (empty($contract)) die("❌ Error: Contract Number is required.");
    if (empty($_POST['transport_mode'])) die("❌ Error: Transport mode is required.");

    $stmt = $conn->prepare("INSERT INTO purchase_orders (user_id, contract_number, sender_name, sender_contact, receiver_name, receiver_contact, origin_address, destination_address, transport_mode, weight, package_type, package_description, payment_method, bank_name, distance_km, price, sla_agreement, ai_estimated_time, target_delivery_date, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'PENDING')");
    
    $stmt->bind_param("issssssssddsssdssss", $user_id, $contract, $_POST['sender_name'], $_POST['sender_contact'], $_POST['receiver_name'], $_POST['receiver_contact'], $_POST['origin_address'], $_POST['destination_address'], $_POST['transport_mode'], $_POST['weight'], $_POST['package_type'], $_POST['package_description'], $_POST['payment_method'], $_POST['bank_name'], $_POST['distance_km'], $_POST['price'], $_POST['sla_agreement'], $_POST['ai_estimated_time'], $_POST['target_delivery_date']);

    if ($stmt->execute()) { header("Location: pu_order.php?created=1"); exit(); } 
    else { die("Database Error: " . $stmt->error); }
}

// --- BULK STATUS UPDATE ---
if (isset($_POST['bulk_action'])) {
    requireAdmin();
    if (!empty($_POST['selected_ids'])) {
        $status = $_POST['bulk_status'];
        $ids = implode(",", array_map('intval', $_POST['selected_ids']));
        $conn->query("UPDATE purchase_orders SET status = '$status' WHERE po_id IN ($ids) AND status = 'PENDING'");
        header("Location: pu_order.php?bulk_updated=1"); exit();
    }
}

// --- FETCH DATA & KPI ---
$pos = $conn->query("SELECT * FROM purchase_orders ORDER BY created_at DESC");

// KPI Counters
$kpi = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'REJECTED' THEN 1 ELSE 0 END) as rejected
    FROM purchase_orders
")->fetch_assoc();

// --- HELPER FOR BADGES ---
function getStatusBadge($status) {
    if ($status === 'APPROVED') return '<span class="badge bg-success-subtle text-success border border-success px-3 rounded-pill"><i class="bi bi-check-circle me-1"></i> APPROVED</span>';
    if ($status === 'REJECTED') return '<span class="badge bg-danger-subtle text-danger border border-danger px-3 rounded-pill"><i class="bi bi-x-circle me-1"></i> REJECTED</span>';
    if ($status === 'PENDING') return '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning px-3 rounded-pill"><i class="bi bi-hourglass-split me-1"></i> PENDING</span>';
    if ($status === 'BOOKED') return '<span class="badge bg-info-subtle text-info-emphasis border border-info px-3 rounded-pill"><i class="bi bi-journal-check me-1"></i> BOOKED</span>';
    return '<span class="badge bg-secondary">' . $status . '</span>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Orders | Core 1</title>
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
        .contract-code { font-family: 'Roboto Mono', monospace; font-weight: 600; color: var(--primary-color); letter-spacing: 0.5px; }

        /* Dark Mode */
        body.dark-mode { background-color: #121212; color: #e0e0e0; }
        body.dark-mode .header, body.dark-mode .card, body.dark-mode .modal-content { background-color: #1e1e1e; border-color: #333; color: #e0e0e0; }
        body.dark-mode .table { color: #e0e0e0; --bs-table-bg: transparent; }
        body.dark-mode .table thead th { background-color: #2c2c2c; border-color: #444; color: #ccc; }
        body.dark-mode .table tbody td { border-color: #333; }
        body.dark-mode .form-control, body.dark-mode .form-select { background-color: #2c2c2c; border-color: #444; color: #fff; }
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
        <div class="header d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
                <div class="hamburger text-secondary me-3" id="hamburger" style="cursor: pointer;"><i class="bi bi-list fs-4"></i></div>
                <h4 class="mb-0 fw-bold text-dark-emphasis">Purchase Orders</h4>
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
                        <li><hr class="dropdown-divider"></li>
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
                                <div class="text-uppercase fw-bold text-primary small mb-1">Total Orders</div>
                                <div class="h3 mb-0 fw-bold text-dark"><?= $kpi['total'] ?></div>
                            </div>
                            <div class="kpi-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-clipboard-data"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card kpi-card h-100 border-start border-4 border-warning">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-uppercase fw-bold text-warning small mb-1">Pending Review</div>
                                <div class="h3 mb-0 fw-bold text-dark"><?= $kpi['pending'] ?></div>
                            </div>
                            <div class="kpi-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-hourglass-split"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card kpi-card h-100 border-start border-4 border-success">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-uppercase fw-bold text-success small mb-1">Approved</div>
                                <div class="h3 mb-0 fw-bold text-dark"><?= $kpi['approved'] ?></div>
                            </div>
                            <div class="kpi-icon bg-success bg-opacity-10 text-success"><i class="bi bi-check-lg"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card kpi-card h-100 border-start border-4 border-danger">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-uppercase fw-bold text-danger small mb-1">Rejected</div>
                                <div class="h3 mb-0 fw-bold text-dark"><?= $kpi['rejected'] ?></div>
                            </div>
                            <div class="kpi-icon bg-danger bg-opacity-10 text-danger"><i class="bi bi-x-octagon"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow mb-4">
                <div class="card-header bg-white py-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h6 class="m-0 fw-bold text-secondary"><i class="bi bi-table me-2"></i>Order Registry</h6>
                        
                        <div class="d-flex gap-2 align-items-center">
                            
                            

                            <form method="POST" id="bulkForm" style="display:none;">
                                <input type="hidden" name="bulk_action" value="1">
                                <input type="hidden" name="bulk_status" id="bulkStatusInput">
                            </form>
                            <form method="POST" action="shipments.php" id="bulkShipmentForm" style="display:none;">
                                <input type="hidden" name="bulk_create_shipment" value="1">
                            </form>

                            <?php if ($_SESSION['role'] === 'ADMIN'): ?>
                                <div class="btn-group shadow-sm">
                                    <button type="button" class="btn btn-outline-success btn-sm" onclick="submitBulkAction('APPROVED')">
                                        <i class="bi bi-check-all"></i> Approve
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="submitBulkAction('REJECTED')">
                                        <i class="bi bi-x-lg"></i> Reject
                                    </button>
                                </div>
                                
                                <button type="button" class="btn btn-outline-info text-dark btn-sm shadow-sm border-info" onclick="submitBulkShipment()">
                                    <i class="bi bi-box-seam-fill me-1 text-info"></i> Create Shipment
                                </button>
                                <div class="vr mx-2 text-muted"></div>
                            <?php endif; ?>

                            <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3 shadow-sm" onclick="syncCore3()">
                                <i class="bi bi-cloud-arrow-down-fill me-1"></i> Sync Core 3
                            </button>

                            </div>
                    </div>
                </div>
                
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="poTable" width="100%">
                            <thead>
                                <tr>
                                    <th style="width: 40px;" class="text-center">
                                        <input type="checkbox" class="form-check-input border-secondary" id="selectAll" onclick="toggleSelectAll(this)">
                                    </th>
                                    <th>Contract</th>
                                    <th>Logistics</th>
                                    <th>Status</th>
                                    <th>Details</th>
                                    <th>Priority</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($po = $pos->fetch_assoc()) { ?>
                                    <tr>
                                        <td class="text-center">
                                            <?php if ($po['status'] === 'PENDING' || $po['status'] === 'APPROVED'): ?>
                                                <input type="checkbox" class="form-check-input po-checkbox border-secondary" name="selected_ids[]" value="<?= $po['po_id'] ?>" data-status="<?= $po['status'] ?>">
                                            <?php else: ?>
                                                <i class="bi bi-dash text-muted"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="contract-code"><?= $po['contract_number'] ?></div>
                                            <small class="text-muted"><?= date('M d, Y', strtotime($po['created_at'])) ?></small>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center mb-1">
                                                <i class="bi bi-box-arrow-up-right text-muted me-2 small"></i>
                                                <span><?= $po['sender_name'] ?></span>
                                            </div>
                                            <div class="d-flex align-items-center">
                                                <i class="bi bi-box-arrow-in-down-left text-muted me-2 small"></i>
                                                <span><?= $po['receiver_name'] ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <?= getStatusBadge($po['status']) ?>
                                        </td>
                                        <td>
                                            <div class="small text-muted"><i class="bi bi-truck me-1"></i> <?= $po['transport_mode'] ?></div>
                                            <div class="small text-muted"><i class="bi bi-box me-1"></i> <?= $po['weight'] ?> kg</div>
                                        </td>
                                        <td>
                                            <?php
                                            $sla = $po['sla_agreement'] ?? '';
                                            if (strpos(strtoupper($sla), 'RUSH') !== false || strpos($sla, '24H') !== false) {
                                                echo '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25"><i class="bi bi-lightning-fill"></i> RUSH</span>';
                                            } else {
                                                echo '<span class="badge bg-light text-muted border">Standard</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php if ($_SESSION['role'] === 'ADMIN' && $po['status'] === 'APPROVED') { ?>
                                                <form method="POST" action="shipments.php">
                                                    <input type="hidden" name="po_id" value="<?= $po['po_id'] ?>">
                                                    <button name="create_shipment" class="btn btn-sm btn-outline-primary rounded-pill px-3 shadow-sm">
                                                        <i class="bi bi-arrow-right-short"></i> Ship
                                                    </button>
                                                </form>
                                            <?php } else { ?>
                                                <button class="btn btn-sm btn-light border text-muted" disabled>Locked</button>
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
    </div>

    <div class="modal fade" id="createOrderModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-cart-plus me-2"></i>New Purchase Order</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-12">
                                <h6 class="text-primary fw-bold text-uppercase small border-bottom pb-2">Contract Details</h6>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Contract Number <span class="text-danger">*</span></label>
                                <input type="text" name="contract_number" class="form-control" required placeholder="CN-202X-XXXX">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted">SLA Agreement</label>
                                <select name="sla_agreement" class="form-select">
                                    <option value="Standard">Standard Delivery</option>
                                    <option value="RUSH - 24H">RUSH - 24 Hours</option>
                                    <option value="RUSH - 48H">RUSH - 48 Hours</option>
                                </select>
                            </div>

                            <div class="col-12 mt-4">
                                <h6 class="text-primary fw-bold text-uppercase small border-bottom pb-2">Logistics Info</h6>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Sender Name</label>
                                <input type="text" name="sender_name" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Sender Contact</label>
                                <input type="text" name="sender_contact" class="form-control form-control-sm">
                            </div>
                            <div class="col-12">
                                <label class="form-label small text-muted">Origin Address</label>
                                <input type="text" name="origin_address" class="form-control form-control-sm" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small text-muted">Receiver Name</label>
                                <input type="text" name="receiver_name" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Receiver Contact</label>
                                <input type="text" name="receiver_contact" class="form-control form-control-sm">
                            </div>
                            <div class="col-12">
                                <label class="form-label small text-muted">Destination Address</label>
                                <input type="text" name="destination_address" class="form-control form-control-sm" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label small text-muted">Transport Mode <span class="text-danger">*</span></label>
                                <select name="transport_mode" class="form-select form-select-sm" required>
                                    <option value="">Select Mode</option>
                                    <option value="AIR">Air Freight</option>
                                    <option value="SEA">Sea Freight</option>
                                    <option value="LAND">Land Freight</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Weight (kg)</label>
                                <input type="number" step="0.01" name="weight" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Package Type</label>
                                <input type="text" name="package_type" class="form-control form-control-sm" placeholder="e.g. Box, Pallet">
                            </div>
                            <div class="col-12">
                                <label class="form-label small text-muted">Description</label>
                                <textarea name="package_description" class="form-control form-control-sm" rows="2"></textarea>
                            </div>

                            <input type="hidden" name="payment_method" value="Manual">
                            <input type="hidden" name="bank_name" value="N/A">
                            <input type="hidden" name="distance_km" value="0">
                            <input type="hidden" name="price" value="0">
                            <input type="hidden" name="ai_estimated_time" value="TBD">
                            <input type="hidden" name="target_delivery_date" value="<?= date('Y-m-d', strtotime('+7 days')) ?>">

                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_po" class="btn btn-primary px-4">Create Order</button>
                    </div>
                </form>
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
                "order": [[1, "desc"]],
                "pageLength": 10,
                "language": { "search": "_INPUT_", "searchPlaceholder": "Search orders..." }
            }); 
        });

        function toggleSelectAll(source) {
            document.querySelectorAll('.po-checkbox').forEach(box => box.checked = source.checked);
        }

        function submitBulkAction(status) {
            var checkedBoxes = document.querySelectorAll('.po-checkbox:checked');
            if (checkedBoxes.length === 0) { Swal.fire('No Selection', 'Please select PENDING items to update.', 'warning'); return; }

            var form = document.getElementById('bulkForm');
            form.querySelectorAll('input[name="selected_ids[]"]').forEach(i => i.remove());
            
            var validCount = 0;
            checkedBoxes.forEach(function(box) {
                if(box.dataset.status === 'PENDING') {
                    var input = document.createElement('input');
                    input.type = 'hidden'; input.name = 'selected_ids[]'; input.value = box.value;
                    form.appendChild(input);
                    validCount++;
                }
            });

            if (validCount === 0) { Swal.fire('Invalid Selection', 'Only PENDING orders can be updated.', 'error'); return; }

            document.getElementById('bulkStatusInput').value = status;
            form.submit();
        }

        function submitBulkShipment() {
            var checkedBoxes = document.querySelectorAll('.po-checkbox:checked');
            if (checkedBoxes.length === 0) { Swal.fire('No Selection', 'Please select APPROVED items to ship.', 'warning'); return; }

            var form = document.getElementById('bulkShipmentForm');
            form.querySelectorAll('input[name="selected_ids[]"]').forEach(i => i.remove());
            
            var count = 0;
            checkedBoxes.forEach(function(box) {
                if(box.dataset.status === 'APPROVED') {
                    var input = document.createElement('input');
                    input.type = 'hidden'; input.name = 'selected_ids[]'; input.value = box.value;
                    form.appendChild(input);
                    count++;
                }
            });

            if(count === 0) {
                Swal.fire('Invalid Selection', 'Only APPROVED orders can be shipped.', 'error');
                return;
            }

            form.submit();
        }

        // --- FIXED: Sync Core 3 Function ---
        function syncCore3() {
            Swal.fire({
                title: 'Syncing with Core 3...',
                text: 'Fetching latest bookings from the Logistics System',
                icon: 'info',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            fetch('../api/sync_core3_bookings.php')
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        // FIX: Removed data.skipped since API doesn't return it
                        let msg = `Imported: ${data.imported}\nUpdated: ${data.updated}`;
                        if (data.db_errors > 0) msg += `\nErrors: ${data.db_errors}`;
                        
                        Swal.fire('Sync Complete', msg, 'success').then(() => {
                            location.reload(); 
                        });
                    } else {
                        Swal.fire('Sync Error', data.message || 'Unknown error', 'error');
                        if(data.raw_response) console.error("Raw Response:", data.raw_response);
                    }
                })
                .catch(error => {
                    console.error('Fetch Error:', error);
                    Swal.fire('Connection Error', 'Could not reach Core 3 sync API. Check console.', 'error');
                });
        }
    </script>
</body>
</html>