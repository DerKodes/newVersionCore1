<?php
session_start();
include "../api/db.php";
include "../includes/auth_check.php";

if (!isset($_GET['id'])) {
    die("Invalid BL Request.");
}

$hmbl_id = (int)$_GET['id'];

// Fetch BL Data + Consolidation Info
$query = "
    SELECT 
        h.*, 
        c.consolidation_code, c.trip_no, c.origin, c.destination,
        u.full_name as issuer_name
    FROM hmbl h
    JOIN consolidations c ON h.consolidation_id = c.consolidation_id
    JOIN users u ON h.created_by = u.user_id
    WHERE h.hmbl_id = ?
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $hmbl_id);
$stmt->execute();
$bl = $stmt->get_result()->fetch_assoc();

if (!$bl) die("BL Document Not Found.");

// Fetch Associated Cargo Items
// 🟢 FIXED: Changed s.weight to po.weight
$items_query = "
    SELECT s.shipment_code, po.weight, po.package_type, po.package_description
    FROM hmbl_shipments hs
    JOIN shipments s ON hs.shipment_id = s.shipment_id
    JOIN purchase_orders po ON s.po_id = po.po_id
    WHERE hs.hmbl_id = ?
";
$stmt = $conn->prepare($items_query);
$stmt->bind_param("i", $hmbl_id);
$stmt->execute();
$items = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>BL: <?= $bl['hmbl_no'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-family: "Times New Roman", Times, serif;
            background: #555;
            padding: 20px;
        }

        .page {
            background: white;
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 15mm;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
            position: relative;
        }

        .bl-header {
            border: 2px solid #000;
            display: flex;
        }

        .box {
            border-right: 1px solid #000;
            border-bottom: 1px solid #000;
            padding: 8px;
            font-size: 14px;
        }

        .box-label {
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            color: #555;
            display: block;
            margin-bottom: 3px;
        }

        .bl-title {
            font-size: 24px;
            font-weight: bold;
            text-align: center;
            padding: 20px;
        }

        .cargo-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 2px;
        }

        .cargo-table th {
            border: 1px solid #000;
            padding: 5px;
            font-size: 12px;
            background: #eee;
        }

        .cargo-table td {
            border: 1px solid #000;
            padding: 8px;
            font-size: 13px;
            vertical-align: top;
        }

        .terms {
            font-size: 9px;
            text-align: justify;
            margin-top: 20px;
            color: #444;
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }

            .page {
                box-shadow: none;
                margin: 0;
                width: 100%;
            }

            .no-print {
                display: none !important;
            }
        }
    </style>
</head>

<body>

    <div class="text-center mb-4 no-print">
        <button onclick="window.print()" class="btn btn-primary fw-bold px-4">🖨️ Print Original Bill of Lading</button>
        <button onclick="window.close()" class="btn btn-secondary ms-2">Close</button>
    </div>

    <div class="page">

        <div class="row mb-4 align-items-center">
            <div class="col-6">
                <img src="../assets/slate.png" alt="Logo" style="height: 50px; filter: grayscale(100%);">
                <h5 class="fw-bold mt-2">SLATE LOGISTICS CORP.</h5>
                <small>101 Logistics Way, Port Area<br>Manila, Philippines</small>
            </div>
            <div class="col-6 text-end">
                <h2 class="fw-bold">BILL OF LADING</h2>
                <h5 class="text-danger"><?= $bl['hmbl_no'] ?></h5>
            </div>
        </div>

        <div style="border: 2px solid black;">
            <div class="row g-0">
                <div class="col-6 box">
                    <span class="box-label">Shipper / Exporter</span>
                    <strong><?= strtoupper($bl['shipper']) ?></strong>
                </div>
                <div class="col-6 box" style="border-right: none;">
                    <span class="box-label">Booking / Reference No.</span>
                    <?= $bl['consolidation_code'] ?>
                </div>
            </div>

            <div class="row g-0">
                <div class="col-6 box">
                    <span class="box-label">Consignee</span>
                    <strong><?= strtoupper($bl['consignee']) ?></strong>
                </div>
                <div class="col-6 box" style="border-right: none;">
                    <span class="box-label">Trip Number / Voyage</span>
                    <?= $bl['trip_no'] ?>
                </div>
            </div>

            <div class="row g-0">
                <div class="col-6 box">
                    <span class="box-label">Notify Party</span>
                    <?= strtoupper($bl['notify_party'] ?: $bl['consignee']) ?>
                </div>
                <div class="col-6 p-0">
                    <div class="box" style="border-right: none; border-bottom: 1px solid black;">
                        <span class="box-label">Port of Loading</span>
                        <?= strtoupper($bl['port_of_loading']) ?>
                    </div>
                    <div class="box" style="border-right: none; border-bottom: none;">
                        <span class="box-label">Port of Discharge</span>
                        <?= strtoupper($bl['port_of_discharge']) ?>
                    </div>
                </div>
            </div>

            <div class="row g-0">
                <div class="col-6 box" style="border-bottom: none;">
                    <span class="box-label">Vessel / Carrier</span>
                    <?= strtoupper($bl['vessel']) ?>
                </div>
                <div class="col-6 box" style="border-right: none; border-bottom: none;">
                    <span class="box-label">Date of Issue</span>
                    <?= date("F d, Y", strtotime($bl['created_at'])) ?>
                </div>
            </div>
        </div>

        <table class="cargo-table mt-3">
            <thead>
                <tr>
                    <th style="width: 20%;">Shipment Code</th>
                    <th style="width: 15%;">Package</th>
                    <th style="width: 50%;">Description of Goods</th>
                    <th style="width: 15%; text-align: right;">Gross Weight</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $total_weight = 0;
                while ($item = $items->fetch_assoc()):
                    $total_weight += $item['weight'];
                ?>
                    <tr>
                        <td><?= $item['shipment_code'] ?></td>
                        <td><?= $item['package_type'] ?></td>
                        <td><?= $item['package_description'] ?: 'General Cargo' ?></td>
                        <td class="text-end"><?= number_format($item['weight'], 2) ?> KG</td>
                    </tr>
                <?php endwhile; ?>

                <?php for ($i = 0; $i < 3; $i++): ?>
                    <tr>
                        <td style="height: 30px;"></td>
                        <td></td>
                        <td></td>
                        <td></td>
                    </tr>
                <?php endfor; ?>

                <tr style="background: #eee; font-weight: bold;">
                    <td colspan="3" class="text-end">TOTAL WEIGHT</td>
                    <td class="text-end"><?= number_format($total_weight, 2) ?> KG</td>
                </tr>
            </tbody>
        </table>

        <div class="row mt-5">
            <div class="col-6">
                <div style="border-top: 1px solid black; width: 80%; padding-top: 5px;">
                    <span class="box-label">Received by (Name & Signature)</span>
                </div>
            </div>
            <div class="col-6 text-end">
                <div class="mb-4">
                    <img src="../assets/signature.png" alt="Authorized Sig" style="height: 40px; opacity: 0.5;">
                </div>
                <div style="border-top: 1px solid black; width: 80%; float: right; padding-top: 5px;">
                    <span class="box-label">For SLATE LOGISTICS (Authorized Signature)</span>
                    <small>Issued by: <?= $bl['issuer_name'] ?></small>
                </div>
            </div>
        </div>

        <div class="terms">
            <strong>TERMS AND CONDITIONS:</strong><br>
            1. RECEIVED by the Carrier from the Shipper in apparent good order and condition unless otherwise indicated.
            2. The Goods to be delivered at the above mentioned Port of Discharge or place of delivery.
            3. Weight, measure, marks, numbers, quality, contents and value as declared by the Shipper but unknown to the Carrier.
            4. This Bill of Lading is issued subject to the Standard Trading Conditions of the Carrier.
        </div>

    </div>

</body>

</html>