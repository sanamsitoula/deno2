<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';

$maintenance_id = $_GET['id'] ?? null;

if (!$maintenance_id) {
    die('Maintenance ID required');
}

// Fetch maintenance record with all details
$stmt = $conn->prepare("
    SELECT 
        vmr.*,
        v.vehicle_no,
        v.vehicle_type,
        mt.type_name,
        vmr.labor_cost + vmr.parts_cost as total_cost
    FROM vehicle_maintenance_records vmr
    LEFT JOIN vehicles v ON vmr.vehicle_id = v.vehicle_id
    LEFT JOIN maintenance_types mt ON vmr.maintenance_type_id = mt.maintenance_type_id
    WHERE vmr.maintenance_id = :maintenance_id 
    AND vmr.deleted_at IS NULL
");
$stmt->execute([':maintenance_id' => $maintenance_id]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    die('Maintenance record not found');
}
?>
<!DOCTYPE html>
<html lang="ne">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Maintenance Record - <?= htmlspecialchars($record['vehicle_no']) ?></title>
    <style>
        @page {
            size: A4;
            margin: 15mm;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Kalimati', 'Mukta', Arial, sans-serif;
            font-size: 14px;
            line-height: 1.4;
            color: #000;
            background: white;
        }
        
        .print-container {
            max-width: 210mm;
            margin: 0 auto;
            padding: 10mm;
            background: white;
        }
        
        .header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        
        .logo {
            width: 60px;
            height: 60px;
            float: left;
            margin-right: 20px;
        }
        
        .org-name {
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 3px;
        }
        
        .org-subtitle {
            font-size: 12px;
            margin-bottom: 2px;
        }
        
        .org-address {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 3px;
        }
        
        .org-phone {
            font-size: 12px;
        }
        
        .reference-row {
            display: flex;
            justify-content: space-between;
            border-bottom: 1px solid #000;
            padding: 8px 0;
            margin-bottom: 10px;
        }
        
        .reference-left {
            font-size: 12px;
        }
        
        .reference-right {
            font-size: 12px;
        }
        
        .subject-line {
            margin: 15px 0;
            text-align: center;
            font-weight: bold;
            text-decoration: underline;
        }
        
        .details-section {
            margin: 15px 0;
            font-size: 13px;
            line-height: 1.8;
        }
        
        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            border: 1px solid #000;
        }
        
        .details-table th {
            background: #f0f0f0;
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
            font-weight: bold;
        }
        
        .details-table td {
            border: 1px solid #000;
            padding: 8px;
            min-height: 30px;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        .items-table th,
        .items-table td {
            border: 1px solid #000;
            padding: 6px;
            text-align: center;
        }
        
        .items-table th {
            background: #f0f0f0;
            font-weight: bold;
        }
        
        .items-table .sn-col {
            width: 50px;
        }
        
        .items-table .desc-col {
            text-align: left;
        }
        
        .items-table .qty-col {
            width: 100px;
        }
        
        .items-table .amount-col {
            width: 120px;
            text-align: right;
        }
        
        .signature-section {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }
        
        .signature-box {
            text-align: center;
            width: 22%;
        }
        
        .signature-line {
            border-top: 1px dotted #000;
            margin-top: 50px;
            padding-top: 5px;
            font-size: 11px;
        }
        
        .dotted-line {
            border-bottom: 1px dotted #000;
            display: inline-block;
            min-width: 150px;
        }
        
        @media print {
            body {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
            
            .no-print {
                display: none !important;
            }
            
            .print-container {
                margin: 0;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <div class="no-print" style="text-align: center; padding: 10px; background: #f0f0f0;">
        <button onclick="window.print()" style="padding: 10px 30px; font-size: 16px; cursor: pointer;">
            🖨️ Print
        </button>
        <button onclick="window.close()" style="padding: 10px 30px; font-size: 16px; cursor: pointer; margin-left: 10px;">
            ✖ Close
        </button>
    </div>

    <div class="print-container">
        <!-- Header -->
        <div class="header">
            <div class="logo">
                <!-- Add your organization logo here -->
                <span style="font-size: 48px;">⚙</span>
            </div>
            <div class="org-name">जनक शिक्षा सामग्री केन्द्र लि.</div>
            <div class="org-subtitle">(नेपाल सरकारको पूर्ण स्वामित्व भएको)</div>
            <div class="org-address">केन्द्रीय कार्यालय, सानोठिमी, भक्तपुर</div>
            <div class="org-phone">फोन: ०१ ६६३०७८७, ०१ ६६३०७४६</div>
        </div>

        <!-- Reference Row -->
        <div class="reference-row">
            <div class="reference-left">
                <strong>श्री मानु प्रमुखज्यू</strong><br>
                प्रशासन विभाग<br>
                ज.शि.सा.के.लि.
            </div>
            <div class="reference-right">
                मिति :- <?= htmlspecialchars($record['maintenance_date_nep']) ?> / <?= date('Y.m.d', strtotime($record['maintenance_date_eng'])) ?>
            </div>
        </div>

        <!-- Subject -->
        <div class="subject-line">
            विषय :- गाडी/ट्रक/ DJ/दुराक्टर/मोटरसाइकल ।
        </div>

        <!-- Details -->
        <div class="details-section">
            उपर्युक्त विषयमा मिति <span class="dotted-line"><?= htmlspecialchars($record['maintenance_date_nep']) ?></span> यस केन्द्रको सवारी साधन नं<span class="dotted-line"><?= htmlspecialchars($record['vehicle_no']) ?></span>गाडी बिग्रीएको
            हुँदा निम्नानुसार सामनहरु खरिद गरी सर्भिसिंग  गरिएको  र  गर्नुपर्ने  वार्षिक  सर्भिस सुचित  किलोमिटर<span class="dotted-line"><?= number_format($record['next_due_km'] ?? 0) ?></span> यस पूर्वको किलोमिटर<span class="dotted-line"><?= number_format($record['meter_reading']) ?></span>र जम्मा चलेको किलोमिटर<span class="dotted-line"><?= number_format($record['meter_reading']) ?></span>मएको व्यहोरा
            अवगत गराउँदछु ।
        </div>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th class="sn-col">क.सं</th>
                    <th class="desc-col">विवरण</th>
                    <th class="qty-col">परिमाण</th>
                    <th class="amount-col">कैफियत</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // Parse parts replaced and work description into items
                $items = [];
                $sn = 1;
                
                if (!empty($record['parts_replaced'])) {
                    $parts = explode("\n", $record['parts_replaced']);
                    foreach ($parts as $part) {
                        $part = trim($part);
                        if (!empty($part)) {
                            $items[] = [
                                'description' => $part,
                                'quantity' => '1',
                                'remarks' => 'Parts'
                            ];
                        }
                    }
                }
                
                if (!empty($record['work_description'])) {
                    $items[] = [
                        'description' => $record['work_description'],
                        'quantity' => '-',
                        'remarks' => 'Service'
                    ];
                }
                
                // Add labor and parts cost
                if ($record['labor_cost'] > 0) {
                    $items[] = [
                        'description' => 'श्रम शुल्क (Labor Cost)',
                        'quantity' => '-',
                        'remarks' => 'रू ' . number_format($record['labor_cost'], 2)
                    ];
                }
                
                if ($record['parts_cost'] > 0) {
                    $items[] = [
                        'description' => 'पार्ट्स शुल्क (Parts Cost)',
                        'quantity' => '-',
                        'remarks' => 'रू ' . number_format($record['parts_cost'], 2)
                    ];
                }
                
                // Fill at least 18 rows
                $minRows = 18;
                $currentRows = count($items);
                
                foreach ($items as $item): 
                ?>
                <tr>
                    <td><?= $sn++ ?></td>
                    <td class="desc-col"><?= htmlspecialchars($item['description']) ?></td>
                    <td><?= htmlspecialchars($item['quantity']) ?></td>
                    <td class="amount-col"><?= htmlspecialchars($item['remarks']) ?></td>
                </tr>
                <?php endforeach; ?>
                
                <?php for ($i = $currentRows; $i < $minRows; $i++): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td class="desc-col"></td>
                    <td></td>
                    <td class="amount-col"></td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>

        <!-- Additional Details -->
        <div class="details-section">
            <strong>सेवा प्रदायक:</strong> <?= htmlspecialchars($record['service_provider'] ?? 'N/A') ?><br>
            <strong>मेकानिक नाम:</strong> <?= htmlspecialchars($record['mechanic_name'] ?? 'N/A') ?><br>
            <strong>बिल नं:</strong> <?= htmlspecialchars($record['bill_no'] ?? 'N/A') ?><br>
            <strong>कुल लागत:</strong> रू <?= number_format($record['total_cost'], 2) ?><br>
            <strong>भुक्तानी स्थिति:</strong> <?= $record['payment_status'] ? 'भुक्तानी भयो' : 'बाँकी' ?><br>
            <?php if ($record['is_warranty']): ?>
            <strong>वारेन्टी:</strong> <?= htmlspecialchars($record['warranty_remarks'] ?? 'हो') ?><br>
            <?php endif; ?>
            <?php if ($record['remarks']): ?>
            <strong>टिप्पणी:</strong> <?= htmlspecialchars($record['remarks']) ?><br>
            <?php endif; ?>
        </div>

        <!-- Signature Section -->
        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line">
                    (उजुरी हस्ताक्षर)<br>
                    ज.शि.सा.के.लि.
                </div>
            </div>
            
            <div class="signature-box">
                <div class="signature-line">
                    (स्वीकृत प्रशासन शाखा)<br>
                    ज.शि.सा.के.लि.
                </div>
            </div>
            
            <div class="signature-box">
                <div class="signature-line">
                    (प्रशासन विभाग)<br>
                    ज.शि.सा.के.लि.
                </div>
            </div>
            
            <div class="signature-box">
                <div class="signature-line">
                    (मानव संसाधन तथा वित्त निर्देशनालय)<br>
                    ज.शि.सा.के.लि.
                </div>
            </div>
        </div>

        <div class="signature-section" style="margin-top: 20px;">
            <div class="signature-box">
                
            </div>
            
            <div class="signature-box">
                
            </div>
            
            <div class="signature-box">
                
            </div>
            
            <div class="signature-box">
                <div class="signature-line">
                    (प्रबन्ध संचालक)<br>
                    ज.शि.सा.के.लि.
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto-print when page loads (optional)
        // window.onload = function() { window.print(); }
    </script>
</body>
</html>
