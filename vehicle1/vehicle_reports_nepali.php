<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';

redirect_if_not_logged_in();

// Set UTF-8 encoding for proper Nepali character display
header('Content-Type: text/html; charset=UTF-8');

// Nepali months
$nepali_months = [
    'Baishakh' => 'बैशाख', 'Jestha' => 'जेष्ठ', 'Ashadh' => 'आषाढ', 
    'Shrawan' => 'श्रावण', 'Bhadra' => 'भाद्र', 'Ashwin' => 'आश्विन',
    'Kartik' => 'कार्तिक', 'Mangsir' => 'मंसिर', 'Poush' => 'पौष', 
    'Magh' => 'माघ', 'Falgun' => 'फाल्गुन', 'Chaitra' => 'चैत्र'
];

// Get parameters
$fiscal_year = $_GET['fiscal_year'] ?? '2082/83';
$month_nep = $_GET['month_nep'] ?? 'Mangsir';
$month_nep_unicode = $nepali_months[$month_nep] ?? $month_nep;
$report_type = $_GET['type'] ?? 'mileage';

// Fetch data based on report type
if ($report_type === 'mileage') {
    $stmt = $conn->prepare("
    SELECT 
        v.vehicle_id,
        v.vehicle_no,
        v.vehicle_type,
        v.fuel_type,
        
        -- Opening meter
        (SELECT start_meter 
         FROM vehicle_daily_logs 
         WHERE vehicle_id = v.vehicle_id 
           AND fiscal_year = :fiscal_year 
           AND month_nep = :month_nep
           AND deleted_at IS NULL
           AND start_meter IS NOT NULL
           AND start_meter > 0
         ORDER BY log_date_eng ASC, log_id ASC
         LIMIT 1) as opening_meter,
        
        -- Closing meter
        (SELECT end_meter 
         FROM vehicle_daily_logs 
         WHERE vehicle_id = v.vehicle_id 
           AND fiscal_year = :fiscal_year 
           AND month_nep = :month_nep
           AND deleted_at IS NULL
           AND end_meter IS NOT NULL
           AND end_meter > 0
         ORDER BY log_date_eng DESC, log_id DESC
         LIMIT 1) as closing_meter,
        
        -- Total KM
        COALESCE((
            SELECT SUM(end_meter - start_meter) 
            FROM vehicle_daily_logs 
            WHERE vehicle_id = v.vehicle_id 
              AND fiscal_year = :fiscal_year 
              AND month_nep = :month_nep
              AND deleted_at IS NULL
              AND end_meter IS NOT NULL
              AND start_meter IS NOT NULL
              AND end_meter > start_meter
        ), 0) as total_km,
        
        -- Fuel details
        (SELECT COALESCE(SUM(fcd.disburse_qty), 0) 
         FROM fuel_coupon_distributions fcd
         JOIN fuel_coupons fc ON fcd.coupon_id = fc.coupon_id
         WHERE fc.vehicle_id = v.vehicle_id
           AND fc.fiscal_year = :fiscal_year
           AND fc.month_nep = :month_nep
           AND fc.fuel_type = 'petrol'
           AND fcd.deleted_at IS NULL
           AND fc.deleted_at IS NULL) as petrol_qty,
        
        (SELECT COALESCE(SUM(fcd.total_amount), 0) 
         FROM fuel_coupon_distributions fcd
         JOIN fuel_coupons fc ON fcd.coupon_id = fc.coupon_id
         WHERE fc.vehicle_id = v.vehicle_id
           AND fc.fiscal_year = :fiscal_year
           AND fc.month_nep = :month_nep
           AND fc.fuel_type = 'petrol'
           AND fcd.deleted_at IS NULL
           AND fc.deleted_at IS NULL) as petrol_amount,
        
        (SELECT COALESCE(SUM(fcd.disburse_qty), 0) 
         FROM fuel_coupon_distributions fcd
         JOIN fuel_coupons fc ON fcd.coupon_id = fc.coupon_id
         WHERE fc.vehicle_id = v.vehicle_id
           AND fc.fiscal_year = :fiscal_year
           AND fc.month_nep = :month_nep
           AND fc.fuel_type = 'diesel'
           AND fcd.deleted_at IS NULL
           AND fc.deleted_at IS NULL) as diesel_qty,
        
        (SELECT COALESCE(SUM(fcd.total_amount), 0) 
         FROM fuel_coupon_distributions fcd
         JOIN fuel_coupons fc ON fcd.coupon_id = fc.coupon_id
         WHERE fc.vehicle_id = v.vehicle_id
           AND fc.fiscal_year = :fiscal_year
           AND fc.month_nep = :month_nep
           AND fc.fuel_type = 'diesel'
           AND fcd.deleted_at IS NULL
           AND fc.deleted_at IS NULL) as diesel_amount,
        
        (SELECT COALESCE(SUM(fcd.disburse_qty), 0) 
         FROM fuel_coupon_distributions fcd
         JOIN fuel_coupons fc ON fcd.coupon_id = fc.coupon_id
         WHERE fc.vehicle_id = v.vehicle_id
           AND fc.fiscal_year = :fiscal_year
           AND fc.month_nep = :month_nep
           AND fc.fuel_type = 'mobil'
           AND fcd.deleted_at IS NULL
           AND fc.deleted_at IS NULL) as mobil_qty,
        
        (SELECT COALESCE(SUM(fcd.total_amount), 0) 
         FROM fuel_coupon_distributions fcd
         JOIN fuel_coupons fc ON fcd.coupon_id = fc.coupon_id
         WHERE fc.vehicle_id = v.vehicle_id
           AND fc.fiscal_year = :fiscal_year
           AND fc.month_nep = :month_nep
           AND fc.fuel_type = 'mobil'
           AND fcd.deleted_at IS NULL
           AND fc.deleted_at IS NULL) as mobil_amount,
           
        (SELECT COALESCE(SUM(fcd.total_amount), 0) 
         FROM fuel_coupon_distributions fcd
         JOIN fuel_coupons fc ON fcd.coupon_id = fc.coupon_id
         WHERE fc.vehicle_id = v.vehicle_id
           AND fc.fiscal_year = :fiscal_year
           AND fc.month_nep = :month_nep
           AND fcd.deleted_at IS NULL
           AND fc.deleted_at IS NULL) as total_fuel_amount
        
    FROM vehicles v
    WHERE v.deleted_at IS NULL
      AND v.status = TRUE
    ORDER BY v.vehicle_no
");

    $stmt->execute([
        ':fiscal_year' => $fiscal_year,
        ':month_nep' => $month_nep
    ]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Calculate totals
$grand_total_km = 0;
$grand_total_petrol_qty = 0;
$grand_total_petrol_amt = 0;
$grand_total_diesel_qty = 0;
$grand_total_diesel_amt = 0;
$grand_total_mobil_qty = 0;
$grand_total_mobil_amt = 0;
$grand_total_amount = 0;

foreach ($data as $row) {
    $grand_total_km += $row['total_km'];
    $grand_total_petrol_qty += $row['petrol_qty'];
    $grand_total_petrol_amt += $row['petrol_amount'];
    $grand_total_diesel_qty += $row['diesel_qty'];
    $grand_total_diesel_amt += $row['diesel_amount'];
    $grand_total_mobil_qty += $row['mobil_qty'];
    $grand_total_mobil_amt += $row['mobil_amount'];
    $grand_total_amount += $row['total_fuel_amount'];
}

$current_date_nep = '२०८२ ' . $month_nep_unicode;
?>
<!DOCTYPE html>
<html lang="ne">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>सवारी साधन मासिक विवरण - <?= $month_nep_unicode ?> <?= $fiscal_year ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        @page {
            size: A4 landscape;
            margin: 15mm;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Noto Sans Devanagari', 'Kalimati', 'Mangal', sans-serif;
            font-size: 11pt;
            line-height: 1.3;
        }
        
        .report-container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            background: white;
        }
        
        .header {
            text-align: center;
            margin-bottom: 15px;
            padding: 10px;
            border: 2px solid #000;
            background: #e6f3ff;
        }
        
        .header h1 {
            font-size: 16pt;
            font-weight: bold;
            margin-bottom: 3px;
        }
        
        .header h2 {
            font-size: 13pt;
            margin-bottom: 3px;
        }
        
        .header h3 {
            font-size: 12pt;
            margin-bottom: 2px;
        }
        
        .header .date {
            font-size: 10pt;
            text-align: right;
            margin-top: 5px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        th, td {
            border: 1px solid #000;
            padding: 5px 8px;
            text-align: center;
            font-size: 10pt;
            vertical-align: middle;
        }
        
        th {
            background: #b3d9ff;
            font-weight: bold;
            font-size: 10pt;
        }
        
        .text-left {
            text-align: left;
        }
        
        .text-right {
            text-align: right;
        }
        
        .total-row {
            background: #b3d9ff;
            font-weight: bold;
        }
        
        .footer {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            padding: 15px 0;
        }
        
        .signature-box {
            text-align: center;
            min-width: 200px;
        }
        
        .signature-line {
            border-top: 1px solid #000;
            margin-top: 60px;
            padding-top: 5px;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            
            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
        
        .print-controls {
            text-align: center;
            margin-bottom: 20px;
            padding: 15px;
            background: #f0f0f0;
            border-radius: 5px;
        }
        
        .print-controls button {
            padding: 10px 30px;
            font-size: 14pt;
            margin: 0 10px;
            cursor: pointer;
            border: none;
            border-radius: 5px;
        }
        
        .btn-print {
            background: #007bff;
            color: white;
        }
        
        .btn-back {
            background: #6c757d;
            color: white;
        }
        
        .vehicle-type {
            font-size: 9pt;
            color: #666;
            font-family: Arial, sans-serif;
        }
        
        .amount {
            font-weight: bold;
        }
        
        .remarks-col {
            min-width: 80px;
        }
    </style>
</head>
<body>
    <div class="print-controls no-print">
        <button class="btn-print" onclick="window.print()">🖨️ Print Report</button>
        <button class="btn-back" onclick="window.history.back()">← Back</button>
        <button class="btn-print" onclick="exportToExcel()">📊 Export to Excel</button>
    </div>

    <div class="report-container">
        <div class="header">
            <h1>जनक शिक्षा सामग्री केन्द्र लि.</h1>
            <h2>सानोठिमी, भक्तपुर</h2>
            <h3>कर्मचारी प्रशासन शाखा, सवारी दुरई</h3>
            <h3><?= $month_nep_unicode ?> <?= $fiscal_year ?> को मासिक विवरण</h3>
            <div class="date">मिति: <?= $current_date_nep ?></div>
        </div>

        <table>
            <thead>
                <tr>
                    <th rowspan="3">क्र.सं</th>
                    <th rowspan="3">सवारी नं</th>
                    <th rowspan="3">शुरु/अन्त्य<br>मिटर</th>
                    <th rowspan="3">कति<br>कि.मि.</th>
                    <th rowspan="3">जम्मा<br>कि.मि.</th>
                    <th colspan="8">खपत इन्धनको विवरण</th>
                    <th rowspan="3">जम्मा रकम</th>
                    <th rowspan="3">कैफियत</th>
                    <th rowspan="3">सम्बद्धीय सवारी<br>चालकको दस्तखत</th>
                </tr>
                <tr>
                    <th colspan="3">पेट्रोलको विवरण</th>
                    <th colspan="3">डिजलको विवरण</th>
                    <th colspan="2">मोबिल</th>
                </tr>
                <tr>
                    <th>खपत</th>
                    <th>परिमाण लिटर</th>
                    <th>रकम</th>
                    <th>खपत</th>
                    <th>परिमाण लिटर</th>
                    <th>रकम</th>
                    <th>परिमाण लिटर</th>
                    <th>रकम</th>
                </tr>
            </thead>
            <tbody>
                <?php $serial = 1; ?>
                <?php foreach ($data as $row): ?>
                    <?php 
                    $total_km = $row['total_km'] ?? 0;
                    $opening = $row['opening_meter'] ?? 0;
                    $closing = $row['closing_meter'] ?? 0;
                    ?>
                    <tr>
                        <td><?= $serial++ ?></td>
                        <td class="text-left">
                            <strong><?= htmlspecialchars($row['vehicle_no']) ?></strong><br>
                            <span class="vehicle-type"><?= htmlspecialchars($row['vehicle_type']) ?></span>
                        </td>
                        <td>
                            <?= $opening > 0 ? number_format($opening) : '-' ?><br>
                            <?= $closing > 0 ? number_format($closing) : '-' ?>
                        </td>
                        <td><?= $total_km > 0 ? number_format($total_km) : '-' ?></td>
                        <td><strong><?= number_format($total_km) ?></strong></td>
                        
                        <!-- Petrol -->
                        <td><?= $row['petrol_qty'] > 0 ? number_format($row['petrol_qty'], 2) : '-' ?></td>
                        <td><?= $row['petrol_qty'] > 0 ? number_format($row['petrol_qty'], 2) : '-' ?></td>
                        <td class="amount"><?= $row['petrol_amount'] > 0 ? number_format($row['petrol_amount']) : '-' ?></td>
                        
                        <!-- Diesel -->
                        <td><?= $row['diesel_qty'] > 0 ? number_format($row['diesel_qty'], 2) : '-' ?></td>
                        <td><?= $row['diesel_qty'] > 0 ? number_format($row['diesel_qty'], 2) : '-' ?></td>
                        <td class="amount"><?= $row['diesel_amount'] > 0 ? number_format($row['diesel_amount']) : '-' ?></td>
                        
                        <!-- Mobil -->
                        <td><?= $row['mobil_qty'] > 0 ? number_format($row['mobil_qty'], 2) : '-' ?></td>
                        <td class="amount"><?= $row['mobil_amount'] > 0 ? number_format($row['mobil_amount']) : '-' ?></td>
                        
                        <!-- Total -->
                        <td class="amount"><strong><?= number_format($row['total_fuel_amount']) ?></strong></td>
                        
                        <!-- Remarks -->
                        <td class="remarks-col"></td>
                        
                        <!-- Signature -->
                        <td class="remarks-col"></td>
                    </tr>
                <?php endforeach; ?>
                
                <!-- Total Row -->
                <tr class="total-row">
                    <td colspan="4" class="text-right"><strong>जम्मा</strong></td>
                    <td><strong><?= number_format($grand_total_km) ?></strong></td>
                    <td><?= $grand_total_petrol_qty > 0 ? number_format($grand_total_petrol_qty, 2) : '-' ?></td>
                    <td><?= $grand_total_petrol_qty > 0 ? number_format($grand_total_petrol_qty, 2) : '-' ?></td>
                    <td class="amount"><strong><?= number_format($grand_total_petrol_amt) ?></strong></td>
                    <td><?= $grand_total_diesel_qty > 0 ? number_format($grand_total_diesel_qty, 2) : '-' ?></td>
                    <td><?= $grand_total_diesel_qty > 0 ? number_format($grand_total_diesel_qty, 2) : '-' ?></td>
                    <td class="amount"><strong><?= number_format($grand_total_diesel_amt) ?></strong></td>
                    <td><?= $grand_total_mobil_qty > 0 ? number_format($grand_total_mobil_qty, 2) : '-' ?></td>
                    <td class="amount"><strong><?= number_format($grand_total_mobil_amt) ?></strong></td>
                    <td class="amount"><strong><?= number_format($grand_total_amount) ?></strong></td>
                    <td colspan="2"></td>
                </tr>
            </tbody>
        </table>

        <div class="footer">
            <div class="signature-box">
                <div class="signature-line">तयार गर्ने</div>
            </div>
            <div class="signature-box">
                <div class="signature-line">प्रमाणित गर्ने</div>
            </div>
        </div>
    </div>

    <script>
    function exportToExcel() {
        let csv = '\ufeff';
        const table = document.querySelector('table');
        const rows = table.querySelectorAll('tr');
        
        rows.forEach(row => {
            const cols = row.querySelectorAll('td, th');
            const rowData = [];
            cols.forEach(col => {
                let text = col.innerText.replace(/"/g, '""');
                rowData.push('"' + text + '"');
            });
            csv += rowData.join(',') + '\r\n';
        });
        
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'vehicle_report_<?= $fiscal_year ?>_<?= $month_nep ?>.csv';
        link.click();
    }
    </script>
</body>
</html>