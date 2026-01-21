<?php
session_start();
include "../api/db.php";
include "../includes/auth_check.php";

if (!isset($_GET['id'])) die("Invalid BL ID");

$hmbl_id = (int)$_GET['id'];

// 1. Fetch BL Details
$stmt = $conn->prepare("
    SELECT h.*, c.trip_no, c.origin, c.destination
    FROM hmbl h
    JOIN consolidations c ON h.consolidation_id = c.consolidation_id
    WHERE h.hmbl_id = ?
");
$stmt->bind_param("i", $hmbl_id);
$stmt->execute();
$bl = $stmt->get_result()->fetch_assoc();

if (!$bl) die("BL Not Found");

// 2. Fetch Cargo Details (Fixed Query to JOIN Purchase Orders)
// We join purchase_orders (po) to get weight, package_type, etc.
$cargo = $conn->query("
    SELECT 
        s.shipment_code, 
        s.origin,
        po.transport_mode,
        po.weight, 
        po.package_type, 
        po.package_description
    FROM hmbl_shipments hs
    JOIN shipments s ON hs.shipment_id = s.shipment_id
    JOIN purchase_orders po ON s.po_id = po.po_id
    WHERE hs.hmbl_id = $hmbl_id
");

// Calculate Totals
$total_weight = 0;
$total_pkgs = 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>BL: <?= $bl['hmbl_no'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #525659;
            font-family: 'Times New Roman', serif;
        }

        .page {
            background: white;
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            padding: 15mm;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
            position: relative;
        }

        .box {
            border: 1px solid #000;
            min-height: 100px;
            padding: 8px;
            font-size: 12px;
            margin-bottom: -1px;
            margin-right: -1px;
            overflow: hidden;
        }

        .box-title {
            font-weight: bold;
            font-size: 10px;
            color: #555;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .bl-title {
            font-size: 28px;
            font-weight: bold;
            text-align: center;
            margin-top: 15px;
            color: #000;
        }

        .company-name {
            font-size: 18px;
            font-weight: bold;
            color: #0d6efd;
            text-align: center;
            margin-bottom: 5px;
        }

        table.cargo-table {
            width: 100%;
            font-size: 12px;
            border-collapse: collapse;
            margin-top: 20px;
        }

        table.cargo-table th {
            border-bottom: 1px solid #000;
            border-top: 1px solid #000;
            padding: 8px 5px;
            text-align: left;
        }

        table.cargo-table td {
            padding: 8px 5px;
            vertical-align: top;
        }

        @media print {
            body {
                background: white;
                margin: 0;
            }

            .page {
                box-shadow: none;
                margin: 0;
                width: 100%;
                height: 100%;
                padding: 0;
            }

            .no-print {
                display: none;
            }
        }
    </style>
</head>

<body>

    <div class="text-center no-print pt-3">
        <button onclick="window.print()" class="btn btn-primary shadow-sm">🖨️ Print BL</button>
        <button onclick="window.close()" class="btn btn-secondary shadow-sm">Close</button>
    </div>

    <div class="page">

        <div class="row g-0">
            <div class="col-6">
                <div class="box" style="height: 120px;">
                    <div class="box-title">Shipper / Exporter</div>
                    <?= nl2br($bl['shipper']) ?>
                </div>
                <div class="box" style="height: 120px;">
                    <div class="box-title">Consignee</div>
                    <?= nl2br($bl['consignee']) ?>
                </div>
                <div class="box" style="height: 120px;">
                    <div class="box-title">Notify Party</div>
                    <?= nl2br($bl['notify_party']) ?>
                </div>
            </div>

            <div class="col-6">
                <div class="row g-0">
                    <div class="col-6 box" style="height: 60px;">
                        <div class="box-title">BL Number</div>
                        <span class="fw-bold fs-5"><?= $bl['hmbl_no'] ?></span>
                    </div>
                    <div class="col-6 box" style="height: 60px;">
                        <div class="box-title">Job / Ref No.</div>
                        <?= $bl['trip_no'] ?>
                    </div>
                    <div class="col-12 box d-flex align-items-center justify-content-center flex-column" style="height: 180px;">
                        <div class="company-name">CORE LOGISTICS SYSTEM</div>
                        <div class="text-muted small">123 Logistics Ave, Manila, Philippines</div>
                        <div class="bl-title">BILL OF LADING</div>
                        <div class="small fw-bold mt-2">ORIGINAL</div>
                    </div>
                    <div class="col-12 box" style="height: 120px;">
                        <div class="box-title">For Release of Shipment, Please Contact:</div>
                        <strong>CORE INTERNATIONAL FREIGHT AGENTS</strong><br>
                        Contact Person: Admin Support<br>
                        Tel: +63 2 8700 0000<br>
                        Email: support@corelogistics.com
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-0">
            <div class="col-3 box">
                <div class="box-title">Vessel / Voyage</div>
                <?= $bl['vessel'] ?> / <?= $bl['voyage'] ?>
            </div>
            <div class="col-3 box">
                <div class="box-title">Port of Loading</div>
                <?= $bl['port_of_loading'] ?>
            </div>
            <div class="col-3 box">
                <div class="box-title">Port of Discharge</div>
                <?= $bl['port_of_discharge'] ?>
            </div>
            <div class="col-3 box">
                <div class="box-title">Final Destination</div>
                <?= $bl['destination'] ?>
            </div>
        </div>

        <table class="cargo-table">
            <thead>
                <tr>
                    <th width="20%">MARKS & NUMBERS</th>
                    <th width="10%">QTY</th>
                    <th width="50%">DESCRIPTION OF PACKAGES AND GOODS</th>
                    <th width="20%" class="text-end">GROSS WEIGHT</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($s = $cargo->fetch_assoc()):
                    // Safely handle null values to prevent warnings
                    $w = isset($s['weight']) ? (float)$s['weight'] : 0;
                    $total_weight += $w;
                    $total_pkgs++;
                ?>
                    <tr>
                        <td>
                            <?= $s['shipment_code'] ?><br>
                            <small>TYPE: <?= strtoupper($s['package_type'] ?? 'PKG') ?></small>
                        </td>
                        <td>1</td>
                        <td>
                            <strong><?= $s['transport_mode'] ?? 'STD' ?> FREIGHT</strong><br>
                            <?= $s['package_description'] ?: 'General Cargo' ?><br>
                            <small class="text-muted">Origin: <?= $s['origin'] ?></small>
                        </td>
                        <td class="text-end"><?= number_format($w, 2) ?> KGS</td>
                    </tr>
                <?php endwhile; ?>

                <tr style="border-top: 1px solid #000; font-weight: bold; background-color: #f9f9f9;">
                    <td>TOTALS</td>
                    <td><?= $total_pkgs ?> PKGS</td>
                    <td>SAY: <?= convertNumberToWords($total_pkgs) ?> PACKAGES ONLY</td>
                    <td class="text-end"><?= number_format($total_weight, 2) ?> KGS</td>
                </tr>
            </tbody>
        </table>

        <div class="row g-0 mt-5 pt-5">
            <div class="col-7 pe-4">
                <div style="font-size: 10px; text-align: justify;">
                    RECEIVED by the Carrier the Goods as specified above in apparent good order and condition unless otherwise stated...
                </div>
            </div>
            <div class="col-5 text-center">
                <div style="border-top: 1px solid #000; width: 90%; margin: 0 auto; padding-top: 5px;">
                    Signed for and on behalf of Carrier
                </div>
                <div style="height: 60px;"></div>
                <div style="font-size: 11px; font-weight: bold;">(AUTHORIZED SIGNATURE)</div>
                <div style="font-size: 11px;">Date Issued: <?= date("F j, Y", strtotime($bl['created_at'])) ?></div>
            </div>
        </div>

    </div>

</body>

</html>

<?php
// CUSTOM FUNCTION: No need for PHP Intl extension
function convertNumberToWords($number)
{
    $dictionary = array(
        0 => 'ZERO',
        1 => 'ONE',
        2 => 'TWO',
        3 => 'THREE',
        4 => 'FOUR',
        5 => 'FIVE',
        6 => 'SIX',
        7 => 'SEVEN',
        8 => 'EIGHT',
        9 => 'NINE',
        10 => 'TEN',
        11 => 'ELEVEN',
        12 => 'TWELVE',
        13 => 'THIRTEEN',
        14 => 'FOURTEEN',
        15 => 'FIFTEEN',
        16 => 'SIXTEEN',
        17 => 'SEVENTEEN',
        18 => 'EIGHTEEN',
        19 => 'NINETEEN',
        20 => 'TWENTY',
        30 => 'THIRTY',
        40 => 'FORTY',
        50 => 'FIFTY',
        60 => 'SIXTY',
        70 => 'SEVENTY',
        80 => 'EIGHTY',
        90 => 'NINETY'
    );

    if ($number < 21) {
        return $dictionary[$number];
    } elseif ($number < 100) {
        $tens = ((int) ($number / 10)) * 10;
        $units = $number % 10;
        $string = $dictionary[$tens];
        if ($units) {
            $string .= '-' . $dictionary[$units];
        }
        return $string;
    } elseif ($number < 1000) {
        // Simple logic for hundreds if needed, but usually shipment counts are small
        return (string)$number;
    }
    return (string)$number;
}
?>