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
    <link href="https://fonts.googleapis.com/css2?family=Libre+Barcode+39+Text&family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <link rel="shortcut icon" href="../assets/slate.png" type="image/x-icon">
    
    <style>
        body {
            font-family: 'Roboto', "Helvetica Neue", Arial, sans-serif;
            background: #555;
            padding: 30px;
            font-size: 11px;
        }

        .page {
            background: white;
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 10mm;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.5);
            position: relative;
            color: #000;
            overflow: hidden; /* Prevent watermark spill */
        }

        /* Watermark */
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 100px;
            color: rgba(0, 0, 0, 0.05);
            font-weight: bold;
            z-index: 0;
            pointer-events: none;
            white-space: nowrap;
            border: 5px solid rgba(0, 0, 0, 0.05);
            padding: 20px 50px;
        }

        /* --- Header Section --- */
        .header-section {
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .company-logo img {
            height: 50px;
            filter: grayscale(100%);
        }
        .company-details h1 {
            font-size: 24px;
            font-weight: 700;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .company-details p {
            margin: 0;
            color: #555;
            font-size: 10px;
        }
        .bl-title {
            text-align: right;
        }
        .bl-title h2 {
            font-size: 18px;
            font-weight: bold;
            margin: 0;
            border: 2px solid #000;
            padding: 5px 15px;
            display: inline-block;
        }
        .bl-number {
            font-family: 'Libre Barcode 39 Text', cursive;
            font-size: 36px;
            margin-top: 5px;
            text-align: right;
            line-height: 1;
        }

        /* --- Grid Layout --- */
        .grid-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            border: 1px solid #000;
        }
        .grid-item {
            border: 1px solid #000;
            padding: 5px 8px;
            min-height: 80px;
            margin: -1px 0 0 -1px; /* Collapse borders */
        }
        .grid-full {
            grid-column: 1 / span 2;
        }
        .label {
            display: block;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            color: #444;
            margin-bottom: 3px;
        }
        .value {
            font-size: 12px;
            font-weight: 500;
            white-space: pre-line;
        }

        /* --- Cargo Table --- */
        .cargo-section {
            margin-top: 20px;
            border: 1px solid #000;
        }
        .cargo-table {
            width: 100%;
            border-collapse: collapse;
        }
        .cargo-table th {
            background: #eee;
            border-bottom: 1px solid #000;
            border-right: 1px solid #000;
            padding: 6px;
            font-size: 10px;
            text-transform: uppercase;
            text-align: left;
        }
        .cargo-table th:last-child { border-right: none; }
        .cargo-table td {
            padding: 8px;
            border-right: 1px solid #ccc;
            vertical-align: top;
            font-size: 11px;
        }
        .cargo-table td:last-child { border-right: none; }
        .total-row {
            border-top: 1px solid #000;
            background: #f9f9f9;
            font-weight: bold;
        }

        /* --- Footer --- */
        .footer-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            margin-top: 20px;
            gap: 20px;
        }
        .footer-box {
            border: 1px solid #000;
            padding: 10px;
            min-height: 120px;
            position: relative;
        }
        .signature-area {
            position: absolute;
            bottom: 10px;
            left: 10px;
            right: 10px;
            border-top: 1px dashed #000;
            padding-top: 5px;
            font-size: 10px;
            text-align: center;
        }
        .signature-img {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            height: 60px;
            opacity: 0.8;
        }

        /* --- Terms --- */
        .terms-container {
            margin-top: 20px;
            font-size: 7px;
            color: #666;
            text-align: justify;
            column-count: 2;
            column-gap: 20px;
            line-height: 1.2;
        }

        /* --- Buttons --- */
        .print-btn-container {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            background: rgba(0,0,0,0.7);
            padding: 10px 20px;
            border-radius: 50px;
            display: flex;
            gap: 10px;
        }

        @media print {
            body { background: none; padding: 0; }
            .page { width: 100%; height: 100%; margin: 0; padding: 0; box-shadow: none; border: none; }
            .print-btn-container { display: none !important; }
            /* Force background graphics for logos/watermarks */
        }
    </style>
</head>
<body>

    <div class="print-btn-container no-print">
        <button onclick="window.print()" class="btn btn-primary btn-sm fw-bold rounded-pill px-4">🖨️ Print Original</button>
        <button onclick="window.close()" class="btn btn-light btn-sm fw-bold rounded-pill px-4">Close</button>
    </div>

    <div class="page">
        <div class="watermark">ORIGINAL</div>

        <div class="header-section">
            <div class="d-flex align-items-center gap-3">
                <div class="company-logo">
                    <img src="../assets/slate.png" alt="Logo">
                </div>
                <div class="company-details">
                    <h1>Slate Logistics Corp.</h1>
                    <p>101 Logistics Way, Port Area, Manila, Philippines</p>
                    <p>Tel: +63 2 8123 4567 | Email: operations@slatelogistics.com</p>
                </div>
            </div>
            <div class="bl-title">
                <h2>BILL OF LADING</h2>
                <div class="bl-number"><?= $bl['hmbl_no'] ?></div>
                <div style="font-size: 10px; font-weight: bold; margin-top: 2px;">No. <?= $bl['hmbl_no'] ?></div>
            </div>
        </div>

        <div class="grid-container">
            <div class="grid-item">
                <span class="label">Shipper / Exporter</span>
                <div class="value"><?= strtoupper($bl['shipper']) ?></div>
                <div style="font-size: 10px; color: #555; margin-top: 2px;">
                    (Address not provided)
                </div>
            </div>
            <div class="grid-item">
                <span class="label">Booking Ref. No.</span>
                <div class="value"><?= $bl['consolidation_code'] ?></div>
                <span class="label" style="margin-top: 8px;">Export References</span>
                <div class="value">Trip: <?= $bl['trip_no'] ?></div>
            </div>

            <div class="grid-item">
                <span class="label">Consignee</span>
                <div class="value"><?= strtoupper($bl['consignee']) ?></div>
                <div style="font-size: 10px; color: #555; margin-top: 2px;">
                    (To the order of)
                </div>
            </div>
            <div class="grid-item">
                <span class="label">Notify Party</span>
                <div class="value"><?= strtoupper($bl['notify_party'] ?: 'SAME AS CONSIGNEE') ?></div>
            </div>

            <div class="grid-item">
                <span class="label">Pre-Carriage By</span>
                <div class="value">TRUCK</div>
            </div>
            <div class="grid-item">
                <span class="label">Place of Receipt</span>
                <div class="value"><?= strtoupper($bl['origin']) ?></div>
            </div>

            <div class="grid-item">
                <span class="label">Vessel / Voyage No.</span>
                <div class="value"><?= strtoupper($bl['vessel']) ?></div>
            </div>
            <div class="grid-item">
                <span class="label">Port of Loading</span>
                <div class="value"><?= strtoupper($bl['port_of_loading']) ?></div>
            </div>

            <div class="grid-item">
                <span class="label">Port of Discharge</span>
                <div class="value"><?= strtoupper($bl['port_of_discharge']) ?></div>
            </div>
            <div class="grid-item">
                <span class="label">Place of Delivery</span>
                <div class="value"><?= strtoupper($bl['destination']) ?></div>
            </div>
        </div>

        <div class="cargo-section">
            <table class="cargo-table">
                <thead>
                    <tr>
                        <th style="width: 20%;">Marks & Nos</th>
                        <th style="width: 15%;">Package Type</th>
                        <th style="width: 50%;">Description of Goods</th>
                        <th style="width: 15%; text-align: right;">Gross Weight (KG)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $total_weight = 0;
                    $row_count = 0;
                    // Reset pointer if needed, though usually not required after fetch_assoc loop unless reused
                    $items->data_seek(0); 
                    
                    while ($item = $items->fetch_assoc()):
                        $total_weight += $item['weight'];
                        $row_count++;
                    ?>
                        <tr>
                            <td>
                                <strong><?= $item['shipment_code'] ?></strong><br>
                                <span style="font-size: 9px; color: #666;">1/1</span>
                            </td>
                            <td><?= strtoupper($item['package_type']) ?></td>
                            <td>
                                <strong><?= strtoupper($item['package_description'] ?: 'GENERAL CARGO') ?></strong>
                                <br><span style="font-size: 9px;">Freight Prepaid</span>
                            </td>
                            <td style="text-align: right;"><?= number_format($item['weight'], 2) ?></td>
                        </tr>
                    <?php endwhile; ?>

                    <?php for ($i = 0; $i < (5 - $row_count); $i++): ?>
                        <tr>
                            <td style="height: 30px;">&nbsp;</td>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                        </tr>
                    <?php endfor; ?>

                    <tr class="total-row">
                        <td colspan="3" style="text-align: right; padding-right: 10px;">TOTAL GROSS WEIGHT</td>
                        <td style="text-align: right;"><?= number_format($total_weight, 2) ?> KG</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="footer-grid">
            <div class="footer-box">
                <span class="label">Date & Place of Issue</span>
                <div class="value"><?= date("d M Y", strtotime($bl['created_at'])) ?></div>
                <div class="value">MANILA, PHILIPPINES</div>
                
                <div style="margin-top: 15px;">
                    <span class="label">Number of Original BL(s)</span>
                    <div class="value">THREE (3)</div>
                </div>
            </div>
            
            <div class="footer-box">
                <span class="label">Signed for the Carrier</span>
                <div class="text-center" style="margin-top: 10px;">
                    <img src="../assets/signature.png" alt="Signature" class="signature-img">
                </div>
                <div class="signature-area">
                    AS AGENT FOR THE CARRIER: SLATE LOGISTICS CORP.<br>
                    (<?= htmlspecialchars($bl['issuer_name']) ?>)
                </div>
            </div>
        </div>

        <div class="terms-container">
            <p><strong>RECEIVED</strong> by the Carrier from the Shipper in apparent good order and condition (unless otherwise noted herein) the total number or quantity of Containers or other packages or units indicated in the box opposite entitled "Total No. of Containers/Packages received by the Carrier" for Carriage subject to all the terms and conditions hereof (INCLUDING THE TERMS AND CONDITIONS ON THE REVERSE HEREOF AND THE TERMS AND CONDITIONS OF THE CARRIER'S APPLICABLE TARIFF) from the Place of Receipt or the Port of Loading, whichever is applicable, to the Port of Discharge or the Place of Delivery, whichever is applicable.</p>
            <p><strong>LIABILITY:</strong> The Carrier's liability shall be determined by the Hague-Visby Rules or the US COGSA, depending on the jurisdiction. The Carrier shall not be liable for loss or damage arising or resulting from unseaworthiness unless caused by want of due diligence.</p>
            <p><strong>DELIVERY:</strong> If the Merchant fails to take delivery of the Goods within a reasonable time, the Carrier may, without notice, unpack the Goods if packed in containers and/or store the Goods at the Merchant's sole risk. Such storage shall constitute due delivery hereunder, and thereupon all liability whatsoever of the Carrier in respect of the Goods shall cease.</p>
            <p><strong>JURISDICTION:</strong> Any claim or dispute arising under this Bill of Lading shall be governed by the law of the Philippines and determined in the courts of Manila to the exclusion of the courts of any other country.</p>
        </div>

    </div>
</body>
</html>